-- Migration 065: where each notification channel starts reading the log
-- (`Security\Alerts`).
--
-- A channel reports the `security_log` rows after its cursor. The email channel
-- is on by default, so on an install that already has a log, a cursor of 0 would
-- mail every lockout it ever had the first time anything happened. Each cursor
-- starts at the log's end instead; an install with no log starts at 0, which is
-- the same thing.
--
-- The settings themselves need no table: they are options, read by
-- `Alerts::settings()` with their defaults.

INSERT INTO options (option_key, option_value)
SELECT 'security_alerts_email_cursor', CAST(COALESCE(MAX(id), 0) AS CHAR) FROM security_log
ON DUPLICATE KEY UPDATE option_value = option_value;

INSERT INTO options (option_key, option_value)
SELECT 'security_alerts_slack_cursor', CAST(COALESCE(MAX(id), 0) AS CHAR) FROM security_log
ON DUPLICATE KEY UPDATE option_value = option_value;

INSERT INTO options (option_key, option_value)
SELECT 'security_alerts_webhook_cursor', CAST(COALESCE(MAX(id), 0) AS CHAR) FROM security_log
ON DUPLICATE KEY UPDATE option_value = option_value;
