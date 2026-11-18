-- Migration 122: a file the site already has, in the library where it is
-- (`Models\Media::register()`).
--
-- `site_path` is the file's own address under the web root, such as
-- /img/blog/x.jpg. The library names that file and never moves, renames or
-- re-encodes it, so the address the site has always used stays the picture's
-- address; its narrower copies beside it (x-480.jpg) are listed in `sizes`.
-- NULL for a file the library keeps in uploads/, which every file before this
-- is. Unique: a file is in the library once.
--
-- IF NOT EXISTS, as 066, 107 and 120 are: a database that already has the
-- column or the key is left as it is.
ALTER TABLE attachments
    ADD COLUMN IF NOT EXISTS site_path VARCHAR(500) NULL DEFAULT NULL AFTER filename,
    ADD UNIQUE KEY IF NOT EXISTS uq_attachments_site_path (site_path);
