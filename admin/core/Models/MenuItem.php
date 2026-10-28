<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\ContentType\Registry;
use Cms\ContentType\TaxonomyRegistry;
use Cms\Database\Database;
use Cms\Ui\MenuItemSettings;
use PDO;

class MenuItem
{
    /**
     * THE SUB-MENU MODES THE RENDERER CAN ACTUALLY DRAW.
     *
     * A constant and not an inline literal, because `MenuItemSettings::tabs()`
     * offers these in a select and the two must not drift: an option the dialog
     * draws and this rejects is silently rewritten to `standard` on save, which
     * is a control that looks like it worked.
     *
     * `standard` a dropdown list · `mega` a panel dealt into columns ·
     * `tabbed` a panel whose second level is tabs down the side.
     */
    public const SUB_MENU_TYPES = ['standard', 'mega', 'tabbed'];

    /**
     * WHO AN ITEM IS SHOWN TO — the same treatment, for the same reason.
     *
     * Three answers and no more: everyone (stored as NULL), only people signed
     * in, only people signed out. Roles are left out deliberately — see
     * `visibilityJson()` below.
     *
     * `''` is in the list because it is what the control POSTS for "everyone";
     * `visibilityJson()` turns it into NULL rather than storing a row of JSON
     * saying what the renderer already assumes.
     */
    public const VISIBILITY = ['', 'signed_in', 'signed_out'];

    /**
     * THE MOST COLUMNS A MEGA PANEL CAN BE DIVIDED INTO — twelve.
     *
     * A fifth column on a 1280px screen is 190px wide and holds no link, which
     * would argue for a cap of four only if each column were one link wide. The
     * panel is a GRID: twelve columns with items SPANNING several of them, which
     * is the twelve-column grid every layout system uses, and a single item is
     * never 1/12 wide unless somebody makes it so.
     */
    public const MAX_PANEL_COLUMNS = 12;

    /**
     * All items for a menu, ordered by position.
     */
    public static function allForMenu(int $menuId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT * FROM menu_items WHERE menu_id = :mid ORDER BY position ASC"
        );
        $stmt->execute([':mid' => $menuId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Build a nested tree from a flat list of items.
     *
     * @return array<int, array> Each node has a 'children' key.
     */
    public static function buildTree(array $items): array
    {
        $indexed = [];
        foreach ($items as &$item) {
            $item['children'] = [];
            $indexed[(int) $item['id']] = &$item;
        }
        unset($item);

        $tree = [];
        foreach ($indexed as &$item) {
            $parentId = $item['parent_id'] !== null ? (int) $item['parent_id'] : 0;
            if ($parentId > 0 && isset($indexed[$parentId])) {
                $indexed[$parentId]['children'][] = &$item;
            } else {
                $tree[] = &$item;
            }
        }
        unset($item);

        return $tree;
    }

    /**
     * Resolve the public URL for a menu item based on its type.
     */
    public static function resolveUrl(array $item): string
    {
        switch ($item['type']) {
            case 'page':
                if ($item['target_id']) {
                    $page = Page::find((int) $item['target_id']);
                    if ($page) {
                        return \Cms\Helpers\Url::page((string) $page['slug']);
                    }
                }
                return '#';

            case 'entry':
                // ── ONE ENTRY, RESOLVED FROM THE ENTRY ──────────────────────
                // A single entry can be added to a menu. With only a page, a
                // type's ARCHIVE, a term or a typed URL to add, "link to the
                // Annual General Meeting" would mean pasting an address by hand.
                //
                // Resolved here rather than baked in at save time, exactly as
                // `page` and `taxonomy` above are: renaming the type's public
                // slug — or the entry's own — moves every menu item with it,
                // instead of leaving a menu full of links to an address that
                // stopped existing.
                if (!empty($item['target_id'])) {
                    $post = Post::find((int) $item['target_id']);
                    if ($post !== null) {
                        $type = Registry::get((string) $post['post_type']);
                        $tp   = (string) ($type['public_slug'] ?? '');

                        // NO PUBLIC ARCHIVE, NO ENTRY PAGE. A type with a blank
                        // public slug gets no public routes at all in
                        // `www/index.php`, so an address built here would 404.
                        if ($tp !== '') {
                            return \Cms\Helpers\Url::page($tp . '/' . $post['slug']);
                        }
                    }
                }

                return '#';

            case 'content_type':
                // target_id stores the CT key as a string in the url field;
                // or if target_id is an entry ID, resolve that post.
                // For archive links, url is set directly during save.
                if (!empty($item['url'])) {
                    return $item['url'];
                }
                return '#';

            case 'taxonomy':
                // ── RESOLVED FROM THE TERM, NOT FROM A STORED ADDRESS ────────
                // This arm has existed since menus were written and returned
                // `#` unless someone had typed a URL into it by hand — because
                // until migration 048 a term HAD no public address to resolve
                // to. Every taxonomy route in the router was under /admin or
                // /api.
                //
                // Resolving here rather than baking the address in at save time
                // is the same rule the `page` arm follows two cases up: renaming
                // the taxonomy's public slug moves every menu item with it,
                // instead of leaving a menu full of links to an address that
                // stopped existing.
                if (!empty($item['target_id'])) {
                    $term = Term::find((int) $item['target_id']);
                    if ($term !== null) {
                        $tax = TaxonomyRegistry::get((string) $term['taxonomy_key']);
                        $tp  = (string) ($tax['public_slug'] ?? '');

                        // A TAXONOMY WITH NO PUBLIC ARCHIVE HAS NO ADDRESS, and
                        // that is a real state — blank means "no public pages"
                        // deliberately. `#` is the honest answer; inventing one
                        // would be a link that 404s.
                        if ($tp !== '') {
                            return \Cms\Helpers\Url::page($tp . '/' . $term['slug']);
                        }
                    }
                }

                return !empty($item['url']) ? $item['url'] : '#';

            case 'custom':
            default:
                return $item['url'] ?? '#';
        }
    }

    /**
     * Replace all items in a menu with a new set (bulk save).
     *
     * @param int   $menuId
     * @param array $items  Flat list: each has label, type, url, target_id, parent_index, css_class, target_blank
     *                      parent_index is the 0-based index of the parent in this same array (-1 for root).
     */
    public static function replaceAll(int $menuId, array $items): void
    {
        $pdo = Database::getInstance();

        // Delete existing items
        $stmt = $pdo->prepare("DELETE FROM menu_items WHERE menu_id = :mid");
        $stmt->execute([':mid' => $menuId]);

        if (empty($items)) {
            return;
        }

        // Insert in order, tracking new IDs for parent references
        $newIds = [];

        // ── THE THREE COLUMNS MIGRATION 022 DECLARED AND NOTHING EVER WROTE ─
        // `sub_menu_type`, `mega_columns` and `visibility_rules` shipped with
        // the menus table and had, until now, zero readers and zero writers
        // anywhere in the tree — the same shape as `orders.shipping_total`
        // before delivery was built. A column nothing sets is a feature the
        // schema claims and the product does not have.
        $insertStmt = $pdo->prepare(
            "INSERT INTO menu_items
                (menu_id, parent_id, position, type, target_id, url, label, description,
                 css_class, target_blank, sub_menu_type, mega_columns, visibility_rules,
                 settings)
             VALUES
                (:mid, :pid, :pos, :type, :tid, :url, :label, :desc,
                 :css, :blank, :submenu, :cols, :vis, :set)"
        );

        foreach ($items as $index => $item) {
            $parentIndex = (int) ($item['parent_index'] ?? -1);
            $parentId    = ($parentIndex >= 0 && isset($newIds[$parentIndex]))
                ? $newIds[$parentIndex]
                : null;

            $insertStmt->execute([
                ':mid'   => $menuId,
                ':pid'   => $parentId,
                ':pos'   => $index,
                ':type'  => $item['type'] ?? 'custom',
                ':tid'   => !empty($item['target_id']) ? (int) $item['target_id'] : null,
                ':url'   => $item['url'] ?? null,
                ':label' => $item['label'] ?? 'Untitled',
                // Migration 051. Trimmed and capped to the column, and NULL when
                // empty rather than '' — "no description" is one state, not two.
                ':desc'  => (static function (mixed $d): ?string {
                    $d = is_string($d) ? trim($d) : '';
                    return $d === '' ? null : mb_substr($d, 0, 500);
                })($item['description'] ?? null),
                ':css'   => $item['css_class'] ?? null,
                ':blank' => !empty($item['target_blank']) ? 1 : 0,
                // AN ALLOWLIST, not whatever was posted. These two go straight
                // into the renderer's branching, and a value it has never heard
                // of would fall through to no sub-menu at all — a menu item
                // whose children silently stop appearing.
                ':submenu' => in_array((string) ($item['sub_menu_type'] ?? ''), self::SUB_MENU_TYPES, true)
                    ? (string) $item['sub_menu_type']
                    : 'standard',
                // THE COLUMN COUNT, and only for a panel that is a panel. On a
                // standard dropdown it is a number nothing reads — the state
                // this whole file exists to stop.
                ':cols'    => ((string) ($item['sub_menu_type'] ?? '') === 'mega')
                    ? json_encode(['count' => max(1, min(self::MAX_PANEL_COLUMNS, (int) ($item['mega_columns'] ?? 2)))])
                    : null,
                ':vis'     => self::visibilityJson($item['visibility'] ?? null),
                // MIGRATION 050'S BAG, sanitised against the ONE definition the
                // dialog renders from and the renderer reads. A key the dialog
                // does not offer is dropped here rather than stored, so a stale
                // control on a cached page cannot write a setting nothing reads.
                ':set'     => MenuItemSettings::sanitise($item['settings'] ?? null),
            ]);

            $newIds[$index] = (int) $pdo->lastInsertId();
        }
    }

    /**
     * A NEW TOP-LEVEL PAGE JOINS EVERY MENU THAT ASKED FOR ONE.
     *
     * Migration 049's `menus.auto_add_pages`, and `Page::create()` is the single
     * caller — see the comment there for why it hangs off the insert rather than
     * off the screen.
     *
     * ── APPENDED AT THE TOP LEVEL, AT THE END ───────────────────────────────
     * `position` is the array index `replaceAll()` writes, so "the end" is
     * `MAX(position) + 1` and nothing else moves. Inserting anywhere else would
     * reorder a menu its owner arranged by hand, which is the thing this admin is
     * least allowed to do — and a child of something would be a parent this
     * feature picked on the owner's behalf.
     *
     * ── AND IT CANNOT PRODUCE A DUPLICATE ───────────────────────────────────
     * A page already in the menu is skipped. `Page::create()` runs once per page
     * so this should not arise, but an importer that retries would otherwise put
     * the same link in twice with no way to tell them apart.
     */
    public static function autoAddPage(int $pageId, string $title): void
    {
        $menus = Menu::autoAddMenus();
        if ($menus === []) {
            return;
        }

        $pdo = Database::getInstance();

        $existing = $pdo->prepare(
            "SELECT COUNT(*) FROM menu_items
              WHERE menu_id = :mid AND type = 'page' AND target_id = :tid"
        );
        $next = $pdo->prepare(
            "SELECT COALESCE(MAX(position), -1) + 1 FROM menu_items WHERE menu_id = :mid"
        );
        $insert = $pdo->prepare(
            "INSERT INTO menu_items (menu_id, parent_id, position, type, target_id, label)
             VALUES (:mid, NULL, :pos, 'page', :tid, :label)"
        );

        foreach ($menus as $menu) {
            $menuId = (int) $menu['id'];

            $existing->execute([':mid' => $menuId, ':tid' => $pageId]);
            if ((int) $existing->fetchColumn() > 0) {
                continue;
            }

            $next->execute([':mid' => $menuId]);
            $insert->execute([
                ':mid'   => $menuId,
                ':pos'   => (int) $next->fetchColumn(),
                ':tid'   => $pageId,
                // THE LABEL IS A COPY, taken once. A menu label is editable — the
                // whole point of the box on every row — so it cannot be resolved
                // from the page on every render without throwing away the edit.
                ':label' => $title !== '' ? $title : 'Untitled',
            ]);
        }
    }

    /**
     * WHO SEES THIS ITEM — stored as JSON because migration 022 made the column
     * JSON, and read back by `MenuRenderer`.
     *
     * Three answers and no more: everyone, only people who are signed in, only
     * people who are not. Roles were considered and left out — this admin has
     * three of them and a menu that hides a link from an editor does not stop
     * them reaching it, so it would be a security-shaped control that is not
     * security. Signed-in is different: it is the difference between a "Log in"
     * link and a "My account" one, which is the actual reason a site owner wants
     * this at all.
     */
    private static function visibilityJson(mixed $raw): ?string
    {
        $who = is_string($raw) ? $raw : '';

        // NULL FOR THE DEFAULT rather than `{"who":"everyone"}`. The renderer
        // treats an absent rule as "show it", so writing the default would put a
        // row of JSON on every item in every menu that says what nothing needs
        // to ask.
        // NOT IN THE LIST, OR THE ONE THAT MEANS "EVERYONE" — either way there is
        // nothing to store. `VISIBILITY` is the list; `''` is in it because that
        // is what the control posts, and NULL is what that means in the table.
        if ($who === '' || !in_array($who, self::VISIBILITY, true)) {
            return null;
        }

        return json_encode(['who' => $who]);
    }
}
