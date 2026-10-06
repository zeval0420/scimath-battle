-- 004_create_contestants_table.sql
--
-- MVP models a contestant as a "team" (which may of course be a single
-- individual in some events -- the system doesn't care). `starting_score`
-- lets an event begin with carry-over points (e.g. bonus points from a
-- prior round) without needing a fake "round 0" question.
--
-- Future expansion note (documented per instructions, not built now):
-- individual-member rosters would be a separate `contestant_members` table
-- referencing `contestants.id`, added later without touching this table.

CREATE TABLE IF NOT EXISTS contestants (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id        INT UNSIGNED NOT NULL,
    name            VARCHAR(150)    NOT NULL,
    team_code       VARCHAR(20)     NULL,
    organization    VARCHAR(150)    NULL,
    logo_path       VARCHAR(255)    NULL,
    starting_score  INT             NOT NULL DEFAULT 0,
    display_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active       BOOLEAN         NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_contestants_event_code (event_id, team_code),
    KEY idx_contestants_event_order (event_id, display_order),

    CONSTRAINT fk_contestants_event
        FOREIGN KEY (event_id) REFERENCES events (id)
        ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
