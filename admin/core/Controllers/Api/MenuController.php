<?php

declare(strict_types=1);

namespace Cms\Controllers\Api;

use Cms\Auth\Auth;
use Cms\ContentType\Registry;
use Cms\ContentType\TaxonomyRegistry;
use Cms\Http\Cache;
use Cms\Models\Menu;
use Cms\Models\MenuItem;
use Cms\Models\Page;
use Cms\Models\Term;

class MenuController
{
    /**
     * GET /api/v1/menus/{id}/items — return item tree as JSON.
     */
    public function items(array $vars = []): void
    {
        Auth::requireCan('menus');

        $menuId = (int) ($vars['id'] ?? 0);
        $menu   = Menu::find($menuId);

        header('Content-Type: application/json; charset=utf-8');
        Cache::noStore();

        if (!$menu) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Menu not found']);
            return;
        }

        $items = MenuItem::allForMenu($menuId);
        $tree  = MenuItem::buildTree($items);

        echo json_encode(['success' => true, 'data' => $tree]);
    }

    /**
     * POST /api/v1/menus/{id}/items — save entire tree (bulk replace).
     */
    public function saveItems(array $vars = []): void
    {
        Auth::requireCan('menus');

        $menuId = (int) ($vars['id'] ?? 0);
        $menu   = Menu::find($menuId);

        header('Content-Type: application/json; charset=utf-8');
        Cache::noStore();

        if (!$menu) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Menu not found']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $items = $input['items'] ?? [];

        if (!is_array($items)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid items data']);
            return;
        }

        MenuItem::replaceAll($menuId, $items);

        echo json_encode(['success' => true]);
    }

    /**
     * GET /api/v1/menu-sources/pages — page list for "Add to menu" picker.
     */
    public function sourcePages(array $vars = []): void
    {
        Auth::requireCan('menus');

        header('Content-Type: application/json; charset=utf-8');
        Cache::noStore();

        $pages = Page::all();
        $data  = [];
        foreach ($pages as $p) {
            $data[] = [
                'id'    => (int) $p['id'],
                'title' => $p['title'],
                'slug'  => $p['slug'],
                'url'   => \Cms\Helpers\Url::pageOf($p),
            ];
        }

        echo json_encode(['success' => true, 'data' => $data]);
    }

    /**
     * GET /api/v1/menu-sources/terms — terms that HAVE a public address.
     *
     * ── ONLY FROM TAXONOMIES WITH A PUBLIC SLUG ─────────────────────────────
     * Migration 048 made the public archive opt-in: blank means no public
     * pages, deliberately, for a taxonomy that only organises the admin. Terms
     * from one of those have no address, so offering them here would be a menu
     * control that adds a link to `#`.
     *
     * Each option carries its taxonomy's name, because "Campus" alone does not
     * say whether it is a Topic or a Club type, and an owner with both will
     * have two terms of the same name.
     */
    public function sourceTerms(array $vars = []): void
    {
        Auth::requireCan('menus');

        header('Content-Type: application/json; charset=utf-8');
        Cache::noStore();

        $data = [];
        foreach (TaxonomyRegistry::all() as $taxKey => $tax) {
            $slug = (string) ($tax['public_slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            foreach (Term::allForTaxonomy((string) $taxKey) as $term) {
                $data[] = [
                    'id'       => (int) $term['id'],
                    'title'    => (string) $term['name'],
                    'taxonomy' => (string) ($tax['label_single'] ?? $taxKey),
                    'url'      => \Cms\Helpers\Url::term($term, $slug),
                ];
            }
        }

        echo json_encode(['success' => true, 'data' => $data]);
    }

    /**
     * GET /api/v1/menu-sources/content-types — registered CTs for archive links.
     */
    public function sourceContentTypes(array $vars = []): void
    {
        Auth::requireCan('menus');

        header('Content-Type: application/json; charset=utf-8');
        Cache::noStore();

        // Only types whose entries have pages (`Registry::hasPages()`): one
        // without has no archive to link to.
        $data = [];
        foreach (Registry::withPages() as $key => $ct) {
            $publicSlug = (string) Registry::publicSlug((string) $key);
            $data[] = [
                'key'   => $key,
                'label' => $ct['label'],
                'url'   => \Cms\Helpers\Url::archive((string) $publicSlug),
            ];
        }

        echo json_encode(['success' => true, 'data' => $data]);
    }
}
