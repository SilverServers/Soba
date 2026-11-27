<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Commerce\ManualOrder;
use Cms\Commerce\Money;
use Cms\Commerce\OrderMail;
use Cms\Commerce\Refund;
use Cms\ContentType\Registry;
use Cms\Helpers\Url;
use Cms\Http\Cache;
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

        // AND THE MONTH, the same way: `?month=2026-10`, in the site's zone.
        $month = (string) ($_GET['month'] ?? '');
        if (Order::monthBounds($month) === null) {
            $month = '';
        }

        $orders  = Order::all($status === '' ? null : $status, 200, $month === '' ? null : $month);
        $counts  = Order::countsByStatus();
        $total   = array_sum($counts);

        // THE MONTHS THERE ARE ORDERS IN, newest first, for the month filter.
        $months = [];
        foreach (Order::all(null, 100000) as $row) {
            $months[Order::monthOf($row)] = true;
        }
        $months = array_keys($months);
        rsort($months);

        // WHAT THE LAST BULK ACTION DID, once.
        $bulkReport = $_SESSION['order_bulk_report'] ?? null;
        unset($_SESSION['order_bulk_report']);
        if (!is_array($bulkReport)) {
            $bulkReport = null;
        }

        $pageTitle   = 'Orders';
        $currentPage = 'orders';

        require ROOT_PATH . '/admin/templates/orders/list.php';
    }

    /**
     * Several orders at once: moved to a status, or printed.
     *
     * EACH ONE THROUGH `Order::setStatus()`, as if moved on its own screen: its
     * stock and its code follow it, its customer gets the status's email, and
     * its note says who. One already in that status is passed over by name
     * rather than counted as done.
     */
    public function handleBulk(array $vars = []): void
    {
        Auth::requireRole('shop_manager');
        $this->requireCsrf();

        $action = (string) ($_POST['bulk_action'] ?? '');
        $ids    = array_values(array_unique(array_filter(array_map('intval',
            is_array($_POST['ids'] ?? null) ? $_POST['ids'] : []))));

        if (in_array($action, ['slip', 'invoice'], true) && $ids !== []) {
            header('Location: ' . ADMIN_URL . '/orders/print?doc=' . $action . '&ids=' . implode(',', $ids), true, 303);
            exit;
        }

        $report = ['action' => $action, 'done' => 0, 'skipped' => []];
        if (isset(Order::STATUSES[$action])) {
            foreach ($ids as $id) {
                $order = Order::find($id);
                if ($order === null) {
                    continue;
                }
                if (Order::setStatus($id, $action, $this->who())) {
                    $report['done']++;
                } else {
                    $report['skipped'][] = Order::number($order) . ' is already ' . Order::statusLabel($action) . '.';
                }
            }
        }
        $_SESSION['order_bulk_report'] = $report;

        header('Location: ' . ADMIN_URL . '/orders', true, 303);
        exit;
    }

    /** The orders as a spreadsheet: every one, or a status's, or a month's. */
    public function export(array $vars = []): void
    {
        Auth::requireRole('shop_manager');

        $status = (string) ($_GET['status'] ?? '');
        $month  = (string) ($_GET['month'] ?? '');
        $orders = Order::all(
            isset(Order::STATUSES[$status]) ? $status : null,
            100000,
            Order::monthBounds($month) !== null ? $month : null
        );

        $name = 'orders' . (Order::monthBounds($month) !== null ? '-' . $month : '')
              . (isset(Order::STATUSES[$status]) ? '-' . $status : '') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        Cache::noStore();
        // THE BYTE-ORDER MARK FIRST: without it a spreadsheet opens UTF-8 as
        // the computer's own codepage, and "Hélène Côté" reaches the
        // bookkeeper as "HÃ©lÃ¨ne CÃ´tÃ©".
        echo "\xEF\xBB\xBF" . \Cms\Commerce\OrderExport::csv($orders);
        exit;
    }

    /** One order's packing slip or invoice, to print. */
    public function printOne(array $vars = []): void
    {
        Auth::requireRole('shop_manager');
        $this->printDocs([(int) ($vars['id'] ?? 0)], (string) ($_GET['doc'] ?? 'slip'));
    }

    /** Several orders' slips or invoices, one to a page. */
    public function printMany(array $vars = []): void
    {
        Auth::requireRole('shop_manager');
        $this->printDocs(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))),
            (string) ($_GET['doc'] ?? 'slip'));
    }

    /**
     * A packing slip (what to pack and where it goes, no prices) or an invoice
     * (the same with every figure the order charged and anything refunded),
     * as a page of its own to print, each order on its own sheet.
     *
     * @param list<int> $ids
     */
    private function printDocs(array $ids, string $doc): void
    {
        $doc  = $doc === 'invoice' ? 'invoice' : 'slip';
        $docs = [];
        foreach (array_values(array_unique($ids)) as $id) {
            $order = $id > 0 ? Order::find($id) : null;
            if ($order !== null) {
                $docs[] = ['order' => $order, 'items' => Order::items($id)];
            }
        }
        if ($docs === []) {
            header('Location: ' . ADMIN_URL . '/orders');
            exit;
        }

        $pageTitle = ($doc === 'invoice' ? 'Invoice' : 'Packing slip') . (count($docs) === 1
            ? ' ' . Order::number($docs[0]['order']) : 's, ' . count($docs) . ' orders');

        require ROOT_PATH . '/admin/templates/orders/print.php';
    }

    /** A new order, taken by the shop: on the telephone, at a stall, by email. */
    public function create(array $vars = []): void
    {
        Auth::requireRole('shop_manager');

        $this->renderNew([], [
            'billing_country' => \Cms\Commerce\Countries::shopDefault(),
            'status'          => 'pending',
            'confirm'         => '1',
        ], [['choice' => '', 'quantity' => '1']]);
    }

    public function handleCreate(array $vars = []): void
    {
        Auth::requireRole('shop_manager');
        $this->requireCsrf();

        $form    = \Cms\Commerce\Checkout::readForm($_POST);
        $lines   = is_array($_POST['lines'] ?? null) ? array_values($_POST['lines']) : [];
        $status  = (string) ($_POST['status'] ?? 'pending');
        $confirm = !empty($_POST['confirm']);

        $placed = ManualOrder::place($form, $lines, $status, $confirm, $this->who());
        if ($placed['errors'] !== []) {
            // REFUSED WITH EVERYTHING AS TYPED: the lines, the address and the
            // choices are a telephone call the owner should not have to repeat.
            $this->renderNew($placed['errors'], $form + ['status' => $status, 'confirm' => $confirm ? '1' : '0'],
                $lines !== [] ? $lines : [['choice' => '', 'quantity' => '1']]);
            return;
        }

        header('Location: ' . ADMIN_URL . '/orders/' . (int) $placed['id'], true, 303);
        exit;
    }

    /**
     * @param list<string>               $errors
     * @param array<string, string>      $form
     * @param list<array<string, mixed>> $lines
     */
    private function renderNew(array $errors, array $form, array $lines): void
    {
        $choices     = ManualOrder::choices();
        $pageTitle   = 'New order';
        $currentPage = 'orders';

        require ROOT_PATH . '/admin/templates/orders/new.php';
    }

    public function show(array $vars = []): void
    {
        Auth::requireRole('shop_manager');

        $order = Order::find((int) ($vars['id'] ?? 0));
        if ($order === null) {
            header('Location: ' . ADMIN_URL . '/orders');
            exit;
        }

        $this->render($order);
    }

    /**
     * The order screen, with what could not be saved when something could
     * not, and the boxes as they were typed.
     *
     * @param list<string>         $errors
     * @param array<string, mixed> $typed  field => what was typed, for the form that was refused
     */
    private function render(array $order, array $errors = [], array $typed = []): void
    {
        $items   = $this->withEditUrls(Order::items((int) $order['id']));
        $notes   = Order::notes((int) $order['id']);
        $resends = OrderMail::resendChoices($order);

        // ── WHAT EACH LINE CAN BE EXCHANGED FOR ────────────────────────────
        // The other options of the same product, for a line that has some
        // left to exchange, on an order still being filled or filled. Read
        // here, once per line, so the template stays a template.
        $exchanges = [];
        if (!in_array((string) $order['status'], ['cancelled', 'refunded', 'failed'], true)) {
            foreach ($items as $item) {
                if ($item['variation_id'] === null || $item['post_id'] === null
                        || (int) $item['quantity'] - (int) $item['refunded_qty'] < 1) {
                    continue;
                }
                $others = array_values(array_filter(
                    \Cms\Commerce\Variation::forProduct((int) $item['post_id']),
                    static fn (array $v): bool => (int) $v['id'] !== (int) $item['variation_id']
                ));
                if ($others !== []) {
                    $exchanges[(int) $item['id']] = $others;
                }
            }
        }

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

        $id   = (int) ($vars['id'] ?? 0);
        $note = (string) ($_POST['note'] ?? '');

        // A NOTE TO THE CUSTOMER IS EMAILED, and kept marked as sent only
        // when it went. A private one stays on the order.
        $order = Order::find($id);
        if ($order !== null && (string) ($_POST['note_type'] ?? '') === 'customer') {
            OrderMail::noteToCustomer($order, $note, $this->who());
        } else {
            Order::addNote($id, $note, false, $this->who());
        }

        header('Location: ' . ADMIN_URL . '/orders/' . $id, true, 303);
        exit;
    }

    /** The payment's reference, as read off the bank. */
    public function handlePayment(array $vars = []): void
    {
        Auth::requireRole('shop_manager');
        $this->requireCsrf();

        $id = (int) ($vars['id'] ?? 0);
        Order::setPaymentRef($id, (string) ($_POST['transaction_ref'] ?? ''), $this->who());

        header('Location: ' . ADMIN_URL . '/orders/' . $id, true, 303);
        exit;
    }

    /** The carrier, the tracking number and the carrier's page for the parcel. */
    public function handleTracking(array $vars = []): void
    {
        Auth::requireRole('shop_manager');
        $this->requireCsrf();

        $id    = (int) ($vars['id'] ?? 0);
        $typed = [
            'tracking_carrier' => (string) ($_POST['tracking_carrier'] ?? ''),
            'tracking_number'  => (string) ($_POST['tracking_number'] ?? ''),
            'tracking_url'     => (string) ($_POST['tracking_url'] ?? ''),
        ];
        $error = Order::setTracking($id, $typed['tracking_carrier'], $typed['tracking_number'],
            $typed['tracking_url'], $this->who());

        $order = Order::find($id);
        if ($error !== null && $order !== null) {
            // REFUSED, WITH WHAT WAS TYPED STILL IN THE BOXES: a tracking
            // number retyped from a label because a link was wrong is a second
            // chance to get the number wrong.
            $this->render($order, [$error], $typed);
            return;
        }

        header('Location: ' . ADMIN_URL . '/orders/' . $id, true, 303);
        exit;
    }

    /**
     * A refund, as the refund form asks for it: lines and how many, some of
     * the delivery, an amount typed instead, the stock, and why.
     */
    public function handleRefund(array $vars = []): void
    {
        Auth::requireRole('shop_manager');
        $this->requireCsrf();

        $id    = (int) ($vars['id'] ?? 0);
        $typed = [
            'refund'          => '1',
            'refund_qty'      => is_array($_POST['refund_qty'] ?? null) ? $_POST['refund_qty'] : [],
            'refund_shipping' => (string) ($_POST['refund_shipping'] ?? ''),
            'refund_amount'   => (string) ($_POST['refund_amount'] ?? ''),
            'refund_reason'   => (string) ($_POST['refund_reason'] ?? ''),
            'restock'         => !empty($_POST['restock']) ? '1' : '0',
        ];
        $error = Refund::record($id, $typed['refund_qty'], $typed['refund_shipping'], $typed['refund_amount'],
            $typed['restock'] === '1', $typed['refund_reason'], $this->who());

        $order = Order::find($id);
        if ($error !== null && $order !== null) {
            // REFUSED WITH THE FORM OPEN AND AS TYPED: the quantities, the
            // reason and the tick are the owner's reading of a returned parcel.
            $this->render($order, [$error], $typed);
            return;
        }

        header('Location: ' . ADMIN_URL . '/orders/' . $id, true, 303);
        exit;
    }

    /**
     * What the refund form's lines come to, for the figure beside its button.
     *
     * THE SERVER'S ARITHMETIC, NOT A COPY OF IT IN THE BROWSER: the figure on
     * the button is worked out by `Refund::workOut()`, which is what a blank
     * amount records, so the two cannot differ by a rounding.
     */
    public function refundQuote(array $vars = []): void
    {
        Auth::requireRole('shop_manager');

        header('Content-Type: application/json; charset=utf-8');
        Cache::noStore();

        $order = Order::find((int) ($vars['id'] ?? 0));
        if ($order === null) {
            http_response_code(404);
            echo json_encode(['error' => 'No such order.']);
            exit;
        }

        $items  = Order::items((int) $order['id']);
        $asked  = is_array($_GET['refund_qty'] ?? null) ? $_GET['refund_qty'] : [];
        $qty    = [];
        foreach ($items as $item) {
            $most = (int) $item['quantity'] - (int) $item['refunded_qty'];
            $qty[(int) $item['id']] = max(0, min($most, (int) ($asked[(int) $item['id']] ?? 0)));
        }
        $currency = (string) $order['currency'];
        $shipping = Money::parse((string) ($_GET['refund_shipping'] ?? ''), $currency) ?? 0;
        $shipping = min($shipping, Refund::left($order)['shipping']);
        $amount   = Refund::workOut($order, $items, array_filter($qty), $shipping)['amount'];

        echo json_encode([
            'total' => $amount,
            'text'  => Money::format($amount, $currency),
            'edit'  => Money::edit($amount, $currency),
        ]);
        exit;
    }

    /** The order's addresses and how to reach the customer, corrected. */
    public function handleAddresses(array $vars = []): void
    {
        Auth::requireRole('shop_manager');
        $this->requireCsrf();

        $id     = (int) ($vars['id'] ?? 0);
        $form   = \Cms\Commerce\Checkout::readForm($_POST);
        $errors = Order::setAddresses($id, $form, $this->who());

        $order = Order::find($id);
        if ($errors !== [] && $order !== null) {
            // REFUSED WITH THE FORM OPEN AND AS TYPED: an address retyped
            // from a customer's email is the one most likely to be wrong twice.
            $typed = ['addresses' => '1'];
            foreach (Order::ADDRESS_FIELDS as $key) {
                $typed[$key] = (string) ($form[$key] ?? '');
            }
            $this->render($order, $errors, $typed);
            return;
        }

        header('Location: ' . ADMIN_URL . '/orders/' . $id, true, 303);
        exit;
    }

    /** One option of a line exchanged for another of the same product. */
    public function handleExchange(array $vars = []): void
    {
        Auth::requireRole('shop_manager');
        $this->requireCsrf();

        $id    = (int) ($vars['id'] ?? 0);
        $error = Order::exchange(
            $id,
            (int) ($_POST['item'] ?? 0),
            (int) ($_POST['to_variation'] ?? 0),
            (int) ($_POST['quantity'] ?? 0),
            $this->who()
        );

        $order = Order::find($id);
        if ($error !== null && $order !== null) {
            $this->render($order, [$error], ['exchange' => '1']);
            return;
        }

        header('Location: ' . ADMIN_URL . '/orders/' . $id, true, 303);
        exit;
    }

    /** One of the order's emails, sent again. */
    public function handleEmail(array $vars = []): void
    {
        Auth::requireRole('shop_manager');
        $this->requireCsrf();

        $id    = (int) ($vars['id'] ?? 0);
        $order = Order::find($id);
        if ($order !== null) {
            OrderMail::resend($order, (string) ($_POST['email'] ?? ''), $this->who());
        }

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
