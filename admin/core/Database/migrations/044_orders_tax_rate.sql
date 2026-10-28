-- Migration 044: an order remembers the tax it was charged.
--
-- ── WHY THE RATE AND THE NAME GO ON THE ROW ─────────────────────────────────
-- `orders.tax_total` has held a number since migration 041. A number on its own
-- is not a receipt: "$2.25" against a $45 basket is a charge the customer
-- cannot check and the owner cannot explain. What makes it checkable is the
-- name and the rate beside it — "GST (5%) $2.25" — and both of those live in
-- settings, which CHANGE.
--
-- Reading them at render time would mean an owner raising their rate in April
-- silently restates every receipt they issued in March. The order row would say
-- $2.25 and the line above it would say 7%, and the two would not agree with
-- each other on the same page.
--
-- This is the same rule `payment_method_title` already follows, and migration
-- 041's header says why: *"The TITLE as it reads today, copied onto the order.
-- Renaming the method in settings tomorrow must not rewrite this receipt."*
-- The `currency` column is there for the third instance of it. A receipt is a
-- record of what happened, not a view of what is currently configured.
--
-- ── EXISTING ORDERS ARE HONEST AT ZERO AND BLANK ────────────────────────────
-- Every order placed before this was placed by a shop that charged no tax —
-- nothing set `tax_total` at all, so they are all zero. A rate of 0 and an
-- empty name say exactly that, and the receipt prints no tax line for them,
-- which is what those receipts said when they were issued.
--
-- ── AND `tax_inclusive` IS THE THIRD ONE ────────────────────────────────────
-- Not decoration. Inclusive, the tax is PART of the total; exclusive it was
-- added to it. Without knowing which, a receipt cannot say whether "Total"
-- already contains the $2.25 printed above it — and getting that backwards on
-- a reprint is how a customer concludes they were billed twice.

ALTER TABLE orders
    ADD COLUMN tax_rate      INT         NOT NULL DEFAULT 0 AFTER tax_total,
    ADD COLUMN tax_label     VARCHAR(60) NOT NULL DEFAULT '' AFTER tax_rate,
    ADD COLUMN tax_inclusive TINYINT(1)  NOT NULL DEFAULT 0 AFTER tax_label;
