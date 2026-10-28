<?php

declare(strict_types=1);

namespace Cms\ContentType;

/**
 * Content Type Registry — the single source of truth for all Content Type definitions.
 *
 * Developers call Registry::register() in config/content-types.php.
 * The admin UI, routes, sidebars, and API all read from here at runtime.
 * Nothing is stored in the database for code-registered Content Types — definitions are code.
 * UI-created Content Types are loaded from the `content_types` DB table at bootstrap and merged in.
 *
 * Content Type definition shape:
 * [
 *   'label'        => 'Events',          // Plural label (admin sidebar, page heading)
 *   'label_single' => 'Event',           // Singular (edit screen title, breadcrumbs)
 *   'admin_slug'   => 'events',          // /admin/events, /admin/events/new, etc.
 *   'public_slug'  => 'events',          // /events/{post-slug} on public site
 *   'menu_icon'    => 'calendar',        // Lucide icon name
 *   'supports'     => ['title','content'],// Core fields shown on edit screen
 *   'fields'       => [ ... ],           // Field group (see FieldRenderer for schema)
 *   'archetypes'   => ['post'],          // One or more of: 'post', 'event', 'listing', 'product'
 * ]
 */
class Registry
{
    /** @var array<string, array<string, mixed>> */
    private static array $contentTypes = [];

    /**
     * Register a Content Type.
     *
     * @param string               $key        Unique identifier and post_type DB value (e.g. 'event')
     * @param array<string, mixed> $definition Content Type definition array
     */
    public static function register(string $key, array $definition): void
    {
        self::$contentTypes[$key] = array_merge([
            'key'          => $key,
            'label'        => ucfirst($key) . 's',
            'label_single' => ucfirst($key),
            'admin_slug'   => $key . 's',
            'public_slug'  => $key . 's',
            'menu_icon'    => 'file-text',
            'supports'     => ['title', 'content'],
            'fields'       => [],
            'source'       => 'code', // 'code' | 'database'
            'archetypes'   => ['post'],
            // WHICH KIND OF THING OWNS THIS KIND OF THING — 'club' on the Club
            // Events type. Migration 047 has the argument. NOT `nav_parent`,
            // which is two lines down and nests the SIDEBAR MENU: a type can sit
            // under another in the menu without its entries belonging to
            // anything, and entries can belong to a parent whose type sits
            // elsewhere in the menu entirely. Two words for one concept is how
            // a screen ends up asking the wrong one.
            'parent_type'  => null,
            // HOW MANY TO A PAGE — migration 090. NULL follows the site's own
            // number; see perPage() and firstPageExtra() below.
            'per_page'         => null,
            'first_page_extra' => 0,
            'nav_parent'   => null,   // string CT key to nest under, or null for top-level
            'nav_items'    => [],     // custom sidebar sub-items: [['label', 'slug'|'url', 'page_key'?, 'icon'?]]
        ], $definition, ['key' => $key]); // key is always overwritten to match $key
    }

    /**
     * Get a Content Type definition by its key. Returns null if not registered.
     *
     * @return array<string, mixed>|null
     */
    public static function get(string $key): ?array
    {
        return self::$contentTypes[$key] ?? null;
    }

    /**
     * HOW MANY ENTRIES A PAGE OF THIS TYPE'S ARCHIVE SHOWS — 0 is every one
     * on a single page. A type with no number of its own follows the site's
     * (Settings → General, `Helpers\Paging::perPage()`), so a site that never
     * sets one sees no change: a per-type value overrides the site-wide one.
     * A site may set Articles to 12 and Portfolio to 0. Migration 090.
     */
    public static function perPage(string $key): int
    {
        $own = self::get($key)['per_page'] ?? null;
        if ($own === null || $own === '') {
            return \Cms\Helpers\Paging::perPage();
        }

        return max(0, min(\Cms\Helpers\Paging::MAX_PER_PAGE, (int) $own));
    }

    /**
     * EXTRA ENTRIES ON PAGE 1 OF THIS TYPE'S OWN ARCHIVE — never its term
     * archives. A design that leads page 1 with a featured entry takes it
     * from the page's own list, which would leave the grid under it one
     * short; this many more go on page 1, and page 2 carries on after them.
     * 0 is every page the same size.
     */
    public static function firstPageExtra(string $key): int
    {
        return max(0, min(\Cms\Helpers\Paging::MAX_PER_PAGE, (int) (self::get($key)['first_page_extra'] ?? 0)));
    }

    /**
     * Look up a Content Type by its admin_slug (for routing context where only the URL slug is known).
     *
     * @return array<string, mixed>|null
     */
    public static function getByAdminSlug(string $adminSlug): ?array
    {
        foreach (self::$contentTypes as $contentType) {
            if ($contentType['admin_slug'] === $adminSlug) {
                return $contentType;
            }
        }
        return null;
    }

    /**
     * Return all registered Content Types, keyed by their content type key.
     *
     * @return array<string, array<string, mixed>>
     */
    /**
     * The Content Types that carry a given ARCHETYPE, in registration order.
     *
     * Asks the REGISTRY, which is the rule: anything answering "what exists in
     * this install?" goes through here and never through a model query. A
     * screen that wants "the feed-shaped types" has exactly one place to ask.
     *
     * @return array<string, array<string, mixed>> key => definition
     */
    public static function withArchetype(string $archetype): array
    {
        $out = [];
        foreach (self::all() as $key => $definition) {
            if (in_array($archetype, (array) ($definition['archetypes'] ?? []), true)) {
                $out[$key] = $definition;
            }
        }
        return $out;
    }

    public static function all(): array
    {
        return self::$contentTypes;
    }

    /**
     * The type whose entries own this type's entries, or null.
     *
     * ASKS THE REGISTRY, not the table. A type may be registered in code or
     * created in the admin, and a parent declared across that line has to
     * resolve — otherwise "Club Events belong to a Club" works or not depending
     * on which file the Club was born in, which would make one way of creating
     * a type a privileged back door.
     *
     * A declaration naming a type that is not registered resolves to NULL rather
     * than throwing: a type can be deactivated after something declared it, and
     * an admin that fatals on a stale key is one nobody can use to fix the key.
     * The entry screen then shows no parent control, which is the truth.
     *
     * @return array<string, mixed>|null
     */
    public static function parentOf(string $key): ?array
    {
        $definition = self::get($key);
        $parentKey  = $definition['parent_type'] ?? null;

        if (!is_string($parentKey) || $parentKey === '' || $parentKey === $key) {
            return null;
        }

        return self::get($parentKey);
    }

    /**
     * The types that declare `$key` as their parent, in registration order.
     *
     * @return array<string, array<string, mixed>> key => definition
     */
    public static function childTypesOf(string $key): array
    {
        $out = [];
        foreach (self::$contentTypes as $childKey => $definition) {
            if (($definition['parent_type'] ?? null) === $key) {
                $out[$childKey] = $definition;
            }
        }

        return $out;
    }

    /**
     * May `$key` declare `$parentKey` as its parent?
     *
     * ── A CYCLE IS NOT A TASTE QUESTION, IT IS A HANG ───────────────────────
     * Anything that walks up a chain — a breadcrumb, a delete that checks for
     * children, the sidebar — loops forever on A→B→A, and the screen it was
     * drawing never returns. There is no output to read and nothing malformed
     * to find: a check for an error finds none, because the page it was
     * checking never arrived.
     *
     * So the walk here is BOUNDED as well as cycle-aware. `count()` is a hard
     * ceiling no chain can exceed honestly, and reaching it means the data is
     * lying about itself — refuse, rather than trust the visited-set to have
     * caught every shape.
     */
    public static function canParent(string $key, string $parentKey): bool
    {
        if ($parentKey === '' || $parentKey === $key) {
            return false;   // nothing is its own parent
        }

        if (self::get($parentKey) === null) {
            return false;   // and nothing belongs to a type that is not here
        }

        $seen  = [$key => true];
        $at    = $parentKey;
        $limit = count(self::$contentTypes) + 1;

        for ($step = 0; $step < $limit; $step++) {
            if (isset($seen[$at])) {
                return false;
            }
            $seen[$at] = true;

            $next = self::get($at)['parent_type'] ?? null;
            if (!is_string($next) || $next === '') {
                return true;    // reached the top of the chain
            }
            $at = $next;
        }

        return false;
    }
}
