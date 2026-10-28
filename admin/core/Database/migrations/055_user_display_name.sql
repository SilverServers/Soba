-- Migration 055: a user has a name to show on what they write.
--
-- The byline printed the USERNAME — the handle someone signs in with, lowercase,
-- no spaces — on every public entry, in its structured data and on the home
-- feed. The two are kept apart for exactly this reason: "jsmith" is how you
-- sign in, "Jane Smith" is who wrote the article.
--
-- NULL means "use the username", so every existing byline reads as it did until
-- someone fills this in. `User::nameSql()` is the one place that rule lives;
-- empty is stored as NULL (User::cleanName()) so the rule is one COALESCE.

ALTER TABLE users
    ADD COLUMN display_name VARCHAR(100) NULL DEFAULT NULL AFTER username;
