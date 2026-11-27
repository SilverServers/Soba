<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\Auth\Auth;
use Cms\ContentType\Registry;
use Cms\ContentType\TaxonomyRegistry;
use Cms\Database\Database;
use Cms\Helpers\Url;
use Cms\Ui\AdminMenu;

/**
 * THE COLUMN, ARRANGED BY THE SITE — the admin's sidebar as its staff work,
 * one row per job under the site's own headings, in place of a group per
 * content type.
 *
 * DECLARED IN `admin/config/admin-menu.php`, which no package ships and no
 * update touches. It returns the column as rows:
 *
 *     return [
 *         ['type' => 'event', 'label' => 'Events'],
 *         ['heading' => 'People', 'rows' => [
 *             ['type' => 'staff'],
 *             ['terms' => 'department', 'label' => 'Departments'],
 *         ]],
 *         ['heading' => 'Website', 'rows' => [
 *             ['page' => 'about', 'label' => 'About us'],
 *             ['screen' => 'media', 'label' => 'Photos', 'icon' => 'image'],
 *             ['settings' => 'site-text'],
 *             ['tool' => 'live', 'role' => 'editor'],
 *             ['url' => '/admin/forms/3/submissions', 'label' => 'Applications', 'icon' => 'envelope'],
 *         ]],
 *         ['cms' => 'Running the site'],
 *     ];
 *
 * A row names one thing:
 *   type      a content type's list, by its key; lit on the list, its
 *             entries, Add new and its trash
 *   terms     a taxonomy's terms, by its key
 *   page      a page's editor, by its address
 *   tool      a site tool, by its path (`Site\Tools`)
 *   settings  a settings page the site declares, by its key (`SettingsPages`)
 *   screen    one of the CMS's own screens, by its address under the
 *             admin's: 'media', 'forms/submissions', 'settings/business'
 *             (`Ui\AdminMenu::SCREENS`)
 *   url       an address: the site's own (one under /admin follows the
 *             admin) or another site's
 * with its `label` and `icon` (the thing's own unless it names them; an
 * address must name its label) and `role`, the least role that sees it. A
 * row is shown only to an account its screen lets in, besides, by the gates
 * the column has always mirrored, a grant included (`Auth::can()`): no one
 * meets a row that turns them away (`AdminMenu::opens()`).
 *
 * A row under no heading is among the lead rows, under Dashboard. What the
 * file leaves out has no row, and still opens by its address, a type from
 * + New too. For an administrator the CMS's own groups, Files and media to
 * Security, follow the rows under one heading, Administration unless
 * `['cms' => …]` names it, less any screen the file has placed; a site tool
 * or settings page is wherever the file puts it, and no type has a group.
 * Without the file the column is the CMS's own.
 *
 * A ROW THAT IS NOT ONE (nothing to open or two things, a thing there is none
 * of, a key a row does not take, an address with no label, a role there is
 * none of) is left out and said in the log, once and then at most hourly while
 * it stays, since every admin page draws the column; a heading that is not
 * one goes with its rows.
 */
final class Column
{
    /** What a row may name, one each. */
    public const KINDS = ['type', 'terms', 'page', 'tool', 'settings', 'screen', 'url'];

    /** What a row may say besides. */
    public const OWN = ['label', 'icon', 'role'];

    /** The heading over the CMS's own groups, for administrators, unless the file names one. */
    public const CMS_HEADING = 'Administration';

    /** @var array{lead: list<array<string, mixed>>, headings: list<array{label: string, rows: list<array<string, mixed>>}>, cms: string}|false|null */
    private static array|false|null $column = null;

    /**
     * The site's column, each row resolved, or null when the site has no
     * admin/config/admin-menu.php, or it returns no list. Read once a
     * request.
     *
     * @return array{lead: list<array<string, mixed>>, headings: list<array{label: string, rows: list<array<string, mixed>>}>, cms: string}|null
     */
    public static function rows(): ?array
    {
        if (self::$column === null) {
            self::$column = self::read();
        }

        return self::$column === false ? null : self::$column;
    }

    /** Forget the file read this request: after a test writes one. */
    public static function reset(): void
    {
        self::$column = null;
    }

    /**
     * A list of rows as the column draws it, each resolved, and what in it
     * is not one, in words. Pure but for what it looks up: the registries,
     * the site's tools and settings pages, and the pages by their address.
     *
     * @param array<mixed> $list
     * @return array{0: array{lead: list<array<string, mixed>>, headings: list<array{label: string, rows: list<array<string, mixed>>}>, cms: string}, 1: list<string>}
     */
    public static function parse(array $list): array
    {
        $problems = [];
        $headings = [];
        $cms      = self::CMS_HEADING;
        $raw      = [];   // [heading index, or null for the lead rows; the row]

        foreach ($list as $entry) {
            if (!is_array($entry)) {
                $problems[] = 'an entry that is not a row';
                continue;
            }
            if (array_key_exists('cms', $entry)) {
                if (count($entry) !== 1 || !is_string($entry['cms']) || trim($entry['cms']) === '') {
                    $problems[] = "the heading over the CMS's own groups, which is named alone: ['cms' => 'Its words']";
                    continue;
                }
                $cms = trim($entry['cms']);
                continue;
            }
            if (array_key_exists('heading', $entry)) {
                $label = is_string($entry['heading']) ? trim($entry['heading']) : '';
                if ($label === '' || !is_array($entry['rows'] ?? null) || !array_is_list($entry['rows'])
                    || array_diff(array_keys($entry), ['heading', 'rows']) !== []) {
                    $problems[] = 'the heading "' . $label . "\" with its rows, since a heading is ['heading' => 'Its words', 'rows' => [ … ]]";
                    continue;
                }
                $headings[] = ['label' => $label, 'rows' => []];
                foreach ($entry['rows'] as $row) {
                    $raw[] = [count($headings) - 1, $row];
                }
                continue;
            }
            $raw[] = [null, $entry];
        }

        $pages = self::pagesAt(array_map(static fn (array $r): mixed => is_array($r[1]) ? ($r[1]['page'] ?? null) : null, $raw));
        $lead  = [];
        foreach ($raw as [$place, $row]) {
            $problem  = '';
            $resolved = self::resolve($row, $pages, $problem);
            if ($resolved === null) {
                $problems[] = $problem;
                continue;
            }
            if ($place === null) {
                $lead[] = $resolved;
            } else {
                $headings[$place]['rows'][] = $resolved;
            }
        }

        return [['lead' => $lead, 'headings' => $headings, 'cms' => $cms], $problems];
    }

    /** Read the site's file, quietly (`ConfigFile::read()`), and say once an hour what in it is not a row. */
    private static function read(): array|false
    {
        $file = defined('ROOT_PATH') ? ROOT_PATH . '/config/admin-menu.php' : '';
        if ($file === '' || !is_file($file)) {
            return false;
        }
        $list = ConfigFile::read($file);
        if (!is_array($list) || !array_is_list($list)) {
            self::note(['the whole file, which returns no list of rows, so the column is the CMS\'s own']);

            return false;
        }
        [$column, $problems] = self::parse($list);
        if ($problems !== []) {
            self::note($problems);
        }

        return $column;
    }

    /**
     * One row, resolved to what the column draws: its words, mark and
     * address, the admin address it lights on and how (`AdminMenu::lit()`),
     * the door its screen keeps (`AdminMenu::opens()`) and its own least
     * role. Null, and the reason in words, for a row that is not one.
     *
     * @param array<string, array{id: int, title: string}> $pages  by address
     * @return array{kind: string, target: string, label: string, icon: string, href: string, path: ?string, match: string, door: array<string, string>, role: ?string}|null
     */
    private static function resolve(mixed $row, array $pages, string &$problem): ?array
    {
        if (!is_array($row)) {
            $problem = 'a row that is not a list of keys';

            return null;
        }
        $kinds = array_values(array_intersect(array_keys($row), self::KINDS));
        if (count($kinds) !== 1) {
            $problem = $kinds === []
                ? 'a row naming nothing to open, where it names one of ' . implode(', ', self::KINDS)
                : 'a row naming ' . implode(' and ', $kinds) . ', where it names one';

            return null;
        }
        $kind   = $kinds[0];
        $target = is_string($row[$kind]) ? trim($row[$kind]) : '';
        $what   = 'the ' . $kind . ' row "' . $target . '"';
        $extra  = array_values(array_diff(array_keys($row), [$kind, ...self::OWN]));
        if ($extra !== []) {
            $problem = $what . ', since a row takes no "' . implode('" or "', array_map('strval', $extra)) . '"';

            return null;
        }
        if ($target === '') {
            $problem = 'a ' . $kind . ' row naming none';

            return null;
        }
        foreach (['label', 'icon'] as $own) {
            if (array_key_exists($own, $row) && (!is_string($row[$own]) || trim($row[$own]) === '')) {
                $problem = $what . ', whose ' . $own . ' is empty';

                return null;
            }
        }
        $role = $row['role'] ?? null;
        if ($role !== null && (!is_string($role) || !isset(Auth::ROLE_LEVELS[$role]))) {
            $problem = $what . ', whose role is not one of ' . implode(', ', array_keys(Auth::ROLE_LEVELS));

            return null;
        }
        if ($kind === 'url' && !isset($row['label'])) {
            $problem = $what . ', since an address names its label';

            return null;
        }

        $thing = match ($kind) {
            'type'     => self::type($target),
            'terms'    => self::terms($target),
            'page'     => isset($pages[$target]) ? self::page($pages[$target]) : null,
            'tool'     => self::tool($target),
            'settings' => self::settings($target),
            'screen'   => self::screen($target),
            default    => self::address($target),
        };
        if ($thing === null) {
            $problem = match ($kind) {
                'type'     => 'the type row "' . $target . '", since there is no content type "' . $target . '"',
                'terms'    => 'the terms row "' . $target . '", since there is no taxonomy "' . $target . '"',
                'page'     => 'the page row "' . $target . '", since no page has the address "' . $target . '"',
                'tool'     => 'the tool row "' . $target . '", since there is no site tool "' . $target . '"',
                'settings' => 'the settings row "' . $target . '", since there is no settings page "' . $target . '"',
                'screen'   => 'the screen row "' . $target . "\", since the CMS has no screen at that address",
                default    => 'the url row "' . $target . '", since it is not an address',
            };

            return null;
        }

        return [
            'kind'   => $kind,
            'target' => $target,
            'label'  => isset($row['label']) ? trim((string) $row['label']) : $thing['label'],
            'icon'   => isset($row['icon']) ? trim((string) $row['icon']) : $thing['icon'],
            'href'   => $thing['href'],
            'path'   => $thing['path'],
            'match'  => $thing['match'],
            'door'   => $thing['door'],
            'role'   => $role,
        ];
    }

    /** @return array{label: string, icon: string, href: string, path: ?string, match: string, door: array<string, string>}|null */
    private static function type(string $key): ?array
    {
        $type = Registry::get($key);
        if ($type === null) {
            return null;
        }
        $slug = trim((string) $type['admin_slug'], '/');

        // `ContentTypeController` lets in every account: an author sees their own.
        return ['label' => (string) $type['label'], 'icon' => (string) $type['menu_icon'], 'href' => Url::admin($slug),
                'path' => '/admin/' . $slug, 'match' => 'under', 'door' => ['role' => 'author']];
    }

    /** @return array{label: string, icon: string, href: string, path: ?string, match: string, door: array<string, string>}|null */
    private static function terms(string $key): ?array
    {
        $tax = TaxonomyRegistry::get($key);
        if ($tax === null) {
            return null;
        }
        $slug = trim((string) $tax['admin_slug'], '/');

        // `TermController` is an editor's on every action.
        return ['label' => (string) $tax['label'], 'icon' => 'terms', 'href' => Url::admin('taxonomies/' . $slug . '/terms'),
                'path' => '/admin/taxonomies/' . $slug . '/terms', 'match' => 'under', 'door' => ['role' => 'editor']];
    }

    /**
     * @param array{id: int, title: string} $page
     * @return array{label: string, icon: string, href: string, path: ?string, match: string, door: array<string, string>}
     */
    private static function page(array $page): array
    {
        return ['label' => $page['title'], 'icon' => 'pages', 'href' => Url::admin('pages/' . $page['id'] . '/edit'),
                'path' => '/admin/pages/' . $page['id'], 'match' => 'under', 'door' => ['screen' => 'pages']];
    }

    /** @return array{label: string, icon: string, href: string, path: ?string, match: string, door: array<string, string>}|null */
    private static function tool(string $path): ?array
    {
        $tool = Tools::all()[$path] ?? null;
        if ($tool === null) {
            return null;
        }

        return ['label' => $tool['label'], 'icon' => $tool['icon'] !== '' ? $tool['icon'] : 'tools', 'href' => Url::admin('tools/' . $path),
                'path' => '/admin/tools/' . $path, 'match' => 'under', 'door' => ['role' => $tool['role']]];
    }

    /** @return array{label: string, icon: string, href: string, path: ?string, match: string, door: array<string, string>}|null */
    private static function settings(string $key): ?array
    {
        $page = SettingsPages::get($key);
        if ($page === null) {
            return null;
        }

        return ['label' => (string) $page['label'], 'icon' => (string) $page['icon'] !== '' ? (string) $page['icon'] : 'settings',
                'href' => Url::admin('site-settings/' . $key), 'path' => '/admin/site-settings/' . $key, 'match' => 'under',
                'door' => ['role' => (string) $page['role']]];
    }

    /** @return array{label: string, icon: string, href: string, path: ?string, match: string, door: array<string, string>}|null */
    private static function screen(string $key): ?array
    {
        $screen = AdminMenu::SCREENS[$key] ?? null;
        if ($screen === null) {
            return null;
        }

        return ['label' => $screen[0], 'icon' => $screen[1], 'href' => Url::admin($key), 'path' => '/admin/' . $key,
                'match' => $screen[2], 'door' => ['screen' => $key]];
    }

    /**
     * An address: one under /admin is the admin's, wherever it answers, lit
     * on that address alone and shown to whoever its screen lets in; another
     * of the site's, or another site's, leaves the admin and lights nowhere.
     *
     * @return array{label: string, icon: string, href: string, path: ?string, match: string, door: array<string, string>}|null
     */
    private static function address(string $url): ?array
    {
        if (preg_match('~^https?://[^/\s]+~i', $url) === 1) {
            return ['label' => '', 'icon' => 'link', 'href' => $url, 'path' => null, 'match' => 'exact', 'door' => ['role' => 'author']];
        }
        if (!str_starts_with($url, '/') || preg_match('~\s~', $url) === 1) {
            return null;
        }
        $path = '/' . trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            $admin = rtrim($path, '/');

            return ['label' => '', 'icon' => 'link', 'href' => Url::admin(substr($url, strlen('/admin'))),
                    'path' => $admin, 'match' => 'exact', 'door' => ['path' => $admin]];
        }

        return ['label' => '', 'icon' => 'link', 'href' => Url::public(ltrim($url, '/')), 'path' => null, 'match' => 'exact', 'door' => ['role' => 'author']];
    }

    /**
     * The pages the rows name, by address, in the site's own language, in
     * one query: each one's id and title. A page in the Trash has none.
     *
     * @param list<mixed> $addresses
     * @return array<string, array{id: int, title: string}>
     */
    private static function pagesAt(array $addresses): array
    {
        $addresses = array_values(array_unique(array_filter(array_map(
            static fn (mixed $a): string => is_string($a) ? trim($a) : '',
            $addresses
        ))));
        if ($addresses === []) {
            return [];
        }
        $stmt = Database::getInstance()->prepare(
            "SELECT id, slug, title FROM pages WHERE lang = '' AND status <> 'trash' AND slug IN ("
            . implode(', ', array_fill(0, count($addresses), '?')) . ')'
        );
        $stmt->execute($addresses);
        $pages = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $pages[(string) $row['slug']] = ['id' => (int) $row['id'], 'title' => (string) $row['title']];
        }

        return $pages;
    }

    /**
     * What is left out, in the log: once, then again an hour later if it is
     * still there, since every admin page draws the column.
     *
     * @param list<string> $problems
     */
    private static function note(array $problems): void
    {
        \Cms\Helpers\LogNote::hourly('admin_menu_noted', 'Admin menu: left out of admin/config/admin-menu.php: ' . implode('; ', $problems) . '.');
    }
}
