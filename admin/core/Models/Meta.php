<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Database\Database;
use PDO;

/**
 * Post meta — stores custom field values keyed by post_id + meta_key.
 * One row per field per post. Upsert on save.
 * Complex types (repeater, relationship) are JSON-encoded.
 */
class Meta
{
    /**
     * Get a single meta value for a post.
     * Returns null if the key doesn't exist.
     */
    public static function get(int $postId, string $key): mixed
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT meta_value FROM post_meta WHERE post_id = :post_id AND meta_key = :meta_key LIMIT 1'
        );
        $stmt->execute([':post_id' => $postId, ':meta_key' => $key]);
        $row = $stmt->fetchColumn();
        return $row !== false ? $row : null;
    }

    /**
     * Get all meta for a post as a flat key → value map.
     * JSON strings are NOT auto-decoded here — the caller (or FieldRenderer) handles that.
     *
     * @return array<string, string|null>
     */
    public static function getAll(int $postId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT meta_key, meta_value FROM post_meta WHERE post_id = :post_id'
        );
        $stmt->execute([':post_id' => $postId]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * getAll() for a page of entries in ONE query — post id => key => value.
     *
     * The archives called getAll() once per card, "acceptable at a page size of
     * 10; revisit if the page size grows". The page size is a setting now
     * (`Helpers\Paging`, up to 100), so it grew. An entry with no meta is
     * present as [] rather than absent, so a caller can index by any id it
     * passed.
     *
     * @param  int[] $postIds
     * @return array<int, array<string, string|null>>
     */
    public static function getAllFor(array $postIds): array
    {
        $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds), static fn (int $id): bool => $id > 0)));
        $out     = array_fill_keys($postIds, []);
        if ($postIds === []) {
            return $out;
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT post_id, meta_key, meta_value FROM post_meta WHERE post_id IN ('
            . implode(',', array_fill(0, count($postIds), '?')) . ')'
        );
        $stmt->execute($postIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['post_id']][(string) $row['meta_key']] = $row['meta_value'];
        }
        return $out;
    }

    /**
     * Upsert a single meta value.
     * Encodes arrays/objects to JSON automatically.
     */
    public static function set(int $postId, string $key, mixed $value): void
    {
        $pdo = Database::getInstance();

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO post_meta (post_id, meta_key, meta_value)
             VALUES (:post_id, :meta_key, :meta_value)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)'
        );
        $stmt->execute([
            ':post_id'    => $postId,
            ':meta_key'   => $key,
            ':meta_value' => $value,
        ]);
    }

    /**
     * Save all custom field values for a post from raw POST data.
     * Delegates sanitization to FieldRenderer.
     * Skips fields whose keys aren't present in POST (handles optional fields gracefully).
     *
     * @param array<int, array<string, mixed>> $fields  CPT field definitions
     * @param array<string, mixed>             $postData  $_POST array
     */
    public static function saveFields(int $postId, array $fields, array $postData): void
    {
        if (empty($fields)) {
            return;
        }

        // Defer to FieldRenderer to avoid circular dependency.
        // FieldRenderer::sanitizeField() handles per-type coercion + encoding.
        foreach ($fields as $field) {
            $key   = $field['key'];
            $value = \Cms\ContentType\FieldRenderer::sanitizeField($field, $postData);
            self::set($postId, $key, $value);
        }
    }

    /**
     * Remove one key from a post. For a value going back to its default,
     * which is stored as NO row: `set()` with null keeps the row and stores
     * NULL in it.
     */
    public static function delete(int $postId, string $key): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('DELETE FROM post_meta WHERE post_id = :post_id AND meta_key = :meta_key');
        $stmt->execute([':post_id' => $postId, ':meta_key' => $key]);
    }

    /**
     * Delete all meta rows for a post.
     * Rarely needed directly — ON DELETE CASCADE handles post deletion.
     */
    public static function deleteAll(int $postId): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('DELETE FROM post_meta WHERE post_id = :post_id');
        $stmt->execute([':post_id' => $postId]);
    }
}
