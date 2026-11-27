<?php

declare(strict_types=1);

namespace Cms\ContentType;

use Cms\Database\Database;
use Cms\Models\Meta;

/**
 * WHAT A TYPE'S ENTRY LIST SHOWS BESIDE EACH TITLE, AND HOW IT IS ORDERED
 * WHEN NOBODY HAS CHOSEN: a type's 'list_columns' and 'default_sort', in its
 * definition or, for a type made on the Content Types screen, migration 130's
 * columns.
 *
 *     'list_columns' => [
 *         'event_start_date' => 'Starts',     a field of the type, under a heading
 *         'tax:venue'        => 'Venue',      a taxonomy's terms
 *         'children:session' => 'Sessions',   how many entries of a child type it has
 *     ],
 *     'default_sort' => ['field' => 'event_start_date', 'order' => 'asc'],
 *
 * A heading left out is the field's, the taxonomy's or the child type's own
 * name, so a list of keys will do. A field is a column when the list can print
 * it on one line and sort by it (`FIELD_TYPES`); a taxonomy must be one the
 * type has, and a child type one that belongs to it. A column that is not one
 * is said in the log and left out, so a typo never takes the list down.
 *
 * UNSET, an event type's list shows when each starts, the date its Upcoming
 * and Past tabs are ordered by (`Content\Events::order()`).
 */
final class ListColumns
{
    /** The field types a cell prints on one line and the list sorts by. */
    public const FIELD_TYPES = ['text', 'number', 'date', 'datetime', 'select', 'checkbox'];

    /** The event archetype's start, its list's column when none is named. */
    private const EVENT_START = 'event_start_date';

    /** @var array<string, list<array{spec: string, kind: string, key: string, heading: string, field: array<string, mixed>|null}>> */
    private static array $resolved = [];

    /**
     * The type's columns, in the order shown.
     *
     * @return list<array{spec: string, kind: string, key: string, heading: string, field: array<string, mixed>|null}>
     */
    public static function forType(string $type): array
    {
        if (isset(self::$resolved[$type])) {
            return self::$resolved[$type];
        }
        $definition = Registry::get($type);
        if ($definition === null) {
            return [];
        }
        $named = $definition['list_columns'] ?? null;
        if (!is_array($named)) {
            // AN EVENT TYPE'S OWN, quietly: one without the field has none.
            // Under the field's own label, as the screen's tick box keeps it.
            $start = self::isEvent($type) ? self::column($type, self::EVENT_START, '') : null;

            return self::$resolved[$type] = $start !== null ? [$start] : [];
        }
        $columns = [];
        foreach ($named as $spec => $heading) {
            if (is_int($spec)) {
                [$spec, $heading] = [(string) $heading, ''];
            }
            $column = self::column($type, (string) $spec, trim((string) $heading));
            if ($column === null) {
                error_log('List columns: "' . $spec . '" on the ' . $type . ' type is not a column its list can show, and is left out');
                continue;
            }
            $columns[] = $column;
        }

        return self::$resolved[$type] = $columns;
    }

    /**
     * How the list is ordered when nobody has chosen, in the list's own words
     * ('title', 'date' or 'field:{key}'); null is newest first.
     *
     * @return array{orderby: string, order: string}|null
     */
    public static function defaultSort(string $type): ?array
    {
        $sort = Registry::get($type)['default_sort'] ?? null;
        if (!is_array($sort)) {
            return null;
        }
        $field = (string) ($sort['field'] ?? '');
        $order = ($sort['order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        if ($field === 'title' || $field === 'date') {
            return ['orderby' => $field, 'order' => $order];
        }

        return self::sortable($type, $field) !== null ? ['orderby' => 'field:' . $field, 'order' => $order] : null;
    }

    /**
     * A field of the type the list can sort by, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function sortable(string $type, string $key): ?array
    {
        $field = $key !== '' ? ContentTypeSchema::field($type, $key) : null;

        return $field !== null && in_array($field['type'] ?? 'text', self::FIELD_TYPES, true) ? $field : null;
    }

    /** Is it an event type, whose list has Upcoming and Past? */
    public static function isEvent(string $type): bool
    {
        return in_array('event', (array) (Registry::get($type)['archetypes'] ?? []), true);
    }

    /**
     * Every column the type's list could show, for the Content Types screen:
     * its fields a cell can print, its taxonomies, then its child types.
     *
     * @return list<array{spec: string, heading: string, kind: string}>
     */
    public static function available(string $type): array
    {
        $out = [];
        foreach (ContentTypeSchema::fields($type) as $field) {
            if (in_array($field['type'] ?? 'text', self::FIELD_TYPES, true)) {
                $out[] = ['spec' => (string) $field['key'], 'heading' => (string) ($field['label'] ?? $field['key']), 'kind' => 'field'];
            }
        }
        foreach (TaxonomyRegistry::getForContentType($type) as $key => $taxonomy) {
            $out[] = ['spec' => 'tax:' . $key, 'heading' => (string) $taxonomy['label'], 'kind' => 'tax'];
        }
        foreach (Registry::all() as $key => $child) {
            if (($child['parent_type'] ?? null) === $type) {
                $out[] = ['spec' => 'children:' . $key, 'heading' => (string) $child['label'], 'kind' => 'children'];
            }
        }

        return $out;
    }

    /**
     * Each row's cell for its field and child-count columns, as HTML: a field
     * as `FieldDisplay` prints it, a count as a number. A taxonomy's cell is
     * the template's, since its terms link to the list's own filter.
     *
     * @param list<array{spec: string, kind: string, key: string, heading: string, field: array<string, mixed>|null}> $columns
     * @param list<int> $ids
     * @return array<int, array<string, string>> id => spec => HTML
     */
    public static function cells(array $columns, array $ids): array
    {
        $out = array_fill_keys($ids, []);
        if ($ids === [] || $columns === []) {
            return $out;
        }
        $meta = array_filter($columns, static fn (array $c): bool => $c['kind'] === 'field') !== [] ? Meta::getAllFor($ids) : [];
        foreach ($columns as $column) {
            if ($column['kind'] === 'field') {
                foreach ($ids as $id) {
                    $out[$id][$column['spec']] = FieldDisplay::html((array) $column['field'], $meta[$id][$column['key']] ?? null);
                }
            } elseif ($column['kind'] === 'children') {
                $counts = self::childCounts($column['key'], $ids);
                foreach ($ids as $id) {
                    $out[$id][$column['spec']] = (string) ($counts[$id] ?? 0);
                }
            }
        }

        return $out;
    }

    /**
     * A column's width, the same on every row, since each row is a grid of
     * its own and a track sized to its content would differ from one row to
     * the next: room for a date and time, a short count, or a few words.
     */
    public static function track(array $column): string
    {
        if ($column['kind'] === 'children') {
            return '4.5rem';
        }

        return match ((string) ($column['field']['type'] ?? '')) {
            'datetime'           => '10rem',
            'date'               => '7rem',
            'number', 'checkbox' => '4.5rem',
            default              => '8rem',
        };
    }

    /** Forget what was resolved this request: after a test declares a type. */
    public static function reset(): void
    {
        self::$resolved = [];
    }

    /**
     * How many entries of a child type each parent has, its trash aside.
     *
     * @param list<int> $ids
     * @return array<int, int>
     */
    private static function childCounts(string $childType, array $ids): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT parent_id, COUNT(*) AS n FROM posts WHERE post_type = ? AND status <> 'trash' AND parent_id IN ("
            . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY parent_id'
        );
        $stmt->execute(array_merge([$childType], $ids));
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['parent_id']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * One column, resolved, or null when it is not one this type's list can
     * show.
     *
     * @return array{spec: string, kind: string, key: string, heading: string, field: array<string, mixed>|null}|null
     */
    private static function column(string $type, string $spec, string $heading): ?array
    {
        if (str_starts_with($spec, 'tax:')) {
            $key      = substr($spec, 4);
            $taxonomy = TaxonomyRegistry::getForContentType($type)[$key] ?? null;

            return $taxonomy === null ? null
                : ['spec' => $spec, 'kind' => 'tax', 'key' => $key, 'heading' => $heading !== '' ? $heading : (string) $taxonomy['label'], 'field' => null];
        }
        if (str_starts_with($spec, 'children:')) {
            $key   = substr($spec, 9);
            $child = Registry::get($key);

            return $child === null || ($child['parent_type'] ?? null) !== $type ? null
                : ['spec' => $spec, 'kind' => 'children', 'key' => $key, 'heading' => $heading !== '' ? $heading : (string) $child['label'], 'field' => null];
        }
        $field = self::sortable($type, $spec);

        return $field === null ? null
            : ['spec' => $spec, 'kind' => 'field', 'key' => $spec, 'heading' => $heading !== '' ? $heading : (string) ($field['label'] ?? $spec), 'field' => $field];
    }
}
