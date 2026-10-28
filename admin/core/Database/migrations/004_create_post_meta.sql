-- Migration 004: Post meta table
-- Stores all custom field values for Content Types.
-- Scalar types stored directly in meta_value.
-- Complex types (repeater, relationship) stored as JSON.
-- Type information lives entirely in the Content Type registry (code), not here.

CREATE TABLE IF NOT EXISTS post_meta (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_id    INT UNSIGNED NOT NULL,
    meta_key   VARCHAR(255) NOT NULL,
    meta_value LONGTEXT     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_post_meta (post_id, meta_key),
    KEY idx_post_meta_post_id (post_id),
    CONSTRAINT fk_post_meta_post
        FOREIGN KEY (post_id) REFERENCES posts (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
