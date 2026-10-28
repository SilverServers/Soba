-- Migration 011: Create terms table
-- Stores individual terms within taxonomies.
-- taxonomy_key is a string reference (not int FK) — consistent with posts.post_type.
-- parent_id is self-referencing for hierarchical taxonomies (categories).
-- ON DELETE SET NULL promotes children to root level if parent is deleted.

CREATE TABLE IF NOT EXISTS terms (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    taxonomy_key VARCHAR(50)  NOT NULL,
    name         VARCHAR(200) NOT NULL,
    slug         VARCHAR(200) NOT NULL,
    description  TEXT,
    parent_id    INT UNSIGNED NULL DEFAULT NULL,
    sort_order   INT UNSIGNED NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_term_slug (taxonomy_key, slug),
    KEY idx_term_tax    (taxonomy_key),
    KEY idx_term_parent (parent_id),
    CONSTRAINT fk_term_parent FOREIGN KEY (parent_id)
        REFERENCES terms (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
