-- Migration 066: a submission has a status, and spam keeps its reason — and
-- what was discarded as certain spam is counted, never stored.
--
-- `status` is 'received' or 'spam'. A spam row keeps why it was turned away,
-- and every row keeps the page it was sent from, the browser, the hash of the
-- time-trap token it carried (so one page load can carry one lead) and whether
-- the notification email went (NULL: none was due). See `Forms\Guard`.
--
-- IF NOT EXISTS THROUGHOUT. A site that made this change itself may have every
-- column and key below, from a migration of its own, with these exact names
-- and types; on that database this statement must change nothing that is
-- already there. MariaDB has had the clause since 10.0.2.
ALTER TABLE form_submissions
    ADD COLUMN IF NOT EXISTS status      VARCHAR(16)  NOT NULL DEFAULT 'received' AFTER data,
    ADD COLUMN IF NOT EXISTS spam_reason VARCHAR(255) NULL DEFAULT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS source_page VARCHAR(500) NULL DEFAULT NULL AFTER spam_reason,
    ADD COLUMN IF NOT EXISTS user_agent  VARCHAR(300) NULL DEFAULT NULL AFTER ip_address,
    ADD COLUMN IF NOT EXISTS token_hash  CHAR(64)     NULL DEFAULT NULL AFTER user_agent,
    ADD COLUMN IF NOT EXISTS notified    TINYINT(1)   NULL DEFAULT NULL AFTER token_hash,
    ADD KEY IF NOT EXISTS idx_form_submissions_status (status, submitted_at),
    ADD KEY IF NOT EXISTS idx_form_submissions_ip (ip_address, submitted_at),
    ADD KEY IF NOT EXISTS idx_form_submissions_token (token_hash),
    -- One form's inbox or spam, newest first, a page at a time. Without it a
    -- form's list reads every row the form ever had through `idx_form_id` and
    -- sorts them: a Spam view of 245,096 rows timed out.
    ADD KEY IF NOT EXISTS idx_form_submissions_form_status (form_id, status, submitted_at);

-- WHAT WAS DISCARDED, AS A NUMBER. A post the guard is certain is not a person
-- (a decoy filled, a forged token, a post from another site) is not stored —
-- of one day's 245,096 posts, 240,970 were caught by a single decoy — but
-- every one adds to its day's count here, by form and by reason, so a rule
-- that starts catching real people still shows up somewhere a person looks.
-- No payload and no address: a count is all it keeps.
CREATE TABLE IF NOT EXISTS form_spam_counts (
    form_id INT UNSIGNED  NOT NULL,
    day     DATE          NOT NULL,
    reason  VARCHAR(32)   NOT NULL,
    hits    INT UNSIGNED  NOT NULL DEFAULT 0,
    PRIMARY KEY (form_id, day, reason),
    KEY idx_form_spam_counts_day (day),
    CONSTRAINT fk_form_spam_counts_form FOREIGN KEY (form_id) REFERENCES forms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
