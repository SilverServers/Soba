-- Migration 040: booked appointment slots.
--
-- The Appointment field offers the slots the owner is available for, and a slot
-- somebody has taken must stop being offered. That needs a row per booking, and
-- it needs the DATABASE to be the thing that says a slot is taken.
--
-- ── WHY THE UNIQUE INDEX IS THE WHOLE POINT ─────────────────────────────────
-- "Check whether it is free, then write it" is a race with a window between the
-- two halves, and the window is exactly when two people are looking at the same
-- popular slot. Both read "free", both write, and the calendar has two people in
-- one appointment — which nobody notices until they both turn up.
--
-- So the check is not in PHP. The insert either succeeds or violates this index,
-- and the second visitor is told the slot went while they were deciding. That is
-- a real answer to a real race rather than a smaller window.
--
-- ── WHAT `slot_key` IS ──────────────────────────────────────────────────────
-- form + field + the slot's start. The resource being booked is ONE FIELD on ONE
-- FORM: two different forms each keep their own calendar. That is the honest
-- scope of this version and it is a limitation worth stating out loud — a
-- business with two booking forms can still double-book itself, because nothing
-- here models "the business" as a resource. When that is needed the key gains a
-- calendar id and this comment is where to start.
--
-- ── ON DELETE CASCADE ───────────────────────────────────────────────────────
-- Deleting a submission frees its slot. The alternative is a calendar that fills
-- up with appointments nobody can look up, and an owner tidying their
-- submissions would silently take their own availability away.

CREATE TABLE IF NOT EXISTS form_appointments (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    -- UNSIGNED, matching `forms.id` and `form_submissions.id`. A signed INT
    -- here is a foreign key MySQL refuses to create at all (errno 150), which
    -- is the good outcome: the alternative is a column that looks like a
    -- reference and enforces nothing.
    form_id       INT UNSIGNED NOT NULL,
    submission_id INT UNSIGNED NULL,
    field_name    VARCHAR(64)  NOT NULL,
    slot_key      VARCHAR(160) NOT NULL,
    starts_at     DATETIME     NOT NULL,
    ends_at       DATETIME     NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_slot (slot_key),
    KEY idx_form_field (form_id, field_name, starts_at),

    CONSTRAINT fk_appt_form
        FOREIGN KEY (form_id) REFERENCES forms (id) ON DELETE CASCADE,
    CONSTRAINT fk_appt_submission
        FOREIGN KEY (submission_id) REFERENCES form_submissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
