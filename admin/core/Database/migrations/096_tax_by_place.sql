-- Migration 096: sales tax by where an order goes.
--
-- ── A ROW OF RATES PER PLACE, AND A LINE PER TAX ────────────────────────────
-- A shop charged one rate everywhere (migration 044). A Canadian shop owes
-- 13% HST on an Ontario order, 5% GST on an Alberta one, and GST and QST on
-- two lines for Quebec, so the rates are a list by place (`TaxRates`, kept in
-- the `commerce_tax_rates` option) and an order keeps a line for each tax it
-- was charged, with the name, the rate and the amount, as its receipt prints
-- them. A rate is in thousandths of a percent: 9.975% is 9975.
CREATE TABLE IF NOT EXISTS order_tax_lines (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id  INT UNSIGNED NOT NULL,
    label     VARCHAR(60)  NOT NULL,
    rate      INT UNSIGNED NOT NULL,
    amount    BIGINT       NOT NULL,
    position  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    KEY idx_order_tax_lines_order (order_id),
    CONSTRAINT fk_order_tax_lines_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── AND WHERE IT WAS TAXED ──────────────────────────────────────────────────
-- The country and province the rates were chosen for — the delivery address,
-- the billing one, or the shop's own for an order collected in person — so a
-- return for GST/HST can be totalled by province without working each order
-- out again from its addresses.
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS tax_country CHAR(2)     NOT NULL DEFAULT '' AFTER tax_inclusive,
    ADD COLUMN IF NOT EXISTS tax_region  VARCHAR(10) NOT NULL DEFAULT '' AFTER tax_country;

-- ── THE ONE RATE A SHOP HAD IS ITS FIRST ROW ────────────────────────────────
-- Charged everywhere, as it was, under the name it had: a shop that charged
-- 5% GST on every order still does until its owner adds the provinces. The
-- rate was in hundredths of a percent and is now in thousandths.
INSERT INTO options (option_key, option_value)
SELECT 'commerce_tax_rates',
       JSON_ARRAY(JSON_OBJECT(
           'country', '',
           'region',  '',
           'label',   COALESCE(NULLIF(TRIM(l.option_value), ''), 'Tax'),
           'rate',    CAST(r.option_value AS UNSIGNED) * 10
       ))
  FROM options r
  LEFT JOIN options l ON l.option_key = 'commerce_tax_label'
 WHERE r.option_key = 'commerce_tax_rate'
   AND CAST(r.option_value AS UNSIGNED) > 0
   AND NOT EXISTS (SELECT 1 FROM options x WHERE x.option_key = 'commerce_tax_rates');
