-- Migration 128: every account is square until its owner picks Rounded.
--
-- `users.radius_pref` (037; NULL for the default since 070) holds an
-- account's corners: NULL is Square, the design language's `classic`, and
-- 'modern' is Rounded (`Ui\Theme::resolveRadius()`). The account page offers
-- the choice from this release on. A value already stored was written by a
-- setting since removed, and an account holding 'modern' was still drawn
-- rounded, so each goes back to NULL here, once, before anyone can choose:
-- an account is drawn rounded only after Rounded is picked on its page.
UPDATE users
   SET radius_pref = NULL
 WHERE radius_pref IS NOT NULL;
