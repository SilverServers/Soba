-- Migration 098: what a discount code applies to, from when, and how often each.
--
-- ── WHAT A SHOP'S ORDINARY CODES NEED ───────────────────────────────────────
-- A code took a percentage or an amount off the whole basket, until a last
-- day, a number of times across everybody. A clothing shop's ordinary codes
-- are narrower than that: free delivery for a weekend, 20% off the outerwear
-- but not what is already on sale, one use each for a newsletter's readers,
-- from the first of the month.
--
--   starts_at      the first day it works, in the site's own days; NULL is
--                  from now. Its last day, `expires_at`, works too.
--   free_shipping  delivery costs nothing while it applies — beside the
--                  amount off, which may then be nothing.
--   exclude_sale   the goods on sale are left out of what it takes off.
--   per_customer   how many orders one email address may use it on; 0 is
--                  as many as `usage_limit` allows everybody.
--   product_ids    JSON lists of the products, and of the terms (a category,
--   category_ids   a collection), it takes off; both empty is everything.
ALTER TABLE coupons
    ADD COLUMN IF NOT EXISTS starts_at     DATE         NULL DEFAULT NULL AFTER expires_at,
    ADD COLUMN IF NOT EXISTS free_shipping TINYINT(1)   NOT NULL DEFAULT 0 AFTER amount,
    ADD COLUMN IF NOT EXISTS exclude_sale  TINYINT(1)   NOT NULL DEFAULT 0 AFTER min_spend,
    ADD COLUMN IF NOT EXISTS per_customer  INT UNSIGNED NOT NULL DEFAULT 0 AFTER usage_limit,
    ADD COLUMN IF NOT EXISTS product_ids   TEXT         NULL DEFAULT NULL AFTER exclude_sale,
    ADD COLUMN IF NOT EXISTS category_ids  TEXT         NULL DEFAULT NULL AFTER product_ids;
