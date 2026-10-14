<?php
// scorer/api.php - JSON endpoint for the scorer page.
//   GET  ?event=ID                      -> {scores: [...]}            (polled by every scorer)
//   POST {event, question, contestant,
//         result, scorer}               -> {ok, scores: [...]}        (result: correct|incorrect|no_answer|clear)
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $eventId = (int)($_GET['event'] ?? 0);
    if (!load_event($conn, $eventId)) fail(404, 'Event not found.');
    echo json_encode(['ok' => true, 'scores' => load_scores($conn, $eventId)]);
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

if (!in_array($result, ['correct', 'incorrect', 'no_answer', 'clear'], true)) fail(400, 'Invalid result.');
$event = load_event($conn, $eventId);
if (!$event) fail(404, 'Event not found.');

// The question and the team must both belong to this event.
$stmt = $conn->prepare("SELECT points FROM scimath_questions WHERE id = ? AND event_id = ? AND is_active = 1");
$stmt->bind_param('ii', $questionId, $eventId);
$stmt->execute();
$q = $stmt->get_result()->fetch_assoc();
if (!$q) fail(400, 'Unknown question.');

$stmt = $conn->prepare("SELECT 1 FROM scimath_contestants WHERE id = ? AND event_id = ? AND is_active = 1");
$stmt->bind_param('ii', $contestId, $eventId);
$stmt->execute();
if (!$stmt->get_result()->fetch_row()) fail(400, 'Unknown team.');

$points = $result === 'correct' ? ($q['points'] === null ? (int)$event['default_points'] : (int)$q['points']) : 0;

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("SELECT id, result, points_awarded FROM scimath_score_entries WHERE question_id = ? AND contestant_id = ? FOR UPDATE");
    $stmt->bind_param('ii', $questionId, $contestId);
    $stmt->execute();
    $prev = $stmt->get_result()->fetch_assoc();

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
} catch (Throwable $e) {
    $conn->rollback();
    fail(500, 'Could not save the score.');
}

echo json_encode(['ok' => true, 'scores' => load_scores($conn, $eventId)]);
