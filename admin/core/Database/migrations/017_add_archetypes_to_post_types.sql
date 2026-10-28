-- Migration 017: Add archetypes column to post_types table
-- Stores the content archetype(s) for each Content Type as a JSON array.
-- Valid values: "post", "event", "listing", "product"
-- Default: ["post"]

ALTER TABLE post_types
    ADD COLUMN archetypes JSON NOT NULL DEFAULT (JSON_ARRAY('post'))
    AFTER menu_icon;
