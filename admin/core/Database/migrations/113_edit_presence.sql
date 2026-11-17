-- Migration 113: who has a page or an entry open in its editor.
--
-- One row a person a page or entry (`Content\Presence`): when they opened it,
-- and when they were last seen in it (opening it, writing in it, saving it).
-- Another person opening the same screen is told who is in it. A row older
-- than the presence window is no one; leaving the editor deletes it.
CREATE TABLE IF NOT EXISTS edit_presence (
    object_type VARCHAR(8)   NOT NULL,
    object_id   INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    opened_at   DATETIME     NOT NULL,
    seen_at     DATETIME     NOT NULL,
    writing_at  DATETIME     NULL DEFAULT NULL,
    PRIMARY KEY (object_type, object_id, user_id),
    KEY idx_edit_presence_seen (seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
