-- 005_create_questions_table.sql
--
-- Question content is an uploaded slide image (`image_path`), never stored
-- as HTML/text. `answer_image_path` is the (optional) reveal image shown when
-- the answer is displayed. `points` / `time_seconds` are NULLable: NULL means
-- "inherit the event default from scimath_event_settings".

CREATE TABLE IF NOT EXISTS scimath_questions (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id            INT UNSIGNED NOT NULL,
    category_id         INT UNSIGNED NULL,
    question_number     SMALLINT UNSIGNED NOT NULL,
    image_path          VARCHAR(255)    NOT NULL,
    answer_image_path   VARCHAR(255)    NULL,
    points              INT UNSIGNED    NULL,
    time_seconds        SMALLINT UNSIGNED NULL,
    is_active           BOOLEAN         NOT NULL DEFAULT 1,
    display_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_scimath_questions_event_number (event_id, question_number),
    KEY idx_scimath_questions_event_order (event_id, display_order),
    KEY idx_scimath_questions_category (category_id),

    CONSTRAINT fk_scimath_questions_event
        FOREIGN KEY (event_id) REFERENCES scimath_events (id)
        ON DELETE CASCADE,

    -- A category can be deleted without destroying the questions that
    -- referenced it; they just become uncategorized.
    CONSTRAINT fk_scimath_questions_category
        FOREIGN KEY (category_id) REFERENCES scimath_categories (id)
        ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
