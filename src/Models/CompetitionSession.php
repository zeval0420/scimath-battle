<?php

require_once __DIR__ . '/Model.php';
require_once __DIR__ . '/../../config/constants.php';

/**
 * The single row (per event) of live runtime state. This is the only place
 * "what's happening right now" lives -- CompetitionRuntime (the service
 * layer) is the only code that should call the mutating methods here;
 * everything else should read state through CompetitionRuntime::getState().
 *
 * Timer model: the timer is RUNNING iff `timer_started_at IS NOT NULL`. It
 * is PAUSED iff `timer_paused_at IS NOT NULL` (with timer_started_at NULL).
 * Otherwise it's idle for the current question (not yet started, or just
 * reset). `timer_remaining_seconds` is always the authoritative "seconds
 * left as of the last anchor point" (question load, pause, or reset) --
 * while running, actual remaining time is computed by subtracting elapsed
 * wall-clock time since `timer_started_at` from that baseline. This is
 * never stored as a live countdown, so there's no per-second DB write and
 * no drift between whoever is asking (operator panel or public display).
 */
class CompetitionSession extends Model
{
    protected static string $table = 'competition_sessions';

    protected static array $fillable = [
        'event_id', 'current_question_id', 'current_round', 'round_started_at',
        'display_state', 'timer_duration_seconds', 'timer_started_at',
        'timer_paused_at', 'timer_remaining_seconds', 'is_active', 'ended_at',
    ];

    public static function forEvent(int $eventId): ?array
    {
        $rows = self::where(['event_id' => $eventId]);

        return $rows[0] ?? null;
    }

    /**
     * Every event gets exactly one session row, created idle/inactive. The
     * admin "create event" flow calls this alongside
     * EventSetting::createDefaultsFor() so runtime code can always assume a
     * session row exists.
     */
    public static function createFor(int $eventId): array
    {
        return self::create([
            'event_id'      => $eventId,
            'display_state' => DisplayState::COVER,
            'is_active'     => 0,
        ]);
    }

    /**
     * Locks the session row for update within the current transaction.
     * CompetitionRuntime wraps every mutating operation in a transaction and
     * calls this first, so two concurrent requests (e.g. an operator
     * double-clicking "next question") serialize instead of racing.
     */
    public static function lockForEvent(int $eventId): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM competition_sessions WHERE event_id = :event_id FOR UPDATE');
        $stmt->execute(['event_id' => $eventId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Computes seconds remaining right now. Never trusts a client-side
     * countdown -- always derived from the wall-clock `timer_started_at`
     * snapshot plus the last-saved baseline.
     */
    public static function computeRemainingSeconds(array $session): int
    {
        if ($session['timer_started_at'] === null) {
            // Not running: paused or idle -- the stored value is authoritative.
            return (int) ($session['timer_remaining_seconds'] ?? $session['timer_duration_seconds'] ?? 0);
        }

        $startedAt = strtotime($session['timer_started_at']);
        $elapsed = time() - $startedAt;
        $baseline = (int) $session['timer_remaining_seconds'];

        return max(0, $baseline - $elapsed);
    }

    public static function isTimerRunning(array $session): bool
    {
        return $session['timer_started_at'] !== null;
    }

    public static function isTimerPaused(array $session): bool
    {
        return $session['timer_started_at'] === null && $session['timer_paused_at'] !== null;
    }
}
