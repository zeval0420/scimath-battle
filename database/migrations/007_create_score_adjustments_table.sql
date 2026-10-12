-- 007_create_score_adjustments_table.sql
--
-- Append-only audit log of every create/correct action against a
-- scimath_score_entries row. Rows here are never updated or deleted by the
-- application.

CREATE TABLE IF NOT EXISTS scimath_score_adjustments (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    score_entry_id      INT UNSIGNED NOT NULL,
    previous_result     ENUM('correct', 'incorrect', 'no_answer', 'adjustment') NULL,
    previous_points     INT             NULL,
    new_result          ENUM('correct', 'incorrect', 'no_answer', 'adjustment') NOT NULL,
    new_points          INT             NOT NULL,
    reason              VARCHAR(255)    NULL,
    changed_by          VARCHAR(100)    NULL,
    changed_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_scimath_score_adjustments_entry (score_entry_id),

    CONSTRAINT fk_scimath_score_adjustments_entry
        FOREIGN KEY (score_entry_id) REFERENCES scimath_score_entries (id)
        ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
