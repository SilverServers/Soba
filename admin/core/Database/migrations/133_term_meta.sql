-- Migration 133: a term's own fields (`ContentType\TermFields`, `Term::meta()`),
-- kept as post_meta keeps an entry's: one row per field, its value as the
-- field keeps it on an entry, gone with its term.

CREATE TABLE IF NOT EXISTS term_meta (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    term_id    INT UNSIGNED NOT NULL,
    meta_key   VARCHAR(255) NOT NULL,
    meta_value LONGTEXT     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_term_meta (term_id, meta_key),
    CONSTRAINT fk_term_meta_term
        FOREIGN KEY (term_id) REFERENCES terms (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
