-- 007_create_score_adjustments_table.sql
--
-- Design decision: `score_entries` holds the CURRENT value (fast to sum for
-- rankings); this table is an append-only audit log of every create/correct
-- action taken against a score_entries row. Rows here are never updated or
-- deleted by the application.
--
-- This satisfies the spec's requirement for "correcting scores / auditing /
-- displaying score history / recalculating totals" without turning the hot
-- path (ranking calculation) into a full event-sourced replay on every read.

CREATE TABLE IF NOT EXISTS score_adjustments (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    score_entry_id      INT UNSIGNED NOT NULL,
    previous_result      ENUM('correct', 'incorrect', 'no_answer', 'adjustment') NULL,
        -- NULL on the very first record (initial score, nothing "previous")
    previous_points      INT             NULL,
    new_result           ENUM('correct', 'incorrect', 'no_answer', 'adjustment') NOT NULL,
    new_points           INT             NOT NULL,
    reason               VARCHAR(255)    NULL,
    changed_by           VARCHAR(100)    NULL,
    changed_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_score_adjustments_entry (score_entry_id),

    CONSTRAINT fk_score_adjustments_entry
        FOREIGN KEY (score_entry_id) REFERENCES score_entries (id)
        ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
