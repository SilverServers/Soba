-- Migration 100: how an order went, so the customer can follow it.
--
-- ── THE CARRIER, THE NUMBER AND WHERE TO FOLLOW IT ──────────────────────────
-- Written on the order by whoever posts it, and printed in the email that
-- tells the customer it is on its way. The link is the carrier's own page for
-- the parcel, pasted as the carrier gave it; nothing here builds one from a
-- number, because a carrier that moves its tracking page would break every
-- link built from the old pattern. Blank is an order not posted, or not
-- tracked.
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS tracking_carrier VARCHAR(60)  NOT NULL DEFAULT '' AFTER transaction_ref,
    ADD COLUMN IF NOT EXISTS tracking_number  VARCHAR(80)  NOT NULL DEFAULT '' AFTER tracking_carrier,
    ADD COLUMN IF NOT EXISTS tracking_url     VARCHAR(500) NOT NULL DEFAULT '' AFTER tracking_number;
