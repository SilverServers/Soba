CREATE TABLE IF NOT EXISTS media_folders (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    parent_id  INT UNSIGNED NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_parent_id (parent_id),
    CONSTRAINT fk_media_folders_parent
        FOREIGN KEY (parent_id) REFERENCES media_folders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
