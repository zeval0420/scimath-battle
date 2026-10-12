-- 010_create_answer_slides_table.sql
--
-- Answer-reveal images, optionally tied to a specific question (NULL =
-- general/event-level answer slide).

CREATE TABLE IF NOT EXISTS scimath_answer_slides (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id        INT UNSIGNED NOT NULL,
    question_id     INT UNSIGNED NULL,
    image_path      VARCHAR(255)    NULL,
    display_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active       BOOLEAN         NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_scimath_answer_slides_event_order (event_id, display_order),
    KEY idx_scimath_answer_slides_question (question_id),

    CONSTRAINT fk_scimath_answer_slides_event
        FOREIGN KEY (event_id) REFERENCES scimath_events (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_scimath_answer_slides_question
        FOREIGN KEY (question_id) REFERENCES scimath_questions (id)
        ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
