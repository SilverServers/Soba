-- Migration 043: Hidden and Private were the same state wearing two names.
--
-- ── WHY ONE STATE AND NOT TWO ───────────────────────────────────────────────
-- The status vocabulary was published · password · private · hidden · trash,
-- and to a visitor the last two were indistinguishable. `hidden` was excluded
-- by `findBySlug()`, so its URL 404'd. `private` was returned by `findBySlug()`
-- and then 404'd by the public controllers unless `Auth::check()` passed. Both
-- ended at the same 404 on the same URL for the same person.
--
-- The only difference was that a signed-in administrator could open a `private`
-- entry at its public address and could not open a `hidden` one — which is not
-- a distinction an owner can hold in their head, and not one the two labels
-- ("Hidden", "Private") announce. Two words for one idea is how a status select
-- teaches its user that the software is arbitrary.
--
-- So there is one not-public state and it is `private`: the address 404s for
-- anyone who is not signed in, and the owner can proof-read the real page
-- before publishing it. That is the more useful of the two behaviours, and it
-- is the one that survives.
--
-- ── ORDER MATTERS: REMAP FIRST, THEN NARROW ─────────────────────────────────
-- `hidden` is the DEFAULT the models have been writing since migration 029, so
-- most unpublished content in any existing install carries it. Narrowing the
-- ENUM first would leave those rows holding a value the column no longer
-- declares — MySQL coerces that to '' with a warning, and every one of them
-- would fall out of both the public site and the admin filters at once, with
-- nothing malformed for a check to notice.
--
-- The UPDATE therefore runs while `hidden` is still legal, and the ALTER runs
-- after there is nothing left to lose. `Database::runMigrations()` opens a
-- transaction around the file and re-opens one after the DDL's implicit commit,
-- so the failure that matters — the UPDATE failing — rolls back before any
-- column has been reshaped.

UPDATE posts SET status = 'private' WHERE status = 'hidden';
UPDATE pages SET status = 'private' WHERE status = 'hidden';

ALTER TABLE posts
    MODIFY COLUMN status ENUM('published','password','private','trash')
    NOT NULL DEFAULT 'private';

ALTER TABLE pages
    MODIFY COLUMN status ENUM('published','password','private','trash')
    NOT NULL DEFAULT 'private';
