-- Migration 093: an account can be one of the site's people
-- (a site's own change, brought into core). The site lists its people
-- (people.php in its theme; Helpers\People) — its team, say — and a byline links to the person's page and photo. The key
-- is the person's key there; one person is one account, so the index is
-- unique; NULL is "not linked".
--
-- IF NOT EXISTS, both: a site may have made this column and key under these
-- names, and this must change nothing there.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS person_key VARCHAR(100) NULL DEFAULT NULL AFTER display_name,
    ADD UNIQUE KEY IF NOT EXISTS uq_users_person_key (person_key);
