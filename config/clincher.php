<?php
// config/clincher.php - clincher round data shared by the operator and scorer pages.
// Each clincher question has its own set of participants and winners, chosen by the operator.
// Needs $conn (mysqli); the table is created on first use.

function ensure_clincher_table(mysqli $conn): void
{
    static $done = false; // DDL commits any open transaction, so run it once, before one is started
    if ($done) return;
    $done = true;
    $conn->query('CREATE TABLE IF NOT EXISTS scimath_clincher_entries (
            question_id INT UNSIGNED NOT NULL,
            contestant_id INT UNSIGNED NOT NULL,
            is_winner TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (question_id, contestant_id),
            KEY idx_clincher_contestant (contestant_id),
            CONSTRAINT fk_clincher_question FOREIGN KEY (question_id) REFERENCES scimath_questions (id) ON DELETE CASCADE,
            CONSTRAINT fk_clincher_contestant FOREIGN KEY (contestant_id) REFERENCES scimath_contestants (id) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

/** [question_id => ['participants' => [contestant ids], 'winners' => [contestant ids]]] for the event. */
function load_clincher(mysqli $conn, int $eventId): array
{
    ensure_clincher_table($conn);
    $stmt = $conn->prepare(
        'SELECT ce.question_id, ce.contestant_id, ce.is_winner
         FROM scimath_clincher_entries ce
         JOIN scimath_questions q ON q.id = ce.question_id
         WHERE q.event_id = ?
         ORDER BY ce.contestant_id'
    );
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result() as $r) {
        $qid = (int)$r['question_id'];
        $out[$qid] ??= ['participants' => [], 'winners' => []];
        $out[$qid]['participants'][] = (int)$r['contestant_id'];
        if ($r['is_winner']) $out[$qid]['winners'][] = (int)$r['contestant_id'];
    }
    return $out;
}

/** contestant_id => number of clincher rounds won; used to break ties in the rankings. */
function clincher_wins(array $clincher): array
{
    $wins = [];
    foreach ($clincher as $c) foreach ($c['winners'] as $id) $wins[$id] = ($wins[$id] ?? 0) + 1;
    return $wins;
}
