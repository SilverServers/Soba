-- Migration 092: a gallery can be shown in one of the site's places
-- (a site's own change, brought into core), as
-- a form can (080). The site lists the places (places.php, kind 'gallery';
-- Helpers\Places); the key is the place's key there. One gallery per place,
-- so the index is unique; NULL is "in no place" — it still shows wherever its
-- shortcode is pasted.
--
-- IF NOT EXISTS, both: a site may have made this column and key under these
-- names, and this must change nothing there.
ALTER TABLE galleries
    ADD COLUMN IF NOT EXISTS place_key VARCHAR(64) NULL DEFAULT NULL AFTER title,
    ADD UNIQUE KEY IF NOT EXISTS uq_galleries_place_key (place_key);
