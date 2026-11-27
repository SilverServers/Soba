<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\ContentType\Registry;
use Cms\ContentType\TaxonomyRegistry;
use Cms\Helpers\Theme;
use Cms\Helpers\Url;
use Cms\Models\Option;

/**
 * WHAT A PAGE LISTS, AS ITS THEME SAYS: a contacts type printed on a Contacts
 * page, a taxonomy's terms on a Locations page. A theme picks a page's layout
 * by its address, and names in its theme.json what each such page lists:
 *
 *     "page_lists": {"contact": ["staff"], "truckers": ["trucker"], "visit": ["terms:location"]}
 *
 * a type by its key, a taxonomy's terms as `terms:{key}`, the page by its
 * address (`about/team` for a page under another). The page's editor then
 * says, above its text, where its list comes from, with the way to open that
 * list and to add to it, and calls its text the page's introduction. A name
 * that is not a type or a taxonomy is said in the log, at most hourly while it
 * stays, and left out.
 */
final class PageLists
{
    /** When the log last heard which names were left out, and of what: md5|time. */
    private const NOTED = 'page_lists_noted';

    /** @var array<string, list<array<string, mixed>>>|null this request's, read once */
    private static ?array $lists = null;

    /**
     * Every page the active theme names, by its address, each with what it
     * lists: `kind` (type or terms), `key`, `plural`, `single`, `open` (the
     * list's screen) and `add` (the screen that adds one).
     *
     * @return array<string, list<array{kind: string, key: string, plural: string, single: string, open: string, add: string}>>
     */
    public static function all(): array
    {
        if (self::$lists !== null) {
            return self::$lists;
        }
        $named = Theme::active()['page_lists'] ?? [];
        $out   = [];
        $bad   = [];
        foreach (is_array($named) ? $named : [] as $address => $names) {
            $at = self::address((string) $address);
            if ($at === '' || !is_array($names)) {
                $bad[] = '"' . $address . '" does not name a page and a list of what it lists';
                continue;
            }
            foreach ($names as $name) {
                $list = is_string($name) ? self::resolve($name) : null;
                if ($list === null) {
                    $bad[] = '"' . (is_scalar($name) ? (string) $name : gettype($name)) . '" (for ' . $at . ') is not a type or a taxonomy';
                    continue;
                }
                $out[$at][] = $list;
            }
        }
        if ($bad !== []) {
            self::note($bad);
        }

        return self::$lists = $out;
    }

    /**
     * What one page lists, by its address, or nothing.
     *
     * @return list<array{kind: string, key: string, plural: string, single: string, open: string, add: string}>
     */
    public static function forPath(string $address): array
    {
        return self::all()[self::address($address)] ?? [];
    }

    /**
     * What a page lists: the page by its own address, a translation by the
     * address it has in the site's own language.
     *
     * @param array<string, mixed> $page a row of `pages`
     * @return list<array<string, mixed>>
     */
    public static function forPage(array $page): array
    {
        return self::forPath((string) ($page['slug'] ?? ''));
    }

    /** Forget what was read, for a request that changes the theme. */
    public static function reset(): void
    {
        self::$lists = null;
    }

    /** A page's address as theme.json names it: no slashes at either end, lower case. */
    private static function address(string $address): string
    {
        return strtolower(trim(trim($address), '/'));
    }

    /** @return array{kind: string, key: string, plural: string, single: string, open: string, add: string}|null */
    private static function resolve(string $name): ?array
    {
        $name = trim($name);
        if (str_starts_with($name, 'terms:')) {
            $key = substr($name, 6);
            $tax = TaxonomyRegistry::get($key);
            if ($tax === null) {
                return null;
            }
            $base = 'taxonomies/' . (string) $tax['admin_slug'] . '/terms';

            return [
                'kind'   => 'terms',
                'key'    => $key,
                'plural' => Words::taxonomyLabel($tax),
                'single' => (string) ($tax['label_single'] ?? $tax['label'] ?? $key),
                'open'   => Url::admin($base),
                'add'    => Url::admin($base . '/new'),
            ];
        }
        $type = Registry::get($name);
        if ($type === null) {
            return null;
        }

        return [
            'kind'   => 'type',
            'key'    => $name,
            'plural' => (string) ($type['label'] ?? $name),
            'single' => (string) ($type['label_single'] ?? $type['label'] ?? $name),
            'open'   => Url::admin((string) $type['admin_slug']),
            'add'    => Url::admin((string) $type['admin_slug'] . '/new'),
        ];
    }

    /**
     * Said in the log: once, then at most hourly while the same names stay
     * left out, since every page's editor reads the lists.
     *
     * @param list<string> $bad
     */
    private static function note(array $bad): void
    {
        $sum  = md5(implode("\n", $bad));
        $was  = explode('|', (string) (Option::get(self::NOTED, '') ?? ''), 2);
        if (($was[0] ?? '') === $sum && time() - (int) ($was[1] ?? 0) < 3600) {
            return;
        }
        Option::set(self::NOTED, $sum . '|' . time());
        error_log('Theme page_lists left out: ' . implode('; ', $bad) . '.');
    }
}
