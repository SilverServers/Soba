-- Migration 010: Create taxonomies table
-- Stores UI-created taxonomy definitions.
-- Code-registered taxonomies are loaded from config/taxonomies.php at bootstrap
-- and merged into TaxonomyRegistry (identical pattern to Content Types / content_types table).
-- Note: the post_types column is renamed to content_types in migration 018.

CREATE TABLE IF NOT EXISTS taxonomies (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    taxonomy_key    VARCHAR(50)  NOT NULL,
    plural_label    VARCHAR(100) NOT NULL,
    singular_label  VARCHAR(100) NOT NULL,
    admin_slug      VARCHAR(100) NOT NULL,
    post_types      JSON         NOT NULL,
    is_hierarchical TINYINT(1)   NOT NULL DEFAULT 0,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tax_key  (taxonomy_key),
    UNIQUE KEY uq_tax_slug (admin_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
