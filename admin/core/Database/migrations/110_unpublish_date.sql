-- Migration 110: a page or an entry can be taken off the site on a date.
--
-- `unpublish_at` is when a published or password-protected page or entry
-- stops being shown, in UTC as `published_at` is; NULL keeps it on the site.
-- Nothing runs at that moment: from it on, the page answers a visitor as a
-- private one does and leaves every list (`Post::live()`, `Page::live()`),
-- the way a publish date still to come holds an entry back. The admin shows
-- it as Ended. A revision keeps the date with the rest of the row, so a
-- restore puts it back too.
--
-- IF NOT EXISTS, as 107 is: a database that has the column already, with this
-- name and type, is left as it is.
ALTER TABLE posts
    ADD COLUMN IF NOT EXISTS unpublish_at DATETIME NULL DEFAULT NULL AFTER published_at;

ALTER TABLE pages
    ADD COLUMN IF NOT EXISTS unpublish_at DATETIME NULL DEFAULT NULL AFTER status;

ALTER TABLE revisions
    ADD COLUMN IF NOT EXISTS unpublish_at DATETIME NULL DEFAULT NULL AFTER published_at;
