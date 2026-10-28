<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Database\Database;
use PDO;

/**
 * MediaVersion — tracks previous file versions when attachments are replaced.
 *
 * Each record is a snapshot of the file metadata at the time it was replaced.
 * The old physical file is preserved on disk for potential future restore.
 */
class MediaVersion
{
    /**
     * Get all previous versions for an attachment, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function allForAttachment(int $attachmentId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT * FROM media_versions WHERE attachment_id = :aid ORDER BY replaced_at DESC"
        );
        $stmt->execute([':aid' => $attachmentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Create a version snapshot (called before replacing a file).
     */
    /**
     * How many versions this attachment already has.
     *
     * Used to name the next archived file (`photo.v3.jpg`). A COUNT rather than
     * `max(id)`: the number only has to be unique within one attachment's
     * directory, and ids are global, so `max(id)` would produce
     * `photo.v4712.jpg` on a busy install.
     */
    public static function countFor(int $attachmentId): int
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM media_versions WHERE attachment_id = :id'
        );
        $stmt->execute([':id' => $attachmentId]);

        return (int) $stmt->fetchColumn();
    }

    public static function create(array $data): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "INSERT INTO media_versions
                (attachment_id, filename, original_name, file_size, mime_type, width, height)
             VALUES
                (:attachment_id, :filename, :original_name, :file_size, :mime_type, :width, :height)"
        );
        $stmt->execute([
            ':attachment_id' => $data['attachment_id'],
            ':filename'      => $data['filename'],
            ':original_name' => $data['original_name'],
            ':file_size'     => $data['file_size'],
            ':mime_type'     => $data['mime_type'],
            ':width'         => $data['width'] ?? null,
            ':height'        => $data['height'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Find a version record by ID.
     */
    public static function find(int $id): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM media_versions WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Delete a version record.
     */
    public static function delete(int $id): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("DELETE FROM media_versions WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
