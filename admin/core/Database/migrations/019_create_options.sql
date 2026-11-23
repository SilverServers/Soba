-- Migration 019: Create options table for site-wide key/value settings
CREATE TABLE IF NOT EXISTS options (
    option_key   VARCHAR(191) NOT NULL PRIMARY KEY,
    option_value TEXT NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default settings
INSERT INTO options (option_key, option_value) VALUES
    ('site_name',    ''),
    ('site_tagline', ''),
    ('admin_email',  ''),
    ('timezone',     'America/Vancouver'),
    ('date_format',  'M j, Y'),
    ('time_format',  'g:i A'),
    ('permalink_structure', '/{content_type}/{slug}'),
    ('smtp_host',    ''),
    ('smtp_port',    '587'),
    ('smtp_username', ''),
    ('smtp_password', ''),
    ('smtp_encryption', 'tls'),
    ('smtp_from_email', ''),
    ('smtp_from_name',  '');
