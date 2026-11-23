-- Migration 126: two-step sign-in starts over.
--
-- The keys that seal an authenticator app's secret and hash the backup codes
-- (`Security\Sealed`) changed in this release, so what was kept before can no
-- longer be read or matched. It is cleared here: each account that used an
-- authenticator app sets one up again, with new backup codes, and its grace
-- period (`two_step_asked_at`) starts again, so the site's policy asks it to,
-- rather than shutting it out. A code by email keeps nothing, and a remembered
-- browser is hashed another way: both carry on.

UPDATE users
   SET two_step_asked_at = NULL
 WHERE id IN (SELECT user_id FROM user_factors WHERE kind = 'app')
    OR id IN (SELECT user_id FROM user_backup_codes);

DELETE FROM user_factors WHERE kind = 'app';

DELETE FROM user_backup_codes;
