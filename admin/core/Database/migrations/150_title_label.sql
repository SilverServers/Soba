-- Migration 150: a Content Type's own word for its entries' title, such as
-- Name or Business name, for a type made on the Content Types screen ("Title
-- label"); a type registered in code says 'title_label' in its definition
-- (`Registry::titleLabel()`). NULL, or blank, is the word every type had
-- before: "{Singular} title" over the editor's title box, "Title" over its list.
--
-- IF NOT EXISTS, as 090's, 130's and 131's: a site that made the column
-- itself, with this name, finds nothing to do here and keeps its values.
ALTER TABLE content_types
    ADD COLUMN IF NOT EXISTS title_label VARCHAR(100) NULL DEFAULT NULL AFTER default_status;
