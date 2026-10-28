-- Migration 071: one answer per question.
--
-- The built-in question (`Forms\Captcha`) no longer keeps its answer in the
-- visitor's session: the form carries it sealed under the forms' own key, so a
-- public page needs no session and sets no cookie. A question answered — right
-- or wrong — is noted here, by the SHA-256 of its nonce, until it would have
-- expired anyway, so one question cannot be replayed or guessed at twice.

CREATE TABLE IF NOT EXISTS captcha_spent (
    nonce_hash  CHAR(64)  NOT NULL,
    expires_at  DATETIME  NOT NULL,
    PRIMARY KEY (nonce_hash),
    KEY idx_captcha_spent_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
