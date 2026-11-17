<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Database\Database;
use PDO;

/**
 * MediaFolder — flat folder system for organising media library files.
 *
 * Folders are one level deep (no nesting in v1). The `parent_id` column exists
 * for future expansion but is always NULL for now.
 */
class MediaFolder
{
    /** The longest name a folder may have, in characters: what the name boxes allow. */
    public const NAME_MAX = 100;

    /**
     * WHY A NAME CANNOT BE USED, or null when it can: for a new folder, or for
     * folder `$id` renamed. One rule for every door that names a folder, the
     * admin's and the API's, so a name refused in one is refused in all.
     *
     * The name is taken as given; trim it first. "Already a folder" is decided
     * by the column's collation, so "Photos" and "photos" are one name.
     */
    public static function refusal(string $name, ?int $id = null): ?string
    {
        if ($name === '') {
            return 'Give the folder a name.';
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            return 'A folder name can be ' . self::NAME_MAX . ' characters long at most.';
        }
        if (self::nameExists($name, $id)) {
            return 'There is already a folder called "' . $name . '".';
        }

        return null;
    }

    /**
     * Return all folders sorted by name, each with a file_count.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query(
            "SELECT f.*,
                    (SELECT COUNT(*) FROM attachments WHERE folder_id = f.id) AS file_count
             FROM media_folders f
             WHERE f.parent_id IS NULL
             ORDER BY f.name ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find a folder by ID.
     */
    public static function find(int $id): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM media_folders WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Create a folder. Returns the new folder ID.
     */
    public static function create(string $name): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("INSERT INTO media_folders (name) VALUES (:name)");
        $stmt->execute([':name' => $name]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Rename a folder.
     */
    public static function update(int $id, string $name): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE media_folders SET name = :name WHERE id = :id");
        return $stmt->execute([':name' => $name, ':id' => $id]);
    }

    /**
     * Delete a folder. Files in the folder are NOT deleted — their folder_id
     * becomes NULL via ON DELETE SET NULL.
     */
    public static function delete(int $id): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("DELETE FROM media_folders WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Check if a folder name already exists (optionally excluding a given ID).
     */
    public static function nameExists(string $name, ?int $excludeId = null): bool
    {
        $pdo    = Database::getInstance();
        $sql    = "SELECT COUNT(*) FROM media_folders WHERE name = :name";
        $params = [':name' => $name];

        if ($excludeId !== null) {
            $sql .= " AND id != :id";
            $params[':id'] = $excludeId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }
}
