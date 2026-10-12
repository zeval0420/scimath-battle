<?php

require_once __DIR__ . '/Model.php';

class Contestant extends Model
{
    protected static string $table = 'scimath_contestants';

    protected static array $fillable = [
        'event_id', 'name', 'team_code', 'acronym', 'organization', 'logo_path',
        'starting_score', 'display_order', 'is_active', 'is_clincher',
    ];

    public static function forEvent(int $eventId, bool $activeOnly = false): array
    {
        $where = ['event_id' => $eventId];
        if ($activeOnly) {
            $where['is_active'] = 1;
        }

        return self::where($where, 'display_order ASC, name ASC');
    }

    /** Active teams manually selected to take part in the clincher round. */
    public static function clincherForEvent(int $eventId): array
    {
        return self::where(['event_id' => $eventId, 'is_active' => 1, 'is_clincher' => 1], 'display_order ASC, name ASC');
    }
}
