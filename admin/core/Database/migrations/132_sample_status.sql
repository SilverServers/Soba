-- Migration 132: Sample, a status for an entry that stands in for content
-- still to come, so a layout can be seen before the real content exists
-- (`Models\Post::SAMPLE`): shown as Published on a copy of the site that is
-- not production, and never in production or in a static copy, where it is
-- Private and System health lists it.
--
-- Entries only: a page has no Sample, so `pages.status` keeps its list. The
-- new value goes before `trash`, in the order `Ui\Status::registry()` lists
-- the statuses; no row holds it yet, so nothing is remapped first (043).
ALTER TABLE posts
    MODIFY COLUMN status ENUM('published','password','private','sample','trash')
    NOT NULL DEFAULT 'private';
