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
                self::publicSlugOrNull($data['public_slug'] ?? null),
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
                self::publicSlugOrNull($data['public_slug'] ?? null),
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
                self::publicSlugOrNull($data['public_slug'] ?? null),
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
                self::publicSlugOrNull($data['public_slug'] ?? null),
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
     * What its entry list shows beside each title, and its order when nobody
     * has chosen (migration 130, `ContentType\ListColumns`). One caller, the
     * Content Type form. NULL keeps the type's own default.
     *
     * @param list<string>|null                          $columns
     * @param array{field: string, order: string}|null   $sort
     */
    public static function setListView(int $id, ?array $columns, ?array $sort): void
    {
        Database::getInstance()
            ->prepare('UPDATE content_types SET list_columns = :c, list_sort = :s WHERE id = :id')
            ->execute([
                ':c'  => $columns === null ? null : json_encode(array_values($columns), JSON_UNESCAPED_UNICODE),
                ':s'  => $sort === null ? null : json_encode($sort, JSON_UNESCAPED_UNICODE),
                ':id' => $id,
            ]);
    }

    /**
     * The Status a new entry of the type starts at (migration 131): 'published'
     * or Private, stored as NULL. One caller, the Content Type form.
     */
    public static function setDefaultStatus(int $id, string $status): void
    {
        Database::getInstance()
            ->prepare('UPDATE content_types SET default_status = :s WHERE id = :id')
            ->execute([':s' => $status === 'published' ? 'published' : null, ':id' => $id]);
    }

    /**
     * The type's own word for its entries' title (migration 150), such as
     * Name; blank is stored as NULL, the word every type had before. One
     * caller, the Content Type form.
     */
    public static function setTitleLabel(int $id, string $label): void
    {
        Database::getInstance()
            ->prepare('UPDATE content_types SET title_label = :t WHERE id = :id')
            ->execute([':t' => trim($label) === '' ? null : trim($label), ':id' => $id]);
    }

    /**
     * How its editor names each entry it may belong to (migration 148), or
     * NULL for the parent's title. One caller, the Content Type form.
     */
    public static function setParentLabel(int $id, string $pattern): void
    {
        Database::getInstance()
            ->prepare('UPDATE content_types SET parent_label = :p WHERE id = :id')
            ->execute([':p' => trim($pattern) === '' ? null : trim($pattern), ':id' => $id]);
    }

    /**
     * What Copy makes of its entries (migration 147, `ContentType\CopyRules`),
     * or NULL for the CMS's own. One caller, the Content Type form.
     *
     * @param array{suffix: string, status: string, skip: list<string>}|null $rules
     */
    public static function setCopyRules(int $id, ?array $rules): void
    {
        Database::getInstance()
            ->prepare('UPDATE content_types SET copy_rules = :c WHERE id = :id')
            ->execute([':c' => $rules === null ? null : json_encode($rules, JSON_UNESCAPED_UNICODE), ':id' => $id]);
    }

    /**
     * Where its fields go on its editor (migration 149): 'high', or NULL for
     * after the text. One caller, the Content Type form.
     */
    public static function setFieldsPosition(int $id, string $at): void
    {
        Database::getInstance()
            ->prepare('UPDATE content_types SET fields_position = :p WHERE id = :id')
            ->execute([':p' => $at === 'high' ? 'high' : null, ':id' => $id]);
    }

    /**
     * The patterns its titles and addresses are made from (migration 146,
     * `ContentType\Patterns`), each NULL for none. One caller, the Content
     * Type form.
     */
    public static function setPatterns(int $id, string $title, string $slug): void
    {
        Database::getInstance()
            ->prepare('UPDATE content_types SET title_pattern = :t, slug_pattern = :s WHERE id = :id')
            ->execute([':t' => trim($title) === '' ? null : trim($title), ':s' => trim($slug) === '' ? null : trim($slug), ':id' => $id]);
    }

    /**
     * The child types its editor shows, each with its columns (migration 145,
     * `ContentType\Children`), or none, stored as NULL. One caller, the
     * Content Type form.
     *
     * @param array<string, array{columns: list<string>}> $children
     */
    public static function setChildren(int $id, array $children): void
    {
        Database::getInstance()
            ->prepare('UPDATE content_types SET children = :c WHERE id = :id')
            ->execute([':c' => $children === [] ? null : json_encode($children, JSON_UNESCAPED_UNICODE), ':id' => $id]);
    }

    /**
     * What staff see on the type's screens (migration 140, `ContentType\StaffView`):
     * each panel's and each list part's least role, or false for no one, and
     * what the list is grouped by. A part left as it was is not stored, and a
     * type with nothing changed stores NULL. One caller, the Content Type form.
     *
     * @param array<string, string|false> $panels
     * @param array<string, string|false> $list
     */
    public static function setStaffView(int $id, array $panels, array $list): void
    {
        $json = static fn (array $a): ?string => $a === [] ? null : json_encode($a, JSON_THROW_ON_ERROR);
        Database::getInstance()
            ->prepare('UPDATE content_types SET panels = :p, list_view = :l WHERE id = :id')
            ->execute([':p' => $json($panels), ':l' => $json($list), ':id' => $id]);
    }

    /**
     * How many terms of each taxonomy the type's entries take (migration 151),
     * by taxonomy, each `['min' => 0|1, 'max' => N|null]`; none is stored as
     * NULL, every taxonomy's own rule. One caller, the Content Type form.
     *
     * @param array<string, array{min: int, max: int|null}> $rules
     */
    public static function setTermRules(int $id, array $rules): void
    {
        Database::getInstance()
            ->prepare('UPDATE content_types SET term_rules = :r WHERE id = :id')
            ->execute([':r' => $rules === [] ? null : json_encode($rules, JSON_UNESCAPED_UNICODE), ':id' => $id]);
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
     * The Public URL worth storing, or null: blank means the type's entries
     * have no page of their own (`Registry::hasPages()`, migration 129), and
     * the column is UNIQUE, which holds any number of NULLs and one ''.
     */
    private static function publicSlugOrNull(mixed $value): ?string
    {
        $slug = is_string($value) ? trim($value) : '';

        return $slug === '' ? null : $slug;
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
