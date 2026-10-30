<?php

declare(strict_types=1);

namespace Cms\Controllers\Api;

use Cms\Auth\Auth;
use Cms\ContentType\Registry;
use Cms\Database\Database;
use Cms\Models\Meta;
use Cms\Models\Option;
use Cms\Models\Page;
use Cms\Models\Post;
use PDO;

/**
 * ExportController — JSON download endpoints for content export.
 *
 * All endpoints require admin role and return downloadable JSON files.
 */
class ExportController
{
    /**
     * GET /api/v1/export/all — export everything.
     */
    public function all(array $vars = []): void
    {
        Auth::requireRole('admin');

        $data = [
            'cms_export' => true,
            'version'        => '1.0',
            'exported_at'    => date('c'),
            'pages'          => $this->getPages(),
            'content_types'  => [],
            'settings'       => $this->getSettings(),
        ];

        foreach (Registry::all() as $key => $ct) {
            $data['content_types'][$key] = [
                'label'   => $ct['label'],
                'entries' => $this->getContentTypeEntries($key),
            ];
        }

        $this->sendJson($data, 'cms-export-all');
    }

    /**
     * GET /api/v1/export/pages — pages only.
     */
    public function pages(array $vars = []): void
    {
        Auth::requireRole('admin');

        $data = [
            'cms_export' => true,
            'version'        => '1.0',
            'exported_at'    => date('c'),
            'pages'          => $this->getPages(),
        ];

        $this->sendJson($data, 'cms-export-pages');
    }

    /**
     * GET /api/v1/export/content-type/{key} — specific Content Type.
     */
    public function contentType(array $vars = []): void
    {
        Auth::requireRole('admin');

        $key = $vars['key'] ?? '';
        $ct  = Registry::get($key);

        if ($ct === null) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Content type not found']);
            return;
        }

        $data = [
            'cms_export' => true,
            'version'        => '1.0',
            'exported_at'    => date('c'),
            'content_type'   => $key,
            'label'          => $ct['label'],
            'entries'        => $this->getContentTypeEntries($key),
        ];

        $this->sendJson($data, 'cms-export-' . $key);
    }

    /**
     * GET /api/v1/export/settings — options table dump.
     */
    public function settings(array $vars = []): void
    {
        Auth::requireRole('admin');

        $data = [
            'cms_export' => true,
            'version'        => '1.0',
            'exported_at'    => date('c'),
            'settings'       => $this->getSettings(),
        ];

        $this->sendJson($data, 'cms-export-settings');
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function getPages(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query(
            "SELECT id, title, slug, content, parent_id, menu_order, status,
                    featured_image_id, seo_title, meta_description,
                    created_at, updated_at
             FROM pages
             WHERE status != 'trash'
             ORDER BY menu_order ASC, title ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getContentTypeEntries(string $postType): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT p.id, p.title, p.slug, p.content, p.excerpt, p.status,
                    p.featured_image_id, p.author_id,
                    p.created_at, p.updated_at,
                    " . \Cms\Models\User::nameSql('u') . " AS author_name
             FROM posts p
             JOIN users u ON u.id = p.author_id
             WHERE p.post_type = :pt AND p.status != 'trash'
             ORDER BY p.created_at DESC"
        );
        $stmt->execute([':pt' => $postType]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Attach meta + terms to each entry
        foreach ($entries as &$entry) {
            $entry['meta'] = Meta::getAll((int) $entry['id']);

            $termStmt = $pdo->prepare(
                "SELECT t.name, t.slug, t.taxonomy_key
                 FROM post_term_relationships ptr
                 JOIN terms t ON t.id = ptr.term_id
                 WHERE ptr.post_id = :pid"
            );
            $termStmt->execute([':pid' => $entry['id']]);
            $entry['terms'] = $termStmt->fetchAll(PDO::FETCH_ASSOC);

            // ── A PRODUCT'S OPTIONS ARE PART OF THE PRODUCT ──────────────────
            // They live in `product_variations`, not in meta, so the export
            // carried none of them: a shop restored or moved from this file lost
            // every size, colour and count. Written with what a person would
            // type — the price as the form shows it, blank when it is the
            // product's own — and no ids, which mean nothing on another install.
            if (\Cms\Commerce\Product::isSellableType($postType)) {
                // ITS SIZE AND COLOUR BY SLUG, as the entry's own terms are:
                // a term's id is this install's, and its slug is what two
                // installs can agree on. Its photo is left out, as the entry's
                // featured image is — an attachment id names a different file
                // anywhere else.
                $slugOf = static function (int $termId): string {
                    $term = \Cms\Models\Term::find($termId);
                    return $term !== null ? (string) $term['slug'] : '';
                };
                $entry['options'] = array_map(static fn (array $v): array => [
                    'label'        => (string) $v['label'],
                    'sku'          => (string) $v['sku'],
                    'price'        => $v['price'] === null ? null : \Cms\Commerce\Money::edit((int) $v['price']),
                    'sale_price'   => ($v['sale_price'] ?? null) === null ? null : \Cms\Commerce\Money::edit((int) $v['sale_price']),
                    'terms'        => array_filter(array_map($slugOf, \Cms\Commerce\Variation::termsOf($v))),
                    'manage_stock' => (int) $v['manage_stock'],
                    'stock_qty'    => (int) $v['stock_qty'],
                    'stock_status' => (string) $v['stock_status'],
                ], \Cms\Commerce\Variation::forProduct((int) $entry['id']));
            }
        }
        unset($entry);

        return $entries;
    }

    private function getSettings(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query("SELECT option_key, option_value FROM options ORDER BY option_key ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['option_key']] = $row['option_value'];
        }
        return $settings;
    }

    /**
     * HOW MANY RECORDS EACH EXPORT WILL CONTAIN — `pages`, `settings`, and one
     * entry per registered Content Type key.
     *
     * ── WHY THIS LIVES HERE AND NOT ON THE SCREEN ───────────────────────────
     * The Tools screen used to offer four identical `Download` links with
     * nothing but a label to tell them apart, and no way to know whether a file
     * would hold three records or three thousand before it landed in the
     * downloads folder. The obvious fix is a count beside each one — and the
     * obvious way to get it is a `COUNT(*)` written in the template, which is
     * how a number that DISAGREES with the file gets shipped.
     *
     * These count exactly what the getters above select. `status != 'trash'` is
     * repeated here deliberately, sitting a few lines from the queries it has to
     * match, rather than being inferred by a caller who cannot see them. If a
     * getter's WHERE clause changes, the count is the next thing on the screen.
     *
     * @return array{pages: int, settings: int, types: array<string, int>}
     */
    public static function counts(): array
    {
        $pdo = Database::getInstance();

        $types = [];
        foreach (Registry::all() as $key => $ct) {
            // Post::countOfType() carries the identical predicate and is already
            // the number the Content Types list shows, so the two screens cannot
            // report different totals for the same type.
            $types[(string) $key] = Post::countOfType((string) $key);
        }

        return [
            'pages'    => (int) $pdo->query("SELECT COUNT(*) FROM pages WHERE status != 'trash'")->fetchColumn(),
            'settings' => (int) $pdo->query('SELECT COUNT(*) FROM options')->fetchColumn(),
            'types'    => $types,
        ];
    }

    private function sendJson(array $data, string $filenamePrefix): void
    {
        $filename = $filenamePrefix . '-' . date('Y-m-d') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
