<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Commerce\Coupon;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * DISCOUNT CODES — the screens that make the table in migration 045 manageable.
 *
 * ── THE INVARIANT APPLIES HERE TOO ──────────────────────────────────────────
 * Whatever creates a thing produces an entry that is FULLY manageable in the
 * admin. A coupon table with no delete button, or a code that
 * can be created and not edited, is the same failure as a read-only Content
 * Type — it just costs less to notice, because there are fewer of them.
 *
 * So: list, create, edit, turn off, delete. `times_used` is shown and never
 * editable, because it is a record of what happened rather than a setting.
 */
final class CouponController
{
    public function index(array $vars = []): void
    {
        Auth::requireRole('shop_manager');
        $user    = Auth::user();
        $coupons = Coupon::all();

        $pageTitle   = 'Discount codes';
        $currentPage = 'coupons';

        // ── THE TEMPLATE OWNS ITS OWN BUFFER AND THE LAYOUT ────────────────
        // The convention `orders/list.php` and the entry screens follow. A
        // SECOND `ob_start()` around the require, with `$pageContent` assigned
        // from it, would let the template's own buffer capture the page, the
        // outer one capture what was left (nothing), and `$pageContent` be
        // overwritten with an empty string: a 200 with a correct sidebar and a
        // blank body, and nothing in the error log.
        require ROOT_PATH . '/admin/templates/coupons/list.php';
    }

    public function create(array $vars = []): void
    {
        Auth::requireRole('shop_manager');
        $this->form(null, [], []);
    }

    public function edit(array $vars = []): void
    {
        Auth::requireRole('shop_manager');

        $coupon = Coupon::findById((int) ($vars['id'] ?? 0));
        if ($coupon === null) {
            $this->notFound();

            return;
        }

        $this->form($coupon, [], []);
    }

    /**
     * One save path for create and edit.
     *
     * ── VALIDATED BEFORE ANYTHING IS WRITTEN, AND ALL OF IT AT ONCE ─────────
     * The same reasoning `SettingsController::handleCommerce()` has: written as
     * a series of validate-then-return, an owner who gets two fields wrong is
     * told about them one at a time on separate round trips.
     */
    public function save(array $vars = []): void
    {
        Auth::requireRole('shop_manager');
        $this->requireCsrf();

        $id      = (int) ($vars['id'] ?? 0);
        $current = $id > 0 ? Coupon::findById($id) : null;
        if ($id > 0 && $current === null) {
            $this->notFound();

            return;
        }

        $checked = Coupon::check($_POST, $id);
        if ($checked['errors'] !== []) {
            $this->form($current, $checked['form'], $checked['errors']);

            return;
        }

        Coupon::store($checked['form'], $id);
        $this->redirect(Url::admin('coupons'));
    }

    public function delete(array $vars = []): void
    {
        Auth::requireRole('shop_manager');
        $this->requireCsrf();

        Coupon::delete((int) ($vars['id'] ?? 0));

        $this->redirect(Url::admin('coupons'));
    }

    /** @param array<string, mixed>|null $coupon */
    private function form(?array $coupon, array $posted, array $errors): void
    {
        $user = Auth::user();

        $pageTitle   = $coupon === null ? 'New discount code' : 'Edit discount code';
        $currentPage = 'coupons';

        require ROOT_PATH . '/admin/templates/coupons/edit.php';
    }

    /**
     * The same guard every other admin controller uses.
     *
     * `Csrf::verify()` does not exist — this called it, and the save was a 500
     * with a fatal in the log rather than a form that worked. `Csrf::validate()`
     * returns a bool and the 400 is the caller's to write, which is why
     * `OrderController` has this same four-line method.
     */
    private function requireCsrf(): void
    {
        if (!Csrf::validate()) {
            http_response_code(400);
            exit('Invalid request.');
        }
    }

    private function redirect(string $to): void
    {
        header('Location: ' . $to, true, 303);
        exit;
    }

    private function notFound(): void
    {
        http_response_code(404);
        $user        = Auth::user();
        $pageTitle   = 'Not found';
        $currentPage = 'coupons';
        $pageContent = '<div class="page"><div class="empty">'
            . '<p class="empty__title">There is no such discount code.</p></div></div>';
        require ROOT_PATH . '/admin/templates/layout.php';
    }
}
