<?php

require_once __DIR__ . '/Model.php';

/**
 * Append-only audit log for score_entries changes. Deliberately exposes no
 * update()/delete() usage in application code -- history must not be
 * editable after the fact.
 */
class ScoreAdjustment extends Model
{
    protected static string $table = 'score_adjustments';

    protected static array $fillable = [
        'score_entry_id', 'previous_result', 'previous_points',
        'new_result', 'new_points', 'reason', 'changed_by',
    ];

    public static function forScoreEntry(int $scoreEntryId): array
    {
        return self::where(['score_entry_id' => $scoreEntryId], 'changed_at ASC');
    }
}
