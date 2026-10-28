-- Migration 037: appearance preferences
--
-- TWO FIXES TO ONE IDEA — "what does this user want the admin to look like".
--
-- 1. `theme_preset` defaulted to 'silverservers-dark', a key from an earlier
--    ThemePresets system that no longer exists. No theme in Ui\Theme has that
--    key, so every row seeded or created with it resolved to the fallback.
--    Nothing looked broken — the fallback IS the default theme — which
--    is precisely why it could sit there: a dead value that resolves to the right
--    answer by accident is invisible until someone builds the picker and finds
--    the stored preference is a key nothing recognises.
--
--    Ui\Theme::resolve() still validates against the registry, so an unknown key
--    remains harmless. This only stops NEW rows being born with a dead one, and
--    re-points the one known bad value. Any other unrecognised key is left alone
--    on purpose: enumerating the 21 valid keys in SQL would put a second copy of
--    the theme registry in a place that cannot be kept in step with the first.
--
-- 2. `radius_pref` did not exist at all. The design language's radius axis
--    (`modern` / `classic`) is INDEPENDENT of the theme and is meant to be a user
--    preference; with no column, every user was pinned to the default and the
--    axis was decorative. Same table, same idea, no new storage layer.

ALTER TABLE users
    MODIFY COLUMN theme_preset VARCHAR(50) NOT NULL DEFAULT 'tuxedo';

UPDATE users
   SET theme_preset = 'tuxedo'
 WHERE theme_preset = 'silverservers-dark';

ALTER TABLE users
    ADD COLUMN radius_pref VARCHAR(16) NOT NULL DEFAULT 'modern' AFTER theme_preset;
