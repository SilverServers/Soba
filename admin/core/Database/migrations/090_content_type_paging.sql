-- Migration 090: how many entries a page of a Content Type's archive
-- shows, and how many more its page 1 carries (a site's own change,
-- brought into core).
--
-- per_page          NULL follows the site's own number (Settings, General,
--                   Reading: the option posts_per_page). 0 lists every entry
--                   on one page. 1 to 100 is that many to a page.
-- first_page_extra  this many more on page 1 of the type's own archive (never
--                   its term archives), for a design that leads page 1 with a
--                   featured entry. Page 2 carries on after them. 0 is every
--                   page the same size.
--
-- IF NOT EXISTS, BOTH. A site may have made these two columns itself, with
-- these names and types, and its update must find nothing to do here and keep
-- its values. MariaDB 10.11 on both installs, as 066 says.
ALTER TABLE content_types
    ADD COLUMN IF NOT EXISTS per_page         SMALLINT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS first_page_extra SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER per_page;
