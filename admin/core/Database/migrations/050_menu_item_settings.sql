-- Migration 050: what a menu item and a menu location can be told.
--
-- The menu screen takes these settings in exactly two places, and both need
-- somewhere to put their answers:
--
--   1. A "Mega Menu" button on every item row, opening a tabbed dialog.
--   2. A settings card per location the menu occupies.
--
-- ── ONE JSON COLUMN, NOT FOURTEEN REAL ONES ─────────────────────────────────
-- The item dialog carries, across its tabs: hide_text, hide_arrow, disable_link,
-- hide_on_mobile, hide_on_desktop, close_after_click, item_align, icon_position,
-- align, hide_sub_menu_on_mobile, collapse_children, icon, badge, image swap,
-- roles, replacements. Every one is a small, optional, per-item display
-- preference that only the renderer reads.
--
-- Fourteen `TINYINT` columns would be fourteen migrations as the dialog grows,
-- fourteen defaults to keep in step across the model, the template and the JS,
-- and a table where most rows are the default in every one of them.
--
-- The rule that decides it: *fewer storage layers, not more operations* — when
-- something cannot be reached, the question is which EXISTING mechanism holds
-- it. This schema already stores shaped, optional, read-by-one-consumer data
-- as JSON, in these exact tables: `menu_items.mega_columns` and
-- `menu_items.visibility_rules` are both JSON, both from migration 022.
--
-- ── WHY THE THREE EXISTING COLUMNS ARE NOT FOLDED IN ────────────────────────
-- `sub_menu_type`, `mega_columns` and `visibility_rules` stay exactly where they
-- are. They have readers and writers — `MenuRenderer` branches on all three —
-- and live data is not moved into a new column just to tidy it. A settings bag
-- for what is NEW; the three columns that already work keep working.
--
-- ── NULL IS THE DEFAULT, AND IT MEANS "NOTHING SET" ─────────────────────────
-- Not `'{}'`. A row of empty JSON on every item in every menu is a fact nobody
-- asked for, and the renderer has to treat absent and empty identically anyway —
-- so there is no state worth spending a byte on.

ALTER TABLE menu_items
    ADD COLUMN settings JSON DEFAULT NULL AFTER visibility_rules;

-- ── AND THE PER-LOCATION SETTINGS ───────────────────────────────────────────
-- The second place. Its card is per THEME LOCATION, not per menu, and
-- that is not an arbitrary choice: it holds how the menu BEHAVES where it is
-- shown — open on hover or on click, the effect, the responsive breakpoint, what
-- the mobile toggle does. The same menu in the header and the footer wants
-- different answers, which is expressible because migration 049 lets a menu
-- occupy both.
--
-- On `menu_locations` rather than on `menus` for that reason exactly: a column
-- here is per (menu, location) pair, which is the thing being configured.
ALTER TABLE menu_locations
    ADD COLUMN settings JSON DEFAULT NULL AFTER menu_id;
