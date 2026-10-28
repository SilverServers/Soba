-- Migration 031: Revision history for posts and pages.
--
-- Until now `posts` and `post_meta` stored only current state — nothing recorded
-- what a field held before it was changed, so no edit was reversible. That is a
-- gap in the CMS generally, and a hard blocker for the Assistant: an
-- agent must never make a change the owner cannot undo.
--
-- Design notes:
--
--  * One row per saved version, holding a full snapshot of the core columns plus
--    a JSON blob of every post_meta key at that moment. Snapshotting whole
--    versions rather than diffs keeps restore trivial (write the row back) at the
--    cost of storage, which is the right trade for text content.
--
--  * `object_type` + `object_id` rather than a FK, because revisions cover both
--    `posts` and `pages`, which are separate tables. Deletion is handled in the
--    application layer (Revision::deleteFor) — MySQL cannot express a FK to one
--    of two tables.
--
--  * `change_set_id` is nullable and unused for now. When the Assistant lands,
--    every revision it causes will carry the id of the change set the owner
--    approved, so "what did the assistant change, and on whose instruction" is
--    answerable from this table alone.
--
--  * `author_id` is nullable and ON DELETE SET NULL: history must outlive the
--    user account that made it.

CREATE TABLE revisions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    object_type   ENUM('post','page') NOT NULL,
    object_id     INT UNSIGNED        NOT NULL,

    -- Snapshot of the core columns. Nullable so this table can serve both
    -- `posts` (post_type, published_at) and `pages` (parent_id, seo fields).
    title             VARCHAR(500) NOT NULL,
    slug              VARCHAR(500) NOT NULL,
    content           LONGTEXT     NULL,
    status            VARCHAR(20)  NOT NULL,
    featured_image_id INT UNSIGNED NULL,
    menu_order        INT UNSIGNED NOT NULL DEFAULT 0,
    post_type         VARCHAR(50)  NULL,
    parent_id         INT UNSIGNED NULL,
    published_at      DATETIME     NULL,
    seo_title         VARCHAR(500) NULL,
    meta_description  TEXT         NULL,

    -- Every post_meta row for the object at snapshot time, as {key: value}.
    meta_json     LONGTEXT NULL,

    -- Why this revision exists: 'manual' (a normal save), 'restore' (rolled back
    -- to an earlier revision), or 'assistant' (applied from an approved change set).
    reason        ENUM('manual','restore','assistant') NOT NULL DEFAULT 'manual',
    note          VARCHAR(500) NULL,
    change_set_id VARCHAR(64)  NULL,

    author_id     INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_revisions_object (object_type, object_id, created_at),
    INDEX idx_revisions_change_set (change_set_id),

    CONSTRAINT fk_revisions_author
        FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
