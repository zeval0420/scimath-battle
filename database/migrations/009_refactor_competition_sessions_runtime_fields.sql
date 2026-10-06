-- 009_refactor_competition_sessions_runtime_fields.sql
--
-- Stage 2's `phase` column conflated two different concerns: timer
-- sub-state (running/paused/expired) and what's shown on the public
-- display. This stage's spec calls for an explicit, small display-state
-- enum (COVER/QUESTION/TIME_UP/RANKING/FINAL_RESULTS) that the runtime
-- engine drives directly, decoupled from the mechanics of the timer itself.
--
-- Changes:
--   * `phase`                  -> replaced by `display_state`, a strictly
--                                 public-facing enum (constants.php: DisplayState)
--   * `show_ranking_on_display`-> removed; RANKING is now just one of the
--                                 display_state values, so a separate boolean
--                                 for the same concept is redundant
--   * `timer_paused_at`        -> added; explicit wall-clock record of when
--                                 the timer was paused (was previously only
--                                 implied by phase = 'timer_paused')
--   * `ended_at`               -> added; explicit timestamp for when the
--                                 competition ended, distinct from
--                                 `is_active` (a boolean can't say *when*)
--
-- Timer state is now derived, not stored as an enum: the timer is RUNNING
-- iff `timer_started_at IS NOT NULL`; PAUSED iff `timer_paused_at IS NOT
-- NULL` (and timer_started_at IS NULL); otherwise idle/not-yet-started for
-- the current question. See CompetitionSession::computeRemainingSeconds().

ALTER TABLE competition_sessions
    DROP COLUMN phase,
    DROP COLUMN show_ranking_on_display,
    ADD COLUMN display_state ENUM('cover', 'question', 'time_up', 'ranking', 'final_results')
        NOT NULL DEFAULT 'cover' AFTER current_question_id,
    ADD COLUMN timer_paused_at TIMESTAMP NULL AFTER timer_started_at,
    ADD COLUMN ended_at TIMESTAMP NULL AFTER is_active;
