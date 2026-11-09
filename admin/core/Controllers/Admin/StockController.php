<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Commerce\Product;
use Cms\Commerce\Stock;
use Cms\Commerce\StockLog;
use Cms\Commerce\Variation;
use Cms\Security\Csrf;

/**
 * THE SHOP'S STOCK, ON ONE SCREEN.
 *
 * ── EVERY COUNT, AND ONE REASON A SAVE ──────────────────────────────────────
 * Every product that counts its stock, or each of its options, with the count
 * in a box. A delivery is received by typing the new counts and saving with
 * the reason Received; a recount, a damaged one and a customer's return the
 * same. Each count changed is a line in its product's history, with the
 * reason, who, and the note.
 *
 * ── A COUNT THAT MOVED WHILE THE OWNER WAS TYPING IS NOT OVERWRITTEN ───────
 * Each box posts the count it was showing beside the new one. A sale between
 * the screen opening and the save changed the count underneath, and writing
 * the typed figure over it would lose the sale: that row is left as it is, and
 * named with its count now, to be typed again.
 */
final class StockController
{
    /** The stock screen: every counted product and option. */
    public function index(array $vars = []): void
    {
        Auth::requireRole('shop_manager');
        $this->render(null);
    }

    /** New counts, with one reason for all of them. */
    public function handleSave(array $vars = []): void
    {
        Auth::requireRole('shop_manager');
        if (!Csrf::validate()) {
            http_response_code(400);
            exit('Invalid request.');
        }

        $reason = (string) ($_POST['reason'] ?? '');
        if (!in_array($reason, StockLog::SCREEN_REASONS, true)) {
            $reason = 'recount';
        }
        $note  = trim((string) ($_POST['note'] ?? ''));
        $user  = Auth::user();
        $by    = (string) ($user['name'] ?? $user['username'] ?? '');
        $now   = is_array($_POST['count'] ?? null) ? $_POST['count'] : [];
        $was   = is_array($_POST['was'] ?? null) ? $_POST['was'] : [];

        $report = ['changed' => 0, 'moved' => [], 'refused' => []];
        StockLog::during($reason, null, $by, function () use ($now, $was, &$report): void {
            foreach ($now as $key => $typed) {
                $typed = trim((string) $typed);
                $shown = trim((string) ($was[$key] ?? ''));
                if ($typed === $shown) {
                    continue;
                }
                if (!preg_match('/^\d{1,6}$/', $typed) || !preg_match('/^-?\d{1,6}$/', $shown)) {
                    $report['refused'][] = (string) $key;
                    continue;
                }
                [$kind, $id] = [substr((string) $key, 0, 1), (int) substr((string) $key, 1)];
                $done = $kind === 'v'
                    ? Variation::setCount($id, (int) $shown, (int) $typed)
                    : Stock::setCount($id, (int) $shown, (int) $typed);
                if ($done) {
                    $report['changed']++;
                } else {
                    $report['moved'][] = (string) $key;
                }
            }
        }, $note);

        $this->render($report);
    }

    /** One product's history: every change to its counts, newest first. */
    public function history(array $vars = []): void
    {
        Auth::requireRole('shop_manager');
        $postId  = (int) ($vars['id'] ?? 0);
        $product = Product::find($postId);
        if ($product === null) {
            header('Location: ' . ADMIN_URL . '/stock');
            exit;
        }
        $moves = StockLog::forProduct($postId);

        $pageTitle   = 'Stock: ' . (string) $product['title'];
        $currentPage = 'stock';
        require ROOT_PATH . '/admin/templates/stock/history.php';
    }

    /**
     * The screen, with what the last save did when there was one.
     *
     * @param array{changed: int, moved: list<string>, refused: list<string>}|null $report
     */
    private function render(?array $report): void
    {
        $rows        = Stock::listing();
        $lowAt       = Stock::lowAt();
        $pageTitle   = 'Stock';
        $currentPage = 'stock';
        require ROOT_PATH . '/admin/templates/stock/index.php';
    }
}
