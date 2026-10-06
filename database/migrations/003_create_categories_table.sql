-- 003_create_categories_table.sql
-- Categories are pure display labels attached to an event. Application code
-- must never branch on a category name/id; categories exist only so the
-- admin can group and label questions ("Mathematics", "Physics", ...).

CREATE TABLE IF NOT EXISTS categories (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id        INT UNSIGNED NOT NULL,
    name            VARCHAR(100)    NOT NULL,
    description     VARCHAR(255)    NULL,
    display_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_event_name (event_id, name),
    KEY idx_categories_event_order (event_id, display_order),

    CONSTRAINT fk_categories_event
        FOREIGN KEY (event_id) REFERENCES events (id)
        ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
