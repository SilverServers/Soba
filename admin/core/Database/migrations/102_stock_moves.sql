-- Migration 102: every change to a count on the shelf, and why.
--
-- ── A LINE A CHANGE ─────────────────────────────────────────────────────────
-- A product's own count or one option's, by how much, what it was after, and
-- the reason: sold, an order cancelled or reinstated or deleted, refunded,
-- exchanged, received, returned, damaged, recounted, or edited on the product.
-- The order it belonged to when there was one, who, and a note. Named as the
-- product and option were at the time, so a rename or a deletion later leaves
-- the history reading as it happened (`Commerce\StockLog`).
CREATE TABLE IF NOT EXISTS stock_moves (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id      INT UNSIGNED NULL,
    variation_id INT UNSIGNED NULL,
    label        VARCHAR(255) NOT NULL,
    change_qty   INT          NOT NULL,
    qty_after    INT          NULL,
    reason       VARCHAR(40)  NOT NULL,
    note         VARCHAR(255) NOT NULL DEFAULT '',
    order_id     INT UNSIGNED NULL,
    author       VARCHAR(120) NOT NULL DEFAULT '',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_stock_moves_post (post_id, created_at),
    KEY idx_stock_moves_order (order_id),
    CONSTRAINT fk_stock_moves_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
