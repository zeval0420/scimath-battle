<?php
// scorer/lib.php - data loading shared by index.php (first paint) and api.php (sync + save).
require_once __DIR__ . '/../config/database.php'; // provides $conn (mysqli)
require_once __DIR__ . '/../config/clincher.php';

const ROUND_ORDER  = ['easy', 'medium', 'hard', 'clincher'];
const ROUND_LABELS = ['easy' => 'Easy', 'medium' => 'Average', 'hard' => 'Difficult', 'clincher' => 'Clincher'];
// CSS classes in components.css use "average" / "difficult" for the medium / hard rounds.
const ROUND_CSS    = ['easy' => 'easy', 'medium' => 'average', 'hard' => 'difficult', 'clincher' => 'clincher'];

function load_event(mysqli $conn, int $eventId): ?array
{
    $stmt = $conn->prepare(
        "SELECT e.id, e.name, COALESCE(s.default_points, 10) AS default_points, c.current_question_id
         FROM scimath_events e
         LEFT JOIN scimath_event_settings s ON s.event_id = e.id
         LEFT JOIN scimath_competition_sessions c ON c.event_id = e.id
         WHERE e.id = ?"
    );
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function load_questions(mysqli $conn, int $eventId, int $defaultPoints): array
{
    $stmt = $conn->prepare(
        "SELECT id, `round`, question_number, points
         FROM scimath_questions
         WHERE event_id = ? AND is_active = 1 AND `round` <> 'clincher' -- clincher rounds are decided by the operator's winner pick, not scored
         ORDER BY FIELD(`round`, 'easy', 'medium', 'hard', 'clincher'), display_order, question_number"
    );
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $out = [];
    $seen = [];
    foreach ($stmt->get_result() as $r) {
        $rn = $seen[$r['round']] = ($seen[$r['round']] ?? 0) + 1; // number within its round
        $out[] = [
            'id'     => (int)$r['id'],
            'round'  => $r['round'],
            'number' => $rn,
            'name'   => ROUND_LABELS[$r['round']] . ' ' . $rn,
            'points' => $r['points'] === null ? $defaultPoints : (int)$r['points'],
        ];
    }
    return $out;
}

function load_contestants(mysqli $conn, int $eventId): array
{
    $stmt = $conn->prepare(
        "SELECT id, name, acronym, team_code, starting_score
         FROM scimath_contestants
         WHERE event_id = ? AND is_active = 1
         ORDER BY display_order, id"
    );
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result() as $r) {
        $out[] = [
            'id'      => (int)$r['id'],
            'no'      => count($out) + 1, // school number = position in the configured order
            'label'   => $r['acronym'] ?: ($r['team_code'] ?: $r['name']),
            'name'    => $r['name'],
            'start'   => (int)$r['starting_score'],
        ];
    }
    return $out;
}

/** Every score entry for the event: [{q, c, r, p, by}, ...] */
function load_scores(mysqli $conn, int $eventId): array
{
    $stmt = $conn->prepare(
        "SELECT question_id, contestant_id, result, points_awarded, scored_by
         FROM scimath_score_entries
         WHERE event_id = ? AND result <> 'adjustment'
           AND question_id IN (SELECT id FROM scimath_questions WHERE event_id = ? AND `round` <> 'clincher')"
    );
    $stmt->bind_param('ii', $eventId, $eventId);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result() as $r) {
        $out[] = [
            'q'  => (int)$r['question_id'],
            'c'  => (int)$r['contestant_id'],
            'r'  => $r['result'],
            'p'  => (int)$r['points_awarded'],
            'by' => $r['scored_by'],
        ];
    }
    return $out;
}
