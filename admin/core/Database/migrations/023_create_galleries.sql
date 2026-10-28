-- Migration 023: Create galleries and gallery_items tables

CREATE TABLE IF NOT EXISTS galleries (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(200)  NOT NULL,
    description TEXT          DEFAULT NULL,
    settings    JSON          DEFAULT NULL,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gallery_items (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gallery_id    INT UNSIGNED NOT NULL,
    attachment_id INT UNSIGNED NOT NULL,
    position      INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_gallery_id (gallery_id),
    CONSTRAINT fk_gallery_items_gallery    FOREIGN KEY (gallery_id)    REFERENCES galleries (id)   ON DELETE CASCADE,
    CONSTRAINT fk_gallery_items_attachment FOREIGN KEY (attachment_id) REFERENCES attachments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
