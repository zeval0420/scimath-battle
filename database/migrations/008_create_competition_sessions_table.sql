-- 008_create_competition_sessions_table.sql
--
-- One row per event (1:1), holding the live runtime state of a competition
-- in progress. This is the ONLY place "what's happening right now" is
-- tracked -- both the operator panel and the public display read (and the
-- operator panel writes) this table, so a page refresh on either side can
-- always recover exact state instead of relying on client-side memory.
--
-- Timer design: we store `timer_started_at` (wall-clock timestamp) and
-- `timer_remaining_seconds` (snapshot taken on pause/reset), NOT a live
-- ticking countdown value. Elapsed time is computed as
-- `timer_remaining_seconds - (NOW() - timer_started_at)` while phase =
-- 'timer_running'. This avoids write-amplification (no per-second DB writes)
-- and avoids clock drift between server and any client.

CREATE TABLE IF NOT EXISTS competition_sessions (
    id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id                INT UNSIGNED NOT NULL,
    current_question_id     INT UNSIGNED NULL,

    phase                   ENUM(
                                'idle',            -- not yet started / between questions
                                'timer_running',
                                'timer_paused',
                                'timer_expired',
                                'scored',           -- scores entered for current question
                                'ranking_shown'
                             ) NOT NULL DEFAULT 'idle',

    timer_duration_seconds   SMALLINT UNSIGNED NULL,
        -- snapshot of the resolved duration for the current question
        -- (question override, or event default) at the moment it was started
    timer_started_at         TIMESTAMP NULL,
    timer_remaining_seconds  SMALLINT NULL,

    is_active                BOOLEAN NOT NULL DEFAULT 0,
        -- true once the operator has pressed "Start Competition"; false
        -- again once "End Competition" is pressed. Distinct from event.status
        -- so the event record's lifecycle (draft/ready/...) and the specific
        -- live session's on/off switch don't have to be conflated.
    show_ranking_on_display  BOOLEAN NOT NULL DEFAULT 0,

    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_competition_sessions_event (event_id),

    CONSTRAINT fk_competition_sessions_event
        FOREIGN KEY (event_id) REFERENCES events (id)
        ON DELETE CASCADE,

    -- Deliberately NOT a foreign key with ON DELETE CASCADE to questions:
    -- if the current question were ever deleted mid-event we want that to
    -- surface as an application-level error, not silently null out /
    -- collapse the whole session. SET NULL keeps the session row alive.
    CONSTRAINT fk_competition_sessions_question
        FOREIGN KEY (current_question_id) REFERENCES questions (id)
        ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
