<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Database\Database;
use PDO;

/**
 * ContentTypeDefinition model — CRUD for UI-managed Content Type definitions.
 *
 * Code-registered Content Types live in config/content-types.php (via Registry::register()).
 * UI-created Content Types are stored in the content_types table and loaded into the
 * Registry at bootstrap alongside code-registered ones.
 */
class ContentTypeDefinition
{
    /**
     * Return all UI-created Content Type definitions, alphabetical by label.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $db   = Database::getInstance();
        $rows = $db->query('SELECT * FROM content_types ORDER BY plural_label ASC')
                   ->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['supports']   = json_decode($row['supports'], true) ?? [];
            $row['archetypes'] = isset($row['archetypes'])
                ? (json_decode($row['archetypes'], true) ?? ['post'])
                : ['post'];
        }

        return $rows;
    }

    /**
     * Return only active UI-created Content Type definitions (used at bootstrap to load into Registry).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function allActive(): array
    {
        $db   = Database::getInstance();
        $rows = $db->query('SELECT * FROM content_types WHERE is_active = 1 ORDER BY plural_label ASC')
                   ->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['supports']   = json_decode($row['supports'], true) ?? [];
            $row['archetypes'] = isset($row['archetypes'])
                ? (json_decode($row['archetypes'], true) ?? ['post'])
                : ['post'];
        }

        return $rows;
    }

    /**
     * Find a single Content Type definition by its DB id.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM content_types WHERE id = ?');
        $stmt->execute([$id]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $row['supports']   = json_decode($row['supports'], true) ?? [];
        $row['archetypes'] = isset($row['archetypes'])
            ? (json_decode($row['archetypes'], true) ?? ['post'])
            : ['post'];

        return $row;
    }

    /**
     * Find a single Content Type definition by its type_key.
     *
     * @return array<string, mixed>|null
     */
    public static function findByKey(string $key): ?array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM content_types WHERE type_key = ?');
        $stmt->execute([$key]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $row['supports']   = json_decode($row['supports'], true) ?? [];
        $row['archetypes'] = isset($row['archetypes'])
            ? (json_decode($row['archetypes'], true) ?? ['post'])
            : ['post'];

        return $row;
    }

    /**
     * Insert a new Content Type definition. Returns the new row's id.
     *
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();

        // Use archetypes column if available (migration 017), otherwise omit
        $hasArchetypes = self::columnExists('archetypes');

        if ($hasArchetypes) {
            $stmt = $db->prepare('
                INSERT INTO content_types
                    (type_key, plural_label, singular_label, admin_slug, public_slug, parent_type, menu_icon, supports, archetypes, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $data['type_key'],
                $data['plural_label'],
                $data['singular_label'],
                $data['admin_slug'],
                $data['public_slug'],
                // NULL, NOT '' — migration 047 declares it nullable because
                // "belongs to nothing" is the normal case, and an empty string
                // is a key that matches no type while reading as though one was
                // chosen. `Registry::parentOf()` treats both as none; the column
                // should not need it to.
                self::parentTypeOrNull($data['parent_type'] ?? null),
                $data['menu_icon'] ?? 'file',
                json_encode($data['supports'] ?? []),
                json_encode($data['archetypes'] ?? ['post']),
                $data['is_active'] ?? 1,
            ]);
        } else {
            $stmt = $db->prepare('
                INSERT INTO content_types
                    (type_key, plural_label, singular_label, admin_slug, public_slug, menu_icon, supports, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $data['type_key'],
                $data['plural_label'],
                $data['singular_label'],
                $data['admin_slug'],
                $data['public_slug'],
                $data['menu_icon'] ?? 'file',
                json_encode($data['supports'] ?? []),
                $data['is_active'] ?? 1,
            ]);
        }

        return (int) $db->lastInsertId();
    }

    /**
     * Update an existing Content Type definition.
     *
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $db = Database::getInstance();

        $hasArchetypes = self::columnExists('archetypes');

        if ($hasArchetypes) {
            $stmt = $db->prepare('
                UPDATE content_types
                SET type_key = ?, plural_label = ?, singular_label = ?,
                    admin_slug = ?, public_slug = ?, parent_type = ?, menu_icon = ?,
                    supports = ?, archetypes = ?, is_active = ?
                WHERE id = ?
            ');
            $stmt->execute([
                $data['type_key'],
                $data['plural_label'],
                $data['singular_label'],
                $data['admin_slug'],
                $data['public_slug'],
                self::parentTypeOrNull($data['parent_type'] ?? null),
                $data['menu_icon'] ?? 'file',
                json_encode($data['supports'] ?? []),
                json_encode($data['archetypes'] ?? ['post']),
                $data['is_active'] ?? 1,
                $id,
            ]);
        } else {
            $stmt = $db->prepare('
                UPDATE content_types
                SET type_key = ?, plural_label = ?, singular_label = ?,
                    admin_slug = ?, public_slug = ?, menu_icon = ?,
                    supports = ?, is_active = ?
                WHERE id = ?
            ');
            $stmt->execute([
                $data['type_key'],
                $data['plural_label'],
                $data['singular_label'],
                $data['admin_slug'],
                $data['public_slug'],
                $data['menu_icon'] ?? 'file',
                json_encode($data['supports'] ?? []),
                $data['is_active'] ?? 1,
                $id,
            ]);
        }
    }

    /**
     * HOW MANY TO A PAGE, AND HOW MANY MORE ON PAGE 1 — migration 090.
     *
     * ITS OWN SETTER, NOT TWO MORE COLUMNS IN update(). That method rewrites
     * every column it names, so anything saving a type without these two —
     * an import, the assistant — would quietly put the site's archive back
     * to the default. One caller, the Content Type form.
     *
     * $perPage NULL follows the site's own number; 0 lists every entry on
     * one page.
     */
    public static function setPaging(int $id, ?int $perPage, int $firstPageExtra): void
    {
        Database::getInstance()
            ->prepare('UPDATE content_types SET per_page = :pp, first_page_extra = :fx WHERE id = :id')
            ->execute([':pp' => $perPage, ':fx' => max(0, $firstPageExtra), ':id' => $id]);
    }

    /**
     * A parent key worth storing, or null.
     *
     * The legacy no-archetypes branches of `create()` and `update()` do not
     * carry this column and are left alone: they exist for a schema older than
     * migration 017, where 047's column cannot be present either.
     */
    private static function parentTypeOrNull(mixed $value): ?string
    {
        $key = is_string($value) ? trim($value) : '';

        return $key === '' ? null : $key;
    }

    /**
     * Delete a Content Type definition by id.
     */
    /**
     * Delete a Content Type — and take its generated field groups with it.
     *
     * Deleting the row alone left every field group still pointing at a key
     * nothing registers, and the field groups list then printed that raw dead
     * key where every other row shows a label and a glyph. `FieldGroup::
     * forgetContentType()` deletes the ARCHETYPE groups (generated by the type,
     * meaningless without it) and merely detaches the ones somebody wrote.
     *
     * ENTRIES ARE NOT TOUCHED. `posts` rows stored against this key stay where
     * they are: they are the owner's content, deleting a type is a structural
     * decision, and silently destroying every article of that kind is not
     * something a screen with one confirm dialog should do. They become
     * unreachable, which is recoverable — re-create the type with the same key
     * and they are back.
     */
    public static function delete(int $id): void
    {
        $row = self::find($id);

        $db   = Database::getInstance();
        $stmt = $db->prepare('DELETE FROM content_types WHERE id = ?');
        $stmt->execute([$id]);

        if ($row !== null) {
            \Cms\Models\FieldGroup::forgetContentType((string) $row['type_key']);
        }
    }

    /**
     * Check whether a type_key is already in use (for validation).
     * Pass $excludeId when editing so the row's own key doesn't fail the check.
     */
    public static function keyExists(string $key, ?int $excludeId = null): bool
    {
        $db = Database::getInstance();

        if ($excludeId !== null) {
            $stmt = $db->prepare('SELECT id FROM content_types WHERE type_key = ? AND id != ?');
            $stmt->execute([$key, $excludeId]);
        } else {
            $stmt = $db->prepare('SELECT id FROM content_types WHERE type_key = ?');
            $stmt->execute([$key]);
        }

        return $stmt->fetch() !== false;
    }

    /**
     * Check whether an admin_slug is already in use.
     */
    public static function adminSlugExists(string $slug, ?int $excludeId = null): bool
    {
        $db = Database::getInstance();

        if ($excludeId !== null) {
            $stmt = $db->prepare('SELECT id FROM content_types WHERE admin_slug = ? AND id != ?');
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $db->prepare('SELECT id FROM content_types WHERE admin_slug = ?');
            $stmt->execute([$slug]);
        }

        return $stmt->fetch() !== false;
    }

    /**
     * Check whether a column exists on the content_types table.
     * Used to safely handle the archetypes column before migration 017 is applied.
     */
    private static function columnExists(string $column): bool
    {
        static $cache = [];
        if (isset($cache[$column])) {
            return $cache[$column];
        }
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'content_types'
               AND COLUMN_NAME  = ?"
        );
        $stmt->execute([$column]);
        $cache[$column] = $stmt->fetch() !== false;
        return $cache[$column];
    }
}
