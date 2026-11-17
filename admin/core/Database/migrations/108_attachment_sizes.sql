-- Migration 108: the smaller widths made of a public image (`Media\Sizes`).
--
-- A list, as JSON, of each width's file beside the image:
-- [{"width": 768, "height": 512, "file": "…-768w.webp"}, …], narrowest first.
-- NULL for an image with none: not an image, private, already narrow, or
-- added before this, until its sizes are made.
--
-- IF NOT EXISTS, as 066 is: a database that already has the column is left as
-- it is.
ALTER TABLE attachments
    ADD COLUMN IF NOT EXISTS sizes TEXT NULL;
