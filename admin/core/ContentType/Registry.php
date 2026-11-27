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
 *   'public_slug'  => 'events',          // /events/{post-slug} on public site; null = no pages
 *   'pages'        => true,              // false: entries have no page of their own (hasPages())
 *   'menu_icon'    => 'calendar',        // Lucide icon name
 *   'supports'     => ['title','content'],// Core fields shown on edit screen
 *   'fields'       => [ ... ],           // Field group (see FieldRenderer for schema)
 *   'archetypes'   => ['post'],          // One or more of: 'post', 'event', 'listing', 'product'
 *   'title_label'  => 'Name',            // its own word for the title (titleLabel())
 *   'terms'        => ['location' => ['min' => 1, 'max' => 1]],   // over a taxonomy's own rule (Term::countRule())
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
            // WHETHER ITS ENTRIES HAVE PAGES OF THEIR OWN: on, unless the
            // definition says false. `hasPages()` asks it with the slug.
            'pages'        => true,
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
            // WHAT ITS ENTRY LIST SHOWS AND HOW IT IS ORDERED — migration 130,
            // `ContentType\ListColumns`. NULL is the type's own default.
            'list_columns'     => null,
            'default_sort'     => null,
            // THE STATUS A NEW ENTRY STARTS AT on its editor — migration 131,
            // `defaultStatus()`. Private unless the type says otherwise.
            'default_status'   => 'private',
            // ITS OWN WORD FOR ITS ENTRIES' TITLE, such as Name — migration
            // 150, `titleLabel()`. NULL is "{Singular} title", as before.
            'title_label'      => null,
            // THE CHILD TYPES ITS EDITOR SHOWS, each a panel of rows with the
            // columns named: ['session' => ['columns' => ['title', 'speaker']]]
            // — migration 145, `ContentType\Children`.
            'children'         => [],
            // WHAT STAFF SEE ON ITS SCREENS — migration 140, `StaffView`: the
            // least role that sees each panel of an entry's editor and each
            // part of its list, and what its list is grouped by. [] is as it was.
            'panels'           => [],
            'list'             => [],
            // HOW MANY TERMS OF A TAXONOMY ITS ENTRIES TAKE, by taxonomy, over
            // the taxonomy's own rule for this type alone — migration 151,
            // `Term::countRule()`: ['location' => ['min' => 1, 'max' => 1]].
            'terms'            => [],
            // ITS TITLES AND ADDRESSES MADE FROM ITS FIELDS, when left blank
            // and when not typed — migration 146, `ContentType\Patterns`.
            'title_pattern'    => '',
            'slug_pattern'     => '',
            // WHAT COPY MAKES OF ITS ENTRIES: the words after a copy's title,
            // its status and the fields it never takes — migration 147,
            // `ContentType\CopyRules`. Empty is "(copy)", Private, every field.
            'copy'             => [],
            // HOW ITS EDITOR NAMES EACH ENTRY IT MAY BELONG TO, in the token
            // form of `ContentType\Patterns` over the parent's fields; '' is
            // the parent's title — migration 148.
            'parent_label'     => '',
            // WHERE ITS FIELDS GO ON ITS EDITOR: 'high', between the title and
            // the text, or after the text; a field group's own Position wins —
            // migration 149, `fieldsPosition()`.
            'fields_position'  => '',
            'nav_parent'   => null,   // string CT key to nest under, or null for top-level
            'nav_items'    => [],     // custom sidebar sub-items: [['label', 'slug'|'url', 'page_key'?, 'icon'?, 'role'?]], 'role' the least role that sees it
        ], $definition, ['key' => $key]); // key is always overwritten to match $key

        // A FIELD'S OPTION THE CMS DOES NOT KNOW, said in the log (`FieldOptions`).
        if (is_array($definition['fields'] ?? null) && $definition['fields'] !== []) {
            FieldOptions::note('the type "' . $key . '"', $definition['fields']);
        }
    }

    /**
     * The Status a new entry of the type starts at on its editor: 'published'
     * when the type says so ('default_status', or "New entries start as" on
     * the Content Types screen), else 'private', as every new entry started
     * before. A status that is not one of the two is said in the log and taken
     * as Private: a new entry is never on the site by a typo. Copy, Translate
     * and imports keep their own rules.
     */
    public static function defaultStatus(string $key): string
    {
        $status = (string) (self::get($key)['default_status'] ?? 'private');
        if (!in_array($status, ['private', 'published'], true)) {
            error_log('Content types: "' . $key . '" names a status for new entries there is none of ("' . $status . '"); they start Private');

            return 'private';
        }

        return $status;
    }

    /**
     * THE WORDS OVER A TYPE'S TITLE BOX: its own word for the title
     * (`'title_label'`, or Title label on the Content Types screen, migration
     * 150), such as Name or Business name, or "{Singular} title" as every type
     * has had. "Title" for a type that is not registered.
     */
    public static function titleLabel(string $key): string
    {
        $type = self::get($key);
        if ($type === null) {
            return 'Title';
        }

        return self::ownTitleLabel($key) ?? (string) $type['label_single'] . ' title';
    }

    /**
     * The type's own word for its entries' title, or null when it names none
     * (blank is none): its list's heading and the sentence refusing an empty
     * title then say "Title", as before.
     */
    public static function ownTitleLabel(string $key): ?string
    {
        $own = self::get($key)['title_label'] ?? null;
        $own = is_string($own) ? trim($own) : '';

        return $own === '' ? null : $own;
    }

    /**
     * Where a type's own fields go on its editor, and its field groups that
     * name no Position of their own: 'high', between the title and the text,
     * when the type says so ('fields_position', or "Its fields" on the Content
     * Types screen), else 'normal', after the text. A place that is not one
     * is said in the log and taken as after the text.
     */
    public static function fieldsPosition(string $key): string
    {
        $at = (string) (self::get($key)['fields_position'] ?? '');
        if ($at === 'high') {
            return 'high';
        }
        if ($at !== '' && $at !== 'normal') {
            error_log('Content types: "' . $key . '" puts its fields somewhere there is none of ("' . $at . '"); they go after its text');
        }

        return 'normal';
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
     * WHETHER THIS TYPE'S ENTRIES HAVE PAGES OF THEIR OWN — the one question
     * every reader of `public_slug` asks, rather than testing the slug.
     *
     * On by default. Off when the definition says `'pages' => false`, even
     * with a `public_slug` left in it, and when there is no Public URL: a type
     * registered with `'public_slug' => null`, or made in the admin with its
     * Public URL left blank. Such a type's entries are data for the site's
     * own templates, such as the reviews or questions a block lists: no
     * address, archive or feed, no place in the sitemap, search, the static
     * copy or a menu, and no SEO fields. Their slugs stay, unique as ever, and
     * the API, exports, revisions and translations carry them as any other.
     */
    public static function hasPages(string $key): bool
    {
        return self::publicSlug($key) !== null;
    }

    /** The type's Public URL when its entries have pages of their own, else null. */
    public static function publicSlug(string $key): ?string
    {
        $type = self::get($key);
        if ($type === null || !(bool) ($type['pages'] ?? true)) {
            return null;
        }
        $slug = (string) ($type['public_slug'] ?? '');

        return trim($slug, " /") === '' ? null : $slug;
    }

    /**
     * The types whose entries have pages of their own, in registration order.
     *
     * @return array<string, array<string, mixed>> key => definition
     */
    public static function withPages(): array
    {
        return array_filter(self::$contentTypes, static fn (string $key): bool => self::hasPages($key), ARRAY_FILTER_USE_KEY);
    }

    /**
     * `AND {alias}.post_type IN (…)` for the types with pages, and its values
     * to bind, for a public list that gathers entries of several types (a
     * term's archive, its map in the sitemap). With none, a condition no row
     * meets.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function withPagesSql(string $alias = 'p'): array
    {
        $keys = array_map('strval', array_keys(self::withPages()));
        if ($keys === []) {
            return [' AND 1 = 0', []];
        }

        return [' AND ' . $alias . '.post_type IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', $keys];
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
