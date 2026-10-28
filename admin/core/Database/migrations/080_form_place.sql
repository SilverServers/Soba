-- A form can be shown in one of the site's places, as a gallery can: each lead
-- page's form, say. The site
-- lists the places (places.php, kind 'form'; Helpers\Places); the key is the
-- place's key there. One form per place, so the index is unique; NULL is "in
-- no place" — it still shows wherever its shortcode is pasted.
--
-- IF NOT EXISTS, both: a site may have made this column and key itself, in a
-- migration of its own, and this must change nothing there.
ALTER TABLE forms
    ADD COLUMN IF NOT EXISTS place_key VARCHAR(64) NULL DEFAULT NULL AFTER title,
    ADD UNIQUE KEY IF NOT EXISTS uq_forms_place_key (place_key);
