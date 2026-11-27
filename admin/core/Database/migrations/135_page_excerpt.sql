-- Migration 135: a page's summary, plain text, as an entry's excerpt is
-- (migration 053): for a theme to print under the page's title, and for
-- the page's search description when its own is empty.

ALTER TABLE pages ADD COLUMN excerpt TEXT NULL AFTER content;
