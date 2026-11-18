-- Migration 123: a refused form's answers, carried back to its page.
--
-- A form sent without JavaScript and refused (a required answer missing, an
-- address mistyped, a page left open too long) goes back to the page it was
-- sent from, with each message under its field and every answer as typed
-- (`Models\FormReturn`). What goes back is kept here for minutes, under a
-- random key the browser holds in a cookie of its own: nothing personal is
-- written into an address, no visitor session is needed, and the row is
-- taken once, by the page that draws the form, or lapses.
--
--   token_hash  sha256 of the cookie's key; the key itself is never kept
--   answers     JSON, name => what was typed (text, or a list for a choice
--               of several); never a file, a drawing or a password
--   errors      JSON, name => the message for that field, '' => the form's
CREATE TABLE IF NOT EXISTS form_returns (
    token_hash  CHAR(64)     NOT NULL PRIMARY KEY,
    form_id     INT UNSIGNED NOT NULL,
    answers     MEDIUMTEXT   NOT NULL,
    errors      TEXT         NOT NULL,
    created_at  DATETIME     NOT NULL,
    KEY idx_form_returns_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
