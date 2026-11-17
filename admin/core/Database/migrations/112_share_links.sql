-- Migration 112: a page or an entry not yet on the site, shared by a link.
--
-- One link a page or entry at most (`Content\Share`): a random salt, from
-- which the link's code is made under the site's `app_secret`, so the table
-- alone does not give the code away; when the link stops working; and who
-- made it. Stopping a link deletes its row; a link past its date is not
-- honoured and is replaced when the next one is made.
CREATE TABLE IF NOT EXISTS share_links (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    object_type VARCHAR(8)   NOT NULL,
    object_id   INT UNSIGNED NOT NULL,
    salt        CHAR(32)     NOT NULL,
    expires_at  DATETIME     NOT NULL,
    created_by  INT UNSIGNED NULL DEFAULT NULL,
    created_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_share_object (object_type, object_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
