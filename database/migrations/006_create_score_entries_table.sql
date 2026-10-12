-- 006_create_score_entries_table.sql
--
-- One row per (question, contestant): the current, authoritative result and
-- points. Totals/rankings are always derived by summing this table -- never
-- stored. `points_awarded` is a snapshot taken at scoring time. Corrections
-- update the row in place, mirrored into scimath_score_adjustments.

CREATE TABLE IF NOT EXISTS scimath_score_entries (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id            INT UNSIGNED NOT NULL,
    question_id         INT UNSIGNED NOT NULL,
    contestant_id       INT UNSIGNED NOT NULL,
    result              ENUM('correct', 'incorrect', 'no_answer', 'adjustment')
                                         NOT NULL,
    points_awarded      INT             NOT NULL,
    scored_by           VARCHAR(100)    NULL,
    scored_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- One current result per team per question. Re-scoring is an UPDATE (a
    -- correction), not a new row -- history lives in score_adjustments.
    UNIQUE KEY uq_scimath_score_entries_question_contestant (question_id, contestant_id),

    KEY idx_scimath_score_entries_event (event_id),
    KEY idx_scimath_score_entries_contestant (contestant_id),

    CONSTRAINT fk_scimath_score_entries_event
        FOREIGN KEY (event_id) REFERENCES scimath_events (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_scimath_score_entries_question
        FOREIGN KEY (question_id) REFERENCES scimath_questions (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_scimath_score_entries_contestant
        FOREIGN KEY (contestant_id) REFERENCES scimath_contestants (id)
        ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
