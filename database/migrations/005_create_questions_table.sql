-- 005_create_questions_table.sql
--
-- Question content is an uploaded slide image (`image_path`), never stored
-- as HTML/text -- this preserves math notation, diagrams, chemical formulas,
-- etc. exactly as authored.
--
-- `points` / `time_seconds` are NULLable: NULL means "inherit the event's
-- default_points / default_time_seconds from event_settings". This is what
-- lets an admin set a blanket default and override only a few questions,
-- matching the spec's example (Q1/Q2 = default 60s, Q3 = 90s, Q4 = 120s).

CREATE TABLE IF NOT EXISTS questions (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id            INT UNSIGNED NOT NULL,
    category_id         INT UNSIGNED NULL,
    question_number     SMALLINT UNSIGNED NOT NULL,
    image_path          VARCHAR(255)    NOT NULL,
    points              INT UNSIGNED    NULL,
    time_seconds        SMALLINT UNSIGNED NULL,
    is_active           BOOLEAN         NOT NULL DEFAULT 1,
    display_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_questions_event_number (event_id, question_number),
    KEY idx_questions_event_order (event_id, display_order),
    KEY idx_questions_category (category_id),

    CONSTRAINT fk_questions_event
        FOREIGN KEY (event_id) REFERENCES events (id)
        ON DELETE CASCADE,

    -- A category can be deleted (e.g. admin reorganizing) without destroying
    -- the questions that referenced it; they just become uncategorized.
    CONSTRAINT fk_questions_category
        FOREIGN KEY (category_id) REFERENCES categories (id)
        ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
