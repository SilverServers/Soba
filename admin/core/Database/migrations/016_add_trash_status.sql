-- Add 'trash' to the status ENUM on posts and pages.
-- 'trash' is used for soft-deleted items; they are excluded from all public
-- and admin list queries but can be restored or permanently deleted.

ALTER TABLE posts
    MODIFY COLUMN status ENUM('draft','published','scheduled','trash') NOT NULL DEFAULT 'draft';

ALTER TABLE pages
    MODIFY COLUMN status ENUM('draft','published','trash') NOT NULL DEFAULT 'draft';
