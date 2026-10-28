-- Migration 012: Create post_term_relationships table
-- Many-to-many: posts <-> terms.
-- Both FKs cascade on delete — automatic cleanup when post or term is removed.
-- taxonomy_key is derived via JOIN to terms — not stored here.

CREATE TABLE IF NOT EXISTS post_term_relationships (
    post_id INT UNSIGNED NOT NULL,
    term_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (post_id, term_id),
    KEY idx_ptr_term (term_id),
    CONSTRAINT fk_ptr_post FOREIGN KEY (post_id)
        REFERENCES posts (id) ON DELETE CASCADE,
    CONSTRAINT fk_ptr_term FOREIGN KEY (term_id)
        REFERENCES terms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
