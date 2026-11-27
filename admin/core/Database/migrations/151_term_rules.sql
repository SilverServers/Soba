-- Migration 151: how many terms of each taxonomy a Content Type's entries
-- take, for a type made on the Content Types screen, in place of the
-- taxonomy's own rule (migration 056) for that type alone: JSON by taxonomy,
-- {"location": {"min": 1, "max": 1}}. A type registered in code says 'terms'
-- in its definition (`Term::countRule()`). NULL is every taxonomy's own rule,
-- as before.
--
-- IF NOT EXISTS, as 130's and 150's: a site that made the column itself, with
-- this name, finds nothing to do here and keeps its values.
ALTER TABLE content_types
    ADD COLUMN IF NOT EXISTS term_rules LONGTEXT NULL DEFAULT NULL AFTER title_label;
