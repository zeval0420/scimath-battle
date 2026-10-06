-- 002_create_event_settings_table.sql
--
-- Design decision: 1:1 with events (one settings row per event), rather than
-- a generic key/value table.
--
-- Rationale: the settings we know we need (default points, default time,
-- ranking behaviour, auto-show-ranking) are few, well-typed, always present,
-- and are read on nearly every request (public display, operator panel).
-- Typed columns let the DB enforce sane values/defaults and keep queries
-- simple (no pivoting a key/value table every read).
--
-- `extra_settings` (JSON) is the escape hatch for future, rarely-queried,
-- presentation-only knobs (e.g. theme color, transition style) that don't
-- need to be relationally validated or indexed. This avoids both "dozens of
-- speculative columns" and "everything is an opaque blob".

CREATE TABLE IF NOT EXISTS event_settings (
    id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id                    INT UNSIGNED NOT NULL,

    -- Scoring / question defaults (used when a Question doesn't override)
    default_points              INT UNSIGNED NOT NULL DEFAULT 10,
    default_time_seconds        SMALLINT UNSIGNED NOT NULL DEFAULT 60,

    -- Ranking behaviour
    ranking_order               ENUM('score_desc', 'score_asc') NOT NULL DEFAULT 'score_desc',
    tie_break_mode              ENUM('none') NOT NULL DEFAULT 'none',
        -- Deliberately a single-value enum for now (per spec: tie-breaking is
        -- a future concern). Modeled as an enum, not a free-text column, so
        -- adding a real tie-break strategy later is a migration, not a data
        -- cleanup exercise.
    auto_show_ranking_after_score BOOLEAN NOT NULL DEFAULT 0,

    -- Timer behaviour
    timer_warning_seconds       SMALLINT UNSIGNED NULL,
        -- e.g. flash/beep when this many seconds remain; NULL = disabled

    -- Presentation
    display_theme               VARCHAR(50) NOT NULL DEFAULT 'default',

    -- Free-form, rarely-queried, future-proofing bucket (see rationale above)
    extra_settings               JSON NULL,

    created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_event_settings_event_id (event_id),

    CONSTRAINT fk_event_settings_event
        FOREIGN KEY (event_id) REFERENCES events (id)
        ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
