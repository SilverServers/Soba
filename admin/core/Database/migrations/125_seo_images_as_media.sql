-- Migration 125: Settings → SEO's default sharing image and organisation logo
-- name a picture of the media library by its id (`SEO\Head::siteImage()`).
--
-- A page resolves the id to the picture's address as it is drawn, so a file
-- renamed in the library, or a site moved from a development address to its
-- own, keeps both. Until now each was a whole address typed or pasted into a
-- box. One that names a picture kept in uploads/ becomes that picture's id,
-- whichever host it was written with; any other address is left as it is, and
-- the screen shows it in a box of its own until it is replaced or emptied.
UPDATE options o
JOIN attachments a
  ON a.visibility = 'public'
 AND a.site_path IS NULL
 AND a.mime_type LIKE 'image/%'
 AND a.filename = SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(o.option_value, '#', 1), '?', 1), '/uploads/', -1)
SET o.option_value = CAST(a.id AS CHAR)
WHERE o.option_key IN ('seo_og_image', 'seo_org_logo')
  AND o.option_value LIKE '%/uploads/%';
