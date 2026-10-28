-- Migration 056: how many terms an entry takes from a taxonomy.
--
-- Nothing could require one term, exactly one, or no more than N. A site that
-- files every article under exactly one category can have a breadcrumb,
-- related posts and Article schema that all assume it. The rule belongs to the
-- taxonomy: set on its screen, obeyed by every write path through
-- `Term::setPostTerms()`.
--
--   min_terms  0 = none needed, 1 = at least one
--   max_terms  NULL = no limit, N = no more than N ("exactly one" is 1 and 1)
--
-- The defaults are the old behaviour, so every existing taxonomy reads as it
-- did until someone sets a rule.

ALTER TABLE taxonomies
    ADD COLUMN min_terms TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_hierarchical,
    ADD COLUMN max_terms SMALLINT UNSIGNED NULL DEFAULT NULL AFTER min_terms;
