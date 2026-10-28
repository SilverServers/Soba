CREATE TABLE IF NOT EXISTS media_versions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attachment_id INT UNSIGNED NOT NULL,
    filename      VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_size     INT UNSIGNED NOT NULL,
    mime_type     VARCHAR(100) NOT NULL,
    width         SMALLINT UNSIGNED NULL,
    height        SMALLINT UNSIGNED NULL,
    replaced_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attachment_id (attachment_id),
    CONSTRAINT fk_media_versions_attachment
        FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
