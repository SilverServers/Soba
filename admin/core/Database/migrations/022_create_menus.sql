-- Migration 022: Create menus and menu_items tables
-- Full mega menu schema from day one

CREATE TABLE IF NOT EXISTS menus (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(200) NOT NULL,
    location_slug VARCHAR(100) DEFAULT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_location_slug (location_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_items (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_id          INT UNSIGNED NOT NULL,
    parent_id        INT UNSIGNED DEFAULT NULL,
    position         INT UNSIGNED NOT NULL DEFAULT 0,
    type             VARCHAR(50)  NOT NULL DEFAULT 'custom',
    target_id        INT UNSIGNED DEFAULT NULL,
    url              VARCHAR(500) DEFAULT NULL,
    label            VARCHAR(200) NOT NULL,
    css_class        VARCHAR(200) DEFAULT NULL,
    target_blank     TINYINT(1)   NOT NULL DEFAULT 0,
    sub_menu_type    VARCHAR(50)  DEFAULT 'standard',
    mega_columns     JSON         DEFAULT NULL,
    visibility_rules JSON         DEFAULT NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_menu_id  (menu_id),
    INDEX idx_parent   (parent_id),
    CONSTRAINT fk_menu_items_menu   FOREIGN KEY (menu_id)   REFERENCES menus (id) ON DELETE CASCADE,
    CONSTRAINT fk_menu_items_parent FOREIGN KEY (parent_id) REFERENCES menu_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
