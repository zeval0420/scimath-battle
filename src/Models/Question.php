<?php

require_once __DIR__ . '/Model.php';
require_once __DIR__ . '/../../config/constants.php';

class Question extends Model
{
    protected static string $table = 'scimath_questions';

    protected static array $fillable = [
        'event_id', 'category_id', 'round', 'question_number', 'image_path', 'answer_image_path',
        'points', 'time_seconds', 'is_active', 'display_order',
    ];

    public static function hasAnswerImage(array $question): bool
    {
        return !empty($question['answer_image_path']);
    }

    /**
     * @param bool        $activeOnly Only active questions.
     * @param string|null $round      Restrict to a single round (Round constants).
     */
    public static function forEvent(int $eventId, bool $activeOnly = false, ?string $round = null): array
    {
        $where = ['event_id' => $eventId];
        if ($activeOnly) {
            $where['is_active'] = 1;
        }
        if ($round !== null) {
            $where['round'] = $round;
        }

        return self::where($where, 'display_order ASC, question_number ASC');
    }

    /** First active question belonging to the given round, or null. */
    public static function firstInRound(int $eventId, string $round): ?array
    {
        $rows = self::forEvent($eventId, true, $round);

        return $rows[0] ?? null;
    }

    /**
     * The rounds this event actually has active questions for, in canonical
     * order (easy -> medium -> hard -> clincher). Rounds with no questions
     * are omitted.
     *
     * @return string[]
     */
    public static function activeRounds(int $eventId): array
    {
        $present = array_column(self::forEvent($eventId, true), 'round');
        $present = array_unique($present);

        return array_values(array_filter(Round::ALL, fn ($r) => in_array($r, $present, true)));
    }

    /**
     * Resolves the question's *effective* points, falling back to the
     * event's default_points when the question doesn't override it.
     * This is the one place that logic lives, so operator scoring and any
     * future admin preview both stay consistent.
     */
    public static function effectivePoints(array $question, array $eventSettings): int
    {
        return $question['points'] !== null
            ? (int) $question['points']
            : (int) $eventSettings['default_points'];
    }

    public static function effectiveTimeSeconds(array $question, array $eventSettings): int
    {
        return $question['time_seconds'] !== null
            ? (int) $question['time_seconds']
            : (int) $eventSettings['default_time_seconds'];
    }

    public static function createBatch(int $eventId, array $items): array
    {
        $db = self::db();
        $db->beginTransaction();
        $inserted = [];
        try {
            $stmt = $db->prepare(
                'INSERT INTO scimath_questions (event_id,category_id,round,question_number,' .
                'image_path,answer_image_path,points,time_seconds,is_active,display_order) ' .
                'VALUES (:event_id,:category_id,:round,:question_number,:image_path,' .
                ':answer_image_path,:points,:time_seconds,:is_active,:display_order)'
            );
            foreach ($items as $index => $item) {
                $stmt->execute([
                    'event_id'          => $eventId,
                    'category_id'       => $item['category_id'] ?: null,
                    'round'             => $item['round'] ?? Round::EASY,
                    'question_number'   => (int) $item['question_number'],
                    'image_path'        => $item['image_path'],
                    'answer_image_path' => $item['answer_image_path'] ?? null,
                    'points'            => $item['points'] ?: null,
                    'time_seconds'      => $item['time_seconds'] ?: null,
                    'is_active'         => $item['is_active'] ? 1 : 0,
                    'display_order'     => $index,
                ]);
                $inserted[] = self::findOrFail((int) $db->lastInsertId());
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $inserted;
    }

    public static function nextQuestionNumber(int $eventId): int
    {
$stmt = self::db()->prepare(
             'SELECT COALESCE(MAX(question_number), 0) + 1 AS next_number
              FROM scimath_questions WHERE event_id = :event_id'
         );
        $stmt->execute(['event_id' => $eventId]);

        return (int) $stmt->fetch()['next_number'];
    }
}
