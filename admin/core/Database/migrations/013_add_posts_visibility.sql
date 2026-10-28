-- Migration 013: Add visibility and password_hash columns to posts table.
-- visibility: public (default) | password (requires password) | private (auth only)
-- password_hash: bcrypt hash of the protection password; NULL when not password-protected

ALTER TABLE posts
    ADD COLUMN visibility    ENUM('public','password','private') NOT NULL DEFAULT 'public' AFTER status,
    ADD COLUMN password_hash VARCHAR(255) NULL DEFAULT NULL AFTER visibility;
