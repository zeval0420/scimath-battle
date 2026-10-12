-- 002_create_event_settings_table.sql
--
-- 1:1 with events (one settings row per event). Typed columns for the few
-- well-known, always-read settings; `extra_settings` JSON is the escape hatch
-- for future, rarely-queried presentation-only knobs.

CREATE TABLE IF NOT EXISTS scimath_event_settings (
    id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id                    INT UNSIGNED NOT NULL,

    -- Scoring / question defaults (used when a Question doesn't override)
    default_points              INT UNSIGNED NOT NULL DEFAULT 10,
    default_time_seconds        SMALLINT UNSIGNED NOT NULL DEFAULT 60,

    -- Ranking behaviour
    ranking_order               ENUM('score_desc', 'score_asc') NOT NULL DEFAULT 'score_desc',
    tie_break_mode              ENUM('none') NOT NULL DEFAULT 'none',
    auto_show_ranking_after_score BOOLEAN NOT NULL DEFAULT 0,

    -- Timer behaviour
    timer_warning_seconds       SMALLINT UNSIGNED NULL,

    -- Presentation
    display_theme               VARCHAR(50) NOT NULL DEFAULT 'default',

    -- Audio / media assets
    timer_audio_path            VARCHAR(255) NULL,
    banner_audio_path           VARCHAR(255) NULL,
    game_audio_path             VARCHAR(255) NULL,
    banner_audio_volume         SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    game_audio_volume           SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    times_up_image_path         VARCHAR(255) NULL,

    -- Free-form, rarely-queried, future-proofing bucket
    extra_settings              JSON NULL,

    created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_scimath_event_settings_event_id (event_id),

    CONSTRAINT fk_scimath_event_settings_event
        FOREIGN KEY (event_id) REFERENCES scimath_events (id)
        ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
