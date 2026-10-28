-- Migration 067: suspended accounts.
--
-- A SUSPENDED account cannot sign in by any route — a password, a second
-- step, a reset link — until an administrator restores it. An administrator
-- suspends one on Users → Edit (or the server, `security.php suspend`), and
-- so does the inactivity rule (Security → Login protection → Inactive
-- accounts): an editor or author nobody has used for 90 days. See
-- `Security\Accounts`.
--
-- `last_seen_at` is the rule's clock. It is set at every sign-in AND once a
-- day while a session is in use (`Auth::check()`): a browser kept busy never
-- signs in again, so the last SIGN-IN would call an account used every day
-- inactive. Backfilled here from what the log and the sessions list remember.
--
-- `restored_at` restarts the clock when an administrator restores an account
-- the rule suspended. Without it the next day's sweep would suspend it again.
--
-- `suspended_why` is 'inactive', 'admin' (with `suspended_by`) or 'server'.

ALTER TABLE users
    ADD COLUMN last_seen_at  DATETIME     NULL DEFAULT NULL AFTER two_step_asked_at,
    ADD COLUMN suspended_at  DATETIME     NULL DEFAULT NULL AFTER last_seen_at,
    ADD COLUMN suspended_why VARCHAR(16)  NULL DEFAULT NULL AFTER suspended_at,
    ADD COLUMN suspended_by  INT UNSIGNED NULL DEFAULT NULL AFTER suspended_why,
    ADD COLUMN restored_at   DATETIME     NULL DEFAULT NULL AFTER suspended_by;

UPDATE users u
   SET u.last_seen_at = NULLIF(GREATEST(
           COALESCE((SELECT MAX(l.created_at) FROM security_log l WHERE l.event = 'sign_in' AND l.user_id = u.id), '1000-01-01 00:00:00'),
           COALESCE((SELECT MAX(s.last_seen_at) FROM user_sessions s WHERE s.user_id = u.id), '1000-01-01 00:00:00')
       ), '1000-01-01 00:00:00');

-- THE RULE'S CLOCK STARTS NOW, on every install: an update never suspends an
-- account for months that passed before the rule existed.
INSERT INTO options (option_key, option_value)
VALUES ('security_inactive_since', DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s'))
ON DUPLICATE KEY UPDATE option_value = option_value;
