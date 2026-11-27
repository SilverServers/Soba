-- Migration 145: the child types a Content Type's editor shows, each as a
-- panel of rows, for a type made on the Content Types screen ("On each
-- entry's editor"); a type registered in code says 'children' in its
-- definition (`ContentType\Children`).
--
-- children  JSON, by child type: {"session": {"columns": ["title", "speaker",
--           "photos"]}}, each column the child's title or one of its fields,
--           in the order a row shows them. NULL is none.
--
-- IF NOT EXISTS, as 090's, 130's and 131's: a site that made the column
-- itself, with this name, finds nothing to do here and keeps its values.
ALTER TABLE content_types
    ADD COLUMN IF NOT EXISTS children LONGTEXT NULL DEFAULT NULL AFTER default_status;
