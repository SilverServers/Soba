-- 034 — a proposal can now be superseded by a later one.
--
-- Multi-turn refinement: "actually make Team $189" should amend the pending
-- proposal rather than start a second one. The model re-proposes the COMPLETE
-- revised change set, and the earlier proposal is marked `superseded` — kept in
-- the transcript for the record, but no longer approvable.
--
-- Without this there would be two `pending` diffs in one conversation and no
-- indication of which one the owner actually meant, which is precisely the sort
-- of ambiguity that must never reach the apply path.

ALTER TABLE assistant_messages
    MODIFY change_set_status
        ENUM('pending','applied','discarded','invalid','superseded') NULL;
