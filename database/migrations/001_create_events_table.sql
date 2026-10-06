-- 001_create_events_table.sql
-- The root entity. Every competition run through the system is an "event".
-- Nothing about a specific event (Sci-Math Battle, Science Quiz Bee, etc.) is
-- hard-coded; it is all rows in this table and the tables that reference it.

CREATE TABLE IF NOT EXISTS events (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                VARCHAR(150)    NOT NULL,
    subtitle            VARCHAR(255)    NULL,
    logo_path           VARCHAR(255)    NULL,
    cover_image_path    VARCHAR(255)    NULL,
    event_date          DATE            NULL,
    status              ENUM('draft', 'ready', 'active', 'completed', 'archived')
                                         NOT NULL DEFAULT 'draft',
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_events_status (status)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
