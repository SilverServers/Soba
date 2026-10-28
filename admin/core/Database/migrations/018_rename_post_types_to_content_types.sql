-- Migration 018: Rename post_types table → content_types
-- and rename the post_types JSON columns in field_groups and taxonomies tables.
--
-- The PHP class and all terminology was already updated to "Content Types" in the
-- previous refactor. This migration brings the DB schema terminology into alignment.
--
-- Note: posts.post_type column is intentionally NOT renamed — it is an internal
-- implementation detail (the single-table inheritance column) and
-- not a user-facing or developer-facing surface.

RENAME TABLE post_types TO content_types;

ALTER TABLE field_groups
    CHANGE COLUMN post_types content_types JSON NOT NULL;

ALTER TABLE taxonomies
    CHANGE COLUMN post_types content_types JSON NOT NULL;
