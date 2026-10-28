-- Migration 052: the phone's toggle bar, per location.
--
-- The bar a phone shows in place of the menu, built from blocks — the menu
-- toggle itself, an animated one, a spacer, a logo, search, icon, HTML and an
-- image toggle — each placed left, centre or right and dragged into order.
--
-- ── ON THE PAIR, NOT IN THE SETTINGS BAG ────────────────────────────────────
-- Not per THEME, beside the theme's colours: the one thing a bar belongs to
-- is the place the menu is shown. So it sits on `menu_locations`, next to
-- migration 050's `settings` — but in its own column, because it is a LIST of
-- structured blocks, and the bag is a flat map of switches that
-- `MenuLocationSettings` allowlists key by key.
--
-- NULL means the default bar: one menu toggle reading "Menu", where this
-- theme's phone header has always had it. `Ui\MenuToggleBlocks` owns the shape.

ALTER TABLE menu_locations
    ADD COLUMN toggle_blocks JSON DEFAULT NULL AFTER settings;
