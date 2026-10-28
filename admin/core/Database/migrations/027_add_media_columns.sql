ALTER TABLE attachments
    ADD COLUMN folder_id   INT UNSIGNED NULL AFTER height,
    ADD COLUMN visibility  ENUM('public', 'private') NOT NULL DEFAULT 'public' AFTER folder_id,
    ADD COLUMN alt_text    VARCHAR(500) NULL AFTER visibility,
    ADD INDEX idx_folder_id (folder_id),
    ADD INDEX idx_visibility (visibility),
    ADD INDEX idx_mime_type (mime_type),
    ADD CONSTRAINT fk_attachments_folder
        FOREIGN KEY (folder_id) REFERENCES media_folders(id) ON DELETE SET NULL;
