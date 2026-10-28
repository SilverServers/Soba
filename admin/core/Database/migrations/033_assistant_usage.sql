-- Migration 033: Assistant token usage.
--
-- Per-message token counts, so spend is attributable rather than a single opaque
-- monthly figure. Without this there is no way to answer "what did that cost" or
-- to enforce a ceiling before the next call.
--
-- Stored on the message rather than a separate ledger: usage belongs to the turn
-- that incurred it, and cascades away with the conversation.

ALTER TABLE assistant_messages
    ADD COLUMN input_tokens  INT UNSIGNED NOT NULL DEFAULT 0 AFTER tools_used,
    ADD COLUMN output_tokens INT UNSIGNED NOT NULL DEFAULT 0 AFTER input_tokens,
    ADD INDEX idx_assistant_messages_created (created_at);
