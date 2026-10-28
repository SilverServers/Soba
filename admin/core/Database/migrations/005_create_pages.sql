-- Migration 005: Pages table
-- Hierarchical static pages (About, Contact, Services, etc.).
-- Separate from the posts/CPT table — different editing paradigm, no custom fields.

CREATE TABLE IF NOT EXISTS pages (
    id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    parent_id  INT UNSIGNED  NULL DEFAULT NULL,
    title      VARCHAR(500)  NOT NULL,
    slug       VARCHAR(500)  NOT NULL,
    content    LONGTEXT,
    menu_order INT UNSIGNED  NOT NULL DEFAULT 0,
    status     ENUM('draft','published') NOT NULL DEFAULT 'draft',
    author_id  INT UNSIGNED  NOT NULL,
    created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pages_slug (slug),
    KEY idx_pages_parent (parent_id),
    KEY idx_pages_status (status),
    CONSTRAINT fk_pages_parent FOREIGN KEY (parent_id) REFERENCES pages (id) ON DELETE SET NULL,
    CONSTRAINT fk_pages_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
