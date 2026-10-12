<?php

/**
 * Central place for the fixed vocabularies used across the system.
 *
 * These mirror the database ENUM columns exactly. Keeping them as PHP
 * constants (rather than repeating string literals through the codebase)
 * means a typo becomes a fatal error / IDE warning instead of a silent bug,
 * and the accepted values are documented in exactly one place.
 *
 * NONE of these represent event-specific business logic (e.g. no
 * "Sci-Math Battle" or "Mathematics" here) -- they are generic states of the
 * competition engine itself, applicable to any event.
 */

final class EventStatus
{
    public const DRAFT     = 'draft';
    public const READY     = 'ready';
    public const ACTIVE    = 'active';
    public const COMPLETED = 'completed';
    public const ARCHIVED  = 'archived';

    public const ALL = [self::DRAFT, self::READY, self::ACTIVE, self::COMPLETED, self::ARCHIVED];
}

final class AnswerResult
{
    public const CORRECT    = 'correct';
    public const INCORRECT  = 'incorrect';
    public const NO_ANSWER  = 'no_answer';
    public const ADJUSTMENT = 'adjustment'; // manual/custom point adjustment not tied to a right/wrong call

    public const ALL = [self::CORRECT, self::INCORRECT, self::NO_ANSWER, self::ADJUSTMENT];
}

/**
 * What the public display (and operator preview) should currently be
 * showing. This is deliberately separate from timer mechanics (running /
 * paused / idle) -- those are derived from timer_started_at / timer_paused_at
 * on the competition_sessions row, not stored as an enum. See
 * CompetitionSession::computeRemainingSeconds().
 */
final class DisplayState
{
    public const COVER          = 'cover';
    public const QUESTION       = 'question';
    public const TIME_UP        = 'time_up';
    public const RANKING        = 'ranking';
    public const ROUND_RANKING  = 'round_ranking';
    public const FINAL_RESULTS  = 'final_results';
    public const PROMOTIONAL    = 'promotional';
    public const ANSWER         = 'answer';
    public const HIDDEN         = 'hidden';

    public const ALL = [
        self::COVER, self::QUESTION, self::TIME_UP, self::RANKING,
        self::ROUND_RANKING, self::FINAL_RESULTS, self::PROMOTIONAL,
        self::ANSWER, self::HIDDEN,
    ];
}

final class RankingOrder
{
    public const SCORE_DESC = 'score_desc';
    public const SCORE_ASC  = 'score_asc';

    public const ALL = [self::SCORE_DESC, self::SCORE_ASC];
}

/**
 * The contest's rounds, ordered from the easiest to the final tie-break.
 *
 * The first three are difficulty rounds that every team plays. CLINCHER is
 * the final round, and only the teams the operator manually selects
 * (scimath_contestants.is_clincher = 1) take part in it.
 */
final class Round
{
    public const EASY     = 'easy';
    public const MEDIUM   = 'medium';
    public const HARD     = 'hard';
    public const CLINCHER = 'clincher';

    public const ALL = [self::EASY, self::MEDIUM, self::HARD, self::CLINCHER];

    /** Rounds every team plays (clincher excluded). */
    public const DIFFICULTY = [self::EASY, self::MEDIUM, self::HARD];

    public static function label(string $round): string
    {
        return match ($round) {
            self::EASY     => 'Easy',
            self::MEDIUM   => 'Medium',
            self::HARD     => 'Hard',
            self::CLINCHER => 'Clincher',
            default        => ucfirst($round),
        };
    }

    public static function isClincher(?string $round): bool
    {
        return $round === self::CLINCHER;
    }
}
