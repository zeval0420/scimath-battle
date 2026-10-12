-- 004_create_contestants_table.sql
--
-- A contestant is a "team" (which may be a single individual). `starting_score`
-- allows an event to begin with carry-over points. `acronym` is the short
-- display label used by the scorer/scoreboard ("Acro." column).

CREATE TABLE IF NOT EXISTS scimath_contestants (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id        INT UNSIGNED NOT NULL,
    name            VARCHAR(150)    NOT NULL,
    team_code       VARCHAR(20)     NULL,
    acronym         VARCHAR(20)     NULL,
    organization    VARCHAR(150)    NULL,
    logo_path       VARCHAR(255)    NULL,
    starting_score  INT             NOT NULL DEFAULT 0,
    display_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active       BOOLEAN         NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_scimath_contestants_event_code (event_id, team_code),
    KEY idx_scimath_contestants_event_order (event_id, display_order),

    CONSTRAINT fk_scimath_contestants_event
        FOREIGN KEY (event_id) REFERENCES scimath_events (id)
        ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
