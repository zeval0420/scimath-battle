<?php

require_once __DIR__ . '/Model.php';

class PromotionalSlide extends Model
{
    protected static string $table = 'scimath_promotional_slides';

    protected static array $fillable = [
        'event_id', 'title', 'description', 'file_path', 'file_type',
        'display_order', 'is_active',
    ];

    public static function forEvent(int $eventId, bool $activeOnly = false): array
    {
        $where = ['event_id' => $eventId];
        if ($activeOnly) {
            $where['is_active'] = 1;
        }

        return self::where($where, 'display_order ASC, id ASC');
    }

    public static function nextForEvent(int $eventId): ?array
    {
        $slides = self::forEvent($eventId, true);
        return $slides[0] ?? null;
    }

    public static function deleteWithFile(int $id): bool
    {
        $slide = self::find($id);
        if ($slide === null) {
            return false;
        }

        require_once __DIR__ . '/../Support/Uploader.php';
        Uploader::delete($slide['file_path']);

        return self::delete($id);
    }
}
