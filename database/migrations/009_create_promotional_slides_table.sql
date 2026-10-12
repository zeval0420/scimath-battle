-- 009_create_promotional_slides_table.sql
--
-- Event-scoped slides (images/documents) the operator can push to the public
-- display between questions/rounds.

CREATE TABLE IF NOT EXISTS scimath_promotional_slides (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id        INT UNSIGNED NOT NULL,
    title           VARCHAR(150)    NOT NULL,
    description     TEXT            NULL,
    file_path       VARCHAR(255)    NULL,
    file_type       VARCHAR(50)     NULL,
    display_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active       BOOLEAN         NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_scimath_promotional_slides_event_order (event_id, display_order),

    CONSTRAINT fk_scimath_promotional_slides_event
        FOREIGN KEY (event_id) REFERENCES scimath_events (id)
        ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
