<?php

declare(strict_types=1);

namespace Cms\ContentType;

use Cms\Auth\Auth;

/**
 * WHAT STAFF SEE ON A TYPE'S SCREENS, by role: the panels of an entry's
 * editor, the parts of its list and how the list is grouped, and who may add
 * a term to a taxonomy. Staff whose work never touches the author's name, a
 * search engine's title or a bulk action are not shown them, and an editor
 * cannot add a fifth location only an administrator should.
 *
 * DECLARED per type, in code or on its Content Types screen (migration 140):
 *
 *     'panels' => ['author' => 'admin', 'seo' => 'admin'],
 *     'list'   => ['address' => 'admin', 'author_filter' => 'admin', 'bulk' => 'admin', 'group_by' => 'section'],
 *
 * and per taxonomy, in code or on its screen in Structure → Taxonomies:
 *
 *     'add_terms' => 'admin',
 *
 * Each names the least role that sees the thing, or `false` for no one; what
 * a type does not name is as it was (`PANELS`, `LIST`), so today's screens
 * stay the default. A role there is none of is said in the log, once an hour,
 * and taken as the default, so a typo neither hides a panel from everyone nor
 * shows it to the wrong people.
 *
 * A panel not shown leaves its stored values as they are on save; a list's
 * bulk action is refused to a role its list does not offer it to; and a term
 * is refused alike by the entry editor's row, the API and the terms screen.
 */
final class StaffView
{
    /** An entry's editor's panels, and who sees each unless the type says. */
    public const PANELS = ['author' => 'editor', 'seo' => 'author'];

    /** A list's parts, and who sees each unless the type says. */
    public const LIST = ['address' => 'author', 'author_filter' => 'editor', 'bulk' => 'author'];

    /** What each is called on the Content Types screen and in the log. */
    public const NAMES = [
        'author'        => 'Author panel',
        'seo'           => 'Search engines panel',
        'address'       => 'Addresses',
        'author_filter' => 'Author filter',
        'bulk'          => 'Bulk actions',
    ];

    /** The choices the Content Types and Taxonomies screens offer for each, by what they post. */
    public const ROLE_CHOICES = [
        'author'       => 'Authors and up',
        'editor'       => 'Editors and up',
        'shop_manager' => 'Shop managers and up',
        'admin'        => 'Administrators',
        'none'         => 'No one',
    ];

    /**
     * What a screen posted for one part, as the type keeps it: a role, false
     * for no one, or null for the default (not kept). Anything else posted is
     * the default.
     */
    public static function posted(mixed $value, string $default): string|false|null
    {
        $value = is_string($value) ? $value : '';
        $role  = $value === 'none' ? false : (isset(Auth::ROLE_LEVELS[$value]) ? $value : $default);

        return $role === $default ? null : $role;
    }

    /**
     * What the type's list can be grouped by, for its Content Types screen and
     * its save: each Choice field by its key, then each taxonomy on the type as
     * `tax:{key}`.
     *
     * @return array<string, string>
     */
    public static function groupOptions(string $type): array
    {
        $out = [];
        foreach (ContentTypeSchema::fields($type) as $field) {
            if (($field['type'] ?? '') === 'select' && isset($field['key'])) {
                $out[(string) $field['key']] = (string) ($field['label'] ?? $field['key']);
            }
        }
        foreach (TaxonomyRegistry::getForContentType($type) as $key => $taxonomy) {
            $out['tax:' . $key] = (string) $taxonomy['label'];
        }

        return $out;
    }

    /** Does the signed-in account see this panel of the type's entries? */
    public static function panel(string $type, string $panel): bool
    {
        $role = self::role($type, 'panels', $panel, self::PANELS[$panel] ?? 'admin');

        return $role !== false && Auth::hasRole($role);
    }

    /** Does the signed-in account see this part of the type's list? */
    public static function listPart(string $type, string $part): bool
    {
        $role = self::role($type, 'list', $part, self::LIST[$part] ?? 'admin');

        return $role !== false && Auth::hasRole($role);
    }

    /**
     * The least role that sees a panel or a list's part of the type, or false
     * for no one: the type's own word, else the default.
     */
    public static function role(string $type, string $set, string $part, string $default): string|false
    {
        $named = (array) (Registry::get($type)[$set] ?? []);
        if (!array_key_exists($part, $named) || $named[$part] === null || $named[$part] === '' || $named[$part] === true) {
            return $default;
        }
        if ($named[$part] === false) {
            return false;
        }
        if (is_string($named[$part]) && isset(Auth::ROLE_LEVELS[$named[$part]])) {
            return $named[$part];
        }
        \Cms\Helpers\LogNote::hourly('staff_view_noted', 'Content types: "' . $type . '" names a role there is none of for its '
            . strtolower(self::NAMES[$part] ?? $part) . ' ("' . (is_scalar($named[$part]) ? (string) $named[$part] : gettype($named[$part]))
            . '"); it is shown as it was');

        return $default;
    }

    /**
     * WHAT THE TYPE'S LIST IS GROUPED BY (`'group_by'`): a Choice field of
     * the type, by its key, or a taxonomy on it, by its key or as
     * `tax:{key}`, with each value's heading in the order the field or the
     * taxonomy keeps them. Null when the list is not grouped, or names
     * neither.
     *
     * @return array{kind: string, key: string, label: string, values: array<string, string>}|null
     */
    public static function groupBy(string $type): ?array
    {
        $named = (array) (Registry::get($type)['list'] ?? []);
        $by    = is_string($named['group_by'] ?? null) ? trim($named['group_by']) : '';
        if ($by === '') {
            return null;
        }
        $taxKey = str_starts_with($by, 'tax:') ? substr($by, 4) : $by;
        $field  = str_starts_with($by, 'tax:') ? null : ContentTypeSchema::field($type, $by);
        if ($field !== null && ($field['type'] ?? '') === 'select') {
            $values = [];
            foreach ((array) ($field['options'] ?? []) as $value => $label) {
                $values[(string) $value] = (string) $label;
            }

            return ['kind' => 'field', 'key' => $by, 'label' => (string) ($field['label'] ?? $by), 'values' => $values];
        }
        $taxonomy = TaxonomyRegistry::getForContentType($type)[$taxKey] ?? null;
        if ($taxonomy !== null) {
            $values = [];
            foreach (self::flatten(\Cms\Models\Term::treeForTaxonomy($taxKey)) as $term) {
                $values[(string) $term['id']] = (string) $term['name'];
            }

            return ['kind' => 'tax', 'key' => $taxKey, 'label' => (string) $taxonomy['label'], 'values' => $values];
        }
        \Cms\Helpers\LogNote::hourly('staff_view_noted', 'Content types: "' . $type . '" groups its list by "' . $by
            . '", which is neither a Choice field of it nor a taxonomy on it; the list is not grouped');

        return null;
    }

    /**
     * Ids in the list's order, put into the group's: each value's entries
     * together, in the values' order, the list's own order kept within each,
     * and those with none last. A taxonomy's entry goes under the first of
     * its terms in the taxonomy's order.
     *
     * @param array{kind: string, key: string, values: array<string, string>} $group
     * @param list<int> $ids
     * @return array{ids: list<int>, of: array<int, string>}  `of`: each id's value, '' for none
     */
    public static function grouped(array $group, array $ids): array
    {
        if ($ids === []) {
            return ['ids' => [], 'of' => []];
        }
        $rank = array_flip(array_map('strval', array_keys($group['values'])));
        $of   = [];
        if ($group['kind'] === 'field') {
            $meta = \Cms\Models\Meta::getAllFor($ids);
            foreach ($ids as $id) {
                $value     = (string) ($meta[$id][$group['key']] ?? '');
                $of[$id]   = isset($rank[$value]) ? $value : '';
            }
        } else {
            $terms = \Cms\Models\Term::forPosts($ids, [$group['key']]);
            foreach ($ids as $id) {
                $best = '';
                foreach ($terms[$id][$group['key']] ?? [] as $term) {
                    $termId = (string) $term['id'];
                    if (isset($rank[$termId]) && ($best === '' || $rank[$termId] < $rank[$best])) {
                        $best = $termId;
                    }
                }
                $of[$id] = $best;
            }
        }
        $buckets = [];
        foreach ($ids as $id) {
            $buckets[$of[$id]][] = $id;
        }
        $ordered = [];
        foreach ([...array_keys($group['values']), ''] as $value) {
            array_push($ordered, ...($buckets[(string) $value] ?? []));
        }

        return ['ids' => $ordered, 'of' => $of];
    }

    /**
     * May the signed-in account add a term to the taxonomy? Its
     * `'add_terms'` role, else as it was: an author on a tag-style taxonomy,
     * an editor on a nested one. Editing the terms there are stays an
     * editor's, on the terms screen.
     */
    public static function addsTerms(string $taxKey): bool
    {
        $role = self::addTermsRole($taxKey);

        return $role !== false && Auth::hasRole($role);
    }

    /** The least role that may add a term to the taxonomy, or false for no one. */
    public static function addTermsRole(string $taxKey): string|false
    {
        $taxonomy = TaxonomyRegistry::get($taxKey) ?? [];
        $default  = !empty($taxonomy['hierarchical']) ? 'editor' : 'author';
        $named    = $taxonomy['add_terms'] ?? null;
        if ($named === null || $named === '' || $named === true) {
            return $default;
        }
        if ($named === false) {
            return false;
        }
        if (is_string($named) && isset(Auth::ROLE_LEVELS[$named])) {
            return $named;
        }
        \Cms\Helpers\LogNote::hourly('staff_view_noted', 'Taxonomies: "' . $taxKey . '" names a role there is none of to add its terms ("'
            . (is_scalar($named) ? (string) $named : gettype($named)) . '"); they are added as they were');

        return $default;
    }

    /**
     * A role as the screens offer it, for its words: "an administrator", or
     * "no one".
     */
    public static function who(string|false $role): string
    {
        if ($role === false) {
            return 'no one';
        }
        $name = strtolower(Auth::ROLE_LABELS[$role] ?? $role);

        return (preg_match('/^[aeiou]/', $name) === 1 ? 'an ' : 'a ') . $name;
    }

    /**
     * @param array<int, array<string, mixed>> $tree
     * @return list<array<string, mixed>>
     */
    private static function flatten(array $tree): array
    {
        $out = [];
        foreach ($tree as $term) {
            $out[] = $term;
            array_push($out, ...self::flatten((array) ($term['children'] ?? [])));
        }

        return $out;
    }
}
