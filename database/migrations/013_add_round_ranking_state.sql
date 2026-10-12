-- 013_add_round_ranking_state.sql
--
-- Adds a dedicated 'round_ranking' display state (a per-round standings view
-- with the score breakdown for that round only), alongside the existing
-- 'ranking' state (which now presents the final/cumulative breakdown).
--
-- `display_round` remembers which round the operator selected for the
-- round-ranking view, so the public display can keep showing it across polls
-- without the operator re-selecting it.
--
-- The new ENUM value is APPENDED (not inserted mid-list): MySQL stores ENUM
-- values by ordinal position, so appending preserves the existing values of
-- every already-stored row.

ALTER TABLE scimath_competition_sessions
    MODIFY COLUMN display_state ENUM(
        'cover',
        'question',
        'time_up',
        'ranking',
        'final_results',
        'promotional',
        'answer',
        'hidden',
        'round_ranking'
    ) NOT NULL DEFAULT 'cover',
    ADD COLUMN display_round ENUM('easy', 'medium', 'hard', 'clincher') NULL AFTER display_state;
