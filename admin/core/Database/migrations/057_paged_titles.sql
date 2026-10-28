-- Migration 057: a page number in paged titles, and a pattern for term
-- archives.
--
-- Page 2 of an archive has its own canonical now, so it needs its own title.
-- Migration 021 seeded the archive and home patterns without {page_num}, so
-- every page of a list had page 1's title.
--
-- ONLY WHERE THE SEED IS UNTOUCHED. A pattern the owner has written is theirs;
-- this rewrites a row only while it still holds exactly what 021 put there.
-- The token is closed up on page 1 (Head::buildTitle()), so page 1's title does
-- not change.

UPDATE options
   SET option_value = '{content_type_label} | {page_num} | {site_name}'
 WHERE option_key = 'seo_pattern_archive'
   AND option_value = '{content_type_label} | {site_name}';

UPDATE options
   SET option_value = '{site_name} | {page_num} | {tagline}'
 WHERE option_key = 'seo_pattern_home'
   AND option_value = '{site_name} | {tagline}';

-- A term archive's title was the term's name and nothing else — no site name —
-- until it read a pattern (Public\TermController). Head::titlePatterns() holds
-- the same default; the row is what the SEO screen shows and saves.
INSERT IGNORE INTO options (option_key, option_value) VALUES
    ('seo_pattern_term', '{term_name} | {page_num} | {site_name}');
