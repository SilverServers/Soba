-- Migration 003: Posts table
-- Backbone for all Content Types. post_type column allows Posts, Events,
-- Team Members, etc. to share one table.

CREATE TABLE IF NOT EXISTS posts (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    post_type    VARCHAR(50)   NOT NULL DEFAULT 'post',
    title        VARCHAR(500)  NOT NULL,
    slug         VARCHAR(500)  NOT NULL,
    content      LONGTEXT,
    status       ENUM('draft','published','scheduled') NOT NULL DEFAULT 'draft',
    published_at DATETIME      NULL DEFAULT NULL,
    author_id    INT UNSIGNED  NOT NULL,
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_posts_slug (slug),
    KEY idx_posts_type_status (post_type, status),
    KEY idx_posts_author (author_id),
    CONSTRAINT fk_posts_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
