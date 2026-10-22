<?php
// scorer/api.php - JSON endpoint for the scorer page.
//   GET  ?event=ID                      -> {scores: [...]}            (polled by every scorer)
//   POST {event, question, contestant,
//         result, scorer}               -> {ok, scores: [...]}        (result: correct|incorrect|no_answer|clear)
require_once __DIR__ . '/../config/auth.php';
require_login(true);
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // failed queries throw, so the save handler can catch and retry

function fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $eventId = (int)($_GET['event'] ?? 0);
    if (!load_event($conn, $eventId)) fail(404, 'Event not found.');
    echo json_encode(['ok' => true, 'scores' => load_scores($conn, $eventId), 'clincher' => (object)load_clincher($conn, $eventId)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Method not allowed.');

$in         = json_decode(file_get_contents('php://input'), true) ?: [];
$eventId    = (int)($in['event'] ?? 0);
$questionId = (int)($in['question'] ?? 0);
$contestId  = (int)($in['contestant'] ?? 0);
$result     = (string)($in['result'] ?? '');
$scorer     = mb_substr(trim((string)($in['scorer'] ?? '')), 0, 100);
$scorer     = $scorer === '' ? null : $scorer;

$action = (string)($in['action'] ?? '');
if ($action === 'reset_question' || $action === 'reset_event') {
    if (!load_event($conn, $eventId)) fail(404, 'Event not found.');
    if ($action === 'reset_question') {
        $stmt = $conn->prepare("DELETE FROM scimath_score_entries WHERE event_id = ? AND question_id = ?");
        $stmt->bind_param('ii', $eventId, $questionId);
    } else {
        $stmt = $conn->prepare("DELETE FROM scimath_score_entries WHERE event_id = ?");
        $stmt->bind_param('i', $eventId);
    }
    if (!$stmt->execute()) fail(500, 'Could not reset the scores.');
    echo json_encode(['ok' => true, 'scores' => load_scores($conn, $eventId), 'clincher' => (object)load_clincher($conn, $eventId)]);
    exit;
}

if (!in_array($result, ['correct', 'incorrect', 'no_answer', 'clear'], true)) fail(400, 'Invalid result.');
$event = load_event($conn, $eventId);
if (!$event) fail(404, 'Event not found.');

// The question and the team must both belong to this event.
$stmt = $conn->prepare("SELECT points, `round` FROM scimath_questions WHERE id = ? AND event_id = ? AND is_active = 1");
$stmt->bind_param('ii', $questionId, $eventId);
$stmt->execute();
$q = $stmt->get_result()->fetch_assoc();
if (!$q) fail(400, 'Unknown question.');

$stmt = $conn->prepare("SELECT 1 FROM scimath_contestants WHERE id = ? AND event_id = ? AND is_active = 1");
$stmt->bind_param('ii', $contestId, $eventId);
$stmt->execute();
if (!$stmt->get_result()->fetch_row()) fail(400, 'Unknown team.');

$points = $result === 'correct' ? ($q['points'] === null ? (round_points($conn, $eventId)[$q['round']] ?? (int)$event['default_points']) : (int)$q['points']) : 0;

// Several scorers save at once (different contestants, same question). Locking a row that does not exist yet
// (SELECT ... FOR UPDATE on the unique key) takes a gap lock, and two scorers inserting into neighbouring
// gaps deadlock - one of them failed. So: look the row up without locking, lock it by primary key only when
// it exists, and retry if MySQL still picks this transaction as a deadlock/lock-wait victim.
$saved = false;
for ($attempt = 0; $attempt < 4 && !$saved; $attempt++) {
try {
    $stmt = $conn->prepare("SELECT id FROM scimath_score_entries WHERE question_id = ? AND contestant_id = ?");
    $stmt->bind_param('ii', $questionId, $contestId);
    $stmt->execute();
    $existingId = ($stmt->get_result()->fetch_row()[0] ?? null);

    $conn->begin_transaction();
    $prev = null;
    if ($existingId !== null) {
        $stmt = $conn->prepare("SELECT id, result, points_awarded FROM scimath_score_entries WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $existingId);
        $stmt->execute();
        $prev = $stmt->get_result()->fetch_assoc();
    }

    if ($result === 'clear') {
        if ($prev) {
            $stmt = $conn->prepare("DELETE FROM scimath_score_entries WHERE id = ?");
            $stmt->bind_param('i', $prev['id']);
            $stmt->execute();
        }
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO scimath_score_entries (event_id, question_id, contestant_id, result, points_awarded, scored_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE result = VALUES(result), points_awarded = VALUES(points_awarded), scored_by = VALUES(scored_by)"
        );
        $stmt->bind_param('iiisis', $eventId, $questionId, $contestId, $result, $points, $scorer);
        $stmt->execute();

        // Audit trail when a score is changed by another (or the same) scorer.
        if ($prev && ($prev['result'] !== $result || (int)$prev['points_awarded'] !== $points)) {
            $stmt = $conn->prepare(
                "INSERT INTO scimath_score_adjustments (score_entry_id, previous_result, previous_points, new_result, new_points, changed_by)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('isisis', $prev['id'], $prev['result'], $prev['points_awarded'], $result, $points, $scorer);
            $stmt->execute();
        }
    }
    $conn->commit();
    $saved = true;
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $_) {}
    $transient = in_array((int)$e->getCode(), [1205, 1213], true); // lock wait timeout, deadlock
    if (!$transient || $attempt === 3) fail(500, 'Could not save the score.');
    usleep(random_int(30000, 120000));
}
}

echo json_encode(['ok' => true, 'scores' => load_scores($conn, $eventId)]);
