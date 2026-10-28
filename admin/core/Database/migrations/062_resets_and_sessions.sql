-- Migration 062: the way back in, and where an account is signed in.
--
-- ── A RESET LINK IS A PASSWORD FOR ONE USE ─────────────────────────────────
-- `reset_token_hash` holds the SHA-256 of the token mailed to the account's
-- address, never the token: a copy of this table must not be a way into every
-- account with a request outstanding. One outstanding link per account — asking
-- again replaces it — and `reset_expires_at` ends it. Using it, or changing the
-- password any other way, clears both (`Security\PasswordReset`).
--
-- ── WHERE AN ACCOUNT IS SIGNED IN ───────────────────────────────────────────
-- `user_sessions` is one row per signed-in browser: which account, the address
-- and browser it signed in from, and when it was last seen. `Auth::check()`
-- reads it in the same query as the account, so deleting a row signs that
-- browser out on its next click — which is what "Sign out" beside a row on the
-- account screen does, and what "Sign out everywhere else" does to every row
-- but this one. The PHP session id itself is never stored: its SHA-256 is, for
-- the same reason the reset token's is.

ALTER TABLE users
    ADD COLUMN reset_token_hash CHAR(64) NULL DEFAULT NULL AFTER password_changed_at,
    ADD COLUMN reset_expires_at DATETIME NULL DEFAULT NULL AFTER reset_token_hash,
    ADD KEY idx_users_reset_token (reset_token_hash);

CREATE TABLE IF NOT EXISTS user_sessions (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED  NOT NULL,
    session_hash CHAR(64)      NOT NULL,
    ip           VARCHAR(45)   NOT NULL,
    user_agent   VARCHAR(255)  NULL,
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_user_sessions_hash (session_hash),
    KEY idx_user_sessions_user (user_id, last_seen_at),
    CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
