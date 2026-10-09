<?php

require_once __DIR__ . '/Model.php';
require_once __DIR__ . '/ScoreAdjustment.php';
require_once __DIR__ . '/../../config/constants.php';

class ScoreEntry extends Model
{
    protected static string $table = 'scimath_score_entries';

    protected static array $fillable = [
        'event_id', 'question_id', 'contestant_id', 'result', 'points_awarded', 'scored_by',
    ];

    public static function forQuestion(int $questionId): array
    {
        return self::where(['question_id' => $questionId]);
    }

    public static function forEvent(int $eventId): array
    {
        return self::where(['event_id' => $eventId]);
    }

    public static function findByQuestionAndContestant(int $questionId, int $contestantId): ?array
    {
        $rows = self::where(['question_id' => $questionId, 'contestant_id' => $contestantId]);

        return $rows[0] ?? null;
    }

    /**
     * Score entries for an event (optionally scoped to one question), joined
     * with contestant/question labels for convenient display -- used by the
     * runtime service's getScores(). Read-only convenience; scoring itself
     * always goes through recordResult().
     */
    public static function detailedForEvent(int $eventId, ?int $questionId = null): array
    {
$sql = <<<SQL
             SELECT
                 se.id, se.question_id, se.contestant_id, se.result, se.points_awarded,
                 se.scored_by, se.scored_at, se.updated_at,
                 q.question_number,
                 c.name AS contestant_name, c.team_code
             FROM scimath_score_entries se
             JOIN scimath_questions q ON q.id = se.question_id
             JOIN scimath_contestants c ON c.id = se.contestant_id
             WHERE se.event_id = :event_id
         SQL;

        $params = ['event_id' => $eventId];
        if ($questionId !== null) {
            $sql .= ' AND se.question_id = :question_id';
            $params['question_id'] = $questionId;
        }

        $sql .= ' ORDER BY q.question_number ASC, c.display_order ASC';

        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Records a result for a contestant on a question, or corrects it if one
     * already exists. This is the ONLY entry point that should ever write to
     * score_entries -- it guarantees every change (initial or corrected) is
     * mirrored into score_adjustments, satisfying the audit requirement, and
     * it is safe against duplicate submissions, accidental double-clicks,
     * and races:
     *
     *   - Idempotent: if the submitted result+points are identical to what's
     *     already stored, this is a no-op (the existing entry is returned
     *     unchanged) rather than writing a redundant "no-op correction" into
     *     the audit trail. This is what makes a double-clicked "Correct"
     *     button harmless.
     *   - Race-safe: the existing row (if any) is locked with SELECT ... FOR
     *     UPDATE inside the transaction, so two concurrent requests for the
     *     same question/contestant serialize rather than interleave. If two
     *     requests both see "no existing row" and race to INSERT, the
     *     table's UNIQUE(question_id, contestant_id) constraint lets exactly
     *     one succeed; the other catches the resulting PDOException and
     *     retries as an update against the row the winner just created,
     *     rather than surfacing a confusing duplicate-key error to the
     *     operator.
     */
    public static function recordResult(
        int $eventId,
        int $questionId,
        int $contestantId,
        string $result,
        int $pointsAwarded,
        ?string $scoredBy = null,
        ?string $reason = null
    ): array {
        $db = self::db();
        $db->beginTransaction();

        try {
            $entry = self::writeResult($eventId, $questionId, $contestantId, $result, $pointsAwarded, $scoredBy, $reason);
            $db->commit();

            return $entry;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Does the actual locked read + insert-or-update. Split out from
     * recordResult() so the race-fallback path can call it again after
     * catching a duplicate-key exception, without nesting transactions.
     */
    private static function writeResult(
        int $eventId,
        int $questionId,
        int $contestantId,
        string $result,
        int $pointsAwarded,
        ?string $scoredBy,
        ?string $reason
    ): array {
        $db = self::db();

$stmt = $db->prepare(
             'SELECT * FROM scimath_score_entries WHERE question_id = :question_id AND contestant_id = :contestant_id FOR UPDATE'
         );
        $stmt->execute(['question_id' => $questionId, 'contestant_id' => $contestantId]);
        $existing = $stmt->fetch();
        $existing = $existing === false ? null : $existing;

        if ($existing !== null) {
            // Idempotent no-op: identical resubmission (e.g. a double-click)
            // changes nothing and leaves no redundant audit entry.
            if ($existing['result'] === $result && (int) $existing['points_awarded'] === $pointsAwarded) {
                return $existing;
            }

            $entry = parent::update($existing['id'], [
                'result'         => $result,
                'points_awarded' => $pointsAwarded,
                'scored_by'      => $scoredBy,
            ]);

            ScoreAdjustment::create([
                'score_entry_id'  => $entry['id'],
                'previous_result' => $existing['result'],
                'previous_points' => $existing['points_awarded'],
                'new_result'      => $result,
                'new_points'      => $pointsAwarded,
                'reason'          => $reason,
                'changed_by'      => $scoredBy,
            ]);

            return $entry;
        }

        try {
            $entry = parent::create([
                'event_id'       => $eventId,
                'question_id'    => $questionId,
                'contestant_id'  => $contestantId,
                'result'         => $result,
                'points_awarded' => $pointsAwarded,
                'scored_by'      => $scoredBy,
            ]);
        } catch (PDOException $e) {
            // Lost the race: another request inserted the row between our
            // SELECT ... FOR UPDATE (which found nothing) and our INSERT.
            // Recurse once -- the row now exists, so this becomes the
            // update path above. A genuine constraint violation on any
            // other column would still throw and propagate normally.
            if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), '1062')) {
                return self::writeResult($eventId, $questionId, $contestantId, $result, $pointsAwarded, $scoredBy, $reason);
            }
            throw $e;
        }

        ScoreAdjustment::create([
            'score_entry_id'  => $entry['id'],
            'previous_result' => null,
            'previous_points' => null,
            'new_result'      => $result,
            'new_points'      => $pointsAwarded,
            'reason'          => $reason,
            'changed_by'      => $scoredBy,
        ]);

        return $entry;
    }

    /**
     * Full audit trail for one score entry (all corrections in order).
     */
    public static function history(int $scoreEntryId): array
    {
        return ScoreAdjustment::forScoreEntry($scoreEntryId);
    }

    /**
     * Computes standings for an event by summing contestants' starting_score
     * plus every score_entries.points_awarded, then ranking.
     *
     * This is the single source of truth for ranking math -- both the
     * operator panel and the public display call this (directly, or via the
     * same API endpoint) rather than each re-implementing the sum/sort,
     * satisfying the "don't duplicate logic between operator and public
     * interfaces" rule.
     *
     * Equal totals receive equal rank (standard competition ranking, i.e.
     * "1224" ranking) with no tie-breaker, per current spec.
     */
    public static function rankingForEvent(int $eventId): array
    {
        $settings = EventSetting::forEvent($eventId);
        $direction = ($settings !== null && $settings['ranking_order'] === RankingOrder::SCORE_ASC)
            ? 'ASC'
            : 'DESC';

$sql = <<<SQL
             SELECT
                 c.id            AS contestant_id,
                 c.name          AS name,
                 c.team_code     AS team_code,
                 c.acronym       AS acronym,
                 c.logo_path     AS logo_path,
                 c.starting_score
                     + COALESCE(SUM(se.points_awarded), 0) AS total_score
             FROM scimath_contestants c
             LEFT JOIN scimath_score_entries se
                 ON se.contestant_id = c.id AND se.event_id = c.event_id
             WHERE c.event_id = :event_id
               AND c.is_active = 1
             GROUP BY c.id, c.name, c.team_code, c.acronym, c.logo_path, c.starting_score
             ORDER BY total_score {$direction}, c.display_order ASC
         SQL;

        $stmt = self::db()->prepare($sql);
        $stmt->execute(['event_id' => $eventId]);
        $rows = $stmt->fetchAll();

        // Assign standard competition rank (ties share a rank; next rank
        // skips accordingly, e.g. 1, 2, 2, 4).
        $rank = 0;
        $previousScore = null;
        foreach ($rows as $index => &$row) {
            if ($row['total_score'] !== $previousScore) {
                $rank = $index + 1;
            }
            $row['rank'] = $rank;
            $previousScore = $row['total_score'];
        }
        unset($row);

        return $rows;
    }
}
