-- Migration 008: Field Groups + Fields
-- Field groups are sets of custom fields assigned to one or more content types.
-- Each group has a list of fields stored in field_group_fields.
-- Note: the post_types column is renamed to content_types in migration 018.

CREATE TABLE IF NOT EXISTS field_groups (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title       VARCHAR(255) NOT NULL,
    post_types  JSON         NOT NULL,   -- JSON array of post type keys, e.g. ["event","board_member"]
    sort_order  INT UNSIGNED NOT NULL DEFAULT 0,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS field_group_fields (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_id     INT UNSIGNED NOT NULL,
    field_key    VARCHAR(100) NOT NULL,
    field_label  VARCHAR(255) NOT NULL,
    field_type   VARCHAR(50)  NOT NULL DEFAULT 'text',
    sort_order   INT UNSIGNED NOT NULL DEFAULT 0,
    is_required  TINYINT(1)   NOT NULL DEFAULT 0,
    settings     JSON,   -- type-specific options: rows, min, max, options, post_type_target, etc.
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fgf_group (group_id),
    CONSTRAINT fk_fgf_group FOREIGN KEY (group_id)
        REFERENCES field_groups (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
