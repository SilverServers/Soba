-- Migration 054: every live entry has a publish date.
--
-- Scheduling makes the date part of what "published" means: an entry is on the
-- site when its status is published AND its date has come
-- (Post::live(), `published_at <= UTC_TIMESTAMP()`). A published row with a
-- NULL date would therefore vanish from every list the moment this rule lands
-- — `NULL <= anything` is not true.
--
-- The model now refuses to leave one (Post::liveDate() on create, and the
-- follow-up statement in Post::update()), but rows written before it may exist:
-- the assistant created entries private with no date and could later publish
-- them through `update_core`, which carried the NULL across. Each such row gets
-- the moment it was created — the closest honest date there is.

UPDATE posts
   SET published_at = created_at
 WHERE status IN ('published', 'password')
   AND published_at IS NULL;
