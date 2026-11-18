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
     * GET /api/v1/export/products/{key} — a type that sells, as a spreadsheet:
     * a row for each product and one for each of its options
     * (`Commerce\ProductSheet`), the file the import reads back. It starts
     * with the byte-order mark that tells a spreadsheet the file is UTF-8, so
     * "Café — M / Navy" opens as it was written.
     */
    public function products(array $vars = []): void
    {
        Auth::requireRole('admin');

        $key = (string) ($vars['key'] ?? '');
        if (Registry::get($key) === null || !\Cms\Commerce\Product::isSellableType($key)) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'No content type that sells is called that']);
            return;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $key . '-products-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-store');
        echo "\xEF\xBB\xBF" . \Cms\Commerce\ProductSheet::export($key);
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
            "SELECT id, title, slug, lang,
                    (SELECT o.slug FROM pages o WHERE o.id = pages.translation_of) AS translates,
                    content, parent_id, menu_order, status, password_hash,
                    featured_image_id, seo_title, meta_description,
                    created_at, updated_at
             FROM pages
             WHERE status != 'trash'
             ORDER BY menu_order ASC, title ASC"
        );
        return array_map([self::class, 'carried'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * WHAT ANOTHER INSTALL NEEDS TO KEEP A ROW AS IT WAS HERE (`Content\Import`):
     * a row behind a password keeps the password's hash, and no other row
     * carries one; and the featured image goes as a file, not as an id, which
     * names another file anywhere else: its name, its size and its contents'
     * SHA-256, for the import to find it in its own library.
     *
     * A TRANSLATION (`Content\Translation`) carries its language and the slug
     * of the row it translates (`translates`), its original's id being this
     * install's; a row in the site's own language carries neither.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function carried(array $row): array
    {
        if (($row['status'] ?? '') !== 'password') {
            unset($row['password_hash']);
        }
        if ((string) ($row['lang'] ?? '') === '') {
            unset($row['lang'], $row['translates']);
        } elseif ((string) ($row['translates'] ?? '') === '') {
            unset($row['translates']);
        }
        $image = \Cms\Models\Media::describe((int) ($row['featured_image_id'] ?? 0));
        if ($image !== null) {
            $row['featured_image'] = $image;
        }

        return $row;
    }

    private function getContentTypeEntries(string $postType): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT p.id, p.title, p.slug, p.lang,
                    (SELECT o.slug FROM posts o WHERE o.id = p.translation_of) AS translates,
                    p.content, p.excerpt, p.status, p.published_at, p.password_hash,
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
            $entry         = self::carried($entry);
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
