-- Migration 047: an entry that belongs to another entry.
--
-- A content model such as News, Events, Clubs, CLUB EVENTS, Board of Directors
-- needs parent/child nesting. Without it, "Club Events belong to a Club" can
-- only ever be a relationship FIELD. An association, not a nesting.
--
-- ── TWO COLUMNS, BECAUSE THERE ARE TWO QUESTIONS ────────────────────────────
-- `content_types.parent_type`  which KIND of thing owns this kind of thing.
--                              Declared once, by the person who defines the
--                              type. Club Events belong to Clubs.
-- `posts.parent_id`            WHICH ONE owns this one. Chosen per entry, by
--                              whoever writes it. This social belongs to the
--                              Chess Club.
--
-- A single column cannot answer both. Without the type-level declaration the
-- entry screen has no list to offer — it would have to show every entry of
-- every type, which for a site with four hundred of them is not a control.
-- Without the entry-level column the declaration says nothing about any
-- particular thing.
--
-- ── WHY `posts.parent_id` IS SELF-REFERENTIAL AND NOT A TYPED PAIR ──────────
-- The parent's TYPE is already known: it is whatever the child's type declares.
-- Storing it again on every row would be a second copy of one fact, and the two
-- can disagree — a Club Event whose `parent_id` points at a News entry is a row
-- no screen can render and no rule can catch. One column, and the type-level
-- declaration is what says what it may point at.
--
-- ── ON DELETE SET NULL, NOT CASCADE ─────────────────────────────────────────
-- `pages.parent_id` is SET NULL and this follows it deliberately. Deleting a
-- Club must not silently delete its events: they are separate things the owner
-- wrote separately, and a cascade means one confirm dialog quietly removes work
-- that was never named in it. Orphaned children surface at the top of their own
-- list, where they can be seen and re-parented.
--
-- ── WHAT THIS DOES *NOT* DO: THE PUBLIC ADDRESS ─────────────────────────────
-- A child still lives at `/{public_slug}/{its-own-slug}`. Nothing in the URL
-- changes and no route is added.
--
-- That is the decision `pages` already made, with the reasoning written into
-- `admin/admin/templates/pages/list.php`: "A child page still lives at
-- `/its-own-slug`. `parent_id` orders the menu and nothing else." Nesting means
-- one thing in this admin — whose it is, and where it sits in a list — and
-- making it mean a second thing for Content Types only would be two mechanisms
-- wearing one word. Slug uniqueness therefore stays per type, unchanged.

ALTER TABLE posts
    ADD COLUMN parent_id INT UNSIGNED NULL DEFAULT NULL AFTER id,
    ADD CONSTRAINT fk_posts_parent FOREIGN KEY (parent_id)
        REFERENCES posts (id) ON DELETE SET NULL;

-- The child list of one parent is the query every nested screen runs, and it
-- runs it once per parent row. Without this it is a full scan of `posts` per
-- parent on a screen that draws many of them.
CREATE INDEX idx_posts_parent ON posts (parent_id);

ALTER TABLE content_types
    ADD COLUMN parent_type VARCHAR(50) NULL DEFAULT NULL AFTER public_slug;

-- NO FOREIGN KEY ON `parent_type`, and this is not an oversight. `Registry`
-- serves types from BOTH the table and `config/content-types.php`, so anything
-- asking "what exists?" asks the Registry, not the table. A constraint against
-- `content_types.type_key` would refuse a parent that is registered in code,
-- which would make one way of creating a type a privileged back door. The
-- Registry validates it instead, where it can see both.
