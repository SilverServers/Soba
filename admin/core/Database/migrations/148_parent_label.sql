-- Migration 148: how a child type's editor names each entry it may belong to,
-- for a type made on the Content Types screen ("Each named as", under Belongs
-- to); a type registered in code says 'parent_label' in its definition. In
-- the token form of titles made from fields (`ContentType\Patterns`), over
-- the parent's fields: '{event_start_date:D, M j} · {event_location} ·
-- {title}'. NULL is the parent's title.
--
-- IF NOT EXISTS, as 145's to 147's: a site that made the column itself, with
-- this name, finds nothing to do here and keeps its values.
ALTER TABLE content_types
    ADD COLUMN IF NOT EXISTS parent_label VARCHAR(255) NULL DEFAULT NULL AFTER copy_rules;
