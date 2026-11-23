-- Migration 070: a user's admin palette and corners may be unset.
--
-- `theme_preset` and `radius_pref` (migrations 006 and 037) were NOT NULL with
-- a default, so every account held a value and "never chose" could not be told
-- from "chose the default". NULL now means the default (`Ui\Theme`).
--
-- The values that are the old defaults become NULL: no screen has ever let
-- anyone choose one, so a 'tuxedo' or a 'classic' on a row is the default it
-- was born with, not a choice. Anything else is left as it is.

ALTER TABLE users
    MODIFY COLUMN theme_preset VARCHAR(50) NULL DEFAULT NULL,
    MODIFY COLUMN radius_pref  VARCHAR(16) NULL DEFAULT NULL;

UPDATE users SET theme_preset = NULL WHERE theme_preset = 'tuxedo';
UPDATE users SET radius_pref  = NULL WHERE radius_pref  = 'classic';
