-- Migration 061: an account can be told to choose a new password.
--
-- `must_change_password` holds WHY, or NULL for "nothing to do":
--
--   seed    it still has the password every install is seeded with (002)
--   admin   an administrator set it — at "Add user", or on someone else's
--           account — so somebody other than its owner knows it
--   all     an administrator asked every account to choose a new one
--
-- The first-sign-in screen reads it, says which, and clears it once a new
-- password is saved (`AuthController::choosePassword()`). A password that
-- merely breaks the password rule needs no row: that is found at sign-in, the
-- one moment the plain password is in hand.
--
-- ── THE SEEDED ACCOUNT, FOUND WITHOUT KNOWING ITS PASSWORD ──────────────────
-- An older install was seeded with `admin / corecms` (a new one is seeded with no
-- password at all), and that starting password is retired. A bcrypt hash cannot
-- be checked in SQL, but it does not need to be: 002 wrote one exact string,
-- and an account whose hash is still byte-for-byte
-- that string still has that password. Any account whose password has ever
-- been changed has a different hash, and is left alone.

ALTER TABLE users
    ADD COLUMN must_change_password VARCHAR(16) NULL DEFAULT NULL AFTER session_version,
    ADD COLUMN password_changed_at  DATETIME    NULL DEFAULT NULL AFTER must_change_password;

UPDATE users
   SET must_change_password = 'seed'
 WHERE password_hash = '$2y$12$D1vc3b8PrUezFE4Jr7mPIezxSvz7UFCxQwW4cv9fLLyoBXn/fGrdy';
