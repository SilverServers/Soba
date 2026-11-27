<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\Auth\Auth;
use Cms\ContentType\ContentTypeSchema;
use Cms\Models\FieldGroup;

/**
 * A SITE'S OWN SETTINGS PAGES — where staff change the words and addresses
 * the site's templates print (a motto, a button's label, a list page's
 * introduction, an outside address), as an options page does.
 *
 * DECLARED IN CODE, in the site's own `admin/config/settings-pages.php`,
 * which no package ships and no update touches, because only a template
 * reads a page's values and a template is code:
 *
 *     \Cms\Site\SettingsPages::register('site-text', [
 *         'label'    => 'Site text',
 *         'icon'     => 'words',         // a sidebar mark (`Ui\Sidebar::iconFor()`)
 *         'role'     => 'editor',        // the least role that may open it: 'admin' unless named
 *         'place'    => 'top',           // 'settings' (the default): in Settings; 'top': under Dashboard
 *         'sections' => [
 *             ['label' => 'Home', 'fields' => [
 *                 ['key' => 'motto', 'label' => 'Motto', 'type' => 'text'],
 *             ]],
 *         ],
 *     ]);
 *
 * Its fields are written in the content types' field schema, every field type
 * with them, in sections each with its label (a page of one section may give
 * `'fields'` alone). A field group made on screen can be placed on a page as
 * well, beside the content types, and its fields follow the page's own; a key
 * the page's own fields already use is the code's, as on a content type
 * (`ContentTypeSchema::mergeFields()`). Values are options (`Site\Settings`).
 *
 * A row that is not one — a bad key, no label, an unknown role or place — is
 * said in the log and left out, so a typo never takes the admin down.
 */
final class SettingsPages
{
    /** Who may open a page whose row names no role: the administrators. */
    public const ROLE = 'admin';

    /** Where a page's sidebar row sits: in Settings, or among the lead rows under Dashboard. */
    public const PLACES = ['settings', 'top'];

    /** The prefix of a page's location in a field group's list of places. */
    public const LOCATION = 'settings:';

    /** A page's key: plain enough for an address, an option's key and a location. */
    private const KEY = '/^[a-z0-9][a-z0-9-]{0,40}$/';

    /** A field's key, as the field group editor writes one. */
    private const FIELD_KEY = '/^[a-z][a-z0-9_]{0,63}$/';

    /** @var array<string, array<string, mixed>> */
    private static array $pages = [];

    private static bool $loaded = false;

    /**
     * Declare a page. Its definition is checked here, once: a row that is not
     * one is logged and left out.
     *
     * @param array<string, mixed> $definition
     */
    public static function register(string $key, array $definition): void
    {
        $problem = self::problem($key, $definition);
        if ($problem !== null) {
            error_log('Settings pages: "' . $key . '" left out: ' . $problem);
            return;
        }
        $sections = isset($definition['sections'])
            ? (array) $definition['sections']
            : [['label' => (string) $definition['label'], 'fields' => (array) ($definition['fields'] ?? [])]];
        // A FIELD'S OPTION THE CMS DOES NOT KNOW, said in the log (`ContentType\FieldOptions`).
        $fields = [];
        foreach ($sections as $section) {
            array_push($fields, ...array_values((array) ($section['fields'] ?? [])));
        }
        \Cms\ContentType\FieldOptions::note('the settings page "' . $key . '"', $fields);

        self::$pages[$key] = [
            'key'      => $key,
            'label'    => trim((string) $definition['label']),
            'icon'     => trim((string) ($definition['icon'] ?? '')),
            'role'     => (string) ($definition['role'] ?? self::ROLE),
            'place'    => (string) ($definition['place'] ?? 'settings'),
            'sections' => array_values(array_map(static fn (array $s): array => [
                'label'  => trim((string) ($s['label'] ?? '')),
                'fields' => array_values((array) ($s['fields'] ?? [])),
            ], $sections)),
        ];
    }

    /**
     * Every declared page, in the order declared.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        self::load();

        return self::$pages;
    }

    /** @return array<string, mixed>|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * The pages the signed-in account may open, in one place of the sidebar.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forSidebar(string $place): array
    {
        return array_filter(self::all(), static fn (array $p): bool => $p['place'] === $place && Auth::hasRole($p['role']));
    }

    /** A page's place in a field group's list: `settings:{key}`. */
    public static function location(string $key): string
    {
        return self::LOCATION . $key;
    }

    /** The page a field group's location names, or null when it names a content type. */
    public static function fromLocation(string $location): ?string
    {
        return str_starts_with($location, self::LOCATION) ? substr($location, strlen(self::LOCATION)) : null;
    }

    /**
     * The page's fields in sections, as its screen draws them: its own
     * sections, then one per field group placed on it, headed by the group's
     * name; a field whose key the page already uses is left out of the group.
     *
     * @return list<array{label: string, fields: list<array<string, mixed>>}>
     */
    public static function sections(string $key): array
    {
        $page = self::get($key);
        if ($page === null) {
            return [];
        }
        $own = [];
        foreach ($page['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $own[] = $field + ['_group_title' => $section['label']];
            }
        }
        $merged = ContentTypeSchema::mergeFields($own, FieldGroup::getActiveGroupsForContentType(self::location($key)));

        $out = [];
        foreach ($merged as $field) {
            $label = (string) ($field['_group_title'] ?? $page['label']);
            if (!isset($out[$label])) {
                $out[$label] = ['label' => $label, 'fields' => []];
            }
            $out[$label]['fields'][] = $field;
        }

        return array_values($out);
    }

    /**
     * Every field on the page, its own and its groups', in one list.
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(string $key): array
    {
        $fields = [];
        foreach (self::sections($key) as $section) {
            array_push($fields, ...$section['fields']);
        }

        return $fields;
    }

    /** Forget the pages read this request: after a test declares one. */
    public static function reset(): void
    {
        self::$pages  = [];
        self::$loaded = false;
    }

    /** Read the site's list once, quietly (`ConfigFile::read()`). */
    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        if (defined('ROOT_PATH')) {
            ConfigFile::read(ROOT_PATH . '/config/settings-pages.php');
        }
    }

    /**
     * Why a row is not a page, or null when it is one.
     *
     * @param array<string, mixed> $definition
     */
    private static function problem(string $key, array $definition): ?string
    {
        if (preg_match(self::KEY, $key) !== 1) {
            return 'its key must be lower-case letters, digits and dashes';
        }
        if (trim((string) ($definition['label'] ?? '')) === '') {
            return 'it has no label';
        }
        $role = (string) ($definition['role'] ?? self::ROLE);
        if (!isset(Auth::ROLE_LEVELS[$role])) {
            return 'its role "' . $role . '" is not one of ' . implode(', ', array_keys(Auth::ROLE_LEVELS));
        }
        if (!in_array((string) ($definition['place'] ?? 'settings'), self::PLACES, true)) {
            return 'its place must be ' . implode(' or ', self::PLACES);
        }
        $sections = isset($definition['sections']) ? (array) $definition['sections'] : [['fields' => (array) ($definition['fields'] ?? [])]];
        $seen     = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                return 'a section is not a list';
            }
            foreach ((array) ($section['fields'] ?? []) as $field) {
                $fieldKey = is_array($field) ? (string) ($field['key'] ?? '') : '';
                if (preg_match(self::FIELD_KEY, $fieldKey) !== 1) {
                    return 'a field\'s key "' . $fieldKey . '" must start with a letter, then lower-case letters, digits and underscores';
                }
                if (isset($seen[$fieldKey])) {
                    return 'the field key "' . $fieldKey . '" is used twice';
                }
                $seen[$fieldKey] = true;
            }
        }

        return null;
    }
}
