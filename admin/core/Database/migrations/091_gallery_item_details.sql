-- Migration 091: a gallery's item carries a caption, a link and a
-- thumbnail of its own (a site's own change, brought into
-- core). A portfolio's tiles use all three.
--
-- caption              under the picture, as words
-- link_url             where the picture goes, or, with the lightbox on, where
--                      its caption goes (the picture then opens in full)
-- thumb_attachment_id  what the grid shows when it is not the picture itself.
--                      Deleting that file puts the picture itself back.
--
-- IF NOT EXISTS throughout. A site may have made these columns and the key
-- with these names, and its update must find nothing to do here. MariaDB
-- 10.11 on both installs, as 066 says.
ALTER TABLE gallery_items
    ADD COLUMN IF NOT EXISTS caption             VARCHAR(300) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS link_url            VARCHAR(500) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS thumb_attachment_id INT UNSIGNED NULL DEFAULT NULL,
    ADD CONSTRAINT fk_gallery_items_thumb FOREIGN KEY IF NOT EXISTS (thumb_attachment_id)
        REFERENCES attachments (id) ON DELETE SET NULL;
