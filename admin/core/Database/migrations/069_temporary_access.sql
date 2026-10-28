-- Migration 069: temporary access.
--
-- A sign-in link an administrator gives to someone who needs in for a while,
-- with a lifespan and a number of uses. It makes an account of its own
-- (`Security\TemporaryAccess`), never an administrator, signed in only by its
-- link, and suspended (migration 067) when its time runs out or it is
-- revoked — so what it did stays attributed.
--
-- One row per account. The link is kept as its SHA-256 only, like a reset link
-- (migration 062): it is shown once, when it is made, and a lost one is
-- replaced, never shown again. `ended_why` is 'expired' or 'revoked'.

CREATE TABLE IF NOT EXISTS temporary_access (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    token_hash    CHAR(64)     NOT NULL,
    max_uses      INT UNSIGNED NOT NULL,
    uses          INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at    DATETIME     NOT NULL,
    created_by    INT UNSIGNED NULL DEFAULT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at  DATETIME     NULL DEFAULT NULL,
    ended_at      DATETIME     NULL DEFAULT NULL,
    ended_why     VARCHAR(16)  NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_temporary_access_user (user_id),
    UNIQUE KEY uq_temporary_access_token (token_hash),
    KEY idx_temporary_access_open (ended_at, expires_at),
    CONSTRAINT fk_temporary_access_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
