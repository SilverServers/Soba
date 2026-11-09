-- Migration 104: when an unpaid order's customer was reminded to pay.
--
-- Set once, the day before the order lapses (`Commerce\Lapse`), by the write
-- that claims the reminder: two visits at once send it once.
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS payment_reminded_at DATETIME NULL DEFAULT NULL AFTER tracking_url;
