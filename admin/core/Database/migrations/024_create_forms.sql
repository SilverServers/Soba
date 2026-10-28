-- Migration 024: Create forms and form_submissions tables

CREATE TABLE IF NOT EXISTS forms (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(200)  NOT NULL,
    fields      JSON          DEFAULT NULL,
    settings    JSON          DEFAULT NULL,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_submissions (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id      INT UNSIGNED NOT NULL,
    data         JSON         DEFAULT NULL,
    is_read      TINYINT(1)   NOT NULL DEFAULT 0,
    ip_address   VARCHAR(45)  DEFAULT NULL,
    submitted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_form_id (form_id),
    INDEX idx_is_read (is_read),
    CONSTRAINT fk_form_submissions_form FOREIGN KEY (form_id) REFERENCES forms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
