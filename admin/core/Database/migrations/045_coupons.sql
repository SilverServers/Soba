-- Migration 045: discount codes.
--
-- ── WHY A TABLE AND NOT A CONTENT TYPE ──────────────────────────────────────
-- The first question is always "which existing mechanism should hold this?",
-- and the honest answer here is neither of them.
--
-- A coupon is not content: it has no page, no slug, no author, no revisions, no
-- SEO and nothing to say. Registering it as a Content Type would put SPRING20
-- in the sidebar beside News and Events, give it a public URL that has to be
-- suppressed, and hand it an editor for a body nobody will ever write. Every
-- one of those is a lie the owner has to learn to ignore.
--
-- Nor is it settings: there are many of them, they are created and retired
-- constantly, and each one needs a usage counter that a checkout increments
-- under concurrency. That is a table.
--
-- ── `times_used` IS A COUNTER, AND IT IS WHY THE LIMIT CAN BE TRUSTED ───────
-- `Commerce\Stock`'s header has the argument in full and it is the same one:
-- "read the count, see it is under the limit, write the new count" is a race
-- whose window is exactly when two people are redeeming the last use of a code.
-- The increment is one atomic UPDATE with the guard in its WHERE, and
-- `rowCount()` decides whether the redemption happened.
--
-- ── THE CODE IS UNIQUE, CASE-INSENSITIVELY ─────────────────────────────────
-- A customer types `spring20` on their phone and the owner typed `SPRING20`.
-- Those are the same coupon to everybody except a database, so the column is
-- stored upper-cased and carries a UNIQUE index — which also stops an owner
-- creating two codes that differ only in case and then wondering which one the
-- discount came from.
--
-- ── AMOUNTS ARE MINOR UNITS OR HUNDREDTHS OF A PERCENT ─────────────────────
-- Never a float, and which one `amount` means is decided by `kind`. A `percent`
-- coupon of 10% stores 1000, exactly as `commerce_tax_rate` does; a `fixed` one
-- of $5.00 stores 500. One column because a coupon is one or the other and two
-- nullable columns would let a row be both.
--
-- ── AND EVERY LIMIT IS OPTIONAL ─────────────────────────────────────────────
-- `min_spend`, `usage_limit` and `expires_at` are all "no limit" at their
-- defaults. A shop that wants a code with no conditions should not have to
-- invent a date in 2099 and a limit of 99999 to say so.

CREATE TABLE IF NOT EXISTS coupons (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    code        VARCHAR(40)  NOT NULL,

    -- 'percent' | 'fixed'. What `amount` is measured in.
    kind        VARCHAR(10)  NOT NULL DEFAULT 'percent',

    -- Hundredths of a percent for 'percent', minor units for 'fixed'.
    amount      BIGINT       NOT NULL DEFAULT 0,

    -- The goods have to come to at least this much. 0 = no minimum.
    min_spend   BIGINT       NOT NULL DEFAULT 0,

    -- How many times it may be redeemed in total. 0 = unlimited.
    usage_limit INT UNSIGNED NOT NULL DEFAULT 0,
    times_used  INT UNSIGNED NOT NULL DEFAULT 0,

    -- NULL = never expires. A DATE and not a DATETIME: an owner setting an end
    -- date means the end of that day, and asking them for a time as well is a
    -- question with no good answer.
    expires_at  DATE         NULL DEFAULT NULL,

    -- Turned off without being deleted. A retired code whose row is deleted
    -- takes its usage history with it, and the orders that used it then point
    -- at nothing.
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,

    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                             ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── AND THE ORDER REMEMBERS WHICH CODE IT WAS ───────────────────────────────
-- `discount_total` alone is a number with no explanation — an owner looking at
-- "$4.50 off" cannot tell whether it was a coupon, a mistake, or a refund, and
-- a customer's receipt cannot name it either.
--
-- The CODE and not the coupon's id, for the third time in this subsystem: the
-- reason `payment_method_title`, `currency` and `tax_label` are all copied onto
-- the row. A coupon renamed or deleted next year must not rewrite a receipt
-- issued today, and a foreign key would either forbid the deletion or blank the
-- receipt when it happened.
--
-- ── AND A FLAG, FOR THE SAME REASON MIGRATION 042 NEEDED ONE ────────────────
-- `coupon_redeemed` says whether THIS order is currently holding a use of the
-- code. Cancelling puts it back and reinstating takes it again, and a status
-- can be changed more than once: pending → cancelled → processing is an
-- ordinary morning's work when somebody rings back.
--
-- Without the flag, "should I put the use back?" has to be inferred from the
-- status being left — which is wrong the second time round. Cancel an order
-- twice on a slow page and the code gains a use it never had.
ALTER TABLE orders
    ADD COLUMN coupon_code     VARCHAR(40) NOT NULL DEFAULT '' AFTER discount_total,
    ADD COLUMN coupon_redeemed TINYINT(1)  NOT NULL DEFAULT 0  AFTER coupon_code;
