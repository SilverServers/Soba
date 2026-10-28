-- Migration 070: a user's admin look may be "the site's".
--
-- Appearance → Branding sets the palette and the corners the admin wears
-- (options `brand_admin_theme`, `brand_admin_radius`, `Ui\Brand`). A user's own choice
-- (`theme_preset`, `radius_pref`, migrations 006 and 037) still wins — but
-- both columns were NOT NULL with a default, so every account held a value
-- and "never chose" could not be told from "chose the default". NULL now
-- means the site's look.
--
-- The values that are the old defaults become NULL: no screen has ever let
-- anyone choose one, so a 'tuxedo' or a 'classic' on a row is the default it
-- was born with, not a choice. Anything else is left as it is.

ALTER TABLE users
    MODIFY COLUMN theme_preset VARCHAR(50) NULL DEFAULT NULL,
    MODIFY COLUMN radius_pref  VARCHAR(16) NULL DEFAULT NULL;

UPDATE users SET theme_preset = NULL WHERE theme_preset = 'tuxedo';
UPDATE users SET radius_pref  = NULL WHERE radius_pref  = 'classic';
