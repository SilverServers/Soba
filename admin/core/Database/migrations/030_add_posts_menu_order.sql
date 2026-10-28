-- Migration 030: Add menu_order to posts.
-- Explicit sort position for Content Types where drag-reorder is the
-- primary ordering (portfolios, feature grids). Ordered ASC so lower
-- values appear first. Defaults to 0 so date-ordered CTs (news, blog)
-- can ignore the column without disruption.

ALTER TABLE posts
    ADD COLUMN menu_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER published_at,
    ADD INDEX idx_posts_menu_order (post_type, menu_order);
