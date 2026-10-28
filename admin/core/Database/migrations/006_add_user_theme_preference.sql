-- Migration 006: Add theme_preset to users table
-- Each user can store a named theme preset (e.g. 'silverservers-dark', 'core-light')
-- Default is 'silverservers-dark' to match the existing hardcoded default.

ALTER TABLE users
    ADD COLUMN theme_preset VARCHAR(50) NOT NULL DEFAULT 'silverservers-dark' AFTER role;
