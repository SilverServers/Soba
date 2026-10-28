-- Migration 049: a menu can appear in more than one place.
--
-- ── THE COLUMN THAT MADE THIS A ONE-TO-ONE ──────────────────────────────────
-- `menus.location_slug` was a single `VARCHAR(100)` carrying a UNIQUE index, so
-- the schema enforced BOTH halves of a one-to-one: one menu per location, and
-- one location per menu. Only the first half is a real rule.
--
-- A location shows exactly one menu — that is what "the primary navigation" MEANS,
-- and two menus in one slot is not a state a renderer can resolve. But a menu in
-- exactly one location is an invented constraint. The commonest thing a site owner
-- does with a small site's navigation is put the SAME menu in the header and the
-- footer, and this schema made that impossible: assigning it to the footer took it
-- out of the header, silently, because `assignLocation()` cleared the other row
-- first. The owner's header navigation disappeared and nothing said why.
--
-- So the pair moves to its own table, where the two halves can be stated
-- separately: `location_slug` is UNIQUE (one menu per location) and `menu_id` is
-- not (a menu in as many locations as it likes).
--
-- ── WHY A TABLE AND NOT A JSON COLUMN ───────────────────────────────────────
-- "Which menu is in the primary location" is the query the public renderer runs on
-- every single page load, for every location the theme asks for. Against a JSON
-- column that is a scan of every menu with a decode per row; against this it is a
-- unique-index lookup. And the rule is not to invent new STORAGE LAYERS — a join
-- table is the mechanism this schema already uses for exactly this shape
-- (`post_term_relationships`), not a new one.
--
-- ── THE COLUMN IS DROPPED, NOT LEFT BEHIND ──────────────────────────────────
-- Two places that answer "where does this menu appear?" WILL disagree, and the
-- screen that reads the stale one tells its owner something false. Every reader
-- uses the join table, so the column goes.

CREATE TABLE IF NOT EXISTS menu_locations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    location_slug VARCHAR(100) NOT NULL,
    menu_id       INT UNSIGNED NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- ONE MENU PER LOCATION. The half of the old constraint that was real.
    UNIQUE INDEX idx_menu_location (location_slug),
    -- NOT UNIQUE. The half that was not: this is what lets one menu be the
    -- header AND the footer.
    INDEX idx_menu_locations_menu (menu_id),
    CONSTRAINT fk_menu_locations_menu FOREIGN KEY (menu_id)
        REFERENCES menus (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CARRY WHAT IS THERE ACROSS. A migration that creates an empty table and leaves
-- the old data where it was is a migration that unassigns every menu on every
-- install that has one — the site's navigation vanishing at deploy time, with the
-- rows still sitting in a column nothing reads any more.
INSERT INTO menu_locations (location_slug, menu_id)
    SELECT location_slug, id FROM menus
     WHERE location_slug IS NOT NULL AND location_slug <> '';

ALTER TABLE menus DROP INDEX idx_location_slug;
ALTER TABLE menus DROP COLUMN location_slug;

-- ── AND THE SETTING THAT ANSWERS "WHY IS MY NEW PAGE NOT IN THE MENU?" ──────
-- "Auto add pages" is the single most useful thing on the menu screen for the
-- audience this CMS is for — a site owner who publishes a page and cannot work
-- out why nothing links to it.
--
-- PER MENU, not a global option, because the answer differs per menu: a header
-- navigation of five links wants nothing added automatically, and a footer sitemap
-- wants everything. One switch for both would be wrong for one of them.
--
-- DEFAULT 0. A menu that silently grows is a menu whose owner did not arrange it,
-- and this admin's standing rule is that a control does what it says and nothing
-- more. Opting in is a choice; opting out is a surprise.
ALTER TABLE menus
    ADD COLUMN auto_add_pages TINYINT(1) NOT NULL DEFAULT 0 AFTER name;
