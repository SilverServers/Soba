-- Migration 058: the Site title is the site's own.
--
-- An unset Site title falls back to the site's own host name
-- (Head::globalTokens()). There is nothing left for this to change: it stays
-- so that a site which has applied it still matches this release's list of
-- migrations.

DO 0;
