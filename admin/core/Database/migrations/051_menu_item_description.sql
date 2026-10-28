-- Migration 051: a menu item can carry a description.
--
-- The description is a field on the item, and each location's settings have a
-- switch, "Item descriptions", that draws it under the item's label.
--
-- ── A REAL COLUMN, NOT A KEY IN MIGRATION 050'S SETTINGS BAG ─────────────────
-- The bag holds DISPLAY PREFERENCES — hide the arrow, push it right, which icon.
-- A description is CONTENT: words the owner writes, which a translation, an
-- export or the assistant needs to find beside the label rather than inside a
-- JSON blob of switches.
--
-- 500 characters: a line or two under a menu link. Anything longer is a page.

ALTER TABLE menu_items
    ADD COLUMN description VARCHAR(500) DEFAULT NULL AFTER label;
