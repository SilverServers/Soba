-- Migration 131: the Status a new entry of a Content Type starts at on its
-- editor, for a type made on the Content Types screen ("New entries start
-- as"); a type registered in code says 'default_status' in its definition
-- (`Registry::defaultStatus()`). NULL, or anything but 'published', is
-- Private, as every new entry started before.
--
-- IF NOT EXISTS, as 090's and 130's: a site that made the column itself, with
-- this name, finds nothing to do here and keeps its values.
ALTER TABLE content_types
    ADD COLUMN IF NOT EXISTS default_status VARCHAR(20) NULL DEFAULT NULL AFTER list_sort;
