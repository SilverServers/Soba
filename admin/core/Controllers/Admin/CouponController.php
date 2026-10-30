<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Commerce\Coupon;
use Cms\Commerce\Money;
use Cms\Commerce\Tax;
use Cms\Database\Database;
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

        $errors = [];
        $form   = [
            'code'        => Coupon::normalise((string) ($_POST['code'] ?? '')),
            'kind'        => ((string) ($_POST['kind'] ?? '')) === Coupon::FIXED
                                ? Coupon::FIXED : Coupon::PERCENT,
            'amount_raw'  => trim((string) ($_POST['amount'] ?? '')),
            'min_raw'     => trim((string) ($_POST['min_spend'] ?? '')),
            'usage_limit' => max(0, (int) ($_POST['usage_limit'] ?? 0)),
            'expires_at'  => trim((string) ($_POST['expires_at'] ?? '')),
            'starts_at'   => trim((string) ($_POST['starts_at'] ?? '')),
            'is_active'   => !empty($_POST['is_active']) ? 1 : 0,
            'free_shipping' => !empty($_POST['free_shipping']) ? 1 : 0,
            'exclude_sale'  => !empty($_POST['exclude_sale']) ? 1 : 0,
            'per_customer'  => max(0, (int) ($_POST['per_customer'] ?? 0)),
            // WHAT IT REACHES: products and terms by id, kept only if they
            // exist as a product of this shop or a term of one of its types.
            'product_ids'   => array_values(array_intersect(
                array_map('intval', is_array($_POST['product_ids'] ?? null) ? $_POST['product_ids'] : []),
                array_keys(self::productChoices())
            )),
            'category_ids'  => array_values(array_intersect(
                array_map('intval', is_array($_POST['category_ids'] ?? null) ? $_POST['category_ids'] : []),
                array_keys(self::termChoices())
            )),
        ];

        if ($form['code'] === '') {
            $errors[] = 'A code is required — it is what the customer types.';
        } elseif (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/', $form['code'])) {
            // ── WHAT A CODE MAY CONTAIN, AND WHY ────────────────────────────
            // Letters, digits, dash and underscore. A code with a space in it
            // is one the customer will mistype; one with a `%` in it is one
            // that arrives URL-decoded as something else. Refused at the point
            // of creation rather than discovered at a checkout.
            $errors[] = 'A code may use letters, numbers, dashes and underscores '
                      . 'only, and must start with a letter or a number.';
        }

        // ── THE CODE IS UNIQUE, AND THE MESSAGE SAYS SO ────────────────────
        // The column has a UNIQUE index, so this is belt and braces — but a
        // duplicate-key exception reaching the screen as a 500 is not an answer
        // an owner can act on.
        if ($form['code'] !== '') {
            $clash = Coupon::find($form['code']);
            if ($clash !== null && (int) $clash['id'] !== $id) {
                $errors[] = 'There is already a code called ' . $form['code'] . '.';
            }
        }

        $amount = $form['kind'] === Coupon::FIXED
            ? Money::parse($form['amount_raw'])
            : Tax::parsePercent($form['amount_raw']);

        // A CODE THAT ONLY MAKES DELIVERY FREE takes nothing off, and says so
        // with a blank box; any other has to take something.
        if ($form['free_shipping'] && ($form['amount_raw'] === '' || $amount === 0)) {
            $amount = 0;
        } elseif ($amount === null || $amount <= 0) {
            $errors[] = $form['kind'] === Coupon::FIXED
                ? 'The amount off is not an amount of money.'
                : 'The percentage off must be a number between 0 and 100, with at '
                  . 'most two decimal places.';
        }

        $min = $form['min_raw'] === '' ? 0 : Money::parse($form['min_raw']);
        if ($min === null) {
            $errors[] = 'The minimum spend is not an amount of money.';
        }

        // A DATE OR NOTHING. `checkdate()` rather than `strtotime()`, which
        // cheerfully reads "the 31st of February" as the 3rd of March and would
        // store an expiry the owner did not choose.
        if ($form['expires_at'] !== '') {
            $parts = explode('-', $form['expires_at']);
            $ok = count($parts) === 3
                && checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
            if (!$ok) {
                $errors[] = 'The expiry date is not a date.';
            }
        }

        if ($form['starts_at'] !== '') {
            $parts = explode('-', $form['starts_at']);
            $ok = count($parts) === 3
                && checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
            if (!$ok) {
                $errors[] = 'The first day is not a date.';
            } elseif ($form['expires_at'] !== '' && $form['expires_at'] < $form['starts_at']) {
                $errors[] = 'The last day it works comes before its first day.';
            }
        }

        if ($errors !== []) {
            $this->form($current, $form, $errors);

            return;
        }

        $db = Database::getInstance();
        $columns = [
            ':code'    => $form['code'],
            ':kind'    => $form['kind'],
            ':amount'  => (int) $amount,
            ':min'     => (int) $min,
            ':limit'   => $form['usage_limit'],
            ':expires' => $form['expires_at'] !== '' ? $form['expires_at'] : null,
            ':starts'  => $form['starts_at'] !== '' ? $form['starts_at'] : null,
            ':active'  => $form['is_active'],
            ':free'    => $form['free_shipping'],
            ':nosale'  => $form['exclude_sale'],
            ':each'    => $form['per_customer'],
            ':products'   => $form['product_ids'] !== [] ? (string) json_encode($form['product_ids']) : null,
            ':categories' => $form['category_ids'] !== [] ? (string) json_encode($form['category_ids']) : null,
        ];

        if ($id > 0) {
            // `times_used` IS NOT IN THIS LIST. It is a record of what happened,
            // not a setting, and a save that reset it would let an owner
            // un-spend a code by opening it and pressing Save.
            $stmt = $db->prepare(
                'UPDATE coupons SET code = :code, kind = :kind, amount = :amount,
                        min_spend = :min, usage_limit = :limit,
                        expires_at = :expires, starts_at = :starts, is_active = :active,
                        free_shipping = :free, exclude_sale = :nosale, per_customer = :each,
                        product_ids = :products, category_ids = :categories
                  WHERE id = :id'
            );
            $columns[':id'] = $id;
            $stmt->execute($columns);
        } else {
            $stmt = $db->prepare(
                'INSERT INTO coupons (code, kind, amount, min_spend, usage_limit,
                                      expires_at, starts_at, is_active, free_shipping,
                                      exclude_sale, per_customer, product_ids, category_ids)
                 VALUES (:code, :kind, :amount, :min, :limit, :expires, :starts, :active, :free,
                         :nosale, :each, :products, :categories)'
            );
            $stmt->execute($columns);
        }

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
    /**
     * The shop's products a code can be limited to: every entry of a type
     * that sells, by title, the type named when there is more than one.
     *
     * @return array<int, string> id => label
     */
    public static function productChoices(): array
    {
        $types = \Cms\Commerce\Product::sellableTypes();
        if ($types === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($types), '?'));
        $stmt  = Database::getInstance()->prepare(
            "SELECT id, title, post_type FROM posts WHERE post_type IN ($marks) AND status <> 'trash' ORDER BY title, id"
        );
        $stmt->execute($types);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $type = \Cms\ContentType\Registry::get((string) $row['post_type']);
            $out[(int) $row['id']] = (string) $row['title']
                . (count($types) > 1 && $type !== null ? ' (' . (string) ($type['label_single'] ?? $type['label']) . ')' : '');
        }

        return $out;
    }

    /**
     * The terms a code can be limited to: every term of every taxonomy the
     * shop's types are filed under — a category, a collection, a colour.
     *
     * @return array<int, string> id => "Outerwear (Product categories)"
     */
    public static function termChoices(): array
    {
        $out = [];
        foreach (\Cms\Commerce\Product::sellableTypes() as $type) {
            foreach (\Cms\ContentType\TaxonomyRegistry::getForContentType($type) as $key => $tax) {
                foreach (\Cms\Models\Term::allForTaxonomy((string) $key) as $term) {
                    $out[(int) $term['id']] = (string) $term['name'] . ' (' . (string) ($tax['label'] ?? $key) . ')';
                }
            }
        }

        return $out;
    }

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
