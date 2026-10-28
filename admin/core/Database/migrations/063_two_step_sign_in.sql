-- Migration 063: two-step sign-in.
--
-- ── A SECOND THING TO KNOW, PER ACCOUNT ─────────────────────────────────────
-- `user_factors` is one row per way an account can prove itself after its
-- password: 'app', an authenticator app, and 'email', a code sent to the
-- account's address. An app's secret is SEALED (`Security\Sealed`, keyed from
-- config.php's app_secret), not hashed: the server has to work out the same six
-- digits the phone does, so it must be able to read it — and a copy of this
-- table without config.php must not be able to. `last_step` is the 30-second
-- step of the last code accepted, so a code works once.
--
-- ── A WAY IN WHEN THE PHONE IS LOST ─────────────────────────────────────────
-- `user_backup_codes`: ten single-use codes, shown once, stored as an HMAC under
-- app_secret. `used_at` marks one spent; asking for new codes deletes the old.
--
-- ── A BROWSER THAT HAS PROVED ITSELF ────────────────────────────────────────
-- `trusted_browsers`: "remember this browser" — a random cookie, stored as its
-- SHA-256, for the days the owner allows. The second step is skipped there, the
-- password never is.
--
-- ── WHEN AN ACCOUNT WAS FIRST ASKED ─────────────────────────────────────────
-- `users.two_step_asked_at`: the first sign-in at which the site's policy asked
-- this account to set up two-step sign-in. Its grace period runs from there, so
-- a policy switched on today, an account promoted today and an account created
-- today all get the same days — and removing a method later does not buy more.

CREATE TABLE IF NOT EXISTS user_factors (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED    NOT NULL,
    kind         VARCHAR(16)     NOT NULL,
    secret       VARCHAR(255)    NULL,
    last_step    BIGINT UNSIGNED NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME        NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_user_factors_kind (user_id, kind),
    CONSTRAINT fk_user_factors_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_backup_codes (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    code_hash  CHAR(64)     NOT NULL,
    used_at    DATETIME     NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_user_backup_codes_user (user_id),
    CONSTRAINT fk_user_backup_codes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trusted_browsers (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    token_hash CHAR(64)     NOT NULL,
    ip         VARCHAR(45)  NOT NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME     NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_trusted_browsers_hash (token_hash),
    KEY idx_trusted_browsers_user (user_id),
    CONSTRAINT fk_trusted_browsers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD COLUMN two_step_asked_at DATETIME NULL DEFAULT NULL AFTER reset_expires_at;
