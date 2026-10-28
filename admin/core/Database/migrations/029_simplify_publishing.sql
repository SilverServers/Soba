-- Simplify publishing: merge status + visibility into a single status column.
-- New status ENUM: published, password, private, hidden, trash

-- ── Posts ─────────────────────────────────────────────────────────────────

-- Step 1: Migrate existing data into unified status values
UPDATE posts SET status = 'password' WHERE status = 'published' AND visibility = 'password';
UPDATE posts SET status = 'private'  WHERE status = 'published' AND visibility = 'private';

-- 'draft' and 'scheduled' both become 'hidden' (not yet valid ENUM values,
-- so we do this BEFORE altering the column — MySQL allows UPDATE to a value
-- that will be in the new ENUM as long as we ALTER in the same migration).
-- Actually, we need a temp column approach because MySQL won't accept values
-- outside the current ENUM. Use a staging column instead.

-- Create temp column with the new ENUM
ALTER TABLE posts
    ADD COLUMN status_new ENUM('published','password','private','hidden','trash') NOT NULL DEFAULT 'hidden' AFTER status;

-- Map old values to new
UPDATE posts SET status_new = CASE
    WHEN status = 'trash' THEN 'trash'
    WHEN status = 'published' AND visibility = 'password' THEN 'password'
    WHEN status = 'published' AND visibility = 'private'  THEN 'private'
    WHEN status = 'published' THEN 'published'
    ELSE 'hidden'
END;

-- Drop old columns, rename new
ALTER TABLE posts DROP COLUMN status;
ALTER TABLE posts DROP COLUMN visibility;
ALTER TABLE posts CHANGE COLUMN status_new status ENUM('published','password','private','hidden','trash') NOT NULL DEFAULT 'hidden';

-- ── Pages ─────────────────────────────────────────────────────────────────

ALTER TABLE pages
    ADD COLUMN status_new ENUM('published','password','private','hidden','trash') NOT NULL DEFAULT 'hidden' AFTER status;

UPDATE pages SET status_new = CASE
    WHEN status = 'trash' THEN 'trash'
    WHEN status = 'published' AND visibility = 'password' THEN 'password'
    WHEN status = 'published' AND visibility = 'private'  THEN 'private'
    WHEN status = 'published' THEN 'published'
    ELSE 'hidden'
END;

ALTER TABLE pages DROP COLUMN status;
ALTER TABLE pages DROP COLUMN visibility;
ALTER TABLE pages CHANGE COLUMN status_new status ENUM('published','password','private','hidden','trash') NOT NULL DEFAULT 'hidden';
