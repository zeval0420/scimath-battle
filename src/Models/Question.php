<?php

require_once __DIR__ . '/Model.php';

class Question extends Model
{
    protected static string $table = 'questions';

    protected static array $fillable = [
        'event_id', 'category_id', 'question_number', 'image_path', 'answer_image_path',
        'points', 'time_seconds', 'is_active', 'display_order',
    ];

    public static function hasAnswerImage(array $question): bool
    {
        return !empty($question['answer_image_path']);
    }

    public static function forEvent(int $eventId, bool $activeOnly = false): array
    {
        $where = ['event_id' => $eventId];
        if ($activeOnly) {
            $where['is_active'] = 1;
        }

        return self::where($where, 'display_order ASC, question_number ASC');
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

    public static function nextQuestionNumber(int $eventId): int
    {
        $stmt = self::db()->prepare(
            'SELECT COALESCE(MAX(question_number), 0) + 1 AS next_number
             FROM questions WHERE event_id = :event_id'
        );
        $stmt->execute(['event_id' => $eventId]);

        return (int) $stmt->fetch()['next_number'];
    }
}
