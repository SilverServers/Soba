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
 *     Appearance      Menus
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
 * ── UNLESS THE SITE ARRANGES IT ─────────────────────────────────────────────
 * A site whose staff work by job rather than by type says so in
 * `admin/config/admin-menu.php` (`Site\Column`): its rows under its own
 * headings, each a type's list, a taxonomy's terms, a page, a tool, a
 * settings page, one of the CMS's screens or an address, and for an
 * administrator the CMS's own groups beneath (`arrangedSections()`).
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
 * calls in the controllers (`admits()`).
 */
final class AdminMenu
{
    /**
     * THE CMS'S OWN SCREENS, by their address under the admin's: the rows of
     * its groups below, and what a site's arranged column names with
     * `'screen' => 'media'` (`Site\Column`). Each one's name, its mark, and
     * how its row lights (`lit()`): on its address and every one beneath it
     * ('under'), on its address alone ('exact'), or, for Taxonomies, on the
     * index and a taxonomy's own screens and never its terms, which are a
     * type's. Who may open each is `admits()`.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const SCREENS = [
        'pages'                     => ['Pages', 'pages', 'under'],
        'media'                     => ['Library', 'library', 'under'],
        'galleries'                 => ['Galleries', 'galleries', 'under'],
        'picture-places'            => ['Picture places', 'picture-places', 'under'],
        'forms'                     => ['All forms', 'forms', 'exact'],
        'forms/submissions'         => ['Submissions', 'submissions', 'under'],
        'forms/spam'                => ['Spam', 'spam', 'under'],
        'orders'                    => ['Orders', 'orders', 'under'],
        'customers'                 => ['Customers', 'customers', 'under'],
        'reports'                   => ['Reports', 'reports', 'under'],
        'coupons'                   => ['Discount codes', 'coupons', 'under'],
        'stock'                     => ['Stock', 'stock', 'under'],
        'content-types'             => ['Content types', 'content-types', 'under'],
        'field-groups'              => ['Field groups', 'field-groups', 'under'],
        'taxonomies'                => ['Taxonomies', 'taxonomies', 'taxonomies'],
        'appearance/menus'          => ['Menus', 'menus', 'under'],
        'tools/import-export'       => ['Import and export', 'import-export', 'exact'],
        'tools/redirects'           => ['Redirects', 'redirects', 'exact'],
        'tools/static-copy'         => ['Static copy', 'static-copy', 'exact'],
        'tools/broken-links'        => ['Broken links', 'broken-links', 'exact'],
        'tools/backups'             => ['Backups', 'backups', 'exact'],
        'tools/system-health'       => ['System health', 'system-health', 'exact'],
        'settings/general'          => ['General', 'settings', 'exact'],
        'settings/branding'         => ['Branding', 'branding', 'exact'],
        'settings/business'         => ['Business details', 'business', 'exact'],
        'settings/seo'              => ['SEO', 'seo', 'exact'],
        'settings/permalinks'       => ['Permalinks', 'permalinks', 'exact'],
        'settings/words'            => ['Words', 'words', 'exact'],
        'settings/email'            => ['Email', 'email', 'exact'],
        'settings/commerce'         => ['Shop', 'commerce', 'exact'],
        'settings/api'              => ['API', 'api', 'exact'],
        'users'                     => ['Users', 'users', 'under'],
        'security'                  => ['Overview', 'security-overview', 'exact'],
        'security/login-protection' => ['Login protection', 'login-protection', 'exact'],
        'security/two-step'         => ['Two-step sign-in', 'two-step', 'exact'],
        'security/passwordless'     => ['Passwordless', 'passwordless', 'exact'],
        'security/temporary-access' => ['Temporary access', 'temporary-access', 'exact'],
        'security/activity'         => ['Activity log', 'activity-log', 'exact'],
        'security/blocking'         => ['Blocking', 'blocking', 'exact'],
        'security/https'            => ['HTTPS', 'https', 'exact'],
        'security/files'            => ['Files', 'file-check', 'exact'],
    ];

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

        $column   = \Cms\Site\Column::rows();
        $sections = $column !== null ? self::arrangedSections($here, $column) : self::ownSections($here);

        return Sidebar::spec(self::openWhereLit($sections));
    }

    /**
     * MAY THE SIGNED-IN ACCOUNT OPEN THIS SCREEN (`SCREENS`)? Each answer is
     * the door its controller keeps, so a row the column draws never turns
     * its reader away.
     */
    public static function admits(string $screen): bool
    {
        return match ($screen) {
            // `PageController` is `requireRole('editor')` on every action.
            'pages'                                    => Auth::hasRole('editor') && Modules::enabled('pages'),
            'media'                                    => Auth::check(),
            // `GalleryController` and `PicturePlaceController` are the
            // administrators'; Picture places only once the site lists one.
            'galleries'                                => Auth::hasRole('admin'),
            'picture-places'                           => Auth::hasRole('admin') && \Cms\Helpers\Places::all('picture') !== [],
            // A switched-off module shows nowhere (`Helpers\Modules`).
            'forms', 'forms/submissions', 'forms/spam' => Auth::check() && Modules::enabled('forms'),
            // ── THE SHOP, ONLY WHEN THERE IS ONE, AND ONLY FOR WHOEVER RUNS
            // IT (`commerceSection()`).
            'orders', 'customers', 'reports', 'coupons', 'stock'
                                                       => self::hasProducts() && Modules::enabled('commerce') && Auth::hasRole('shop_manager'),
            // A SCREEN GRANTED BELOW ADMINISTRATOR (`Auth::can()`).
            'appearance/menus'                         => Modules::enabled('menus') && Auth::can('menus'),
            'tools/redirects'                          => Auth::can('redirects'),
            'settings/business'                        => Auth::can('business'),
            'settings/words'                           => Auth::can('words'),
            'settings/commerce', 'settings/api'        => self::hasProducts() && Modules::enabled('commerce') && Auth::hasRole('admin'),
            default                                    => isset(self::SCREENS[$screen]) && Auth::hasRole('admin'),
        };
    }

    /**
     * Does a door let the account in: a CMS screen's (`admits()`), a least
     * role, or the screen at an admin address (`admitsPath()`)?
     *
     * @param array<string, string> $door
     */
    public static function opens(array $door): bool
    {
        return match (true) {
            isset($door['screen']) => self::admits($door['screen']),
            isset($door['role'])   => Auth::hasRole($door['role']),
            isset($door['path'])   => self::admitsPath($door['path']),
            default                => false,
        };
    }

    /**
     * Does the screen at this admin address let the account in? The nearest
     * one the column knows, by the longest address that holds it: one of the
     * CMS's screens, a type's, a taxonomy's terms, a site tool, a settings
     * page. One under none of them answers as the dashboard does: anyone
     * signed in.
     */
    public static function admitsPath(string $path): bool
    {
        $doors = [];
        foreach (array_keys(self::SCREENS) as $key) {
            $doors['/admin/' . $key] = ['screen' => $key];
        }
        foreach (Registry::all() as $type) {
            $doors['/admin/' . trim((string) $type['admin_slug'], '/')] = ['role' => 'author'];
        }
        foreach (TaxonomyRegistry::all() as $tax) {
            $doors['/admin/taxonomies/' . trim((string) $tax['admin_slug'], '/') . '/terms'] = ['role' => 'editor'];
        }
        foreach (\Cms\Site\Tools::all() as $tool) {
            $doors['/admin/tools/' . $tool['path']] = ['role' => $tool['role']];
        }
        foreach (\Cms\Site\SettingsPages::all() as $key => $settingsPage) {
            $doors['/admin/site-settings/' . $key] = ['role' => (string) $settingsPage['role']];
        }
        $nearest = null;
        foreach (array_keys($doors) as $base) {
            if (self::under($path, (string) $base) && ($nearest === null || strlen((string) $base) > strlen($nearest))) {
                $nearest = (string) $base;
            }
        }

        return $nearest === null ? Auth::check() : self::opens($doors[$nearest]);
    }

    /**
     * How near a row's address is to the page: its length when the row lights
     * on `$here` by its match (`SCREENS`), else 0. The column lights only the
     * nearest, so an About page's own row and a Pages row are never lit
     * together.
     */
    public static function lit(string $match, ?string $path, string $here): int
    {
        if ($path === null) {
            return 0;
        }
        $on = match ($match) {
            'exact'      => $here === $path,
            'taxonomies' => $here === $path || preg_match('~^' . preg_quote($path, '~') . '/(new|\d+)~', $here) === 1,
            default      => self::under($here, $path),
        };

        return $on ? strlen($path) : 0;
    }

    // ----------------------------------------------------------------
    // Sections
    // ----------------------------------------------------------------

    /**
     * The column as the CMS draws it: the lead rows, a group per top-level
     * Content Type, then its own groups.
     *
     * @return list<array<string, mixed>>
     */
    private static function ownSections(string $here): array
    {
        $sections = [self::leadSection($here, Auth::hasRole('editor'))];

        // One group per top-level Content Type, in Registry order.
        foreach (self::contentTypeSections($here) as $section) {
            $sections[] = $section;
        }

        return [...$sections, ...self::cmsGroups($here)];
    }

    /**
     * THE COLUMN A SITE ARRANGES (`Site\Column`): Dashboard and the rows under
     * no heading, then each heading with its rows, each row shown only to an
     * account its screen lets in and its own role allows. Only the nearest
     * row to the page lights (`lit()`). For an administrator the CMS's own
     * groups follow under one heading, less the screens the file has placed
     * and the site's own tools and settings pages, which are where the file
     * puts them. No type has a group.
     *
     * @param array{lead: list<array<string, mixed>>, headings: list<array{label: string, rows: list<array<string, mixed>>}>, cms: string} $column
     * @return list<array<string, mixed>>
     */
    private static function arrangedSections(string $here, array $column): array
    {
        $places  = [$column['lead'], ...array_column($column['headings'], 'rows')];
        $shown   = [];
        $nearest = 0;
        foreach ($places as $place => $rows) {
            foreach ($rows as $row) {
                if (!self::opens($row['door']) || ($row['role'] !== null && !Auth::hasRole($row['role']))) {
                    continue;
                }
                $reach           = self::lit($row['match'], $row['path'], $here);
                $nearest         = max($nearest, $reach);
                $shown[$place][] = [$row, $reach];
            }
        }
        $link = static fn (array $r): array => Sidebar::link((string) $r[0]['label'], (string) $r[0]['href'],
            $r[1] > 0 && $r[1] === $nearest, (string) $r[0]['icon']);

        $lead = [Sidebar::link('Dashboard', Url::admin(), $here === '/admin', 'dashboard'), ...array_map($link, $shown[0] ?? [])];
        if (Modules::enabled('assistant')) {
            $lead[] = Sidebar::link('Assistant', Url::admin('assistant'), self::under($here, '/admin/assistant'), 'assistant');
        }
        $sections = [Sidebar::lead($lead)];
        foreach ($column['headings'] as $i => $heading) {
            $sections[] = Sidebar::heading('rows-' . ($i + 1), $heading['label'], array_map($link, $shown[$i + 1] ?? []));
        }
        if (!Auth::hasRole('admin')) {
            return $sections;
        }

        $placed = [];
        foreach ($shown as $rows) {
            foreach ($rows as [$row]) {
                $placed[(string) $row['href']] = true;
            }
        }
        foreach (\Cms\Site\Tools::all() as $tool) {
            $placed[Url::admin('tools/' . $tool['path'])] = true;
        }
        foreach (array_keys(\Cms\Site\SettingsPages::all()) as $key) {
            $placed[Url::admin('site-settings/' . $key)] = true;
        }
        $groups = [];
        foreach (self::cmsGroups($here) as $group) {
            $group['items'] = array_values(array_filter((array) ($group['items'] ?? []),
                static fn (array $item): bool => !isset($placed[(string) $item['href']])));
            if ($group['items'] !== []) {
                $groups[] = $group;
            }
        }

        return $groups === [] ? $sections : [...$sections, Sidebar::heading('cms', $column['cms'], [], true), ...$groups];
    }

    /**
     * THE CMS'S OWN GROUPS, Files and media to Security, each for whoever may
     * open it.
     *
     * @return list<array<string, mixed>>
     */
    private static function cmsGroups(string $here): array
    {
        $isAdmin  = Auth::hasRole('admin');
        $sections = [self::mediaSection($here)];

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
        // AND ONLY FOR WHOEVER RUNS IT. Orders hold customers' addresses; an
        // author or an editor has no business there (`Auth::ROLE_LEVELS`).
        if (self::hasProducts() && Modules::enabled('commerce') && Auth::hasRole('shop_manager')) {
            $sections[] = self::commerceSection($here);
        }

        if ($isAdmin) {
            $sections[] = self::structureSection($here);
        }
        // Appearance holds Menus alone, so it goes with the menus module, for
        // whoever may open it (`Auth::can()`: administrators, and a role
        // config.php grants it).
        if (Modules::enabled('menus') && Auth::can('menus')) {
            $sections[] = self::appearanceSection($here);
        }
        // Tools sits between the two in every column that has it: the CMS's
        // own rows are the administrators', but a site's tool is shown to the
        // roles its row names, so an editor's column can hold the group too.
        $sections[] = self::toolsSection($here, $isAdmin);
        // Settings: the CMS's own rows are the administrators', and a site's
        // settings page placed here is shown to the roles it names, so an
        // editor's column can hold the group with those alone.
        $settings = self::settingsSection($here, $isAdmin);
        if ($settings !== null) {
            $sections[] = $settings;
        }
        if ($isAdmin) {
            $sections[] = self::securitySection($here);
        }

        return array_values(array_filter($sections));
    }

    /**
     * A GROUP OPENS ON ITS OWN ROWS, NOT ON ITS ADDRESS. Each group says the
     * addresses it is home to, so a screen with no row of its own (a form's
     * editor, an import's report) still opens the group it belongs to; but
     * once any row in the column is lit, only the group holding it is
     * current. Otherwise a site tool placed at the top opened Tools beneath
     * it, every address under /admin/tools being Tools', and a taxonomy's
     * terms, lit in their type's group, opened Structure too.
     *
     * @param list<array<string, mixed>> $sections
     * @return list<array<string, mixed>>
     */
    private static function openWhereLit(array $sections): array
    {
        foreach ($sections as $section) {
            foreach ((array) ($section['items'] ?? []) as $item) {
                if (!empty($item['active'])) {
                    foreach ($sections as $i => $s) {
                        if (isset($s['active'])) {
                            $sections[$i]['active'] = false;
                        }
                    }

                    return $sections;
                }
            }
        }

        return $sections;
    }

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
        // A SITE'S OWN SETTINGS PAGE placed here (`Site\SettingsPages`), for the roles it names.
        foreach (\Cms\Site\SettingsPages::forSidebar('top') as $settingsPage) {
            $items[] = Sidebar::link((string) $settingsPage['label'], Url::admin('site-settings/' . $settingsPage['key']),
                self::under($here, '/admin/site-settings/' . $settingsPage['key']), $settingsPage['icon'] !== '' ? (string) $settingsPage['icon'] : 'settings');
        }
        if (Modules::enabled('assistant')) {
            $items[] = Sidebar::link('Assistant', Url::admin('assistant'), self::under($here, '/admin/assistant'), 'assistant');
        }

        // `PageController` is `requireRole('editor')` on every action, so an
        // author following this row would be bounced to the dashboard.
        if ($isEditor) {
            $items[] = self::screen('pages', $here);
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
        $refused  = [];

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
            // EACH ONCE IN THE GROUP, WITH THE PARENT TYPE: one on this type, or
            // on more than one type in its group, is listed here; one on a
            // single child type alone, with that child, below. ONLY FOR AN
            // EDITOR AND UP, whose the terms screens are (`TermController`):
            // an author following one would be turned back to the dashboard.
            $termsRows = Auth::hasRole('editor');
            $inGroup   = [];
            foreach ($termsRows ? [$key, ...($children[$key] ?? [])] : [] as $member) {
                foreach (array_keys(TaxonomyRegistry::getForContentType((string) $member)) as $taxKey) {
                    $inGroup[$taxKey][] = $member;
                }
            }
            $listed = [];
            foreach ($inGroup as $taxKey => $members) {
                $tax = TaxonomyRegistry::get((string) $taxKey);
                if ($tax === null || ($members[0] !== $key && count($members) === 1)) {
                    continue;
                }
                $listed[$taxKey] = true;
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

                // A child type's own taxonomies belong with it: those it shares
                // with the parent or another child are listed with the parent.
                foreach ($termsRows ? TaxonomyRegistry::getForContentType((string) $childKey) : [] as $taxKey => $tax) {
                    if (isset($listed[$taxKey])) {
                        continue;
                    }
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
            // and this is what renders them. A ROW FOR A ROLE (`'role' =>
            // 'admin'`) is shown to it and the roles above it, as a site tool's
            // row is; one naming a role there is none of is left out and said
            // in the log, once an hour while it stays.
            foreach ((array) ($contentType['nav_items'] ?? []) as $navItem) {
                $role = $navItem['role'] ?? null;
                if ($role !== null && (!is_string($role) || !isset(Auth::ROLE_LEVELS[$role]))) {
                    $refused[] = '"' . (string) ($navItem['label'] ?? '') . '" of "' . $key . '", whose role is not one of '
                        . implode(', ', array_keys(Auth::ROLE_LEVELS));
                    continue;
                }
                if ($role !== null && !Auth::hasRole($role)) {
                    continue;
                }
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
        if ($refused !== []) {
            \Cms\Helpers\LogNote::hourly('nav_items_noted', 'Content types: sidebar rows left out: ' . implode('; ', $refused) . '.');
        }

        return $sections;
    }

    private static function mediaSection(string $here): array
    {
        // PICTURE PLACES only when the site lists any (`Helpers\Places`, kind
        // 'picture'), and with Galleries the administrators' (`admits()`).
        return Sidebar::group('media', 'Files and media', 'group-media', Url::admin('media'), [
            self::screen('media', $here),
            self::screen('galleries', $here),
            self::screen('picture-places', $here),
        ], self::under($here, '/admin/media') || self::under($here, '/admin/galleries') || self::under($here, '/admin/picture-places'));
    }

    private static function formsSection(string $here): array
    {
        return Sidebar::group('forms', 'Forms', 'group-forms', Url::admin('forms'), [
            self::row('forms', $here),
            self::row('forms/submissions', $here),
            // WHAT THE FORMS TURNED AWAY AND KEPT (`Forms\Guard`) — its own
            // row, beside the rest.
            self::row('forms/spam', $here),
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
               || self::under($here, '/admin/customers')
               || self::under($here, '/admin/reports')
               || self::under($here, '/admin/coupons')
               || self::under($here, '/admin/stock');

        return Sidebar::group('commerce', 'Shop', 'orders', Url::admin('orders'), [
            self::row('orders', $here),
            // WHO HAS BOUGHT, AS THEIR ORDERS MAKE THEM: under the orders they
            // are made of (`Commerce\Customers`).
            self::row('customers', $here),
            // WHAT SOLD, AND THE TAX COLLECTED, over any stretch of days.
            self::row('reports', $here),
            // DISCOUNT CODES ARE NOT SETTINGS AND NOT CONTENT. Migration 045's
            // header has the argument: a coupon has no page, no slug and nothing
            // to say, so it is not a Content Type; there are many of them and
            // they are created and retired constantly, so it is not a settings
            // screen either. It gets a row of its own beside the orders it
            // discounts.
            self::row('coupons', $here),
            // EVERY COUNT ON ONE SCREEN, and each product's history of them.
            self::row('stock', $here),
        ], $onShop);
    }

    private static function structureSection(string $here): array
    {
        return Sidebar::group('structure', 'Structure', 'group-structure', Url::admin('content-types'), [
            self::row('content-types', $here),
            self::row('field-groups', $here),
            // The taxonomies INDEX only. A taxonomy's terms are managed inside
            // the Content Type group they belong to, so this row must not light
            // up when you are on a terms screen.
            self::row('taxonomies', $here),
        ], self::under($here, '/admin/content-types')
           || self::under($here, '/admin/field-groups')
           || self::under($here, '/admin/taxonomies'));
    }

    private static function appearanceSection(string $here): array
    {
        return Sidebar::group('appearance', 'Appearance', 'group-appearance', Url::admin('appearance/menus'), [
            self::row('appearance/menus', $here),
        ], self::under($here, '/admin/appearance'));
    }

    private static function toolsSection(string $here, bool $isAdmin): array
    {
        $rows = $isAdmin ? [
            self::row('tools/import-export', $here),
            self::row('tools/redirects', $here),
            self::row('tools/static-copy', $here),
            self::row('tools/broken-links', $here),
            self::row('tools/backups', $here),
            self::row('tools/system-health', $here),
        ] : [];
        // A SCREEN GRANTED BELOW ADMINISTRATOR (`Auth::can()`) is its role's row too.
        if (!$isAdmin && Auth::can('redirects')) {
            $rows[] = self::row('tools/redirects', $here);
        }
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

    private static function settingsSection(string $here, bool $isAdmin): ?array
    {
        // A SITE'S OWN SETTINGS PAGES placed in Settings (`Site\SettingsPages`), after the CMS's own.
        $own = [];
        foreach (\Cms\Site\SettingsPages::forSidebar('settings') as $settingsPage) {
            $own[] = Sidebar::link((string) $settingsPage['label'], Url::admin('site-settings/' . $settingsPage['key']),
                self::under($here, '/admin/site-settings/' . $settingsPage['key']), $settingsPage['icon'] !== '' ? (string) $settingsPage['icon'] : 'settings');
        }
        $onOwn = $own !== [] && array_filter(\Cms\Site\SettingsPages::forSidebar('settings'), static fn (array $p): bool => self::under($here, '/admin/site-settings/' . $p['key'])) !== [];
        if (!$isAdmin) {
            // THE SETTINGS SCREENS A ROLE IS GRANTED (`Auth::can()`), then the site's own pages.
            $granted = array_values(array_filter([
                self::screen('settings/business', $here),
                self::screen('settings/words', $here),
            ]));
            $rows = [...$granted, ...$own];
            $onGranted = $here === '/admin/settings/business' || $here === '/admin/settings/words';

            return $rows === [] ? null
                : Sidebar::group('settings', 'Settings', 'group-settings', (string) $rows[0]['href'], $rows, $onOwn || ($granted !== [] && $onGranted));
        }

        return Sidebar::group('settings', 'Settings', 'group-settings', Url::admin('settings/general'), [...[
            self::row('settings/general', $here),
            // The site's logo on the top bar and the sign-in pages, and their look (`Ui\Branding`).
            self::row('settings/branding', $here),
            self::row('settings/business', $here),
            self::row('settings/seo', $here),
            self::row('settings/permalinks', $here),
            self::row('settings/words', $here),
            self::row('settings/email', $here),
            // ── SHOP SETTINGS, ON THE SAME CONDITION AS THE SHOP ────────────
            // `array_filter()` inside `Sidebar::group()` drops the null, so a
            // site with no product type gets the Settings group it always had
            // and no empty row. Same test as `commerceSection()` (`admits()`),
            // so the two cannot disagree about whether this install is a shop.
            self::screen('settings/commerce', $here),
            // THE KEYS AND WEBHOOKS OTHER PROGRAMS USE, beside the shop's settings.
            self::screen('settings/api', $here),
            self::row('users', $here),
        ], ...$own], self::under($here, '/admin/settings') || self::under($here, '/admin/users') || $onOwn);
    }

    /**
     * SECURITY — one row per screen, from the Overview to Files. Last in the
     * column, below Settings. Each row arrives with its screen, never before
     * it.
     */
    private static function securitySection(string $here): array
    {
        return Sidebar::group('security', 'Security', 'group-security', Url::admin('security'), [
            self::row('security', $here),
            self::row('security/login-protection', $here),
            self::row('security/two-step', $here),
            // Signing in without a password: a code by email, or a link.
            self::row('security/passwordless', $here),
            // Temporary access — a link with a lifespan.
            self::row('security/temporary-access', $here),
            self::row('security/activity', $here),
            // CAPTCHA, addresses and user enumeration.
            self::row('security/blocking', $here),
            // HTTP Strict Transport Security, an owner's decision.
            self::row('security/https', $here),
            // File change detection (`Security\FileCheck`).
            self::row('security/files', $here),
        ], self::under($here, '/admin/security'));
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * One of the CMS's screens as a row (`SCREENS`), for a section whose own
     * condition is already the screen's door.
     */
    private static function row(string $key, string $here): array
    {
        [$label, $icon, $match] = self::SCREENS[$key];

        return Sidebar::link($label, Url::admin($key), self::lit($match, '/admin/' . $key, $here) > 0, $icon);
    }

    /** The same row, for an account its door lets in (`admits()`), else null. */
    private static function screen(string $key, string $here): ?array
    {
        return self::admits($key) ? self::row($key, $here) : null;
    }

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
