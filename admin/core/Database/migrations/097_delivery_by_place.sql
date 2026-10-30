-- Migration 097: delivery by where it goes, and orders collected in person.
--
-- ── A ROW PER PLACE ─────────────────────────────────────────────────────────
-- A shop had one flat rate, free over a threshold, for every address
-- (`commerce_ship_mode`, `_flat`, `_free_over`). Its rows are now a list by
-- place (`Shipping`, the `commerce_ship_rates` option), read as tax rates are
-- read: a province's row, else its country's, else everywhere's. A shop that
-- charged a flat rate gets it as one row for everywhere, so every order is
-- charged as it was; a shop that charged nothing has no rows, which still
-- means nothing.
INSERT INTO options (option_key, option_value)
SELECT 'commerce_ship_rates',
       JSON_ARRAY(JSON_OBJECT(
           'country',   '',
           'region',    '',
           'rate',      CAST(COALESCE(f.option_value, '0') AS UNSIGNED),
           'free_over', CAST(COALESCE(o.option_value, '0') AS UNSIGNED)
       ))
  FROM options m
  LEFT JOIN options f ON f.option_key = 'commerce_ship_flat'
  LEFT JOIN options o ON o.option_key = 'commerce_ship_free_over'
 WHERE m.option_key = 'commerce_ship_mode'
   AND m.option_value = 'flat'
   AND NOT EXISTS (SELECT 1 FROM options x WHERE x.option_key = 'commerce_ship_rates');

-- ── AND HOW AN ORDER REACHES ITS CUSTOMER ───────────────────────────────────
-- "delivery" or "pickup" — blank for an order with nothing to deliver — and
-- the line its receipt prints: "Delivery to Ontario", "Collected: 12 Queen St
-- W, weekdays 10–4". The words are copied, so a pickup address changed next
-- year does not rewrite a receipt already sent.
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS shipping_method       VARCHAR(20)  NOT NULL DEFAULT '' AFTER shipping_total,
    ADD COLUMN IF NOT EXISTS shipping_method_title VARCHAR(255) NOT NULL DEFAULT '' AFTER shipping_method;
