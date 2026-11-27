-- Migration 130: what a Content Type's entry list shows beside each entry's
-- title, and how it is ordered when nobody has chosen, for a type made on the
-- Content Types screen (`ContentType\ListColumns`). A type registered in code
-- says the same in its definition: 'list_columns' and 'default_sort'.
--
-- list_columns  JSON, a list in the order shown: a field's key, `tax:{key}`
--               for a taxonomy's terms, or `children:{type}` for how many
--               entries of a child type each has. NULL is the type's own
--               default (an event type's start); [] is no column.
-- list_sort     JSON, {"field": "title" | "date" | a field's key,
--               "order": "asc" | "desc"}. NULL is newest first.
--
-- IF NOT EXISTS, BOTH, as 090's: a site that made these columns itself, with
-- these names, finds nothing to do here and keeps its values.
ALTER TABLE content_types
    ADD COLUMN IF NOT EXISTS list_columns LONGTEXT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS list_sort    VARCHAR(255) NULL DEFAULT NULL AFTER list_columns;
