<?php

require_once __DIR__ . '/Model.php';
require_once __DIR__ . '/../../config/constants.php';

class Event extends Model
{
    protected static string $table = 'events';

    protected static array $fillable = [
        'name', 'subtitle', 'logo_path', 'cover_image_path', 'event_date', 'status',
    ];

    public static function create(array $attributes): array
    {
        $attributes['status'] ??= EventStatus::DRAFT;

        return parent::create($attributes);
    }

    public static function allOrderedByDate(): array
    {
        return self::all('event_date DESC, created_at DESC');
    }

    /**
     * Convenience loader for everything needed to render the admin
     * "configure event" screen or the public/operator bootstrap payload in
     * one round trip's worth of queries.
     */
    public static function withRelations(int $eventId): array
    {
        $event = self::findOrFail($eventId);

        $event['settings']     = EventSetting::forEvent($eventId);
        $event['categories']   = Category::where(['event_id' => $eventId], 'display_order ASC');
        $event['contestants']  = Contestant::where(['event_id' => $eventId], 'display_order ASC');
        $event['questions']    = Question::where(['event_id' => $eventId], 'display_order ASC');

        return $event;
    }
}

require_once __DIR__ . '/EventSetting.php';
require_once __DIR__ . '/Category.php';
require_once __DIR__ . '/Contestant.php';
require_once __DIR__ . '/Question.php';
