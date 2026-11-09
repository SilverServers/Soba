-- Migration 101: money given back on an order, a part of it at a time.
--
-- ── A REFUND IS A RECORD OF ITS OWN, NOT A NEW TOTAL ───────────────────────
-- The jacket comes back and the tees stay: the order still says what was
-- charged, and each refund says what went back, when, why and by whom. The
-- order's net is the one less the others, worked out when it is read, so no
-- stored figure can disagree with the refunds it is made of.
CREATE TABLE IF NOT EXISTS order_refunds (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id    INT UNSIGNED NOT NULL,
    amount      BIGINT       NOT NULL,            -- minor units, everything that went back
    shipping    BIGINT       NOT NULL DEFAULT 0,  -- of which delivery, before its tax
    tax         BIGINT       NOT NULL DEFAULT 0,  -- of which tax, the sum of its lines
    reason      VARCHAR(255) NOT NULL DEFAULT '',
    restocked   TINYINT(1)   NOT NULL DEFAULT 0,  -- whether its goods went back on the shelf
    author      VARCHAR(120) NOT NULL DEFAULT '',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_order_refunds_order (order_id, created_at),
    CONSTRAINT fk_order_refunds_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── WHICH OF THE ORDER'S LINES, AND HOW MANY ───────────────────────────────
-- `amount` is the goods at the price they were sold at, before the discount's
-- share comes off: what a customer reads beside the name.
CREATE TABLE IF NOT EXISTS order_refund_items (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    refund_id     INT UNSIGNED NOT NULL,
    order_item_id INT UNSIGNED NOT NULL,
    quantity      INT UNSIGNED NOT NULL,
    amount        BIGINT       NOT NULL,

    KEY idx_order_refund_items_refund (refund_id),
    CONSTRAINT fk_order_refund_items_refund FOREIGN KEY (refund_id) REFERENCES order_refunds (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_refund_items_item FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── AND THE TAX IT GAVE BACK, BY NAME ──────────────────────────────────────
-- Each government's share of a refund, as `order_tax_lines` holds each one's
-- share of the order: a GST/HST return counts what was collected less what
-- went back, province by province.
CREATE TABLE IF NOT EXISTS order_refund_tax_lines (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    refund_id INT UNSIGNED NOT NULL,
    label     VARCHAR(60)  NOT NULL,
    rate      INT UNSIGNED NOT NULL,
    amount    BIGINT       NOT NULL,
    position  SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    KEY idx_order_refund_tax_lines_refund (refund_id),
    CONSTRAINT fk_order_refund_tax_lines_refund FOREIGN KEY (refund_id) REFERENCES order_refunds (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── WHAT EACH LINE HAS HAD BACK ─────────────────────────────────────────────
-- `refunded_qty` limits the next refund to what is left. `restocked_qty` is
-- what a refund already put back on the shelf, so cancelling or deleting the
-- order later gives back only the rest, and never a unit twice.
ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS refunded_qty  INT UNSIGNED NOT NULL DEFAULT 0 AFTER quantity,
    ADD COLUMN IF NOT EXISTS restocked_qty INT UNSIGNED NOT NULL DEFAULT 0 AFTER refunded_qty;
