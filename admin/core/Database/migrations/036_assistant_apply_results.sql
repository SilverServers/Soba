-- 039 — record what applying a change set actually did.
--
-- Undo used to work off the stored *proposal*, which is only ever what the model
-- asked for. That is enough to reverse a field edit, and not enough for anything
-- the database decided at apply time:
--
--   * `add_style` has no id until the row exists, so undo could not find the rule
--     it had just created — it silently left it in place.
--   * `create_entry` has the same hole.
--   * A layout edit's previous file contents were computed by the applier and
--     then thrown away with the request.
--
-- Storing the applier's own results closes all of them at once, and closes them
-- for operations that do not exist yet.

ALTER TABLE assistant_messages
    ADD COLUMN apply_results LONGTEXT NULL AFTER change_set_errors;
