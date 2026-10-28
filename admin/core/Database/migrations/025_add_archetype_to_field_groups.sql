-- Migration 025: Add archetype tracking columns to field_groups
-- Enables the archetype engine to auto-create, activate, and deactivate
-- default field groups when content types are created or edited.

ALTER TABLE field_groups
    ADD COLUMN is_archetype TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
    ADD COLUMN archetype_key VARCHAR(20) DEFAULT NULL AFTER is_archetype;
