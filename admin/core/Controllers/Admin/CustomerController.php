<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Commerce\CustomerPrivacy;
use Cms\Commerce\Customers;
use Cms\Helpers\Url;
use Cms\Http\Cache;

/**
 * SHOP → CUSTOMERS: who has bought, how often, how much, and how to reach them
 * (`Commerce\Customers`).
 *
 * Read here, and changed here only to give a customer what is kept about them
 * or to erase their personal details at their request (`CustomerPrivacy`): a
 * customer is their orders, and an order is changed on its own screen. The
 * shop manager's, as the orders are.
 */
class CustomerController
{
    /** GET /admin/customers — every customer, searched and sorted by GET, so a view can be sent on. */
    public function index(array $vars = []): void
    {
        Auth::requireRole('shop_manager');

        $search    = trim((string) ($_GET['s'] ?? ''));
        $sort      = (string) ($_GET['sort'] ?? '');
        $sort      = isset(Customers::SORTS[$sort]) ? $sort : 'last';
        $customers = Customers::all($search, $sort);

        // A CUSTOMER JUST ERASED, said once.
        $erased = $_SESSION['customer_erased'] ?? null;
        unset($_SESSION['customer_erased']);

        $pageTitle   = 'Customers';
        $currentPage = 'customers';

        require ROOT_PATH . '/admin/templates/customers/index.php';
    }

    /** GET /admin/customers/{key} — one customer: their orders, the addresses they used, how to reach them. */
    public function show(array $vars = []): void
    {
        Auth::requireRole('shop_manager');

        $customer = Customers::find((string) ($vars['key'] ?? ''));
        if ($customer === null) {
            // THE ADMIN'S 404, as an entry the admin cannot find is answered.
            http_response_code(404);
            require ROOT_PATH . '/admin/templates/404.php';
            return;
        }

        $pageTitle   = $customer['name'] !== '' ? $customer['name'] : $customer['email'];
        $currentPage = 'customers';

        require ROOT_PATH . '/admin/templates/customers/show.php';
    }

    /**
     * GET /admin/customers/{key}/data — everything kept under their address, as
     * a file to send them (`CustomerPrivacy::export()`).
     */
    public function data(array $vars = []): void
    {
        Auth::requireRole('shop_manager');

        $key   = (string) ($vars['key'] ?? '');
        $email = Customers::emailFor($key);
        if ($email === null) {
            http_response_code(404);
            require ROOT_PATH . '/admin/templates/404.php';
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="customer-' . $key . '.json"');
        Cache::noStore();
        echo json_encode(CustomerPrivacy::export($email), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * POST /admin/customers/{key}/erase — their personal details taken off
     * every order, the books kept (`CustomerPrivacy::erase()`). The page's own
     * confirm says what goes and what stays before this is reached.
     */
    public function erase(array $vars = []): void
    {
        Auth::requireRole('shop_manager');

        $email = Customers::emailFor((string) ($vars['key'] ?? ''));
        if ($email !== null) {
            $user = Auth::user();
            $_SESSION['customer_erased'] = CustomerPrivacy::erase($email, (string) ($user['name'] ?? $user['username'] ?? ''));
        }
        header('Location: ' . Url::admin('customers'), true, 303);
        exit;
    }
}
