-- Migration 042: has this order taken its stock off the shelf?
--
-- ── WHY A FLAG AND NOT AN INFERENCE ─────────────────────────────────────────
-- Stock is taken when an order is placed and put back when it is cancelled or
-- refunded. Both of those are status changes, and a status can be changed more
-- than once: pending → cancelled → processing is an ordinary morning's work
-- when somebody rings back.
--
-- Without a flag, "should I put the stock back?" has to be inferred from the
-- status it is LEAVING — which is wrong the second time round. Cancel an order
-- twice (the owner clicks, the page is slow, they click again) and the shop
-- gains units it never had. Reinstate a cancelled order and it takes stock it
-- already holds.
--
-- The flag says what is TRUE RIGHT NOW: this order is holding stock, or it is
-- not. Every transition asks that question rather than trying to remember the
-- history, and the operation becomes idempotent — which is the only safe shape
-- for something a person can click twice.
--
-- ── DEFAULT 0, AND EXISTING ORDERS ARE HONEST AT 0 ──────────────────────────
-- Orders placed before this migration were taken by a shop that was not
-- counting, so they hold nothing. 0 says exactly that, and a later cancel
-- correctly puts nothing back rather than inventing units.

ALTER TABLE orders
    ADD COLUMN stock_reduced TINYINT(1) NOT NULL DEFAULT 0 AFTER status;
