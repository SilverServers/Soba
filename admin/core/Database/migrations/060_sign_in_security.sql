-- Migration 060: sign-in security — what happened, and who is shut out.
--
-- The CMS keeps two stores for this, because they answer two different
-- questions:
--
--   security_log       WHAT HAPPENED. Append-only. Every sign-in, every failed
--                      one, every lockout and every lift, with the name that was
--                      typed, the address it came from and the browser that sent
--                      it. This is the "review the login attempts" screen.
--
--   security_lockouts  WHO IS SHUT OUT NOW, and until when. A row an
--                      administrator can lift early. The lockout is ALSO written
--                      to the log, so lifting it never erases that it happened.
--
-- A lockout could be derived from the log alone ("the latest lockout event for
-- this address, with no lift after it"), but then lifting one means either
-- editing history or inferring state from the ORDER of rows — and a state that
-- is inferred is a state two readers can disagree about.
--
-- ── NO LOCATION COLUMN ──────────────────────────────────────────────────────
-- A country and city per attempt would mean asking a geolocation service about
-- every address, on the request. That is a third-party call at runtime, which
-- the CMS's rules forbid, and it hands every visitor's address to a stranger.
-- The address and the browser are what the CMS records.
--
-- ── `username` IS WHAT WAS TYPED, `user_id` IS WHO IT MATCHED ───────────────
-- A failed attempt against a name that does not exist is still worth seeing —
-- "admin", "administrator", "test" are the first things a script tries — so the
-- typed name is kept even when no account answers to it.

CREATE TABLE IF NOT EXISTS security_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- sign_in · sign_in_failed · lockout · lockout_lifted · sign_in_moved.
    -- Other events add their own; the column is a name, not an ENUM, so adding
    -- one is not a schema change.
    event       VARCHAR(32)     NOT NULL,

    username    VARCHAR(191)    NULL,
    user_id     INT UNSIGNED    NULL,
    ip          VARCHAR(45)     NOT NULL,
    user_agent  VARCHAR(255)    NULL,
    detail      VARCHAR(255)    NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    -- "How many times has this address failed since…" — the lockout's question,
    -- asked on every failed sign-in.
    KEY idx_security_log_ip (ip, event, created_at),
    -- The activity log's tabs, newest first.
    KEY idx_security_log_event (event, created_at),
    -- `SecurityLog::prune()`, which deletes by age alone.
    KEY idx_security_log_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS security_lockouts (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    ip          VARCHAR(45)     NOT NULL,

    -- NULL: the ADDRESS is locked out. A name: that ACCOUNT is, from anywhere
    -- but the addresses it has signed in from before. Kept as a column now so
    -- the second kind is not a migration.
    username    VARCHAR(191)    NULL,

    attempts    INT UNSIGNED    NOT NULL,

    -- How many more times the address tried while it was shut out. A COUNTER
    -- and not a log row per attempt: a script hammering a locked form sends
    -- thousands of requests a minute, and a row each would turn the lockout
    -- into a way to fill the database. The number is what anybody reviewing
    -- it wants to know anyway.
    refused     INT UNSIGNED    NOT NULL DEFAULT 0,

    locked_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    until_at    DATETIME        NOT NULL,
    lifted_at   DATETIME        NULL,
    lifted_by   INT UNSIGNED    NULL,

    PRIMARY KEY (id),
    KEY idx_security_lockouts_ip (ip, until_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── EVERY SESSION CARRIES THE VERSION IT WAS ISSUED UNDER ───────────────────
-- `Auth::check()` compares the two on every request. Changing a password,
-- deleting an account or "sign out everywhere" bumps this number, and every
-- session issued before it stops counting as signed in — including the ones on
-- other machines, which nothing could reach before.
ALTER TABLE users
    ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER password_hash;
