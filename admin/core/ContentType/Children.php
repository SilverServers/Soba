<?php

declare(strict_types=1);

namespace Cms\ContentType;

/**
 * A PARENT'S CHILDREN, ON ITS OWN EDITOR — the child types a type's editor
 * shows, each as a panel of rows (`Ui\ChildRows`), named in its definition:
 *
 *     'children' => ['session' => ['columns' => ['title', 'speaker', 'photos']]]
 *
 * in code, or ticked on the Content Types screen (migration 145). A column is
 * the child's title or one of its fields a row can draw (`ROW_TYPES`), in the
 * order named. Each row is saved through the child's own checks
 * (`ContentTypeController::handleRowSave()`), so the panel never keeps what
 * the child's own editor would refuse.
 *
 * A child type that does not belong to this one, or a column the child has
 * not got or a row cannot draw, is said in the log and left out, so a typo
 * never fails silently and never takes the editor down.
 */
final class Children
{
    /** The field types a row draws: a box, a choice, a tick, a picture, a file or a gallery. */
    public const ROW_TYPES = ['text', 'textarea', 'number', 'date', 'datetime', 'select', 'checkbox', 'image', 'file', 'gallery'];

    /** @var array<string, list<array<string, mixed>>> parent key => what forType() found, once a request */
    private static array $resolved = [];

    /**
     * The child types `$parentKey`'s editor shows, in the order its definition
     * names them: each one's key, its definition with its fields, and its
     * columns, each `['key' => 'title', 'type' => 'title']` or a field's own
     * definition.
     *
     * @return list<array{key: string, type: array<string, mixed>, columns: list<array<string, mixed>>}>
     */
    public static function forType(string $parentKey): array
    {
        if (isset(self::$resolved[$parentKey])) {
            return self::$resolved[$parentKey];
        }

        $out   = [];
        $named = Registry::get($parentKey)['children'] ?? [];
        foreach (is_array($named) ? $named : [] as $childKey => $spec) {
            $childKey = (string) $childKey;
            $parent   = Registry::parentOf($childKey);
            if ($parent === null || (string) $parent['key'] !== $parentKey) {
                error_log('Content types: "' . $parentKey . '" shows the entries of "' . $childKey . '" on its editor, which is not a type that belongs to it; left out');
                continue;
            }
            $type    = ContentTypeSchema::resolve($childKey) ?? [];
            $columns = self::columns($parentKey, $childKey, $type, is_array($spec) ? ($spec['columns'] ?? []) : []);
            if ($columns === []) {
                error_log('Content types: "' . $parentKey . '" shows the entries of "' . $childKey . '" with no column a row can show; left out');
                continue;
            }
            $out[] = ['key' => $childKey, 'type' => $type, 'columns' => $columns];
        }

        return self::$resolved[$parentKey] = $out;
    }

    /**
     * The columns a row of `$childKey` shows on its parent's editor, or null
     * when its parent's editor does not show it: what a row's save may change.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function columnsOf(string $childKey): ?array
    {
        $parent = Registry::parentOf($childKey);
        if ($parent === null) {
            return null;
        }
        foreach (self::forType((string) $parent['key']) as $child) {
            if ($child['key'] === $childKey) {
                return $child['columns'];
            }
        }

        return null;
    }

    /**
     * What the Content Types screen offers a type: each type that belongs to
     * it, with every column one of its rows could show (its title, then each
     * field a row can draw, in the child's own order).
     *
     * @return list<array{key: string, label: string, columns: list<array{spec: string, label: string}>}>
     */
    public static function available(string $parentKey): array
    {
        $out = [];
        foreach (Registry::childTypesOf($parentKey) as $childKey => $definition) {
            $columns = [['spec' => 'title', 'label' => 'Title']];
            foreach (ContentTypeSchema::fields((string) $childKey) as $field) {
                if (in_array((string) ($field['type'] ?? 'text'), self::ROW_TYPES, true) && (string) ($field['key'] ?? '') !== '') {
                    $columns[] = ['spec' => (string) $field['key'], 'label' => (string) ($field['label'] ?? $field['key'])];
                }
            }
            $out[] = ['key' => (string) $childKey, 'label' => (string) ($definition['label'] ?? $childKey), 'columns' => $columns];
        }

        return $out;
    }

    /**
     * Each named column as a row draws it, those that cannot be left out and
     * said in the log.
     *
     * @param array<string, mixed> $type
     * @param mixed                $named
     * @return list<array<string, mixed>>
     */
    private static function columns(string $parentKey, string $childKey, array $type, mixed $named): array
    {
        $fields = [];
        foreach ((array) ($type['fields'] ?? []) as $field) {
            $fields[(string) ($field['key'] ?? '')] = $field;
        }

        $out  = [];
        $seen = [];
        foreach (is_array($named) ? $named : [] as $column) {
            $column = is_scalar($column) ? (string) $column : '';
            if ($column === '' || isset($seen[$column])) {
                continue;
            }
            $seen[$column] = true;
            if ($column === 'title') {
                $out[] = ['key' => 'title', 'type' => 'title', 'label' => 'Title'];
                continue;
            }
            $field = $fields[$column] ?? null;
            if ($field === null || !in_array((string) ($field['type'] ?? 'text'), self::ROW_TYPES, true)) {
                error_log('Content types: "' . $parentKey . '" shows the column "' . $column . '" on the rows of "' . $childKey . '", '
                    . ($field === null ? 'which is not one of its fields' : 'a ' . (string) $field['type'] . ' field, which a row cannot show') . '; left out');
                continue;
            }
            $out[] = $field;
        }

        return $out;
    }
}
