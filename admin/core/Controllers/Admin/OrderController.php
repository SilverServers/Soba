<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Commerce\Money;
use Cms\ContentType\Registry;
use Cms\Helpers\Url;
use Cms\Models\Order;
use Cms\Models\Post;
use Cms\Security\Csrf;

/**
 * ORDERS, IN THE ADMIN.
 *
 * ── THE SCREEN THE OWNER ACTUALLY LIVES ON ──────────────────────────────────
 * A shop's admin is not the product editor, it is this. Payment is offline in
 * this version, so the owner's whole working day is: an order arrives, they see
 * it, the money turns up in the bank, they mark it Processing, they post it,
 * they mark it Completed. Every one of those is a control on these two screens
 * and none of them is more than one click from the list.
 */
class OrderController
{
    public function index(array $vars = []): void
    {
        Auth::requireRole('shop_manager');

        // THE FILTER IS A GET PARAMETER, so a filtered view can be bookmarked
        // and sent to somebody. A client-side filter cannot be, and it also
        // lies about the counts the moment the list is paginated.
        $status = (string) ($_GET['status'] ?? '');
        if (!isset(Order::STATUSES[$status])) {
            $status = '';
        }

        $orders  = Order::all($status === '' ? null : $status);
        $counts  = Order::countsByStatus();
        $total   = array_sum($counts);

        $pageTitle   = 'Orders';
        $currentPage = 'orders';

        require ROOT_PATH . '/admin/templates/orders/list.php';
    }

    public function show(array $vars = []): void
    {
        Auth::requireRole('shop_manager');

        $order = Order::find((int) ($vars['id'] ?? 0));
        if ($order === null) {
            header('Location: ' . ADMIN_URL . '/orders');
            exit;
        }

        $items = $this->withEditUrls(Order::items((int) $order['id']));
        $notes = Order::notes((int) $order['id']);

        $pageTitle   = 'Order ' . Order::number($order);
        $currentPage = 'orders';

        require ROOT_PATH . '/admin/templates/orders/show.php';
    }

    public function handleStatus(array $vars = []): void
    {
        Auth::requireRole('shop_manager');
        $this->requireCsrf();

        $id     = (int) ($vars['id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');

        // `setStatus()` refuses an unknown status and a no-op change, and
        // writes the note itself — so the audit trail cannot be forgotten by a
        // caller. The name of whoever did it goes with it.
        Order::setStatus($id, $status, $this->who());

        header('Location: ' . ADMIN_URL . '/orders/' . $id, true, 303);
        exit;
    }

    public function handleNote(array $vars = []): void
    {
        Auth::requireRole('shop_manager');
        $this->requireCsrf();

        $id = (int) ($vars['id'] ?? 0);
        Order::addNote($id, (string) ($_POST['note'] ?? ''), false, $this->who());

        header('Location: ' . ADMIN_URL . '/orders/' . $id, true, 303);
        exit;
    }

    public function handleDelete(array $vars = []): void
    {
        Auth::requireRole('shop_manager');
        $this->requireCsrf();

        Order::delete((int) ($vars['id'] ?? 0));

        header('Location: ' . ADMIN_URL . '/orders', true, 303);
        exit;
    }

    /**
     * Stamp each line with a link back to its product, where there still is one.
     *
     * ── THE REGISTRY ANSWERS "WHERE DOES THIS ENTRY LIVE?" ─────────────────
     * There is no `/admin/posts/{id}/edit` route. Every entry is under its own
     * content type's admin slug — `/admin/memberships/14/edit` — and the slug
     * is the Registry's to give, not a guess from the `post_type` key
     * (`plan` is edited at `/admin/memberships`). Asking
     * `ContentTypeDefinition::all()` instead would answer the narrower question
     * "what is in that table" and miss every type registered in `config/`.
     *
     * ── AND IT IS DONE HERE, NOT IN THE TEMPLATE ───────────────────────────
     * A `Post::find()` per row in a foreach is a query per row on a screen that
     * already has the order. This is still one query per line, but it is one
     * place to fix when it matters and the template stays a template.
     *
     * A null `edit_url` is the normal case for a deleted product — `post_id` is
     * ON DELETE SET NULL precisely so the line survives — and also for one that
     * has since been trashed. Both render as plain text.
     *
     * @param  list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function withEditUrls(array $items): array
    {
        foreach ($items as $i => $item) {
            $items[$i]['edit_url'] = null;

            $postId = $item['post_id'] ?? null;
            if ($postId === null) {
                continue;
            }

            $post = Post::find((int) $postId);
            if ($post === null) {
                continue;
            }

            $type = Registry::get((string) $post['post_type']);
            if ($type === null) {
                continue;   // the type was unregistered since the sale
            }

            $items[$i]['edit_url'] = Url::admin(
                $type['admin_slug'] . '/' . (int) $postId . '/edit'
            );
        }

        return $items;
    }

    /**
     * Who is doing this, for the order's note trail.
     *
     * A NOTE WITH NO AUTHOR IS HALF A RECORD. On a shop with two people
     * answering the telephone, "Status changed to Processing" without a name is
     * exactly the note you needed to have a name on.
     */
    private function who(): string
    {
        $user = Auth::user();

        return (string) ($user['name'] ?? $user['username'] ?? '');
    }

    private function requireCsrf(): void
    {
        if (!Csrf::validate()) {
            http_response_code(400);
            exit('Invalid request.');
        }
    }
}
