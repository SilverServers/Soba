-- Migration 105: the links a customer signs in to their account with.
--
-- No password: a customer asks for a link at /account, and the address their
-- orders were placed with is sent one (`Commerce\CustomerAccount`). The token
-- is kept only as its hash, works once, and for half an hour. The address that
-- asked is kept to count requests by, as the sign-in page counts them.
CREATE TABLE IF NOT EXISTS customer_links (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email       VARCHAR(190) NOT NULL,
    token_hash  CHAR(64)     NOT NULL,
    ip_key      VARCHAR(64)  NOT NULL DEFAULT '',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME     NOT NULL,
    used_at     DATETIME     NULL DEFAULT NULL,

    UNIQUE KEY uq_customer_links_token (token_hash),
    KEY idx_customer_links_email (email, created_at),
    KEY idx_customer_links_ip (ip_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
