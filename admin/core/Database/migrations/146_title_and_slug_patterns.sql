-- Migration 146: the patterns a Content Type's titles and addresses are made
-- from, for a type made on the Content Types screen ("Titles and
-- addresses"); a type registered in code says 'title_pattern' and
-- 'slug_pattern' in its definition (`ContentType\Patterns`).
--
-- title_pattern  the title of an entry whose title is left blank, in the
--                token form search titles use: '{tax:location} report,
--                {report_date:F j, Y}'. NULL is none.
-- slug_pattern   the address of an entry whose address is not typed:
--                '{id}-{tax:location}-report-{report_date}'. NULL is none.
--
-- IF NOT EXISTS, as 145's: a site that made the columns itself, with these
-- names, finds nothing to do here and keeps its values.
ALTER TABLE content_types
    ADD COLUMN IF NOT EXISTS title_pattern VARCHAR(255) NULL DEFAULT NULL AFTER children,
    ADD COLUMN IF NOT EXISTS slug_pattern  VARCHAR(255) NULL DEFAULT NULL AFTER title_pattern;
