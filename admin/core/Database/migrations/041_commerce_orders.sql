-- Migration 041: orders and their line items.
--
-- ── AN ORDER IS A RECORD OF A PAST FACT, NOT A VIEW OF PRESENT DATA ─────────
-- Everything about money in this schema is denormalised ON PURPOSE, which is
-- the opposite of the instinct that serves the rest of this database well.
--
-- The reason is that a product is editable and an order is not. If a line item
-- held only `post_id` and read the price back through a join, then the day the
-- owner puts a product on sale, every order ever placed for it would silently
-- restate itself at the new price — including ones already paid, already
-- shipped, already reconciled against a bank statement. The customer's receipt
-- and the admin's order screen would disagree with each other, and neither
-- would match what was charged.
--
-- So `order_items` stores the name, the SKU and the unit price AS THEY WERE. It
-- also keeps `post_id`, but only as a link back for convenience — nothing about
-- the money reads through it, and it is `ON DELETE SET NULL` because deleting a
-- product must not delete the history of having sold it.
--
-- ── AND THE CURRENCY IS ON THE ORDER ────────────────────────────────────────
-- Same rule. `commerce_currency` is a setting and settings change; an order
-- placed in CAD stays an order in CAD after the owner switches the shop to USD.
-- Reading the current setting when rendering an old order would relabel $40.00
-- as $40.00 in a different currency, which is not a rounding error, it is a
-- different number.
--
-- ── MONEY COLUMNS ARE BIGINT, AND THEY ARE MINOR UNITS ──────────────────────
-- Never DECIMAL, never FLOAT. `Commerce\Money` is the only thing that converts
-- between these integers and anything a person reads; see its header for why a
-- float loses a cent on some baskets and not others. BIGINT rather than INT
-- because a signed INT tops out at about $21 million in cents, which is a
-- ceiling this schema has no business having.
--
-- ── `order_key` IS HOW A GUEST FINDS THEIR OWN ORDER ────────────────────────
-- There are no public accounts (see `FieldTypes`' note on User ID), so the
-- order-received page is reached by an unguessable key rather than by a login.
-- It is 32 hex characters from `random_bytes`, NOT the id, because
-- `/order/124` invites `/order/125` and that is somebody else's address and
-- telephone number. The UNIQUE index is what makes the lookup safe to do by key
-- alone.
--
-- ── THE USUAL ORDER STATUSES, MINUS THE ONES WE CANNOT HONESTLY OFFER ───────
-- pending · processing · on-hold · completed · cancelled · refunded · failed.
-- `failed` is in the list even though offline payment methods cannot produce it
-- yet: it is the status a gateway sets, and leaving it out would mean altering
-- the column the day one is added. The rest are what an offline shop actually
-- moves an order through.

CREATE TABLE IF NOT EXISTS orders (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Unguessable, and the only thing a guest needs to see their own order.
    order_key      CHAR(32)     NOT NULL,

    status         VARCHAR(20)  NOT NULL DEFAULT 'pending',

    -- The currency this order was placed in. Never re-read from settings.
    currency       CHAR(3)      NOT NULL,

    -- Minor units. subtotal is the sum of the line items; total is what the
    -- customer owes. They are separate columns rather than one computed from
    -- the other because shipping and tax land between them, and an order must
    -- be able to say what it charged even if the rule that produced it changes.
    subtotal       BIGINT       NOT NULL DEFAULT 0,
    shipping_total BIGINT       NOT NULL DEFAULT 0,
    tax_total      BIGINT       NOT NULL DEFAULT 0,
    discount_total BIGINT       NOT NULL DEFAULT 0,
    total          BIGINT       NOT NULL DEFAULT 0,

    -- How they said they would pay. The id is what the code branches on; the
    -- title is what it was CALLED at the time, so renaming a method in settings
    -- does not rewrite old orders. Same rule as the line items.
    payment_method       VARCHAR(40)  NOT NULL DEFAULT '',
    payment_method_title VARCHAR(120) NOT NULL DEFAULT '',
    transaction_ref      VARCHAR(120) NOT NULL DEFAULT '',

    -- The customer. Flat columns rather than a `customers` table: a guest
    -- checkout has no account to attach to, and two orders from the same
    -- address are not evidence of the same person. If accounts arrive, a
    -- nullable `user_id` joins this table; these columns still hold what was
    -- typed on the day.
    email          VARCHAR(190) NOT NULL DEFAULT '',
    phone          VARCHAR(40)  NOT NULL DEFAULT '',
    billing_name   VARCHAR(160) NOT NULL DEFAULT '',
    billing_line1  VARCHAR(190) NOT NULL DEFAULT '',
    billing_line2  VARCHAR(190) NOT NULL DEFAULT '',
    billing_city   VARCHAR(120) NOT NULL DEFAULT '',
    billing_region VARCHAR(120) NOT NULL DEFAULT '',
    billing_post   VARCHAR(24)  NOT NULL DEFAULT '',
    billing_country CHAR(2)     NOT NULL DEFAULT '',

    -- Shipping, when it differs. Empty `shipping_name` means "same as billing"
    -- and the renderer says so rather than drawing a second identical block.
    shipping_name   VARCHAR(160) NOT NULL DEFAULT '',
    shipping_line1  VARCHAR(190) NOT NULL DEFAULT '',
    shipping_line2  VARCHAR(190) NOT NULL DEFAULT '',
    shipping_city   VARCHAR(120) NOT NULL DEFAULT '',
    shipping_region VARCHAR(120) NOT NULL DEFAULT '',
    shipping_post   VARCHAR(24)  NOT NULL DEFAULT '',
    shipping_country CHAR(2)     NOT NULL DEFAULT '',

    customer_note  TEXT         NULL,

    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    paid_at        DATETIME     NULL,

    UNIQUE KEY uniq_order_key (order_key),
    KEY idx_status  (status, created_at),
    KEY idx_email   (email),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS order_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id    INT UNSIGNED NOT NULL,

    -- A LINK BACK, NOT THE SOURCE OF ANY FIGURE. NULL when the product has
    -- since been deleted; the line still reads correctly because the name, SKU
    -- and price below are its own.
    post_id     INT UNSIGNED NULL,

    name        VARCHAR(255) NOT NULL,
    sku         VARCHAR(80)  NOT NULL DEFAULT '',

    quantity    INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price  BIGINT       NOT NULL DEFAULT 0,   -- minor units, as sold
    line_total  BIGINT       NOT NULL DEFAULT 0,   -- unit_price × quantity

    position    INT UNSIGNED NOT NULL DEFAULT 0,

    KEY idx_order (order_id, position),
    KEY idx_post  (post_id),

    CONSTRAINT fk_item_order
        FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    -- SET NULL, not CASCADE: deleting a product must not delete the record of
    -- having sold it. That would take money out of the order's total and out of
    -- every report built on it, silently.
    CONSTRAINT fk_item_post
        FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── A NOTE ON AN ORDER ──────────────────────────────────────────────────────
-- Two kinds, and the difference matters: `is_customer_note` marks one that was
-- sent to the buyer, which is a thing that has happened and cannot be unsent.
-- The rest are internal — "rang the customer", "posted Tuesday" — and are the
-- reason an order screen is usable at all a week later.
CREATE TABLE IF NOT EXISTS order_notes (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id         INT UNSIGNED NOT NULL,
    author           VARCHAR(120) NOT NULL DEFAULT '',
    body             TEXT         NOT NULL,
    is_customer_note TINYINT(1)   NOT NULL DEFAULT 0,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_order (order_id, created_at),

    CONSTRAINT fk_note_order
        FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
