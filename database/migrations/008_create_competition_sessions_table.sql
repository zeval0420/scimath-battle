-- 008_create_competition_sessions_table.sql
--
-- One row per event (1:1), holding the live runtime state of a competition in
-- progress. This is the ONLY place "what's happening right now" is tracked --
-- both the operator panel and the public display read this table.
--
-- Timer design: we store `timer_started_at` (wall-clock timestamp) and
-- `timer_remaining_seconds` (snapshot taken on pause/reset), NOT a live
-- ticking countdown. The timer is RUNNING iff timer_started_at IS NOT NULL;
-- PAUSED iff timer_paused_at IS NOT NULL (and timer_started_at IS NULL);
-- otherwise idle for the current question. `display_state` is the strictly
-- public-facing view (see config/constants.php: DisplayState).

CREATE TABLE IF NOT EXISTS scimath_competition_sessions (
    id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id                INT UNSIGNED NOT NULL,
    current_question_id     INT UNSIGNED NULL,
    display_state           ENUM(
                                'cover',
                                'question',
                                'time_up',
                                'ranking',
                                'final_results',
                                'promotional',
                                'answer',
                                'hidden'
                            ) NOT NULL DEFAULT 'cover',

    current_round           SMALLINT UNSIGNED NULL,
    round_started_at        TIMESTAMP NULL,

    timer_duration_seconds  SMALLINT UNSIGNED NULL,
    timer_started_at        TIMESTAMP NULL,
    timer_paused_at         TIMESTAMP NULL,
    timer_remaining_seconds SMALLINT NULL,

    is_active               BOOLEAN NOT NULL DEFAULT 0,
    ended_at                TIMESTAMP NULL,

    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_scimath_competition_sessions_event (event_id),

    CONSTRAINT fk_scimath_competition_sessions_event
        FOREIGN KEY (event_id) REFERENCES scimath_events (id)
        ON DELETE CASCADE,

    -- Deliberately SET NULL (not CASCADE) so a deleted current question can
    -- never collapse the whole session row.
    CONSTRAINT fk_scimath_competition_sessions_question
        FOREIGN KEY (current_question_id) REFERENCES scimath_questions (id)
        ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
