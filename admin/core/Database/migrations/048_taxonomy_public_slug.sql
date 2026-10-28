-- Migration 048: a taxonomy gets a public address.
--
-- ── A TERM WITH NO PAGE IS A FILING SYSTEM NOBODY CAN USE ───────────────────
-- Taxonomies could be created, attached to Content Types, given terms, and
-- assigned to entries through the chip picker — and there was no public route
-- anywhere that listed the entries in a term. `grep addRoute` over the whole
-- router found ten taxonomy routes and every one of them under `/admin` or
-- `/api`.
--
-- So the classification existed only inside the admin. That is the "media
-- folders unused" fault at a larger scale: the surface is built, the demo
-- exercises it, and nobody finds out that the one thing a visitor would do with
-- it — "show me everything tagged Campus" — was never possible.
--
-- It also blocked something smaller: `MenuItem::resolveUrl()` has had a
-- `taxonomy` arm since menus were written, and it returns `#` because there was
-- no address for it to return.
--
-- ── WHY A COLUMN AND NOT THE ADMIN SLUG ─────────────────────────────────────
-- `admin_slug` is what sits in `/admin/taxonomies/{slug}/terms`. Reusing it
-- publicly would mean an owner cannot rename the public address without moving
-- the admin screen out from under every bookmark and every link in this
-- codebase — and the two are not the same decision. Content Types already carry
-- both for exactly this reason; taxonomies carried only the admin one.
--
-- NULLABLE, AND NULL MEANS NO PUBLIC ARCHIVE. A taxonomy used purely to
-- organise the admin — an internal status, a workflow flag — should not
-- suddenly gain public pages because a column was added underneath it. The
-- routes are only registered for a taxonomy that has one.

ALTER TABLE taxonomies
    ADD COLUMN public_slug VARCHAR(100) NULL DEFAULT NULL AFTER admin_slug;

-- EVERY EXISTING TAXONOMY GETS ONE, derived from the admin slug, because the
-- alternative is an upgrade that silently leaves every install's taxonomies
-- unreachable and no screen saying why. An owner who does not want the archive
-- clears the field; that is a decision they can see and make.
UPDATE taxonomies SET public_slug = admin_slug WHERE public_slug IS NULL;

-- NOT UNIQUE AGAINST `content_types.public_slug` — a constraint cannot span two
-- tables, and the collision that matters is with a CONTENT TYPE's slug, because
-- `/{type}/{entry}` and `/{taxonomy}/{term}` are the same two-segment shape.
-- `TaxonomyController` refuses it at the form, where the message can say which
-- type is already using the address.
CREATE UNIQUE INDEX idx_taxonomies_public_slug ON taxonomies (public_slug);
