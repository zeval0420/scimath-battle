-- 006_create_score_entries_table.sql
--
-- One row per (question, contestant): the current, authoritative result and
-- points for that team on that question. Totals/rankings are ALWAYS derived
-- by summing this table -- never stored -- so the operator view and the
-- public display can never disagree.
--
-- `points_awarded` is a SNAPSHOT taken at scoring time (copied from
-- questions.points / event_settings.default_points as resolved then). If an
-- admin edits a question's points value later, already-scored entries are
-- NOT retroactively changed -- this protects historical accuracy and keeps
-- "what actually happened during the event" intact.
--
-- Corrections update this row in place (it's the live/current value), but
-- every change is mirrored into `score_adjustments` (migration 007) so nothing
-- is silently overwritten -- see that file for the audit-trail rationale.

CREATE TABLE IF NOT EXISTS score_entries (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id            INT UNSIGNED NOT NULL,
    question_id         INT UNSIGNED NOT NULL,
    contestant_id       INT UNSIGNED NOT NULL,
    result              ENUM('correct', 'incorrect', 'no_answer', 'adjustment')
                                         NOT NULL,
    points_awarded      INT             NOT NULL,
    scored_by           VARCHAR(100)    NULL,
        -- Free-text operator identifier/name; the system spec doesn't
        -- require a full user-account system for operators, so this stays
        -- simple rather than forcing a users table dependency into scoring.
    scored_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- One current result per team per question. Re-scoring the same
    -- question/contestant pair is an UPDATE (a correction), not a new row --
    -- history of that correction lives in score_adjustments.
    UNIQUE KEY uq_score_entries_question_contestant (question_id, contestant_id),

    KEY idx_score_entries_event (event_id),
    KEY idx_score_entries_contestant (contestant_id),

    CONSTRAINT fk_score_entries_event
        FOREIGN KEY (event_id) REFERENCES events (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_score_entries_question
        FOREIGN KEY (question_id) REFERENCES questions (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_score_entries_contestant
        FOREIGN KEY (contestant_id) REFERENCES contestants (id)
        ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
