<?php

declare(strict_types=1);

namespace Cms\Controllers\Api;

use Cms\Auth\Auth;
use Cms\Commerce\Product;
use Cms\Commerce\ShopApi;
use Cms\Commerce\Stock;
use Cms\Commerce\StockLog;
use Cms\Commerce\Variation;
use Cms\ContentType\Registry;
use Cms\Database\Database;
use Cms\Models\Order;
use Cms\Security\ApiKeys;
use PDO;

/**
 * THE SHOP'S API: products, orders and stock, for another program.
 *
 *   GET  /api/v1/products              every product on sale, its options inside
 *   GET  /api/v1/orders                ?status= ?since= (ISO 8601) ?limit= (100 at most), newest first
 *   GET  /api/v1/orders/{id}           one order
 *   POST /api/v1/orders/{id}/status    {"status": "completed", "tracking": {"carrier", "number", "url"}}
 *   POST /api/v1/stock                 {"sku": "UH-NAV-M", "count": 12, "was": 10}
 *
 * ── A KEY, OR AN ADMIN SIGNED IN ────────────────────────────────────────────
 * A program sends its key in a header (`Security\ApiKeys`); a shop
 * manager signed in to the admin can read in a browser too, as the exports
 * are read. Writing takes a key that may write: a signed-in browser is never
 * a program's way in, so no form on another site can write through it.
 *
 * ── WRITES GO THROUGH THE SHOP'S OWN DOORS ──────────────────────────────────
 * A status through `Order::setStatus()`, with its stock, codes and emails; a
 * count through the stock screen's compare-and-set, a line in the history
 * named for the key. A count posted with the one it was (`was`) is refused if
 * it moved, as the stock screen refuses a count sold from while it was typed.
 */
class ShopApiController
{
    public function products(array $vars = []): void
    {
        $this->who(false);
        $out = [];
        foreach (Registry::all() as $key => $type) {
            if (!Product::isSellableType((string) $key)) {
                continue;
            }
            $stmt = Database::getInstance()->prepare(
                "SELECT id FROM posts WHERE post_type = :t AND status = 'published' ORDER BY created_at DESC, id DESC"
            );
            $stmt->execute([':t' => (string) $key]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $product = Product::find((int) $id);
                if ($product !== null) {
                    $out[] = ShopApi::product($product);
                }
            }
        }
        $this->json(200, ['products' => $out]);
    }

    public function orders(array $vars = []): void
    {
        $this->who(false);
        $where  = ['1 = 1'];
        $params = [];
        $status = (string) ($_GET['status'] ?? '');
        if ($status !== '') {
            if (!isset(Order::STATUSES[$status])) {
                $this->json(400, ['error' => 'status is one of ' . implode(', ', array_keys(Order::STATUSES)) . '.']);
            }
            $where[]           = 'status = :status';
            $params[':status'] = $status;
        }
        $since = (string) ($_GET['since'] ?? '');
        if ($since !== '') {
            $at = strtotime($since);
            if ($at === false) {
                $this->json(400, ['error' => 'since is a date and time, such as 2026-11-01T00:00:00Z.']);
            }
            $where[]          = 'created_at >= :since';
            $params[':since'] = gmdate('Y-m-d H:i:s', $at);
        }
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
        $stmt  = Database::getInstance()->prepare(
            'SELECT id FROM orders WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $order = Order::find((int) $id);
            if ($order !== null) {
                $out[] = ShopApi::order($order);
            }
        }
        $this->json(200, ['orders' => $out]);
    }

    public function order(array $vars = []): void
    {
        $this->who(false);
        $order = Order::find((int) ($vars['id'] ?? 0));
        if ($order === null) {
            $this->json(404, ['error' => 'No order has that id.']);
        }
        $this->json(200, ['order' => ShopApi::order($order)]);
    }

    public function setStatus(array $vars = []): void
    {
        $by    = $this->who(true);
        $id    = (int) ($vars['id'] ?? 0);
        $body  = $this->body();
        $order = Order::find($id);
        if ($order === null) {
            $this->json(404, ['error' => 'No order has that id.']);
        }
        $status = (string) ($body['status'] ?? '');
        if ($status !== '' && $status !== (string) $order['status']) {
            if (!isset(Order::STATUSES[$status])) {
                $this->json(400, ['error' => 'status is one of ' . implode(', ', array_keys(Order::STATUSES)) . '.']);
            }
            if (!Order::setStatus($id, $status, $by)) {
                $this->json(409, ['error' => 'The order could not move to ' . $status . '.']);
            }
        }
        if (is_array($body['tracking'] ?? null)) {
            $t       = $body['tracking'];
            $problem = Order::setTracking($id, (string) ($t['carrier'] ?? ''), (string) ($t['number'] ?? ''), (string) ($t['url'] ?? ''), $by);
            if ($problem !== null) {
                $this->json(400, ['error' => $problem]);
            }
        }
        $this->json(200, ['order' => ShopApi::order((array) Order::find($id))]);
    }

    public function setStock(array $vars = []): void
    {
        $by   = $this->who(true);
        $body = $this->body();
        $sku  = trim((string) ($body['sku'] ?? ''));
        $now  = $body['count'] ?? null;
        if ($sku === '' || !is_int($now) || $now < 0) {
            $this->json(400, ['error' => 'Send the sku and the count, a whole number 0 or more.']);
        }
        $db  = Database::getInstance();
        $opt = $db->prepare('SELECT id, post_id, stock_qty, manage_stock FROM product_variations WHERE sku = :s LIMIT 1');
        $opt->execute([':s' => $sku]);
        $variation = $opt->fetch(PDO::FETCH_ASSOC);
        $postId    = null;
        if ($variation === false) {
            $own = $db->prepare("SELECT post_id FROM post_meta WHERE meta_key = :k AND meta_value = :s LIMIT 1");
            $own->execute([':k' => Product::SKU, ':s' => $sku]);
            $postId = $own->fetchColumn() ?: null;
            if ($postId === null) {
                $this->json(404, ['error' => 'No product or option has the sku ' . $sku . '.']);
            }
        }
        $current = $variation !== false
            ? (empty($variation['manage_stock']) ? null : (int) $variation['stock_qty'])
            : Stock::countOf((int) $postId);
        if ($current === null) {
            $this->json(409, ['error' => $sku . ' does not count its stock.']);
        }
        $was = isset($body['was']) && is_int($body['was']) ? $body['was'] : $current;
        $set = StockLog::during('api', null, $by, static fn (): bool => $variation !== false
            ? Variation::setCount((int) $variation['id'], $was, $now)
            : Stock::setCount((int) $postId, $was, $now));
        if (!$set) {
            $this->json(409, ['error' => $sku . ' has ' . $current . ' now, not ' . $was . ': it changed. Send it again with the count it has.',
                              'count' => $current]);
        }
        $this->json(200, ['sku' => $sku, 'count' => $now]);
    }

    // ── Plumbing ────────────────────────────────────────────────────────────

    /** Who is asking, by name for the history and the notes; the answer is sent when nobody may. */
    private function who(bool $write): string
    {
        $key = ApiKeys::fromRequest();
        if ($key !== null) {
            if ($write && empty($key['can_write'])) {
                $this->json(403, ['error' => 'This key reads only. Make one that may write, on Settings → API.']);
            }
            return 'API: ' . (string) $key['name'];
        }
        if (!$write && Auth::check() && Auth::hasRole('shop_manager')) {
            return (string) (Auth::user()['username'] ?? '');
        }
        $this->json(401, ['error' => 'Send an API key in the X-Api-Key header: Settings → API makes one.']);
    }

    /** @return array<string, mixed> the JSON a write sends */
    private function body(): array
    {
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $this->json(400, ['error' => 'Send JSON.']);
        }

        return $body;
    }

    /** @param array<string, mixed> $data */
    private function json(int $status, array $data): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
