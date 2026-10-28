-- Migration 014: Add visibility, password, featured image, and SEO columns to pages table.
-- Pages do not have a meta table, so SEO fields are stored as real columns.
-- featured_image_id references attachments; ON DELETE SET NULL clears the reference if the attachment is deleted.

ALTER TABLE pages
    ADD COLUMN visibility        ENUM('public','password','private') NOT NULL DEFAULT 'public' AFTER status,
    ADD COLUMN password_hash     VARCHAR(255)  NULL DEFAULT NULL AFTER visibility,
    ADD COLUMN featured_image_id INT UNSIGNED  NULL DEFAULT NULL AFTER password_hash,
    ADD COLUMN seo_title         VARCHAR(500)  NULL DEFAULT NULL AFTER featured_image_id,
    ADD COLUMN meta_description  TEXT          NULL DEFAULT NULL AFTER seo_title,
    ADD CONSTRAINT fk_pages_feat_image
        FOREIGN KEY (featured_image_id) REFERENCES attachments (id) ON DELETE SET NULL;
