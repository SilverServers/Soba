-- Migration 129: a type made in the admin may leave its Public URL blank.
--
-- A blank Public URL means the type's entries have no page of their own
-- (`ContentType\Registry::hasPages()`): no address, archive or feed, as a
-- type registered in code with `'public_slug' => null` already has none. The
-- Content Types screen filled a blank one with the admin slug, so every row
-- holds one, and that is left as it is. NULL is the blank: the column stays
-- UNIQUE, and a UNIQUE column holds any number of NULLs.
ALTER TABLE content_types
    MODIFY COLUMN public_slug VARCHAR(100) NULL DEFAULT NULL;

UPDATE content_types
   SET public_slug = NULL
 WHERE public_slug = '';
