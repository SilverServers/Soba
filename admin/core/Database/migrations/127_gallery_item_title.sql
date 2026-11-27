-- Migration 127: a gallery's item carries a title, and a caption of up to
-- 1,000 characters (`Models\Gallery::setItems()`).
--
-- title    its own line above the caption, such as the place the picture
--          was taken: at most 200 characters, NULL for none.
-- caption  held 300 characters, and a longer one was cut short without a
--          word. It holds 1,000 now, and a longer one is refused with a
--          message, never shortened. Every caption already stored fits as
--          it is.
--
-- IF NOT EXISTS, as 091 and 122 are: a database that already has the column
-- is left as it is, and a caption already this wide is changed in nothing.
ALTER TABLE gallery_items
    ADD COLUMN IF NOT EXISTS title VARCHAR(200) NULL DEFAULT NULL AFTER position,
    MODIFY COLUMN caption VARCHAR(1000) NULL DEFAULT NULL;
