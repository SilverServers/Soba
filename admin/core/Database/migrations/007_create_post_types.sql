-- Migration 007: UI-managed Content Types (table renamed to content_types in migration 018)
-- Content Types created via the admin UI are stored here.
-- Code-registered Content Types (config/content-types.php) are loaded separately and merged at runtime.

CREATE TABLE IF NOT EXISTS post_types (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type_key       VARCHAR(50)  NOT NULL,
    plural_label   VARCHAR(100) NOT NULL,
    singular_label VARCHAR(100) NOT NULL,
    admin_slug     VARCHAR(100) NOT NULL,
    public_slug    VARCHAR(100) NOT NULL,
    menu_icon      VARCHAR(50)  NOT NULL DEFAULT 'file',
    supports       JSON         NOT NULL,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_post_types_key      (type_key),
    UNIQUE KEY uq_post_types_admin    (admin_slug),
    UNIQUE KEY uq_post_types_public   (public_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
