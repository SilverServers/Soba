-- Migration 002: the first account
-- Username: admin. No password: `php admin/bin/migrate.php run` gives it its
-- first one, printed once, and its owner chooses their own name and password
-- at the first sign-in.
--
-- '!first-sign-in' IS NOT A HASH. password_verify() refuses it, so nothing
-- signs this account in, from the first page view on, until migrate.php
-- replaces it (`Models\User::NO_PASSWORD`). An install made before this
-- release was seeded with a starting password instead; migration 061 asks for
-- a new one in its place.

INSERT IGNORE INTO users (username, email, password_hash, role)
VALUES (
    'admin',
    'admin@example.com',
    '!first-sign-in',
    'admin'
);
