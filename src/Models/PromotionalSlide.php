<?php

require_once __DIR__ . '/Model.php';

class PromotionalSlide extends Model
{
    protected static string $table = 'scimath_promotional_slides';

    protected static array $fillable = [
        'event_id', 'title', 'description', 'file_path', 'file_type',
        'display_order', 'is_active',
    ];

    public static function createBatch(int $eventId, array $items): void
    {
        $db = self::db();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO scimath_promotional_slides (event_id,title,description,file_path,' .
                'file_type,display_order,is_active) VALUES (:event_id,:title,:description,' .
                ':file_path,:file_type,:display_order,:is_active)'
            );
            foreach ($items as $index => $item) {
                $stmt->execute([
                    'event_id'     => $eventId,
                    'title'        => trim($item['title']) !== '' ? $item['title'] : ('Slide ' . ($index + 1)),
                    'description'  => trim($item['description']) !== '' ? $item['description'] : null,
                    'file_path'    => $item['file_path'],
                    'file_type'    => 'image',
                    'display_order'=> $index,
                    'is_active'    => $item['is_active'] ? 1 : 0,
                ]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

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
