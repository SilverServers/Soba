<?php

declare(strict_types=1);

namespace Cms\ContentType;

use Cms\Models\FieldGroup;

/**
 * Content Type schema resolver — the single answer to "what fields does this
 * Content Type actually have?"
 *
 * A Content Type's field list comes from two places that have to be merged:
 *
 *   1. Code-registered fields — the `fields` array passed to Registry::register()
 *      in config/content-types.php.
 *   2. Active DB field groups — created through the admin UI, or auto-created
 *      from an archetype, and stored in the `field_groups` table.
 *
 * Code-registered fields win on key collision, because code is the source of
 * truth for a developer-defined install.
 *
 * This merge used to live inline in Admin\ContentTypeController::__construct().
 * It was lifted here because more than one caller needs the resolved list — the
 * admin edit screen, and anything that has to reason about a Content Type's shape
 * programmatically (the API layer, and the CMS Assistant). Two copies
 * of this logic would eventually disagree about what fields a Content Type has,
 * which is the worst possible bug in anything that edits content.
 */
class ContentTypeSchema
{
    /**
     * Resolve a Content Type definition with its full, merged field list.
     *
     * Returns null if the key is not registered — callers decide whether that is
     * a 404, an exception, or an empty result.
     *
     * Archetype-sourced fields are tagged with `_from_archetype` and
     * `_archetype_key` so the edit template can render them in their own cards.
     *
     * @return array<string, mixed>|null The Registry definition with 'fields' merged
     */
    public static function resolve(string $contentTypeKey): ?array
    {
        $contentType = Registry::get($contentTypeKey);
        if ($contentType === null) {
            return null;
        }

        $contentType['fields'] = self::mergeFields(
            $contentType['fields'] ?? [],
            FieldGroup::getActiveGroupsForContentType($contentTypeKey)
        );

        return $contentType;
    }

    /**
     * Just the merged field list, without the surrounding definition.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fields(string $contentTypeKey): array
    {
        return self::resolve($contentTypeKey)['fields'] ?? [];
    }

    /**
     * Look up one field definition by its key. Null if the Content Type does not
     * define it — which is the check to make before writing a meta value, since
     * post_meta will happily store any key you hand it.
     *
     * @return array<string, mixed>|null
     */
    public static function field(string $contentTypeKey, string $fieldKey): ?array
    {
        foreach (self::fields($contentTypeKey) as $field) {
            if (($field['key'] ?? null) === $fieldKey) {
                return $field;
            }
        }
        return null;
    }

    /**
     * Merge code-registered fields with DB field group fields: a content
     * type's, and a settings page's (`Site\SettingsPages`), by one rule.
     *
     * @param array<int, array<string, mixed>> $codeFields Fields from Registry::register()
     * @param array<int, array<string, mixed>> $dbGroups   Rows from FieldGroup::getActiveGroupsForContentType()
     * @return array<int, array<string, mixed>>
     */
    public static function mergeFields(array $codeFields, array $dbGroups): array
    {
        $merged   = $codeFields;
        $seenKeys = array_column($codeFields, 'key');

        foreach ($dbGroups as $group) {
            $groupFields = $group['fields'];

            // WHICH GROUP A FIELD CAME FROM, on the field.
            //
            // The merge flattens every group into one list, which is right for a
            // consumer asking "what fields does this type have" and wrong for the
            // entry form, which has to head each group with the group's NAME. The
            // field group editor says, in its own help text, that the name
            // "appears as the heading above these fields on the entry form" — and
            // that was false: the form headed every custom field with
            // "<Type> details" and the group's name appeared nowhere. A form
            // telling its author what a value does, wrongly, is the same defect
            // as a control that posts nowhere.
            //
            // Underscore-prefixed, like the archetype tags below, because these
            // are facts ABOUT a field rather than part of its definition — nothing
            // in FieldRenderer's match reads them.
            foreach ($groupFields as &$g) {
                $g['_group_id']    = (int) $group['id'];
                $g['_group_title'] = (string) $group['title'];
                // Where its fields go on the entry editor (migration 149); '' follows the type.
                $g['_group_position'] = (string) ($group['position'] ?? '');
            }
            unset($g);

            // Tag archetype group fields so the edit template can card them separately.
            if (!empty($group['is_archetype'])) {
                foreach ($groupFields as &$f) {
                    $f['_from_archetype'] = true;
                    $f['_archetype_key']  = $group['archetype_key'] ?? '';
                }
                unset($f);
            }

            // Deduplicate: a code-registered field with the same key wins.
            foreach ($groupFields as $gf) {
                if (!in_array($gf['key'], $seenKeys, true)) {
                    $merged[]   = $gf;
                    $seenKeys[] = $gf['key'];
                }
            }
        }

        return $merged;
    }
}
