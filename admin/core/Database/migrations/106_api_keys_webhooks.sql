-- Migration 106: the keys another program reaches the shop's API with, and the
-- addresses the shop tells when an order comes in or moves.
--
-- A key is shown once, when it is made, and kept here only as its hash, with
-- its last eight characters to tell keys apart (`Security\ApiKeys`). It reads,
-- or reads and writes. A webhook's secret is kept as it is: the other program
-- needs the same one to check what it is sent (`Commerce\Webhooks`).
CREATE TABLE IF NOT EXISTS api_keys (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(120) NOT NULL,
    key_hash     CHAR(64)     NOT NULL,
    hint         CHAR(8)      NOT NULL,
    can_write    TINYINT(1)   NOT NULL DEFAULT 0,
    created_by   VARCHAR(120) NOT NULL DEFAULT '',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME     NULL DEFAULT NULL,

    UNIQUE KEY uq_api_keys_hash (key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhooks (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    url         VARCHAR(500) NOT NULL,
    events      VARCHAR(255) NOT NULL,
    secret      CHAR(64)     NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_status VARCHAR(160) NOT NULL DEFAULT '',
    last_at     DATETIME     NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
