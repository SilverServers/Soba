-- Migration 103: the customers waiting for a sold-out option, or product.
--
-- An address left on the product page, kept for the one email that says it
-- is back and removed when that is sent (`Commerce\StockAlert`): it was given
-- for that and nothing else. `variation_id` is NULL for a product without
-- options. A product deleted takes its waiting list with it.
CREATE TABLE IF NOT EXISTS stock_waitlist (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id      INT UNSIGNED NOT NULL,
    variation_id INT UNSIGNED NULL,
    email        VARCHAR(190) NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_stock_waitlist_item (post_id, variation_id),
    CONSTRAINT fk_stock_waitlist_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
