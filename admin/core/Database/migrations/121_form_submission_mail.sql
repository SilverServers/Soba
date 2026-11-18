-- Migration 121: what happened to each email a submission sent.
--
-- `form_submissions.notified` says only whether the owner's notification went.
-- A site watching its leads needs more: the message's own id where the mail
-- service gave one, so a lead can be traced through that service's record of
-- it, or why it was refused, and when; for the first notification, for each
-- time it was sent again from the inbox, and for the confirmation to the
-- sender. One row each (`FormSubmission::recordMail()`), shown on the
-- submission in the inbox.
--
--   kind        notification | resend | confirmation | history (an import's)
--   transport   brevo | smtp | server | log | history
--   status      sent | failed, as this site saw it; a history import keeps
--               the word its old system wrote (delivered, pending, failed,
--               suppressed)
--   message_id  the id the transport gave the message, where it gave one
--   note        why it failed (a status and the start of the answer, never a
--               key), or the note a history import brings
--   sent_at     UTC, as every stored moment
--
-- ON DELETE CASCADE: a submission deleted takes its emails' record with it.
CREATE TABLE IF NOT EXISTS form_submission_mail (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- UNSIGNED, matching `form_submissions.id` (migration 040 says why).
    submission_id INT UNSIGNED NOT NULL,
    kind          VARCHAR(16)  NOT NULL,
    transport     VARCHAR(16)  NOT NULL DEFAULT '',
    status        VARCHAR(16)  NOT NULL,
    message_id    VARCHAR(255) NULL DEFAULT NULL,
    note          VARCHAR(500) NOT NULL DEFAULT '',
    sent_at       DATETIME     NOT NULL,
    KEY idx_form_submission_mail_sub (submission_id, sent_at),
    CONSTRAINT fk_form_submission_mail_sub FOREIGN KEY (submission_id) REFERENCES form_submissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
