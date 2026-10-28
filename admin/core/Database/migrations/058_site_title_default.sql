-- Migration 058: the Site title is the site's, never the product's.
--
-- Migration 019 seeded site_name = 'Imprint', so every fresh install showed the
-- product's name on its public pages, its titles and its mail until someone
-- thought to change it — a client site shipped that way. The CMS is white-label:
-- an unset Site title falls back to the site's own host name
-- (Head::globalTokens()), which is what this leaves behind.
--
-- ONLY WHERE THE SEED IS UNTOUCHED. A title the owner has typed is theirs.

UPDATE options
   SET option_value = ''
 WHERE option_key = 'site_name'
   AND option_value = 'Imprint';
