<?php

require_once __DIR__ . '/Model.php';

class Category extends Model
{
    protected static string $table = 'categories';

    protected static array $fillable = [
        'event_id', 'name', 'description', 'display_order',
    ];

    public static function forEvent(int $eventId): array
    {
        return self::where(['event_id' => $eventId], 'display_order ASC, name ASC');
    }
}
