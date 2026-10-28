-- Migration 020: Create redirects table for SEO redirect manager
CREATE TABLE IF NOT EXISTS redirects (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_path   VARCHAR(500) NOT NULL,
    to_url      VARCHAR(500) NOT NULL,
    type        SMALLINT NOT NULL DEFAULT 301,
    hit_count   INT UNSIGNED NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_from_path (from_path(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
