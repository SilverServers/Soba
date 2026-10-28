<?php

declare(strict_types=1);

namespace Cms\Ui;

use Cms\Auth\Auth;
use Cms\ContentType\Registry;
use Cms\ContentType\TaxonomyRegistry;
use Cms\Helpers\Modules;
use Cms\Helpers\Url;

/**
 * The admin's information architecture — ONE COLUMN, all of it.
 *
 * ── THE COLUMN'S SHAPE, DELIBERATELY ────────────────────────────────────────
 * Every destination is a row in the column:
 *
 *     Dashboard · Assistant · Pages          ← lead rows, no heading (with a
 *                                              site tool placed at the top,
 *                                              under Dashboard)
 *     {one GROUP per top-level Content Type} ← All X · Add New · its
 *                                              taxonomies · its child types ·
 *                                              its nav_items
 *     Files & media   Library · Galleries
 *     Forms           All forms · Submissions · Spam
 *     Structure       Content types · Field groups · Taxonomies
 *     Appearance      Menus · Theme builder · Customizer
 *     Tools           Import and export · Redirects · System health
 *     Settings        General · SEO · Permalinks · Email · Users
 *     Security        Overview · Login protection · Activity log
 *
 * Splitting navigation into TOP-BAR PILLARS with a per-pillar sidebar breaks
 * the shape: a Content Type becomes a single row with nowhere to hang
 * anything, its taxonomies are exiled to Structure, `nav_items` loses its only
 * consumer, and the parent/child nesting that makes *Clubs → Club Events* work
 * has nothing to render into. The per-type group is the whole point of the
 * column — it is where a Content Type stops being a row and becomes a place.
 *
 * ── SO THE TOP BAR CARRIES NO DESTINATIONS ──────────────────────────────────
 * `navItems()` returns `[]`. With every destination in the column, pillars in
 * the bar would be a second navigation naming the same places — and two
 * navigations disagreeing about where you are is worse than one.
 *
 * ── ANYTHING ANSWERING "WHAT EXISTS IN THIS INSTALL?" ASKS THE REGISTRY ─────
 * That is the reason this class exists rather than each screen assembling its
 * own nav. A model query (`ContentTypeDefinition::all()`) answers the narrower
 * question "what is in this table" — and returning that to a surface labelled
 * with the broader question can tell an owner they have no Content Types while
 * five are working in the sidebar.
 *
 * ── ROLE GATES ARE REAL, NOT DECORATION ─────────────────────────────────────
 * A row that leads somewhere the viewer's role will bounce them off is worse
 * than no row: it advertises a destination and then throws you back to the
 * dashboard with no explanation. The gates here mirror the `requireRole()`
 * calls in the controllers.
 */
final class AdminMenu
{
    /**
     * The current request's admin path, normalised — no query, no base path, no
     * trailing slash, and with the configurable admin slug folded back to
     * `/admin` so the comparisons below never have to know what it is.
     */
    public static function here(): string
    {
        return self::pathOf((string) ($_SERVER['REQUEST_URI'] ?? '/'));
    }

    /**
     * Any address, normalised exactly as `here()` normalises the current one —
     * so the audit can ask this menu what it lights on the page a form was
     * filled in on (`Security\Audit::screen()`).
     */
    public static function pathOf(string $uri): string
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);

        $basePath = defined('BASE_PATH') ? rtrim((string) BASE_PATH, '/') : '';
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        $path = '/' . trim($path, '/');

        $slug = defined('ADMIN_URL_SLUG') ? trim(ADMIN_URL_SLUG, '/') : 'admin';
        if ($slug !== 'admin' && ($path === '/' . $slug || str_starts_with($path, '/' . $slug . '/'))) {
            $path = '/admin' . substr($path, strlen($slug) + 1);
        }

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * The top bar's destinations: THERE ARE NONE.
     *
     * The sidebar holds the whole information architecture. Pillars here would
     * be a second navigation naming the same places, and two navigations
     * disagreeing about where you are is worse than one.
     *
     * The bar is not empty, though — it carries TOOLS, which is a different
     * thing from destinations. See `barTools()`.
     *
     * @return list<array<string, mixed>>
     */
    public static function navItems(?string $here = null): array
    {
        return [];
    }

    /**
     * THE BAR'S TOOLS — things you DO from anywhere, not places you go.
     *
     * That distinction is the whole reason these are in the bar rather than the
     * column: the sidebar answers "where am I / where can I go", so an action
     * you take from every screen has no honest row in it.
     *
     *   + New       start a new entry of any type, or a page, without first
     *               navigating to that type's list
     *   View site   leave the admin for the public site
     *
     * ── AND THIS IS WHERE THE BAR'S NO-ICONS RULE BENDS, ON PURPOSE ─────────
     * The top nav is otherwise TEXT-ONLY: at nav size a glyph reads as noise,
     * and two of three rows wearing one is worse than none. That reasoning is
     * about DECORATIVE glyphs beside labels that already say the same thing.
     *
     * These two are not decorative. `up-right-from-square` on View site is the
     * standard affordance for a link that leaves the current context in a new
     * tab — the same mark the entry list already uses on its own view-on-site
     * control — and `plus` on + New is the verb. Both carry information the
     * label does not.
     *
     * @return array{new: list<array<string, mixed>>, site: string}
     */
    public static function barTools(): array
    {
        $new = [];

        // ASK THE REGISTRY, never the model. A type
        // registered in code and a type created in the admin are equally real
        // to the router, so both belong here.
        foreach (Registry::all() as $contentType) {
            $new[] = [
                'label' => (string) $contentType['label_single'],
                'href'  => Url::admin(trim((string) $contentType['admin_slug'], '/') . '/new'),
                'icon'  => (string) $contentType['menu_icon'],
            ];
        }

        // Pages are not a Content Type, so they are named explicitly — the same
        // exception the dashboard's card grid makes, for the same reason.
        if (Auth::hasRole('editor') && Modules::enabled('pages')) {
            $new[] = ['label' => 'Page', 'href' => Url::admin('pages/new'), 'icon' => 'pages'];
        }

        return ['new' => $new, 'site' => Url::public()];
    }

    /**
     * THE WHOLE MENU. Every section, on every screen, in this order.
     *
     * Path-independent by design: the column does not change shape as you move
     * through the admin, so a destination is always in the place you last saw
     * it. Only the ACTIVE state and the collapse state differ between renders.
     */
    public static function sidebarSpec(?string $here = null): array
    {
        $here = $here ?? self::here();

        $isEditor = Auth::hasRole('editor');
        $isAdmin  = Auth::hasRole('admin');

        $sections = [self::leadSection($here, $isEditor)];

        // One group per top-level Content Type, in Registry order.
        foreach (self::contentTypeSections($here) as $section) {
            $sections[] = $section;
        }

        $sections[] = self::mediaSection($here);
        // A switched-off module shows nowhere (`Helpers\Modules`).
        if (Modules::enabled('forms')) {
            $sections[] = self::formsSection($here);
        }

        // ── SHOP, ONLY WHEN THERE IS ONE ────────────────────────────────────
        // A plumber's brochure site has no products and does not want an
        // Orders row leading to a permanently empty screen. The test is
        // `Registry::withArchetype('product')` — the REGISTRY, so a type
        // registered in `config/` counts, which a model query would miss.
        // Declaring the Product kind on any content type is what turns the
        // shop on, and there is no separate switch to forget.
        if (self::hasProducts() && Modules::enabled('commerce')) {
            $sections[] = self::commerceSection($here);
        }

        if ($isAdmin) {
            $sections[] = self::structureSection($here);
            $sections[] = self::appearanceSection($here);
        }
        // Tools sits between the two in every column that has it: the CMS's
        // own rows are the administrators', but a site's tool is shown to the
        // roles its row names, so an editor's column can hold the group too.
        $sections[] = self::toolsSection($here, $isAdmin);
        if ($isAdmin) {
            $sections[] = self::settingsSection($here);
            $sections[] = self::securitySection($here);
        }

        return Sidebar::spec($sections);
    }

    // ----------------------------------------------------------------
    // Sections
    // ----------------------------------------------------------------

    /**
     * The rows above the first heading — Dashboard, Assistant, Pages.
     *
     * No group label and no collapse control. These are not a category; they
     * are the three places you go from anywhere — and, under Dashboard, a
     * site's own screen whose row says `'place' => 'top'` (`Site\Tools`): the
     * one its staff live in, which under Tools would be buried.
     */
    private static function leadSection(string $here, bool $isEditor): array
    {
        $items = [
            Sidebar::link('Dashboard', Url::admin(), $here === '/admin', 'dashboard'),
        ];
        foreach (\Cms\Site\Tools::all() as $tool) {
            if ($tool['place'] === 'top' && Auth::hasRole($tool['role'])) {
                $items[] = Sidebar::link($tool['label'], Url::admin('tools/' . $tool['path']),
                    self::under($here, '/admin/tools/' . $tool['path']), $tool['icon'] !== '' ? $tool['icon'] : 'tools');
            }
        }
        if (Modules::enabled('assistant')) {
            $items[] = Sidebar::link('Assistant', Url::admin('assistant'), self::under($here, '/admin/assistant'), 'assistant');
        }

        // `PageController` is `requireRole('editor')` on every action, so an
        // author following this row would be bounced to the dashboard.
        if ($isEditor && Modules::enabled('pages')) {
            $items[] = Sidebar::link('Pages', Url::admin('pages'), self::under($here, '/admin/pages'), 'pages');
        }

        return Sidebar::lead($items);
    }

    /**
     * ONE GROUP PER TOP-LEVEL CONTENT TYPE — the shape this column exists for.
     *
     * Each group carries, in this order:
     *
     *     All {Label}   the entry list
     *     Add New       the create form
     *     {taxonomies}  every taxonomy attached to this type, to its terms
     *     {child types} every type declaring this one as its `nav_parent`
     *     {nav_items}   extra rows from the registration
     *
     * A child type is rendered INSIDE its parent's group rather than as its own
     * group, which is what makes *Clubs → Club Events* read as a relationship
     * instead of two unrelated headings. A `nav_parent` naming a type that is
     * not registered is promoted to top level — the row must still appear,
     * because the type exists.
     *
     * @return list<array<string, mixed>>
     */
    private static function contentTypeSections(string $here): array
    {
        $types    = Registry::all();
        $children = [];

        foreach ($types as $key => $contentType) {
            $parent = $contentType['nav_parent'] ?? null;
            if ($parent !== null && $parent !== $key && isset($types[$parent])) {
                $children[$parent][] = $key;
            }
        }

        $sections = [];

        foreach ($types as $key => $contentType) {
            $parent = $contentType['nav_parent'] ?? null;
            if ($parent !== null && $parent !== $key && isset($types[$parent])) {
                continue; // rendered inside its parent's group, below
            }

            $slug  = trim((string) $contentType['admin_slug'], '/');
            $base  = '/admin/' . $slug;
            // "All X" owns everywhere inside the type EXCEPT the create form,
            // which has its own row. So editing an entry, or standing in the
            // trash, still lights the group — a screen whose sidebar has
            // nothing lit is a screen telling you that you are nowhere.
            $onCreate = $here === $base . '/new';

            $items = [
                Sidebar::link(
                    'All ' . $contentType['label'],
                    Url::admin($slug),
                    self::under($here, $base) && !$onCreate,
                    'all-entries'
                ),
                Sidebar::link('Add new', Url::admin($slug . '/new'), $onCreate, 'add-new'),
            ];

            // Taxonomies attached to THIS type live here, not in Structure.
            // Structure is where a taxonomy is DEFINED; this is where its terms
            // are managed, and those are different jobs for different people.
            foreach (TaxonomyRegistry::getForContentType((string) $key) as $tax) {
                $termsPath = '/admin/taxonomies/' . trim((string) $tax['admin_slug'], '/') . '/terms';
                $items[]   = Sidebar::link(
                    (string) $tax['label'],
                    Url::admin('taxonomies/' . $tax['admin_slug'] . '/terms'),
                    self::under($here, $termsPath),
                    'terms'
                );
            }

            // Child Content Types, indented under their parent.
            foreach ($children[$key] ?? [] as $childKey) {
                $child     = $types[$childKey];
                $childSlug = trim((string) $child['admin_slug'], '/');
                $items[]   = Sidebar::link(
                    label:  (string) $child['label'],
                    href:   Url::admin($childSlug),
                    active: self::under($here, '/admin/' . $childSlug),
                    icon:   (string) $child['menu_icon'],
                    count:  null,
                    child:  true,
                    // Truncated to the leaf label on screen, so the accessible
                    // name and the railed tooltip keep the unambiguous one.
                    full:   $contentType['label'] . ' · ' . $child['label']
                );

                // A child type's own taxonomies belong with it.
                foreach (TaxonomyRegistry::getForContentType((string) $childKey) as $tax) {
                    $termsPath = '/admin/taxonomies/' . trim((string) $tax['admin_slug'], '/') . '/terms';
                    $items[]   = Sidebar::link(
                        (string) $tax['label'],
                        Url::admin('taxonomies/' . $tax['admin_slug'] . '/terms'),
                        self::under($here, $termsPath),
                        'terms',
                        null,
                        true,
                        $child['label'] . ' · ' . $tax['label']
                    );
                }
            }

            // `nav_items` from the registration: the Registry accepts them,
            // and this is what renders them.
            foreach ((array) ($contentType['nav_items'] ?? []) as $navItem) {
                $href = !empty($navItem['url'])
                    ? (string) $navItem['url']
                    : Url::admin((string) ($navItem['slug'] ?? ''));

                $items[] = Sidebar::link(
                    (string) ($navItem['label'] ?? ''),
                    $href,
                    !empty($navItem['slug']) && self::under($here, '/admin/' . trim((string) $navItem['slug'], '/')),
                    // Declared, never derived from the label — `strtolower()` on
                    // a label is what broke "Staff schedules".
                    (string) ($navItem['icon'] ?? 'settings')
                );
            }

            // THE GROUP'S OWN ICON IS THE TYPE'S REGISTERED `menu_icon`, and
            // railed it is the only thing on screen standing for this type.
            // Never derived from the label.
            $sections[] = Sidebar::group(
                'ct-' . $key,
                (string) $contentType['label'],
                (string) $contentType['menu_icon'],
                Url::admin($slug),
                $items,
                self::under($here, $base)
            );
        }

        return $sections;
    }

    private static function mediaSection(string $here): array
    {
        return Sidebar::group('media', 'Files and media', 'group-media', Url::admin('media'), [
            Sidebar::link('Library', Url::admin('media'), self::under($here, '/admin/media'), 'library'),
            Sidebar::link('Galleries', Url::admin('galleries'), self::under($here, '/admin/galleries'), 'galleries'),
        ], self::under($here, '/admin/media') || self::under($here, '/admin/galleries'));
    }

    private static function formsSection(string $here): array
    {
        return Sidebar::group('forms', 'Forms', 'group-forms', Url::admin('forms'), [
            Sidebar::link('All forms', Url::admin('forms'), $here === '/admin/forms', 'forms'),
            Sidebar::link(
                'Submissions',
                Url::admin('forms/submissions'),
                self::under($here, '/admin/forms/submissions'),
                'submissions'
            ),
            // WHAT THE FORMS TURNED AWAY AND KEPT (`Forms\Guard`) — its own
            // row, beside the rest.
            Sidebar::link('Spam', Url::admin('forms/spam'), self::under($here, '/admin/forms/spam'), 'spam'),
        ], self::under($here, '/admin/forms'));
    }

    /** Does any content type carry the `product` archetype? */
    private static function hasProducts(): bool
    {
        return \Cms\ContentType\Registry::withArchetype('product') !== [];
    }

    private static function commerceSection(string $here): array
    {
        $onShop = self::under($here, '/admin/orders')
               || self::under($here, '/admin/coupons');

        return Sidebar::group('commerce', 'Shop', 'orders', Url::admin('orders'), [
            Sidebar::link('Orders', Url::admin('orders'), self::under($here, '/admin/orders'), 'orders'),
            // DISCOUNT CODES ARE NOT SETTINGS AND NOT CONTENT. Migration 045's
            // header has the argument: a coupon has no page, no slug and nothing
            // to say, so it is not a Content Type; there are many of them and
            // they are created and retired constantly, so it is not a settings
            // screen either. It gets a row of its own beside the orders it
            // discounts.
            Sidebar::link('Discount codes', Url::admin('coupons'),
                          self::under($here, '/admin/coupons'), 'coupons'),
        ], $onShop);
    }

    private static function structureSection(string $here): array
    {
        return Sidebar::group('structure', 'Structure', 'group-structure', Url::admin('content-types'), [
            Sidebar::link('Content types', Url::admin('content-types'), self::under($here, '/admin/content-types'), 'content-types'),
            Sidebar::link('Field groups', Url::admin('field-groups'), self::under($here, '/admin/field-groups'), 'field-groups'),
            // The taxonomies INDEX only. A taxonomy's terms are managed inside
            // the Content Type group they belong to, so this row must not light
            // up when you are on a terms screen.
            Sidebar::link(
                'Taxonomies',
                Url::admin('taxonomies'),
                $here === '/admin/taxonomies' || preg_match('~^/admin/taxonomies/(new|\d+)~', $here) === 1,
                'taxonomies'
            ),
        ], self::under($here, '/admin/content-types')
           || self::under($here, '/admin/field-groups')
           || self::under($here, '/admin/taxonomies'));
    }

    private static function appearanceSection(string $here): array
    {
        return Sidebar::group('appearance', 'Appearance', 'group-appearance', Url::admin('appearance/themes'), [
            // THEMES FIRST (`Helpers\Theme`).
            Sidebar::link('Themes', Url::admin('appearance/themes'), self::under($here, '/admin/appearance/themes'), 'themes'),
            // The CMS in the site's own name (`Ui\Brand`), and the sign-in
            // page's look.
            Sidebar::link('Branding', Url::admin('appearance/branding'), self::under($here, '/admin/appearance/branding'), 'branding'),
            // The menus module's rows (`Helpers\Modules`); Themes and Branding
            // are the CMS's own and stay. A null row is dropped.
            Modules::enabled('menus')
                ? Sidebar::link('Menus', Url::admin('appearance/menus'), self::under($here, '/admin/appearance/menus'), 'menus')
                : null,
            Modules::enabled('menus')
                ? Sidebar::link('Theme builder', Url::admin('appearance/theme-builder'), $here === '/admin/appearance/theme-builder', 'theme-builder')
                : null,
            Modules::enabled('menus')
                ? Sidebar::link('Customizer', Url::admin('appearance/customizer'), $here === '/admin/appearance/customizer', 'customizer')
                : null,
        ], self::under($here, '/admin/appearance'));
    }

    private static function toolsSection(string $here, bool $isAdmin): array
    {
        $rows = $isAdmin ? [
            Sidebar::link('Import and export', Url::admin('tools/import-export'), $here === '/admin/tools/import-export', 'import-export'),
            Sidebar::link('Redirects', Url::admin('tools/redirects'), $here === '/admin/tools/redirects', 'redirects'),
            Sidebar::link('System health', Url::admin('tools/system-health'), $here === '/admin/tools/system-health', 'system-health'),
        ] : [];
        // THE SITE'S OWN TOOLS, after the CMS's (`Site\Tools`): each answers
        // at /admin/tools/{path} itself; the CMS only lists it — to the roles
        // its row names, the same check its door makes (`Tools::dispatch()`).
        // One placed at the top is listed there instead (`leadSection()`).
        foreach (\Cms\Site\Tools::all() as $tool) {
            if ($tool['place'] === 'top' || !Auth::hasRole($tool['role'])) {
                continue;
            }
            $rows[] = Sidebar::link($tool['label'], Url::admin('tools/' . $tool['path']),
                self::under($here, '/admin/tools/' . $tool['path']), $tool['icon'] !== '' ? $tool['icon'] : 'tools');
        }

        // The group leads to its first row: Import and export for an
        // administrator, the first tool an editor may open for an editor.
        return Sidebar::group('tools', 'Tools', 'group-tools', (string) ($rows[0]['href'] ?? ''), $rows, self::under($here, '/admin/tools'));
    }

    private static function settingsSection(string $here): array
    {
        return Sidebar::group('settings', 'Settings', 'group-settings', Url::admin('settings/general'), [
            Sidebar::link('General', Url::admin('settings/general'), $here === '/admin/settings/general', 'settings'),
            Sidebar::link('SEO', Url::admin('settings/seo'), $here === '/admin/settings/seo', 'seo'),
            Sidebar::link('Permalinks', Url::admin('settings/permalinks'), $here === '/admin/settings/permalinks', 'permalinks'),
            Sidebar::link('Email', Url::admin('settings/email'), $here === '/admin/settings/email', 'email'),
            // ── SHOP SETTINGS, ON THE SAME CONDITION AS THE SHOP ────────────
            // `array_filter()` inside `Sidebar::group()` drops the null, so a
            // site with no product type gets the Settings group it always had
            // and no empty row. Same test as `commerceSection()`, so the two
            // cannot disagree about whether this install is a shop.
            self::hasProducts() && Modules::enabled('commerce')
                ? Sidebar::link('Shop', Url::admin('settings/commerce'), $here === '/admin/settings/commerce', 'commerce')
                : null,
            Sidebar::link('Users', Url::admin('users'), self::under($here, '/admin/users'), 'users'),
        ], self::under($here, '/admin/settings') || self::under($here, '/admin/users'));
    }

    /**
     * SECURITY — one row per screen, from the Overview to Files. Last in the
     * column, below Settings. Each row arrives with its screen, never before
     * it.
     */
    private static function securitySection(string $here): array
    {
        return Sidebar::group('security', 'Security', 'group-security', Url::admin('security'), [
            Sidebar::link('Overview', Url::admin('security'), $here === '/admin/security', 'security-overview'),
            Sidebar::link('Login protection', Url::admin('security/login-protection'), $here === '/admin/security/login-protection', 'login-protection'),
            Sidebar::link('Two-step sign-in', Url::admin('security/two-step'), $here === '/admin/security/two-step', 'two-step'),
            // Signing in without a password: a code by email, or a link.
            Sidebar::link('Passwordless', Url::admin('security/passwordless'), $here === '/admin/security/passwordless', 'passwordless'),
            // Temporary access — a link with a lifespan.
            Sidebar::link('Temporary access', Url::admin('security/temporary-access'), $here === '/admin/security/temporary-access', 'temporary-access'),
            Sidebar::link('Activity log', Url::admin('security/activity'), $here === '/admin/security/activity', 'activity-log'),
            // CAPTCHA, addresses and user enumeration.
            Sidebar::link('Blocking', Url::admin('security/blocking'), $here === '/admin/security/blocking', 'blocking'),
            // HTTP Strict Transport Security, an owner's decision.
            Sidebar::link('HTTPS', Url::admin('security/https'), $here === '/admin/security/https', 'https'),
            // File change detection (`Security\FileCheck`).
            Sidebar::link('Files', Url::admin('security/files'), $here === '/admin/security/files', 'file-check'),
        ], self::under($here, '/admin/security'));
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * Is `$here` this path, or somewhere beneath it?
     *
     * The `. '/'` matters: a bare `str_starts_with('/admin/pages-archive',
     * '/admin/pages')` is true, and would light Pages while you stand somewhere
     * else entirely.
     */
    private static function under(string $here, string $path): bool
    {
        return $here === $path || str_starts_with($here, $path . '/');
    }
}
