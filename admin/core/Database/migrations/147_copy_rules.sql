-- Migration 147: what Copy makes of an entry of a Content Type made on the
-- Content Types screen (its Copies); a type registered in code says 'copy' in
-- its definition (`ContentType\CopyRules`).
--
-- copy_rules  JSON, {"suffix": "(copy)", "status": "private" | "same",
--             "skip": ["legacy_id", "legacy_*"]}: the words after a copy's
--             title, whether it keeps its original's status, and the fields
--             it never takes. NULL is the CMS's own: "(copy)", Private, every
--             field.
--
-- IF NOT EXISTS, as 145's and 146's: a site that made the column itself, with
-- this name, finds nothing to do here and keeps its values.
ALTER TABLE content_types
    ADD COLUMN IF NOT EXISTS copy_rules LONGTEXT NULL DEFAULT NULL AFTER slug_pattern;
