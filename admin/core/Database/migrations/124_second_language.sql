-- Migration 124: a page or an entry in the site's second language.
--
-- A translation is a row like any other, so revisions, share links, the
-- editing lock and unpublish dates work for it unchanged. Two columns say
-- which language a row is in and which row it translates:
--
--   lang            '' for the site's own language, else the second
--                   language as its addresses write it (`fr` in /fr/a-propos).
--                   Every row before this is in the site's own language.
--   translation_of  the row it is a translation of, or NULL. Deleting that
--                   row leaves the translation, unlinked (ON DELETE SET NULL);
--                   one translation per row per language.
--
-- A slug is unique within its language, not across the whole table, so
-- /fr/contact can sit beside /contact. An entry's slug stays unique across
-- every type within its language, as it was across every type before.
--
-- IF [NOT] EXISTS, as 091 and 122 are: a database that already has a column,
-- key or constraint is left as it is.
ALTER TABLE posts
    ADD COLUMN IF NOT EXISTS lang VARCHAR(16) NOT NULL DEFAULT '' AFTER slug,
    -- UNSIGNED, matching `posts.id`.
    ADD COLUMN IF NOT EXISTS translation_of INT UNSIGNED NULL DEFAULT NULL AFTER lang,
    DROP INDEX IF EXISTS uq_posts_slug,
    ADD UNIQUE KEY IF NOT EXISTS uq_posts_lang_slug (lang, slug),
    ADD UNIQUE KEY IF NOT EXISTS uq_posts_translation (translation_of, lang),
    ADD CONSTRAINT fk_posts_translation FOREIGN KEY IF NOT EXISTS (translation_of)
        REFERENCES posts (id) ON DELETE SET NULL;

ALTER TABLE pages
    ADD COLUMN IF NOT EXISTS lang VARCHAR(16) NOT NULL DEFAULT '' AFTER slug,
    ADD COLUMN IF NOT EXISTS translation_of INT UNSIGNED NULL DEFAULT NULL AFTER lang,
    DROP INDEX IF EXISTS uq_pages_slug,
    ADD UNIQUE KEY IF NOT EXISTS uq_pages_lang_slug (lang, slug),
    ADD UNIQUE KEY IF NOT EXISTS uq_pages_translation (translation_of, lang),
    ADD CONSTRAINT fk_pages_translation FOREIGN KEY IF NOT EXISTS (translation_of)
        REFERENCES pages (id) ON DELETE SET NULL;
