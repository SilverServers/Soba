-- Migration 053: an entry can carry an excerpt.
--
-- The `post` archetype has declared `excerpt` support since archetypes were
-- written (ArchetypeDefaults::defaultSupports), and nothing stored one: there
-- was no column, Post::create()/update() wrote none, and the entry form said
-- so in its own header — "an excerpt box would accept text, look saved, and
-- drop it on every submit ... When the column exists, add the control in the
-- same commit." This is the column; the control is in the same commit.
--
-- ── A REAL COLUMN, NOT A META KEY ───────────────────────────────────────────
-- It lives on the post row, beside the title and the body: it is content the
-- owner writes, which the listing card, the search-result description, a
-- revision and an export all read beside the title. Meta is for the fields a
-- Content Type declares; this is a field every post-shaped type has.
--
-- ── PLAIN TEXT, AND NO CAP ──────────────────────────────────────────────────
-- It is a sentence or two in a box, printed escaped. TEXT rather than a
-- VARCHAR limit because excerpts vary widely (309 imported ones ran from 40 to
-- 383 characters), and a cap chosen here would be a cut somebody discovers
-- after an import.
--
-- ── AND THE REVISION THAT HAS TO PUT IT BACK ────────────────────────────────
-- `revisions` snapshots the core columns explicitly. A column added to `posts`
-- and not here is a column a restore cannot restore — the `menu_order` fault
-- Revision::restore() already records, in advance.

ALTER TABLE posts
    ADD COLUMN excerpt TEXT NULL DEFAULT NULL AFTER content;

ALTER TABLE revisions
    ADD COLUMN excerpt TEXT NULL DEFAULT NULL AFTER content;
