-- 035 — an applied change set can be undone from the proposal that caused it.
--
-- Every applied operation already snapshots the object it touches, tagged with
-- the change set id, so the data to reverse it was always there — but the only
-- way to reach it was the Revisions card on each individual entry, picking the
-- right snapshot by hand. That is not something a non-technical site owner will
-- do, which made the undo effectively theoretical.
--
-- `undone` records that the reversal happened, so the transcript reads
-- proposed → applied → undone rather than silently reverting to "applied" with
-- content that no longer matches the diff above it.

ALTER TABLE assistant_messages
    MODIFY change_set_status
        ENUM('pending','applied','discarded','invalid','superseded','undone') NULL;
