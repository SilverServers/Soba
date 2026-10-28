-- Migration 059: the separator becomes a variable, {sep}.
--
-- The title patterns carried their separator literally — "{title} | {site_name}"
-- — so `seo_separator` (seeded by 021) had nothing reading it, and the SEO screen
-- left it off rather than offer a control that did nothing. The patterns are now
-- edited as pills, and the defaults put a Separator pill between the parts;
-- Settings → SEO → Title patterns sets which character it prints.
--
-- ONLY WHERE THE SEED IS UNTOUCHED. A pattern the owner has written is theirs;
-- this rewrites a row only while it still holds exactly what 021 or 057 put
-- there. The separator was seeded as " | " and is stored as the character now;
-- `{sep}` prints "|", so every rewritten title reads exactly as it did.

UPDATE options
   SET option_value = '|'
 WHERE option_key = 'seo_separator'
   AND option_value = ' | ';

UPDATE options
   SET option_value = '{title} {sep} {site_name}'
 WHERE option_key = 'seo_pattern_page'
   AND option_value = '{title} | {site_name}';

UPDATE options
   SET option_value = '{title} {sep} {content_type_label} {sep} {site_name}'
 WHERE option_key = 'seo_pattern_content_type'
   AND option_value = '{title} | {content_type_label} | {site_name}';

UPDATE options
   SET option_value = '{content_type_label} {sep} {page_num} {sep} {site_name}'
 WHERE option_key = 'seo_pattern_archive'
   AND option_value = '{content_type_label} | {page_num} | {site_name}';

UPDATE options
   SET option_value = '{term_name} {sep} {page_num} {sep} {site_name}'
 WHERE option_key = 'seo_pattern_term'
   AND option_value = '{term_name} | {page_num} | {site_name}';

UPDATE options
   SET option_value = '{site_name} {sep} {page_num} {sep} {tagline}'
 WHERE option_key = 'seo_pattern_home'
   AND option_value = '{site_name} | {page_num} | {tagline}';
