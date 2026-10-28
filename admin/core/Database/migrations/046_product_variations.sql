-- Migration 046: a product that is sold in more than one form.
--
-- ── WHY A TABLE AND NOT MORE META ───────────────────────────────────────────
-- The first question is which existing mechanism should hold this.
-- Every other thing a product knows lives in `post_meta`, and a list of sizes
-- would fit there as JSON in one row.
--
-- STOCK IS WHY IT CANNOT. `Commerce\Stock`'s whole argument is that counting
-- has to be done by the database, in one atomic statement with the guard in its
-- WHERE, because "read the count, see it is enough, write the new count" is a
-- race whose window is exactly when two people are buying the last one. A JSON
-- blob in a `longtext` cannot be decremented that way: the read-modify-write is
-- the only option, and it is the bug.
--
-- A shop that sells shirts in three sizes and counts ten in stock can otherwise
-- sell ten smalls. That is the exact failure `Stock` exists to prevent,
-- reintroduced one level down.
--
-- ── A LIST OF NAMED VARIANTS, NOT A MATRIX OF ATTRIBUTES ────────────────────
-- A matrix models this as ATTRIBUTES (Size: S/M/L, Colour: red/blue) which
-- generate VARIATIONS (six of them) that the owner then prices one by one. That
-- machinery exists so a shop with four attributes does not type sixty rows.
--
-- The shop this system is for has three sizes, or four colours, or six of
-- "S/Red, S/Blue, M/Red…" typed out — and typing six rows is faster and far
-- clearer than configuring two attribute sets and then filling in a grid, most
-- of which is combinations they do not sell.
--
-- WHAT WE GIVE UP is generating the combinations, and filtering an archive by
-- one attribute. Both are written on the product screen rather than discovered.
--
-- ── `price` IS NULLABLE, AND THAT IS THE USEFUL PART ────────────────────────
-- NULL means "the same as the product's own price". An owner listing three
-- sizes that all cost £20 should not have to type £20 three times and then keep
-- four numbers in step when it changes — and every one they forget is a variant
-- quietly selling at the old price.
--
-- ── AND STOCK IS PER VARIANT, OPT-IN, THE SAME AS THE PRODUCT'S ─────────────
-- `manage_stock` mirrors `product_manage_stock` exactly: off, the status word
-- is the whole answer and nothing is counted. A ticket that never runs out and
-- a shirt that does are both sold here.

CREATE TABLE IF NOT EXISTS product_variations (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    post_id      INT UNSIGNED NOT NULL,

    -- What the customer picks: "Small", "Large / Red", "500g".
    label        VARCHAR(120) NOT NULL DEFAULT '',

    sku          VARCHAR(80)  NOT NULL DEFAULT '',

    -- Minor units. NULL = whatever the product costs.
    price        BIGINT       NULL DEFAULT NULL,

    manage_stock TINYINT(1)   NOT NULL DEFAULT 0,
    stock_qty    INT          NOT NULL DEFAULT 0,
    stock_status VARCHAR(20)  NOT NULL DEFAULT 'in_stock',

    sort_order   INT          NOT NULL DEFAULT 0,

    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_post (post_id, sort_order),

    -- ON DELETE CASCADE: a variant of a deleted product is a row nothing can
    -- ever reach, and `posts` is where the product lives.
    CONSTRAINT fk_variation_post FOREIGN KEY (post_id)
        REFERENCES posts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── AND THE ORDER LINE REMEMBERS WHICH ONE WAS BOUGHT ───────────────────────
-- The LABEL, copied onto the line, for the fifth time in this subsystem — the
-- reason `payment_method_title`, `currency`, `tax_label` and `coupon_code` are
-- all copied rather than joined. A variant renamed or deleted next year must
-- not rewrite a packing slip printed today, and "Associate" on an order that
-- was actually "Associate — Large" is a parcel packed wrong.
--
-- `variation_id` goes alongside it and is allowed to point at nothing. It is
-- what a future "reorder this" would use; the LABEL is what a human reads, and
-- the two have different jobs.
ALTER TABLE order_items
    ADD COLUMN variation_id    INT UNSIGNED NULL DEFAULT NULL AFTER post_id,
    ADD COLUMN variation_label VARCHAR(120) NOT NULL DEFAULT '' AFTER variation_id;
