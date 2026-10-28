-- Migration 038: flat is the default radius
--
-- 037 shipped `radius_pref` with a column default of 'modern', matching
-- Ui\Theme::DEFAULT_RADIUS at the time. That rounded every input, select and
-- button at 6px against form cards that are correctly sharp, and the corners
-- were the first fault named when the entry form's styling was rejected.
--
-- DEFAULT_RADIUS is 'classic' now. Changing only the PHP constant would have
-- been invisible: `resolveRadius()` reads the stored column and only falls back
-- when the value is unrecognised, so every existing row — and every row created
-- by the old column default — kept its rounded corners. The constant and the
-- column have to move together or one of them is a lie.
--
-- The preference AXIS is untouched. A user who picks Modern still gets rounded
-- controls and rounded modals; this changes what they get before they pick.

ALTER TABLE users
    MODIFY COLUMN radius_pref VARCHAR(16) NOT NULL DEFAULT 'classic';

-- Only rows still carrying the OLD DEFAULT are re-pointed. A row that says
-- 'modern' because someone chose it is indistinguishable in SQL from one that
-- says it because the column defaulted — so this runs while the only rows in
-- existence predate any picker (there is no Appearance screen yet, so nobody
-- can have chosen). When one ships, this UPDATE must not be repeated.
UPDATE users
   SET radius_pref = 'classic'
 WHERE radius_pref = 'modern';
