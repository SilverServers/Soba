-- Migration 111: the addresses visitors asked for that were not found.
--
-- One row an address, counted: how often it was asked for, when first and
-- last, and the page that linked to it the last time, when the browser said
-- (`SEO\NotFound`). Tools → Redirects lists them with Add redirect beside
-- each. No visitor's address or browser is kept, only the address asked for.
-- The newest thousand are kept; an address a redirect answers is dropped.
CREATE TABLE IF NOT EXISTS not_found_log (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    path       VARCHAR(500) NOT NULL,
    hits       INT UNSIGNED NOT NULL DEFAULT 1,
    referrer   VARCHAR(500) NULL DEFAULT NULL,
    first_seen DATETIME     NOT NULL,
    last_seen  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_not_found_path (path),
    KEY idx_not_found_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
