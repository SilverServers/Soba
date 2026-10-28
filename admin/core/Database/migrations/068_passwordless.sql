-- Migration 068: signing in without a password.
--
-- Two ways in without a password: a code by email, and a sign-in
-- link. Both are off until an administrator switches them on (Security →
-- Passwordless); see `Security\Passwordless`.
--
-- A sign-in link is kept like a reset link (migration 062): only the SHA-256
-- of its token, one per account, with when it stops working. A newer link
-- replaces it; using it clears it. A code by email is kept in the session of
-- the sign-in it belongs to, never here.

ALTER TABLE users
    ADD COLUMN signin_token_hash CHAR(64) NULL DEFAULT NULL AFTER restored_at,
    ADD COLUMN signin_expires_at DATETIME NULL DEFAULT NULL AFTER signin_token_hash,
    ADD KEY idx_users_signin_token (signin_token_hash);
