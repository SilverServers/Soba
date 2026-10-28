<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\ContentType\MenuLocationRegistry;
use Cms\ContentType\Registry;
use Cms\ContentType\TaxonomyRegistry;
use Cms\Helpers\MenuRenderer;
use Cms\Models\Media;
use Cms\Models\Menu;
use Cms\Models\MenuItem;
use Cms\Models\Page;
use Cms\Models\Post;
use Cms\Models\Term;
use Cms\Ui\MenuItemSettings;
use Cms\Ui\MenuLocationSettings;
use Cms\Ui\MenuToggleBlocks;

/**
 * APPEARANCE → MENUS.
 *
 * ── A MENU CAN BE IN SEVERAL LOCATIONS ──────────────────────────────────────
 * Migration 049. `location_slug` is gone from `menus`; the pair lives in
 * `menu_locations`, and every read here goes through the `Menu` model's
 * `locationMap()` / `locationsFor()`. There is no second answer to "where does
 * this menu appear?" anywhere in this controller, deliberately.
 *
 * ── WHAT THE EDIT SCREEN IS HANDED, AND WHY IT IS COMPUTED HERE ─────────────
 * Four render sites — create, a failed create, edit and a failed update — and
 * the same rule computed twice drifts apart. So the whole payload is built by
 * one method and the four sites call it.
 */
class MenuController
{
    /**
     * HOW MANY OF ONE TYPE'S ENTRIES THE ADD PANEL OFFERS.
     *
     * NOT UNLIMITED, because a site with four thousand News entries would render
     * four thousand checkboxes into one page, and NOT SILENT ABOUT IT, because a
     * list that is quietly cut short reads as complete. The panel prints "Showing the 100 most recent of 412" whenever this bites, and
     * the filter box says plainly that it searches what is shown.
     */
    private const ADD_PANEL_LIMIT = 100;

    // ----------------------------------------------------------------
    // List
    // ----------------------------------------------------------------

    public function index(array $vars = []): void
    {
        Auth::requireRole('admin');

        $menus     = Menu::all();
        $locations = MenuLocationRegistry::all();
        $pageTitle   = 'Menus';
        $currentPage = 'menus';

        require ROOT_PATH . '/admin/templates/appearance/menus.php';
    }

    // ----------------------------------------------------------------
    // Manage Locations — the second tab
    // ----------------------------------------------------------------

    /**
     * WHICH MENU IS IN WHICH SLOT, read the other way round.
     *
     * The edit screen answers "where does THIS menu go?"; a theme with three
     * locations and six menus is more easily filled in by asking "what goes
     * HERE?", one row per location. Both are offered for that reason.
     *
     * Same table, same model calls, no third store.
     */
    public function showLocations(array $vars = []): void
    {
        Auth::requireRole('admin');

        $locations   = MenuLocationRegistry::all();
        $locationMap = Menu::locationMap();
        $menus       = Menu::all();
        $saved       = isset($_GET['saved']);
        $pageTitle   = 'Menu locations';
        $currentPage = 'menus';

        require ROOT_PATH . '/admin/templates/appearance/menu-locations.php';
    }

    public function handleLocations(array $vars = []): void
    {
        Auth::requireRole('admin');

        $posted = $_POST['locations'] ?? [];
        if (!is_array($posted)) {
            $posted = [];
        }

        // ONLY REGISTERED LOCATIONS. The form draws one row per registration, so
        // anything else in the payload was not on the screen — and a row keyed on
        // an unregistered slug is one no theme will ever ask for and no screen
        // will ever show again.
        foreach (MenuLocationRegistry::all() as $slug => $_loc) {
            $raw    = $posted[(string) $slug] ?? '';
            $menuId = is_string($raw) || is_int($raw) ? (int) $raw : 0;

            // AND ONLY A MENU THAT EXISTS. A stale id would insert a row whose
            // foreign key fails and abort the whole save over one dropdown.
            if ($menuId > 0 && Menu::find($menuId) === null) {
                continue;
            }

            Menu::assignLocation((string) $slug, $menuId > 0 ? $menuId : null);
        }

        header('Location: ' . ADMIN_URL . '/appearance/menus/locations?saved=1');
        exit;
    }

    /**
     * "SELECT A MENU TO EDIT" — the switcher's target.
     *
     * A GET, so nothing is saved by accident and the control needs no script:
     * a `<select onchange>` would strand anyone with scripting off on whichever
     * menu they opened first.
     *
     * AN UNKNOWN ID GOES TO THE LIST, not to a 404 and not to a blank editor.
     * The only way to reach this with a bad id is a stale bookmark or a menu
     * deleted in another tab, and the list is the screen that answers "so what
     * menus ARE there?".
     */
    public function selectMenu(array $vars = []): void
    {
        Auth::requireRole('admin');

        $menuId = (int) ($_GET['menu_id'] ?? 0);

        if ($menuId > 0 && Menu::find($menuId) !== null) {
            header('Location: ' . ADMIN_URL . '/appearance/menus/' . $menuId . '/edit');
            exit;
        }

        header('Location: ' . ADMIN_URL . '/appearance/menus');
        exit;
    }

    // ----------------------------------------------------------------
    // Create
    // ----------------------------------------------------------------

    public function showCreate(array $vars = []): void
    {
        Auth::requireRole('admin');

        $menu     = null;
        $formData = [];
        $errors   = [];
        extract(self::editPayload(null), EXTR_OVERWRITE);
        $pageTitle   = 'Add menu';
        $currentPage = 'menus';

        require ROOT_PATH . '/admin/templates/appearance/menu-edit.php';
    }

    public function handleCreate(array $vars = []): void
    {
        Auth::requireRole('admin');

        $name   = trim($_POST['name'] ?? '');
        $errors = [];

        if ($name === '') {
            $errors[] = 'Menu name is required.';
        }

        if (!empty($errors)) {
            $menu     = null;
            $formData = $_POST;
            extract(self::editPayload(null), EXTR_OVERWRITE);
            $pageTitle   = 'Add menu';
            $currentPage = 'menus';

            require ROOT_PATH . '/admin/templates/appearance/menu-edit.php';
            return;
        }

        $menuId = Menu::create($name);
        Menu::setLocations($menuId, self::postedLocations());

        header('Location: ' . ADMIN_URL . '/appearance/menus/' . $menuId . '/edit?saved=1');
        exit;
    }

    // ----------------------------------------------------------------
    // Edit
    // ----------------------------------------------------------------

    public function showEdit(array $vars = []): void
    {
        Auth::requireRole('admin');

        $menu = Menu::find((int) ($vars['id'] ?? 0));
        if (!$menu) {
            header('Location: ' . ADMIN_URL . '/appearance/menus');
            exit;
        }

        $formData = [];
        $errors   = [];
        extract(self::editPayload($menu), EXTR_OVERWRITE);
        $pageTitle   = 'Edit Menu: ' . $menu['name'];
        $currentPage = 'menus';
        $saved       = isset($_GET['saved']);

        require ROOT_PATH . '/admin/templates/appearance/menu-edit.php';
    }

    public function handleUpdate(array $vars = []): void
    {
        Auth::requireRole('admin');

        $menuId = (int) ($vars['id'] ?? 0);
        $menu   = Menu::find($menuId);
        if (!$menu) {
            header('Location: ' . ADMIN_URL . '/appearance/menus');
            exit;
        }

        $name   = trim($_POST['name'] ?? '');
        $errors = [];

        if ($name === '') {
            $errors[] = 'Menu name is required.';
        }

        if (!empty($errors)) {
            $formData = $_POST;
            extract(self::editPayload($menu), EXTR_OVERWRITE);
            $pageTitle   = 'Edit Menu: ' . $menu['name'];
            $currentPage = 'menus';

            require ROOT_PATH . '/admin/templates/appearance/menu-edit.php';
            return;
        }

        Menu::update($menuId, $name, !empty($_POST['auto_add_pages']));
        Menu::setLocations($menuId, self::postedLocations());

        // ── AND HOW IT BEHAVES IN EACH ──────────────────────────────────
        // AFTER `setLocations()`, never before: the rows it writes are the ones
        // these settings hang off, and a bag saved against a pair that has just
        // been deleted is a write nothing will ever read.
        self::saveLocationSettings($menuId);

        // Save menu items from JSON payload
        $itemsJson = $_POST['menu_items_json'] ?? '[]';
        $items     = json_decode($itemsJson, true);
        if (is_array($items)) {
            MenuItem::replaceAll($menuId, $items);
        }

        header('Location: ' . ADMIN_URL . '/appearance/menus/' . $menuId . '/edit?saved=1');
        exit;
    }

    // ----------------------------------------------------------------
    // Delete
    // ----------------------------------------------------------------

    public function handleDelete(array $vars = []): void
    {
        Auth::requireRole('admin');

        $menuId = (int) ($vars['id'] ?? 0);
        $menu   = Menu::find($menuId);
        if ($menu) {
            Menu::delete($menuId);
        }

        header('Location: ' . ADMIN_URL . '/appearance/menus');
        exit;
    }

    // ----------------------------------------------------------------
    // What the edit screen needs
    // ----------------------------------------------------------------

    /**
     * THE LOCATIONS THE FORM ACTUALLY OFFERED, filtered to the registry.
     *
     * A checkbox list posts an array of slugs. Anything in it that is not a
     * registered location was not on the screen, so it is dropped rather than
     * written — a row keyed on a slug no theme asks for is invisible everywhere
     * except the database.
     *
     * @return list<string>
     */
    private static function postedLocations(): array
    {
        $posted = $_POST['locations'] ?? [];
        if (!is_array($posted)) {
            return [];
        }

        $registered = MenuLocationRegistry::all();
        $out        = [];

        foreach ($posted as $slug) {
            if (!is_string($slug) && !is_int($slug)) {
                continue;
            }
            $slug = (string) $slug;
            if (isset($registered[$slug])) {
                $out[] = $slug;
            }
        }

        return $out;
    }

    /**
     * THE PER-LOCATION BEHAVIOUR BAGS, sanitised and stored.
     *
     * ONLY FOR PAIRS THIS MENU ACTUALLY HOLDS. `setLocationSettings()` scopes
     * its `UPDATE` by both ids, so a slug in the payload that the menu does not
     * occupy writes nothing — which is the authorisation, not a courtesy.
     */
    private static function saveLocationSettings(int $menuId): void
    {
        $posted = $_POST['location_settings'] ?? [];
        if (!is_array($posted)) {
            return;
        }

        $bars = $_POST['toggle_blocks'] ?? [];

        foreach (Menu::locationsFor($menuId) as $slug) {
            $bag = $posted[$slug] ?? null;
            if (is_array($bag)) {
                Menu::setLocationSettings($menuId, $slug, MenuLocationSettings::sanitise($bag));
            }

            // THE PHONE'S TOGGLE BAR (migration 052) — the header's bar only,
            // posted by the designer as one JSON list. Not posted at all means
            // not on the screen, which leaves what is stored alone.
            if (MenuRenderer::isBar($slug) && is_array($bars) && is_string($bars[$slug] ?? null)) {
                Menu::setToggleBlocks($menuId, $slug, MenuToggleBlocks::sanitise($bars[$slug]));
            }
        }
    }

    /**
     * EVERYTHING THE EDIT TEMPLATE RENDERS, IN ONE PLACE.
     *
     * @param  array<string, mixed>|null $menu
     * @return array<string, mixed>
     */
    private static function editPayload(?array $menu): array
    {
        $menuId    = $menu !== null ? (int) $menu['id'] : 0;
        $menuItems = $menuId > 0 ? MenuItem::allForMenu($menuId) : [];

        $itemMedia = [];
        foreach ($menuItems as $row) {
            foreach (MenuItemSettings::mediaIds($row['settings'] ?? null) as $id) {
                $itemMedia[$id] ??= Media::getUrl($id);
            }
        }

        $toggleBlocks = [];
        $toggleMedia  = [];
        foreach ($menuId > 0 ? Menu::toggleBlocksFor($menuId) : [] as $slug => $json) {
            $toggleBlocks[$slug] = MenuToggleBlocks::read($json);
            foreach ($toggleBlocks[$slug] as $block) {
                foreach (MenuToggleBlocks::types()[$block['type']]['fields'] as $field) {
                    $id = (int) ($field['type'] === 'image' ? $block[$field['key']] : 0);
                    if ($id > 0 && !isset($toggleMedia[$id])) {
                        $toggleMedia[$id] = Media::getUrl($id);
                    }
                }
            }
        }

        return [
            // HOW THE MENU BEHAVES IN EACH PLACE IT APPEARS — migration 050,
            // keyed by location slug. Drawn from what is SAVED, because a card
            // for a location the menu does not occupy yet would collect
            // settings against a row that does not exist.
            'locationSettings' => $menuId > 0 ? Menu::settingsFor($menuId) : [],
            // THE PHONE'S TOGGLE BAR for each location, read whole — and the
            // address of every image its blocks name, so the designer can show
            // a picture rather than an attachment number.
            'toggleBlocks'     => $toggleBlocks,
            'toggleMedia'      => $toggleMedia,
            // Every picture an item's settings name (its styling's background
            // image), so the item dialog shows it rather than its number.
            'itemMedia'        => $itemMedia,
            'menuItems'      => $menuItems,
            'tree'           => MenuItem::buildTree($menuItems),
            'locations'      => MenuLocationRegistry::all(),
            // WHO HOLDS EACH LOCATION NOW — what "(Currently set to: X)" is
            // written from. The owner is told what a tick will displace BEFORE
            // they tick it, rather than finding out when their other menu goes
            // dark.
            'locationMap'    => Menu::locationMap(),
            'menuLocations'  => $menuId > 0 ? Menu::locationsFor($menuId) : [],
            'allMenus'       => Menu::all(),
            'autoAddPages'   => $menu !== null && !empty($menu['auto_add_pages']),
            'pages'          => Page::all(),
            'entryGroups'    => self::entryGroups(),
            'termGroups'     => self::termGroups(),
        ];
    }

    /**
     * ONE ADD PANEL PER CONTENT TYPE: its archive, then its entries.
     *
     * ── THE ARCHIVE SITS WITH THE ENTRIES IT LISTS ──────────────────────────
     * "All News" and the individual News entries are the same panel's business,
     * and an owner looking for a link to the news section looks under News. The
     * old screen had a separate "Archive" dropdown one column away from nothing
     * else about that type.
     *
     * ── A TYPE WITH NO PUBLIC SLUG IS SKIPPED ENTIRELY ──────────────────────
     * Same rule the term panel follows: no public address, no link that resolves.
     * Offering one would add an item pointing at `#`.
     *
     * @return list<array{key: string, label: string, archive_url: string,
     *                    entries: list<array{id: int, title: string}>,
     *                    total: int, shown: int}>
     */
    private static function entryGroups(): array
    {
        $out = [];

        foreach (Registry::all() as $key => $type) {
            $slug = (string) ($type['public_slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $archetypes = is_array($type['archetypes'] ?? null) ? $type['archetypes'] : [];
            $entries    = Post::all((string) $key, null, $archetypes);
            $total      = count($entries);

            $rows = [];
            foreach (array_slice($entries, 0, self::ADD_PANEL_LIMIT) as $entry) {
                $rows[] = [
                    'id'    => (int) $entry['id'],
                    'title' => (string) ($entry['title'] ?? 'Untitled'),
                ];
            }

            $out[] = [
                'key'         => (string) $key,
                'label'       => (string) ($type['label'] ?? $key),
                // THE TYPE'S OWN GLYPH, the one the sidebar draws for it.
                'icon'        => (string) ($type['menu_icon'] ?? 'file-text'),
                'archive_url' => '/' . $slug,
                'entries'     => $rows,
                'total'       => $total,
                'shown'       => count($rows),
            ];
        }

        return $out;
    }

    /**
     * The terms a menu item can actually point at, grouped by their taxonomy.
     *
     * A TAXONOMY WITH NO PUBLIC SLUG IS SKIPPED. Migration 048 made the public
     * archive opt-in and blank means "no public pages" deliberately; offering
     * its terms would add a menu link to `#`.
     *
     * GROUPED, not one flat list with the taxonomy name glued to each row:
     * "Campus" alone does not say whether it is a Topic or a Club type, and an
     * owner with both will have two of that name.
     *
     * @return list<array{key: string, label: string,
     *                    terms: list<array{id: int, title: string}>}>
     */
    private static function termGroups(): array
    {
        $out = [];

        foreach (TaxonomyRegistry::all() as $taxKey => $tax) {
            if ((string) ($tax['public_slug'] ?? '') === '') {
                continue;
            }

            $terms = [];
            foreach (Term::allForTaxonomy((string) $taxKey) as $term) {
                $terms[] = [
                    'id'    => (int) $term['id'],
                    'title' => (string) $term['name'],
                ];
            }

            if ($terms === []) {
                continue;
            }

            $out[] = [
                'key'   => (string) $taxKey,
                'label' => (string) ($tax['label'] ?? $tax['label_single'] ?? $taxKey),
                'terms' => $terms,
            ];
        }

        return $out;
    }
}
