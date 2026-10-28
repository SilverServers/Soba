-- Migration 064: a lockout per ACCOUNT, and the link that lifts it — and the
-- key that lets the log count one name.
--
-- Migration 060 kept `security_lockouts.username` for exactly this: NULL is an
-- ADDRESS locked out (`Security\Throttle`); a name is that ACCOUNT locked out,
-- from every address it has never signed in from (`Security\AccountLock`).
--
-- `unlock_hash` is the SHA-256 of the link mailed to the account's owner when
-- its lock starts — never the link itself, so a copy of this table opens
-- nothing. Good until the lock ends; using it lifts the lock.

ALTER TABLE security_lockouts
    ADD COLUMN unlock_hash CHAR(64) NULL DEFAULT NULL AFTER lifted_by,
    ADD KEY idx_security_lockouts_account (username, until_at),
    ADD KEY idx_security_lockouts_unlock (unlock_hash);

-- `AccountLock::failures()` counts one NAME's failures on every failed sign-in.
-- Without a key that starts with the name it reads every failure of the last
-- 12 hours through the event key: 47 ms a time at 20,000 failures across
-- 2,000 names, a cost the attack chooses. With this key, 0.4 ms.
ALTER TABLE security_log
    ADD KEY idx_security_log_username (username, event, created_at);
