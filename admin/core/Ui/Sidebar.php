<?php

declare(strict_types=1);

namespace Cms\Ui;

/**
 * THE SIDEBAR SPEC.
 *
 * A screen DECLARES its sidebar as a typed spec; it never writes sidebar markup.
 * `admin/templates/partials/sidebar.php` owns every pixel, so a row of a given
 * kind looks and behaves identically on every page.
 *
 * There is deliberately NO raw-HTML escape hatch, and that absence is the
 * design. With one, a column grows competing markup idioms in the same 240px,
 * and whole page-body filter bars get relocated into it in costume.
 *
 * ── ONLY THE *NAVIGATE* SURFACE IS BUILT ────────────────────────────────────
 * `shell.css` also styles a TUNE surface — a recessed control panel in the
 * column. The CMS does not use it, and that is deliberate: every content
 * filter belongs OUT of the sidebar, in an in-page `.filterbar`. A list's
 * search and filters therefore live in the page body, where `core.css` §12
 * already defines both idioms. Do not reintroduce a filter in this column.
 *
 * ── EVERY ROW HAS AN ICON. NO EXCEPTIONS. ───────────────────────────────────
 * `iconFor()` cannot return null and the renderer has no iconless branch. A
 * 5px transparent spacer as the fallback would be invisible in the expanded
 * column, and a blank clickable box on the 52px rail, where the glyph IS the
 * row.
 *
 * AND THE ICON IS DECLARED, NEVER DERIVED. Deriving it with
 * `strtolower($label)` breaks a row like "Staff schedules": it asks the map for
 * `staff schedules`, which nothing has registered.
 */
final class Sidebar
{
    /**
     * The last-resort glyph. A safety net, not a licence: a row wearing this has
     * an unregistered key, and `iconFor()` logs it by name.
     */
    public const FALLBACK = 'angle-right';

    /**
     * The whole spec. Sections render in the order given.
     *
     * @param list<array{key:string,label:string,items:list<array<string,mixed>>}> $nav
     */
    public static function spec(array $nav = []): array
    {
        return ['nav' => array_values(array_filter($nav))];
    }

    /**
     * One NAVIGATE section: a labelled, collapsible run of link rows.
     *
     * A section with no items returns `[]` and is filtered out by `spec()` —
     * an empty labelled box is worse than no box.
     *
     * @param list<array<string, mixed>> $items
     */
    public static function section(string $key, string $label, array $items): array
    {
        $items = array_values(array_filter($items));
        if ($items === []) {
            return [];
        }
        return ['key' => $key, 'label' => $label, 'items' => $items];
    }

    /**
     * A GROUP — a top-level row that is itself a destination, plus a submenu.
     *
     * It exists because of what the railed column looks like without it. A
     * TEXT-ONLY heading over its rows vanishes when railed — `shell.css` hides
     * headings at 52px — and leaves its children in a single undifferentiated
     * stripe. With two Content Types registered that is, literally:
     *
     *     list · plus · list · plus
     *
     * "All Events / Add new" and "All News / Add new", four glyphs, no way to
     * tell which pair is which, and the Content Type's OWN registered icon
     * nowhere on screen.
     *
     * So a group has an ICON and an HREF of its own. Railed, the group's
     * glyph is what you see — one mark per destination, all distinct — and its
     * submenu becomes a flyout. Expanded, the row is the section heading and
     * the submenu sits under it.
     *
     * `$href` is the group's primary destination (a Content Type's entry list,
     * Structure's Content types screen). The row is a real anchor, so it keeps
     * keyboard, middle-click and open-in-new-tab; the chevron beside it is a
     * separate control, because one element that both navigates and toggles is
     * two jobs fighting over one click.
     *
     * @param list<array<string, mixed>> $items
     */
    public static function group(
        string $key,
        string $label,
        string $icon,
        string $href,
        array $items,
        bool $active = false
    ): array {
        $items = array_values(array_filter($items));
        if ($items === []) {
            return [];
        }

        return [
            'key'    => $key,
            'label'  => $label,
            'icon'   => $icon,
            'href'   => $href,
            'active' => $active,
            'items'  => $items,
        ];
    }

    /**
     * THE LEAD SECTION — the rows above the first heading.
     *
     * Dashboard, the Assistant and Pages sit at the top of the column with no
     * group header and no collapse control. They are not a category; they are
     * the three places you go from anywhere, and putting a "GENERAL" heading
     * over them would be a label invented to satisfy a renderer rather than to
     * tell anyone anything.
     *
     * It is also the one section that must NOT be collapsible: a column whose
     * first control can hide the way back to the Dashboard is a column that can
     * be locked. The partial keys off the empty label.
     *
     * @param list<array<string, mixed>> $items
     */
    public static function lead(array $items): array
    {
        return self::section('lead', '', $items);
    }

    /**
     * One NAVIGATE row.
     *
     * `$icon` is an ICON REGISTRY KEY or a real slug — never a label. `$count`
     * renders the neutral count badge; pass null for none, and note that 0 is
     * not rendered (a zero badge is noise, and the row's own screen will say so).
     */
    public static function link(
        string $label,
        string $href,
        bool $active = false,
        ?string $icon = null,
        ?int $count = null,
        bool $child = false,
        ?string $full = null
    ): array {
        return [
            'label'  => $label,
            'href'   => $href,
            'active' => $active,
            'icon'   => $icon,
            'count'  => $count,
            'child'  => $child,
            // A child row shows only its leaf label, so `full` carries the
            // unabbreviated name for the accessible name and the railed tooltip.
            'full'   => $full ?? $label,
        ];
    }

    /**
     * Row key => icon slug.
     *
     * This map names CONCEPTS. It is not a gate on the icon set: a caller that
     * passes a real slug is not wrong, and `iconFor()` accepts one.
     *
     * @return array<string, string>
     */
    public static function iconMap(): array
    {
        return [
            // The lead rows
            'dashboard'      => 'table-columns',
            'assistant'      => 'comment',
            // NOT `file`. The `post` archetype's default `menu_icon` is
            // `file-text`, which this map translates to `file` — so Pages and
            // every default Content Type would draw the SAME glyph, and on the
            // rail that is two destinations with one mark. `copy` is two
            // stacked sheets: pages, plural.
            'pages'          => 'copy',

            // Inside a Content Type's own group. Both are DECLARED here rather
            // than derived from "All News" / "Add new", because a derived key
            // draws a blank row for a label like "Staff schedules" — and on
            // the 52px rail, where the glyph IS the row, a blank row is an
            // entry with no name and nothing to aim at.
            'all-entries'    => 'list',
            'add-new'        => 'plus',

            // ── GROUP ROWS ──────────────────────────────────────────────
            // A group's own glyph is the ONLY thing representing it on the
            // railed column, so no two of these may be the same mark.
            'group-media'    => 'folder-open',
            'group-forms'    => 'list-check',
            'group-structure'=> 'folder-tree',
            'group-appearance' => 'droplet',
            // Tools RUNS things (import, redirects, health); Settings ADJUSTS
            // them. `sliders` is the adjust mark, so Tools cannot also have it.
            'group-tools'    => 'terminal',
            'group-settings' => 'sliders',
            // Security GUARDS things. Not `shield-halved`, which System health
            // already wears one group up — two rows with one mark read as one.
            'group-security' => 'lock',

            // Files and media
            'media'          => 'images',
            'library'        => 'images',

            // Components
            'forms'          => 'list-check',
            'submissions'    => 'envelope',
            'spam'           => 'shield-halved',
            // A distinct mark from Library: two rows in the same section that
            // wear the same glyph are told apart only by reading them.
            'galleries'      => 'table-cells-large',

            // Appearance
            // Two windows, one in front of the other: one site, a choice of
            // looks. Its own mark — `layer-group` is Content types, below.
            'themes'         => 'window-restore',
            // The site's own mark on the CMS (`Ui\Brand`).
            'branding'       => 'star',
            // The public site's look: its dials (`Site\Look`).
            'customizer'     => 'sliders',
            'menus'          => 'list-ul',

            // Structure
            'content-types'  => 'layer-group',
            'field-groups'   => 'list',
            'taxonomies'     => 'tags',
            'terms'          => 'tag',

            // People, tools, settings
            'users'          => 'user-group',
            'tools'          => 'sliders',

            // ── Commerce ────────────────────────────────────────────────────
            // The SAME `box-open` the order-status chip's `processing` state
            // and the Items panel heading wear, so an order carries one mark
            // everywhere it appears rather than three a reader has to learn.
            'orders'         => 'box-open',
            // A CODE IS A LABEL YOU HAND OVER, which is what a tag is. NOT
            // `box-open`, which is Orders directly above it — two rows in one
            // group wearing one mark is how a reader learns the marks mean
            // nothing.
            'coupons'        => 'tag',
            // A CUSTOMER, the mark the order's Customer panel wears: one person,
            // NOT `user-group`, which is the staff on Users.
            'customers'      => 'user',
            // SALES GOING UP: the mark the dashboard's sales card wears. NOT
            // `arrow-trend-down`, which marks a size running low.
            'reports'        => 'arrow-trend-up',
            // WHAT PASSES BETWEEN THE SHOP AND ANOTHER PROGRAM, both ways. NOT
            // `link`, which is Permalinks in the same group.
            'api'            => 'arrows-left-right',
            // WHAT IS ON THE SHELF, counted: the number sign, as a count wears
            // it in Settings. NOT `box-open`, which is Orders two rows up, and
            // NOT `layer-group`, which is Content types.
            'stock'          => 'hashtag',
            'commerce'       => 'box-open',
            'import-export'  => 'arrow-right-arrow-left',
            'redirects'      => 'share',
            // THE SITE, TWICE: a copy of it for another host. NOT `copy`,
            // which is Pages.
            'static-copy'    => 'clone',
            // A LINK, followed to see that it reaches something.
            'broken-links'   => 'link',
            // THE DISK THEY ARE KEPT ON.
            'backups'        => 'hard-drive',
            'system-health'  => 'shield-halved',
            'settings'       => 'sliders',
            // THE FRONT DESK: where a business meets the people it serves.
            'business'       => 'bell-concierge',

            // Security
            'security-overview' => 'binoculars',
            // The sign-in mark: the same glyph a sign-in wears in the log.
            'login-protection'  => 'arrow-right-to-bracket',
            // The second thing to have: the phone the code comes from.
            'two-step'          => 'mobile',
            // Sent by mail instead of typed.
            'passwordless'      => 'paper-plane',
            // A link with a lifespan, running down.
            'temporary-access'  => 'stopwatch',
            // The address bar's padlock.
            'https'             => 'lock',
            'activity-log'      => 'table-list',
            // Who is shut out: an address in the sights.
            'blocking'          => 'location-crosshairs',
            // The CMS's own files against what shipped — the same mark a
            // finding wears in the log.
            'file-check'        => 'file',
            // ── PANEL-ONLY MARKS ───────────────────────────────────────────
            // Two aside panels have no nav row to borrow from, so their glyph
            // is declared here with everything else rather than inline at the
            // call site: one map.
            //
            // `flag` for Visibility: the panel answers "who can reach this",
            // which is a state you raise, not a lock — Published is the common
            // case and a padlock over it would read as restricted.
            'visibility'     => 'flag',
            'history'        => 'clock',
            'glyph'          => 'droplet',
            'entry-form'     => 'pen-to-square',
            'seo'            => 'magnifying-glass',
            'permalinks'     => 'link',
            'email'          => 'mail',

            // ── Content Type menu_icon values ───────────────────────────────
            // Registry::register() documents `menu_icon` as a LUCIDE name, and
            // ArchetypeDefaults still hands out Lucide names ('file-text',
            // 'grid', 'shopping-cart'). The icon set here is Font Awesome, where
            // those slugs do not exist — so a Content Type registered with the
            // documented default would render a blank row. They are translated
            // here rather than by renaming them at the source, because a value
            // already stored in a `content_types` row cannot be renamed.
            'file-text'      => 'file',
            'grid'           => 'table-cells',
            'shopping-cart'  => 'box-open',
            'image'          => 'image',
            'calendar'       => 'calendar',
            // 'users' is already registered above and resolves to 'user-group',
            // which is also the right answer for a Content Type using it.
        ];
    }

    /**
     * The glyph for a row key. NEVER null.
     *
     * Resolves in three steps and cannot fail:
     *   1. the registered key — `iconMap()`, the declared vocabulary
     *   2. the key AS a slug — a caller may legitimately pass one
     *   3. `FALLBACK` — a real chevron, never a gap
     *
     * Step 3 also logs the unresolved key by name, so the failure says exactly
     * what to register rather than merely that something is off.
     */
    public static function iconFor(?string $key): string
    {
        if ($key === null || $key === '') {
            error_log('sidebar: no icon for nav key "" — falling back to ' . self::FALLBACK);
            return self::FALLBACK;
        }

        $slug = self::iconMap()[$key] ?? null;
        if ($slug !== null) {
            return $slug;
        }

        if (Icons::exists($key)) {
            return $key;
        }

        error_log('sidebar: no icon for nav key "' . $key . '" — falling back to ' . self::FALLBACK);
        return self::FALLBACK;
    }

    /**
     * Section keys the viewer has EXPANDED, from the cookie `sidebar.js` writes.
     *
     * ── CLOSED IS THE DEFAULT, AND THAT IS THE WHOLE POINT ─────────────────
     * The inverse — a list of COLLAPSED sections, everything else open — opens
     * all seven groups at once on every page load of an install with two
     * Content Types: twenty-odd rows, the column scrolling, and the section you
     * are actually in buried among six you are not. Every extra Content Type
     * makes it worse.
     *
     * So a group is shut unless one of two things is true: it holds the page
     * you are on, or you opened it yourself. It is the only behaviour that
     * survives a site with a dozen types.
     *
     * COLLAPSE STATE IS SERVER-RENDERED. That is not an optimisation — a
     * JS-restored state paints the section open for one frame and snaps it shut
     * on every navigation, which is the single most-noticed chrome jitter there
     * is. The JS only WRITES the cookie; this decides what renders.
     *
     * @return list<string>
     */
    public static function openSections(): array
    {
        $raw = (string) ($_COOKIE['sb_open'] ?? '');
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn (string $k): string => trim(urldecode($k)),
            explode(',', $raw)
        )));
    }

    /** Is the whole column collapsed to the 52px icon rail? */
    public static function isRailed(): bool
    {
        return ($_COOKIE['sb_railed'] ?? '') === '1';
    }
}
