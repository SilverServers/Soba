-- Migration 095: options made from attributes, each with its own photo and sale.
--
-- ── ATTRIBUTES ARE TAXONOMIES ───────────────────────────────────────────────
-- Migration 046 kept a product's options as a list the owner types, sized for
-- a shop with a handful of them. A clothing shop sells a tee in five sizes and
-- three colours: fifteen rows typed one at a time, a single dropdown of
-- fifteen labels for the customer, and no way to show a sold-out XL in Moss as
-- anything but missing.
--
-- Which existing mechanism holds a size? A taxonomy: it has terms in an order
-- of the owner's choosing, a screen to manage them, a picker on the entry form
-- and an address for every term. `is_option` marks the taxonomies a customer
-- chooses from when buying — Size, Colour — as opposed to ones that only file
-- a product, like a category. A product's options are then the combinations of
-- the terms ticked on it, made in one press rather than typed.
ALTER TABLE taxonomies
    ADD COLUMN IF NOT EXISTS is_option TINYINT(1) NOT NULL DEFAULT 0 AFTER max_terms;

-- ── WHAT AN OPTION IS MADE OF, ITS PHOTO, AND ITS SALE ──────────────────────
-- `terms` is {taxonomy_key: term_id}: the option "M / Navy" is the Size term M
-- and the Colour term Navy. NULL for an option typed by hand, which still
-- works exactly as before. The label beside it stays, because an order line
-- copies the label and a term renamed next year must not rewrite a receipt.
--
-- `sale_price` is the option's own, for an option with a price of its own; an
-- option that takes the product's price takes its sale price too. NULL means no
-- sale, as `price` NULL means the product's.
--
-- `image_id` is the photo shown when it is chosen — a colour's photo, set once
-- for every option of that colour.
ALTER TABLE product_variations
    ADD COLUMN IF NOT EXISTS terms JSON NULL DEFAULT NULL AFTER label,
    ADD COLUMN IF NOT EXISTS sale_price BIGINT NULL DEFAULT NULL AFTER price,
    ADD COLUMN IF NOT EXISTS image_id INT UNSIGNED NULL DEFAULT NULL AFTER stock_status;

-- ── AND THE PRODUCT'S OWN NEW FIELDS, ON THE TYPES THAT ALREADY EXIST ───────
-- A product type's fields were copied from `ArchetypeDefaults` when the type
-- was made, so fields added there reach only types made from now on. Every
-- existing product group gets them too, in the place a new type has them,
-- unless it already has one of that key.
--
-- THE SALE'S DAYS GO UNDER THE SALE PRICE, where an owner setting a sale looks
-- for them: every field after it moves down two places to make room. Fields
-- are drawn in `sort_order` alone, so the two need places of their own rather
-- than a tie with the price.
UPDATE field_group_fields f
  JOIN field_groups g ON g.id = f.group_id AND g.archetype_key = 'product'
  JOIN field_group_fields s ON s.group_id = f.group_id AND s.field_key = 'product_sale_price'
  LEFT JOIN field_group_fields d ON d.group_id = f.group_id AND d.field_key = 'product_sale_from'
   SET f.sort_order = f.sort_order + 2
 WHERE f.sort_order > s.sort_order
   AND d.id IS NULL;

INSERT INTO field_group_fields (group_id, field_key, field_label, field_type, sort_order, is_required, settings)
SELECT s.group_id, 'product_sale_from', 'Sale starts', 'date', s.sort_order + 1, 0, JSON_OBJECT('width', 'half')
  FROM field_group_fields s
  JOIN field_groups g ON g.id = s.group_id AND g.archetype_key = 'product'
 WHERE s.field_key = 'product_sale_price'
   AND NOT EXISTS (SELECT 1 FROM field_group_fields f WHERE f.group_id = s.group_id AND f.field_key = 'product_sale_from');

INSERT INTO field_group_fields (group_id, field_key, field_label, field_type, sort_order, is_required, settings)
SELECT s.group_id, 'product_sale_to', 'Sale ends', 'date', s.sort_order + 2, 0, JSON_OBJECT('width', 'half')
  FROM field_group_fields s
  JOIN field_groups g ON g.id = s.group_id AND g.archetype_key = 'product'
 WHERE s.field_key = 'product_sale_price'
   AND NOT EXISTS (SELECT 1 FROM field_group_fields f WHERE f.group_id = s.group_id AND f.field_key = 'product_sale_to');

-- A group whose owner took its sale price out still gets the days, at its end.
INSERT INTO field_group_fields (group_id, field_key, field_label, field_type, sort_order, is_required, settings)
SELECT g.id, 'product_sale_from', 'Sale starts', 'date',
       COALESCE((SELECT MAX(f.sort_order) FROM field_group_fields f WHERE f.group_id = g.id), 0) + 1,
       0, JSON_OBJECT('width', 'half')
  FROM field_groups g
 WHERE g.archetype_key = 'product'
   AND NOT EXISTS (SELECT 1 FROM field_group_fields f WHERE f.group_id = g.id AND f.field_key = 'product_sale_from');

INSERT INTO field_group_fields (group_id, field_key, field_label, field_type, sort_order, is_required, settings)
SELECT g.id, 'product_sale_to', 'Sale ends', 'date',
       COALESCE((SELECT MAX(f.sort_order) FROM field_group_fields f WHERE f.group_id = g.id), 0) + 1,
       0, JSON_OBJECT('width', 'half')
  FROM field_groups g
 WHERE g.archetype_key = 'product'
   AND NOT EXISTS (SELECT 1 FROM field_group_fields f WHERE f.group_id = g.id AND f.field_key = 'product_sale_to');

INSERT INTO field_group_fields (group_id, field_key, field_label, field_type, sort_order, is_required, settings)
SELECT g.id, 'product_gallery', 'More photos', 'repeater',
       COALESCE((SELECT MAX(f.sort_order) FROM field_group_fields f WHERE f.group_id = g.id), 0) + 1,
       0, JSON_OBJECT('sub_fields', JSON_ARRAY(JSON_OBJECT('key', 'image', 'label', 'Photo', 'type', 'image')))
  FROM field_groups g
 WHERE g.archetype_key = 'product'
   AND NOT EXISTS (SELECT 1 FROM field_group_fields f WHERE f.group_id = g.id AND f.field_key = 'product_gallery');
