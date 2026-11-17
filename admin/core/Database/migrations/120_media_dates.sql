-- Migration 120: when a photo was taken, and keys for the library's orders.
--
-- `taken_at` is the date the camera wrote into the photo (EXIF
-- DateTimeOriginal), in UTC like every stored moment. It is read when the
-- file enters the library, before the copy that is kept loses its metadata
-- (`Media\Exif`), and NULL where the file said nothing. "Date taken" orders
-- the library by it, falling back to `created_at`, the date the file was
-- added.
--
-- The two keys serve the orders a large library pays for on every visit:
-- newest first (the default) and largest first. Measured on 100,000 files,
-- the first page of either came from a sort of every row (29 ms, and 162 ms
-- with the Images tab) and now comes from the key (under 1 ms). The other
-- orders sort on an expression no key can hold, and stay under 30 ms on
-- 10,000 files.
--
-- IF NOT EXISTS, as 066 and 107 are: a database that already has the column
-- or a key with this name is left as it is.
ALTER TABLE attachments
    ADD COLUMN IF NOT EXISTS taken_at DATETIME NULL DEFAULT NULL AFTER alt_text,
    ADD KEY IF NOT EXISTS idx_attachments_created (created_at, id),
    ADD KEY IF NOT EXISTS idx_attachments_size (file_size);
