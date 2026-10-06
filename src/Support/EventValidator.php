<?php

/**
 * Checks whether an event is complete enough to move out of DRAFT status.
 * Called before allowing a status change to 'ready' or 'active', so an
 * operator can never be handed a competition that can't actually run.
 */
class EventValidator
{
    /**
     * @return string[] Problems preventing activation; empty array = ready.
     */
    public static function readinessProblems(int $eventId): array
    {
        $problems = [];

        $contestants = Contestant::forEvent($eventId, true);
        if (count($contestants) < 1) {
            $problems[] = 'Add at least one contestant/team before activating this event.';
        }

        $questions = Question::forEvent($eventId, true);
        if (count($questions) < 1) {
            $problems[] = 'Add at least one active question before activating this event.';
        }

        $settings = EventSetting::forEvent($eventId);
        if ($settings === null) {
            $problems[] = 'Event settings are missing (this should not normally happen).';
        }

        return $problems;
    }

    public static function canActivate(int $eventId): bool
    {
        return self::readinessProblems($eventId) === [];
    }
}
