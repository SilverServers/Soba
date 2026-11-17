-- Migration 107: a submission can be a test, and its conversion is taken once.
--
-- `is_test` marks a submission sent from the form's test address: it went
-- through everything a real one does, and its notification went to the test
-- inbox alone (`Forms\Notify`). The inbox shows it and can list tests alone or
-- leave them out.
--
-- `conversion_taken_at` is when a page took the conversion a form's
-- after-sending page carried for it (`Site\Google::conversion()`). The carried
-- value names the submission, and taking it sets this once, so a second page
-- that brings the same value (a reload, another tab, a copied cookie) gets
-- nothing.
--
-- The key answers "does this list hold any tests?" from a few index entries,
-- so the inbox offers its filter only where there is something to filter,
-- however many submissions there are; and it lists tests alone the same way.
--
-- IF NOT EXISTS, as 066 is: a database that has either column or the key
-- already, with this name and type, is left as it is.
ALTER TABLE form_submissions
    ADD COLUMN IF NOT EXISTS is_test             TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read,
    ADD COLUMN IF NOT EXISTS conversion_taken_at DATETIME   NULL DEFAULT NULL AFTER notified,
    ADD KEY IF NOT EXISTS idx_form_submissions_test (is_test, status, form_id, submitted_at);
