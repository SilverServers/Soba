-- Migration 039: when a redirect last fired.
--
-- `hit_count` alone answers "has this ever been used" and nothing else. A rule
-- with 1,788 hits that last fired two years ago and one that fired this morning
-- read identically, and they are opposite decisions: the first is safe to
-- delete, the second is load-bearing. Tools → Redirects lists a Last access
-- column because that is the column the screen is FOR — deciding which rules
-- still earn their place.
--
-- NULL means never, and it stays NULL rather than defaulting to the row's
-- creation time: "added last week, never once followed" is the shape of a rule
-- typed wrong, and a default would hide exactly that.

ALTER TABLE redirects
    ADD COLUMN last_accessed_at DATETIME NULL DEFAULT NULL AFTER hit_count;
