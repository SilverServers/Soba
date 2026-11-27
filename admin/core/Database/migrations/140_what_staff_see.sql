-- Migration 140: what staff see on a type's screens, for a type and a taxonomy
-- made on screen; one registered in code says so in its definition
-- (`ContentType\StaffView`).
--
--   content_types.panels     JSON: the least role that sees each panel of an
--                            entry's editor, {"author": "admin", "seo": "admin"},
--                            or false for no one. A panel not named is as it was.
--   content_types.list_view  JSON: the same for the parts of its list, {"address":
--                            "admin", "author_filter": "admin", "bulk": "admin"},
--                            and "group_by", a Choice field or a taxonomy whose
--                            values head the list's groups.
--   taxonomies.add_terms     The least role that may add a term, on the entry's
--                            editor, through the API and on the terms screen.
--                            NULL is as it was: an author on a tag-style
--                            taxonomy, an editor on a nested one.
--
-- IF NOT EXISTS, as 090's, 130's and 131's: a site that made a column itself,
-- with one of these names, finds nothing to do here and keeps its values.
ALTER TABLE content_types
    ADD COLUMN IF NOT EXISTS panels LONGTEXT NULL DEFAULT NULL AFTER default_status,
    ADD COLUMN IF NOT EXISTS list_view LONGTEXT NULL DEFAULT NULL AFTER panels;

ALTER TABLE taxonomies
    ADD COLUMN IF NOT EXISTS add_terms VARCHAR(20) NULL DEFAULT NULL;
