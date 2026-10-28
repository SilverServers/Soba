CREATE TABLE IF NOT EXISTS attachments (
    id            INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    filename      VARCHAR(255)     NOT NULL,
    original_name VARCHAR(255)     NOT NULL,
    mime_type     VARCHAR(100)     NOT NULL,
    file_size     INT UNSIGNED     NOT NULL,
    width         SMALLINT UNSIGNED NULL,
    height        SMALLINT UNSIGNED NULL,
    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
