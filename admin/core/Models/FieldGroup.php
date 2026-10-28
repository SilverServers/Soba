<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Database\Database;
use PDO;

/**
 * FieldGroup model — CRUD for field groups and their fields.
 *
 * A field group is a named set of custom fields assigned to
 * one or more post types via location rules. Multiple groups can apply
 * to the same post type and are merged in order.
 *
 * Fields stored in field_group_fields are returned in the same shape
 * that FieldRenderer::renderField() expects, so they work seamlessly
 * with the existing CPT edit UI.
 */
class FieldGroup
{
    // ----------------------------------------------------------------
    // Queries
    // ----------------------------------------------------------------

    /**
     * Return all field groups with their content_types decoded.
     * Does not load child fields — use find() for a single group with fields.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $db     = Database::getInstance();
        $groups = $db->query('SELECT * FROM field_groups ORDER BY sort_order ASC, title ASC')
                     ->fetchAll(PDO::FETCH_ASSOC);

        foreach ($groups as &$group) {
            $group['content_types'] = json_decode($group['content_types'], true) ?? [];
            $group['field_count']   = self::countFields($group['id']);
        }

        return $groups;
    }

    /**
     * Find a single field group by id, including its fields.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM field_groups WHERE id = ?');
        $stmt->execute([$id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($group === false) {
            return null;
        }

        $group['content_types'] = json_decode($group['content_types'], true) ?? [];
        $group['fields']        = self::getRawFields($id);

        return $group;
    }

    /**
     * Return all active field groups whose location rules include the given content type key.
     * Fields are returned in FieldRenderer-compatible shape (merged settings).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getActiveGroupsForContentType(string $contentTypeKey): array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare("
            SELECT * FROM field_groups
            WHERE is_active = 1
              AND JSON_CONTAINS(content_types, JSON_QUOTE(?))
            ORDER BY sort_order ASC
        ");
        $stmt->execute([$contentTypeKey]);
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($groups as &$group) {
            $group['content_types'] = json_decode($group['content_types'], true) ?? [];
            $group['is_archetype']  = (bool) ($group['is_archetype'] ?? false);
            $group['archetype_key'] = $group['archetype_key'] ?? null;
            $group['fields']        = self::getRendererFields($group['id']);
        }

        return $groups;
    }

    // ----------------------------------------------------------------
    // Writes
    // ----------------------------------------------------------------

    /**
     * Create a new field group. Returns the new id.
     *
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('
            INSERT INTO field_groups (title, content_types, sort_order, is_active, is_archetype, archetype_key)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $data['title'],
            json_encode($data['content_types'] ?? []),
            $data['sort_order'] ?? 0,
            $data['is_active'] ?? 1,
            !empty($data['is_archetype']) ? 1 : 0,
            $data['archetype_key'] ?? null,
        ]);
        $groupId = (int) $db->lastInsertId();

        if (!empty($data['fields'])) {
            self::saveFields($groupId, $data['fields']);
        }

        return $groupId;
    }

    /**
     * Update an existing field group and replace all its fields.
     *
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('
            UPDATE field_groups
            SET title = ?, content_types = ?, sort_order = ?, is_active = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $data['title'],
            json_encode($data['content_types'] ?? []),
            $data['sort_order'] ?? 0,
            $data['is_active'] ?? 1,
            $id,
        ]);

        // Replace all fields
        $db->prepare('DELETE FROM field_group_fields WHERE group_id = ?')->execute([$id]);

        if (!empty($data['fields'])) {
            self::saveFields($id, $data['fields']);
        }
    }

    /**
     * Delete a field group (CASCADE removes field_group_fields rows).
     */
    public static function delete(int $id): void
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('DELETE FROM field_groups WHERE id = ?');
        $stmt->execute([$id]);
    }

    // ----------------------------------------------------------------
    // Archetype field group management
    // ----------------------------------------------------------------

    /**
     * Find an archetype field group for a given content type key and archetype.
     *
     * @return array<string, mixed>|null
     */
    /**
     * Detach a Content Type from every field group, and delete the groups that
     * existed only to serve it.
     *
     * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
     * Without it, deleting a Content Type would leave its groups behind pointing
     * at a key nothing registers any more, and the field groups list would print
     * the raw dead key where every other row shows a label and a glyph, because
     * there is no type left to ask for either.
     *
     * ── THE TWO CASES ARE NOT THE SAME ──────────────────────────────────────
     * An ARCHETYPE group was GENERATED by ticking a box on the Content Type
     * form. It has no author and no life of its own; when the type goes, it goes.
     *
     * A group somebody WROTE is theirs. Deleting it because its only target
     * disappeared would destroy work in response to an unrelated action — so it
     * is detached and kept, and the list says it is attached to nothing. An
     * author can reattach it or delete it; both are one click and neither is a
     * surprise.
     *
     * @return array{detached: int, deleted: int}
     */
    public static function forgetContentType(string $contentTypeKey): array
    {
        $db       = Database::getInstance();
        $detached = 0;
        $deleted  = 0;

        $stmt = $db->prepare(
            'SELECT id, content_types, is_archetype FROM field_groups WHERE content_types LIKE ?'
        );
        // LIKE is a coarse filter over the JSON column and it is deliberately
        // coarse: the exact test is done in PHP below, because `%"clubs"%` would
        // also match a key that merely CONTAINS "clubs" and a JSON function
        // would tie this to one database's version.
        $stmt->execute(['%' . $contentTypeKey . '%']);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $targets = json_decode((string) $row['content_types'], true);
            if (!is_array($targets) || !in_array($contentTypeKey, $targets, true)) {
                continue;   // the LIKE matched a substring, not this key
            }

            $remaining = array_values(array_filter(
                $targets,
                static fn ($k): bool => $k !== $contentTypeKey
            ));

            if ($remaining === [] && !empty($row['is_archetype'])) {
                self::delete((int) $row['id']);
                $deleted++;
                continue;
            }

            $update = $db->prepare('UPDATE field_groups SET content_types = ? WHERE id = ?');
            $update->execute([json_encode($remaining), (int) $row['id']]);
            $detached++;
        }

        return ['detached' => $detached, 'deleted' => $deleted];
    }

    public static function findArchetypeGroup(string $contentTypeKey, string $archetypeKey): ?array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare("
            SELECT * FROM field_groups
            WHERE is_archetype = 1
              AND archetype_key = ?
              AND JSON_CONTAINS(content_types, JSON_QUOTE(?))
            LIMIT 1
        ");
        $stmt->execute([$archetypeKey, $contentTypeKey]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($group === false) {
            return null;
        }

        $group['content_types'] = json_decode($group['content_types'], true) ?? [];
        // Hydrated like find(), because the Content Type form prints "N fields,
        // in <group>" from it. An un-hydrated row would print 0 — a number that
        // is wrong on every row and looks like a fact.
        $group['fields']        = self::getRawFields((int) $group['id']);
        return $group;
    }

    /**
     * Activate a field group (set is_active = 1).
     */
    public static function activate(int $id): void
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('UPDATE field_groups SET is_active = 1 WHERE id = ?');
        $stmt->execute([$id]);
    }

    /*
     * There is no `deactivate()`. The Field groups editor turns a group on and
     * off through update(), like every other setting on it, and unticking an
     * archetype goes through forgetArchetype() below.
     */

    /**
     * Untick an archetype on a Content Type: take away the group that ticking
     * it generated.
     *
     * ── WHY THIS IS NOT `deactivate()` ──────────────────────────────────────
     * Ticking Product on a Content Type generates *Price and plan*. Unticking
     * it with `is_active = 0` and nothing else would leave the group in the
     * Field groups list — forever, as an inactive row nobody made, nobody
     * wanted, and nobody could account for. The fields it declared would be
     * gone from the entry form, so the row would not even tell the truth about
     * itself. Leaving debris in a management screen from an action taken
     * somewhere else is the same class of failure as a read-only row: the owner
     * is looking at something they did not create and cannot explain.
     *
     * ── THE TWO CASES, and they are `forgetContentType()`'s two cases ───────
     * A generated group whose only target is this type has no life without it:
     * DELETED. One an author has since pointed at other types as well is now
     * doing a job for them: this type is DETACHED from it and the group stands.
     * Same rule as deleting the type outright, because it is the same question —
     * is anything else relying on this? — and two answers to it would eventually
     * disagree.
     *
     * The entries' stored VALUES are untouched either way. `post_meta` rows
     * outlive the field that declared them; re-ticking the archetype brings the
     * fields back and the values with them.
     *
     * @return string '' if there was no such group, otherwise 'deleted' | 'detached'
     */
    public static function forgetArchetype(string $contentTypeKey, string $archetypeKey): string
    {
        $group = self::findArchetypeGroup($contentTypeKey, $archetypeKey);
        if ($group === null) {
            return '';
        }

        $remaining = array_values(array_filter(
            (array) $group['content_types'],
            static fn ($k): bool => $k !== $contentTypeKey
        ));

        if ($remaining === []) {
            self::delete((int) $group['id']);
            return 'deleted';
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare('UPDATE field_groups SET content_types = ? WHERE id = ?');
        $stmt->execute([json_encode($remaining), (int) $group['id']]);
        return 'detached';
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * Save an ordered array of field definitions into field_group_fields.
     *
     * @param array<int, array<string, mixed>> $fields
     */
    private static function saveFields(int $groupId, array $fields): void
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('
            INSERT INTO field_group_fields
                (group_id, field_key, field_label, field_type, sort_order, is_required, settings)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');

        foreach ($fields as $order => $field) {
            $settings = [];

            // Collect type-specific settings
            if (isset($field['rows']))                $settings['rows']                = (int)    $field['rows'];
            if (isset($field['min']))                 $settings['min']                 =           $field['min'];
            if (isset($field['max']))                 $settings['max']                 =           $field['max'];
            if (isset($field['options']))             $settings['options']             = (array)  $field['options'];
            if (isset($field['content_type_target'])) $settings['content_type_target'] = (string) $field['content_type_target'];
            if (isset($field['post_type_target']))    $settings['post_type_target']    = (string) $field['post_type_target'];
            if (isset($field['sub_fields']))          $settings['sub_fields']          = (array)  $field['sub_fields'];
            // A taxonomy field's three settings. This list is a WHITELIST — a
            // setting that is not named here is dropped on save, silently, and
            // the field comes back from the database missing it — a taxonomy
            // field created with a taxonomy chosen would then render "This
            // field has no taxonomy set" on every entry form. Whenever
            // FieldRenderer learns to read a new setting, it has to be added
            // HERE too, or the control that sets it lies.
            if (isset($field['taxonomy_key']))        $settings['taxonomy_key']        = (string) $field['taxonomy_key'];
            if (isset($field['field_mode']))          $settings['field_mode']          = (string) $field['field_mode'];
            if (isset($field['multiple']))            $settings['multiple']            = (int)    $field['multiple'];

            // ── THE FOUR EVERY TYPE HAS ─────────────────────────────────────
            // Presentation, Validation and Conditional Logic are per-field on
            // every type, so they sit outside the type-specific block above.
            //
            // The warning six lines up is not hypothetical: conditional logic
            // was built end to end — editor, evaluator, browser mirror, entry
            // form — and on the first run the rule vanished between the POST and
            // the reload, because this whitelist did not name it. Everything
            // looked right at every other layer.
            if (isset($field['instructions'])) $settings['instructions'] = (string) $field['instructions'];
            if (isset($field['width']))        $settings['width']        = (string) $field['width'];
            if (isset($field['msg_required'])) $settings['msg_required'] = (string) $field['msg_required'];
            if (isset($field['logic']))        $settings['logic']        = (array)  $field['logic'];

            $stmt->execute([
                $groupId,
                $field['key']      ?? '',
                $field['label']    ?? '',
                $field['type']     ?? 'text',
                $order,
                isset($field['required']) ? (int) $field['required'] : 0,
                json_encode($settings),
            ]);
        }
    }

    /**
     * Return raw DB rows from field_group_fields (used by the field group editor).
     * Decodes the settings JSON but keeps DB column names intact.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getRawFields(int $groupId): array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT * FROM field_group_fields WHERE group_id = ? ORDER BY sort_order ASC'
        );
        $stmt->execute([$groupId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['settings'] = json_decode($row['settings'] ?? '{}', true) ?? [];
        }

        return $rows;
    }

    /**
     * Return fields shaped for FieldRenderer::renderField() / validateField() / sanitizeField().
     * Merges type-specific settings into the top-level field array.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function getRendererFields(int $groupId): array
    {
        $rows = self::getRawFields($groupId);

        return array_map(static function (array $row): array {
            $settings = $row['settings'];

            return array_merge($settings, [
                'key'      => $row['field_key'],
                'label'    => $row['field_label'],
                'type'     => $row['field_type'],
                'required' => (bool) $row['is_required'],
            ]);
        }, $rows);
    }

    /**
     * Count of fields in a group (used by the group list view).
     */
    private static function countFields(int $groupId): int
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT COUNT(*) FROM field_group_fields WHERE group_id = ?');
        $stmt->execute([$groupId]);
        return (int) $stmt->fetchColumn();
    }
}
