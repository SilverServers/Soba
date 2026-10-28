-- Migration 015: Add featured_image_id to posts table.
-- Stores the attachment ID of the featured/thumbnail image for a post.
-- ON DELETE SET NULL clears the reference if the attachment is deleted.

ALTER TABLE posts
    ADD COLUMN featured_image_id INT UNSIGNED NULL DEFAULT NULL AFTER password_hash,
    ADD CONSTRAINT fk_posts_feat_image
        FOREIGN KEY (featured_image_id) REFERENCES attachments (id) ON DELETE SET NULL;
