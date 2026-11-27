<?php

declare(strict_types=1);

namespace Cms\ContentType;

use Cms\Models\FieldGroup;

/**
 * A TAXONOMY'S TERMS' OWN FIELDS: a location's address, phone and photo kept
 * on the location itself rather than split between Business details and a
 * page's text.
 *
 * Two sources, merged by the rule an entry's are (`ContentTypeSchema::
 * mergeFields()`): the fields a taxonomy registers in code (`'fields' => […]`
 * in `TaxonomyRegistry::register()`, under "{Term} details"), then each field
 * group placed on the taxonomy (`taxonomy:{key}`, ticked in Structure → Field
 * groups), under its own name; a key the code already uses stays the code's.
 * The values are kept in `term_meta` (`Term::meta()`, `Term::setMeta()`),
 * edited on the term's screen and printed on its page.
 */
final class TermFields
{
    /** How a field group's location names a taxonomy: `taxonomy:location`. */
    public const LOCATION = 'taxonomy:';

    public static function location(string $taxonomy): string
    {
        return self::LOCATION . $taxonomy;
    }

    /** The taxonomy a field group's location names, or null when it names something else. */
    public static function fromLocation(string $location): ?string
    {
        return str_starts_with($location, self::LOCATION) ? substr($location, strlen(self::LOCATION)) : null;
    }

    /**
     * The taxonomy's term fields in sections, as the term's screen draws them.
     *
     * @return list<array{label: string, fields: list<array<string, mixed>>}>
     */
    public static function sections(string $taxonomy): array
    {
        $definition = TaxonomyRegistry::get($taxonomy);
        if ($definition === null) {
            return [];
        }
        $heading = (string) $definition['label_single'] . ' details';
        $own     = [];
        foreach ((array) ($definition['fields'] ?? []) as $field) {
            if (is_array($field) && is_string($field['key'] ?? null) && $field['key'] !== '') {
                $own[] = $field + ['_group_title' => $heading];
            }
        }
        $merged = ContentTypeSchema::mergeFields($own, FieldGroup::getActiveGroupsForContentType(self::location($taxonomy)));

        $out = [];
        foreach ($merged as $field) {
            $label = (string) ($field['_group_title'] ?? $heading);
            if (!isset($out[$label])) {
                $out[$label] = ['label' => $label, 'fields' => []];
            }
            $out[$label]['fields'][] = $field;
        }

        return array_values($out);
    }

    /**
     * Every term field of the taxonomy, in one list.
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(string $taxonomy): array
    {
        $fields = [];
        foreach (self::sections($taxonomy) as $section) {
            array_push($fields, ...$section['fields']);
        }

        return $fields;
    }
}
