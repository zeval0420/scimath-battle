<?php

require_once __DIR__ . '/Model.php';

class AnswerSlide extends Model
{
    protected static string $table = 'scimath_answer_slides';

    protected static array $fillable = [
        'event_id', 'question_id', 'image_path', 'display_order', 'is_active',
    ];

    public static function forEvent(int $eventId, bool $activeOnly = false): array
    {
        $where = ['event_id' => $eventId];
        if ($activeOnly) {
            $where['is_active'] = 1;
        }

        return self::where($where, 'display_order ASC, id ASC');
    }

    public static function forQuestion(int $eventId, int $questionId): array
    {
        return self::where([
            'event_id' => $eventId,
            'question_id' => $questionId,
            'is_active' => 1,
        ], 'display_order ASC');
    }

    public static function deleteWithFile(int $id): bool
    {
        $slide = self::find($id);
        if ($slide === null) {
            return false;
        }

        require_once __DIR__ . '/../Support/Uploader.php';
        Uploader::delete($slide['image_path']);

        return self::delete($id);
    }
}
