-- Migration 099: what a customer agreed to at the checkout, and asked for.
--
-- ── THE SHOP'S TERMS, AGREED TO ─────────────────────────────────────────────
-- A shop that takes returns within 30 days, or none on sale items, needs its
-- customer to have read that before they paid. Settings → Shop names the page
-- (`commerce_terms_page`); the checkout then asks for a tick beside a link to
-- it and refuses an order without one. The order keeps the page's title as it
-- was agreed to, so a page renamed later does not change what the customer saw.
--
-- ── AND NEWS AND OFFERS, ASKED FOR ──────────────────────────────────────────
-- Canada's anti-spam law wants express consent, so the box starts unticked and
-- the order keeps what the customer chose. It is offered only when the owner
-- turns it on (`commerce_offer_signup`).
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS terms_agreed     VARCHAR(255) NOT NULL DEFAULT '' AFTER customer_note,
    ADD COLUMN IF NOT EXISTS marketing_opt_in TINYINT(1)   NOT NULL DEFAULT 0  AFTER terms_agreed;
