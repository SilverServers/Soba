<?php

declare(strict_types=1);

namespace Cms\Controllers\Public;

use Cms\Commerce\Cart;
use Cms\Commerce\Checkout;
use Cms\Commerce\Countries;
use Cms\Commerce\Coupon;
use Cms\Commerce\Money;
use Cms\Commerce\PaymentMethods;
use Cms\Commerce\Product;
use Cms\Commerce\Totals;
use Cms\Helpers\Template;
use Cms\Models\Option;
use Cms\Models\Order;
use Cms\Security\Csrf;
use Cms\SEO\Head;

/**
 * THE PUBLIC SHOP — basket, checkout, and the page a customer lands on after.
 *
 * ── EVERY WRITE IS A POST WITH A CSRF TOKEN, AND THEN A REDIRECT ────────────
 * `GET /cart/add/12` would let any page on the internet put things in a
 * visitor's basket with an `<img>` tag, and would let the browser's back button
 * and its prefetcher do it too. Every mutation here is a POST, checked, and
 * answered with a 303 — so a refresh on the cart page re-reads the cart rather
 * than re-adding to it.
 *
 * ── AND THE SHOP PAGES ARE NOT INDEXABLE ────────────────────────────────────
 * A basket is one visitor's, a checkout is a form, and an order-received page
 * is somebody's address behind an unguessable key. `Head`'s default is
 * `index, follow`, so each of these sets `noindex` explicitly. The one that
 * matters is the receipt: without it, a crawler that ever sees the URL puts a
 * customer's name and address into public search results.
 */
class ShopController
{
    // ── The basket ──────────────────────────────────────────────────────────

    public function cart(array $vars = []): void
    {
        $items    = Cart::items();
        $subtotal = Cart::subtotal();
        // ONE PLACE ADDS AN ORDER UP — `Commerce\Totals`. The basket, the
        // checkout page, `Order::place()` and the receipt all ask it, so they
        // cannot print different figures for the same cart.
        $totals   = Totals::forCart();

        Head::set([
            'title'  => 'Basket — ' . $this->siteName(),
            'robots' => 'noindex, nofollow',
        ]);

        $pageTitle = 'Basket';

        ob_start();
        require Template::locate('shop/cart.php');
        $pageContent = ob_get_clean();
        require Template::locate('base.php');
    }

    public function addToCart(array $vars = []): void
    {
        $this->requireCsrf();

        $postId      = (int) ($_POST['product_id'] ?? 0);
        $quantity    = (int) ($_POST['quantity'] ?? 1);
        $variationId = (int) ($_POST['variation_id'] ?? 0);

        // A QUANTITY OF ZERO OR LESS ON AN "ADD" IS A MALFORMED REQUEST, not an
        // instruction to remove. `Cart::add()` would treat a negative as a
        // decrement, so a crafted POST could empty a basket from a product
        // page; ask for at least one.
        $quantity = max(1, $quantity);

        $product = Product::find($postId);

        if ($product === null) {
            $this->notice('That product is no longer available.', 'error');
        } elseif (!$product['purchasable']) {
            $this->notice($product['title'] . ' is out of stock.', 'error');
        } elseif ($product['variations'] !== [] && $variationId <= 0) {
            // ── A CHOICE THAT WAS NOT MADE IS NOT A DEFAULT ────────────────
            // The select is `required`, so this is a hand-made POST or a
            // browser that ignored it — and "one shirt" with no size is an
            // order the shop cannot pack. Picking the first for them is worse
            // than asking again.
            $this->notice('Choose an option before adding this to your basket.', 'error');
        } elseif (Cart::add($postId, $quantity, $variationId)) {
            $chosen = '';
            foreach ($product['variations'] as $v) {
                if ((int) $v['id'] === $variationId) {
                    $chosen = ' — ' . (string) $v['label'];
                    break;
                }
            }
            $this->notice($product['title'] . $chosen . ' was added to your basket.', 'success');
        } else {
            $this->notice('That product could not be added.', 'error');
        }

        $this->back(BASE_URL . '/cart');
    }

    public function updateCart(array $vars = []): void
    {
        $this->requireCsrf();

        // ONE FORM, EVERY LINE. The cart page posts `quantity[<key>]` for each
        // row at once, so "Update basket" means what it says rather than
        // applying the last box the customer touched.
        //
        // THE KEY IS A LINE, NOT A PRODUCT — `14` or `14:3`. A basket holding a
        // small and a large is two lines of one product, and addressing them by
        // product id would set both to whichever box was read last.
        $quantities = $_POST['quantity'] ?? [];
        if (is_array($quantities)) {
            foreach ($quantities as $lineKey => $quantity) {
                Cart::setQuantity((string) $lineKey, (int) $quantity);
            }
        }

        $remove = trim((string) ($_POST['remove'] ?? ''));
        if ($remove !== '') {
            Cart::remove($remove);
            $this->notice('Item removed.', 'success');
        } else {
            $this->notice('Basket updated.', 'success');
        }

        $this->redirect(BASE_URL . '/cart');
    }

    /**
     * Apply or remove a discount code.
     *
     * ── THE REFUSAL IS THE INTERESTING HALF ─────────────────────────────────
     * Six different situations end with no discount, and "that code is not
     * valid" against a code read off a poster is the message that generates the
     * phone call. `Coupon::explain()` says which — expired, spent, not started,
     * too small a basket, withdrawn, never existed — because five of those the
     * customer can do something about.
     */
    public function applyCoupon(array $vars = []): void
    {
        $this->requireCsrf();

        // REMOVING IS ALWAYS ALLOWED and never an error. A customer taking a
        // code off should not be told anything is wrong.
        if (!empty($_POST['remove_coupon'])) {
            Cart::clearCoupon();
            $this->notice('Discount code removed.', 'success');
            $this->redirect(BASE_URL . '/cart');

            return;
        }

        $typed  = Coupon::normalise((string) ($_POST['coupon_code'] ?? ''));
        if ($typed === '') {
            $this->notice('Type a code first.', 'error');
            $this->redirect(BASE_URL . '/cart');

            return;
        }

        $coupon = Coupon::find($typed);
        if ($coupon === null) {
            $this->notice(Coupon::explain(Coupon::NOT_FOUND), 'error');
            $this->redirect(BASE_URL . '/cart');

            return;
        }

        // CHECKED AGAINST THE BASKET AS IT STANDS. A minimum-spend refusal has
        // to name the figure, which `explain()` does, or "spend more" is an
        // instruction nobody can follow.
        $refusal = Coupon::refusalFor($coupon, Cart::subtotal(), date('Y-m-d'));
        if ($refusal !== '') {
            $this->notice(Coupon::explain($refusal, $coupon), 'error');
            $this->redirect(BASE_URL . '/cart');

            return;
        }

        Cart::setCoupon($typed);
        $this->notice(
            $typed . ' applied — ' . Money::format(Coupon::discountOn($coupon, Cart::subtotal()))
                . ' off.',
            'success'
        );
        $this->redirect(BASE_URL . '/cart');
    }

    // ── Checkout ────────────────────────────────────────────────────────────

    public function checkout(array $vars = []): void
    {
        $this->renderCheckout($this->blankForm(), []);
    }

    public function placeOrder(array $vars = []): void
    {
        $this->requireCsrf();

        $form   = Checkout::readForm($_POST);
        $errors = Checkout::validate($form);

        if ($errors !== []) {
            // ── SAY WHAT CHANGED, NOT THAT SOMETHING DID ───────────────────
            // `validate()` reads the cart, and reading the cart is what PRUNES
            // it — so by the time it reports "There is nothing in your basket"
            // the reason is already known and one function call away.
            $notices = Cart::takeNotices();
            if ($notices !== [] && isset($errors['cart'])) {
                $errors['cart'] = implode(' ', $notices);
            }
            $this->renderCheckout($form, $errors);
            return;
        }

        $order = Checkout::place($form);

        if ($order === null) {
            // ── THE SPECIFIC REASON WINS OVER THE GENERIC ONE ──────────────
            // `place()` can refuse something `validate()` accepted a moment
            // earlier — the last unit sold to somebody else between the two.
            //
            // Re-validating FIRST gets the wrong answer: `Cart::items()` clamps
            // to what is in stock, so
            // the sold-out line is already gone from the basket by the time we
            // ask again, and `validate()` says "There is nothing in your
            // basket." to a customer who had something in it one second ago.
            //
            // True, useless, and alarming. `lastError()` knows WHICH product
            // went, so it is preferred whenever it has something to say.
            $reason = Checkout::lastError();
            $errors = $reason !== ''
                ? ['cart' => $reason]
                : (Checkout::validate($form) ?: [
                    'cart' => 'Your basket changed while you were checking out. Please review it.',
                  ]);
            $this->renderCheckout($form, $errors);
            return;
        }

        $this->redirect(BASE_URL . '/order/' . $order['order_key']);
    }

    /**
     * @param array<string, string> $form
     * @param array<string, string> $errors
     */
    private function renderCheckout(array $form, array $errors): void
    {
        $items     = Cart::items();
        $subtotal  = Cart::subtotal();
        $totals    = Totals::forCart();
        $methods   = PaymentMethods::enabled();
        $countries = Countries::ALL;

        Head::set([
            'title'  => 'Checkout — ' . $this->siteName(),
            'robots' => 'noindex, nofollow',
        ]);

        $pageTitle = 'Checkout';

        ob_start();
        require Template::locate('shop/checkout.php');
        $pageContent = ob_get_clean();
        require Template::locate('base.php');
    }

    // ── The receipt ─────────────────────────────────────────────────────────

    /**
     * The order-received page, found by key.
     *
     * BY KEY, NEVER BY ID — `Order::findByKey()`'s docblock has the reasoning.
     * A 404 for an unknown key rather than a message: "no such order" and "not
     * your order" must look identical, or the difference is a way to find out
     * which order numbers exist.
     */
    public function orderReceived(array $vars = []): void
    {
        $order = Order::findByKey((string) ($vars['key'] ?? ''));

        if ($order === null) {
            Template::notFound();
            return;
        }

        $items       = Order::items((int) $order['id']);
        $instructions = PaymentMethods::describe((string) $order['payment_method'])['instructions'];

        Head::set([
            'title'  => 'Order ' . Order::number($order) . ' — ' . $this->siteName(),
            'robots' => 'noindex, nofollow',
        ]);

        $pageTitle = 'Thank you';

        ob_start();
        require Template::locate('shop/order-received.php');
        $pageContent = ob_get_clean();
        require Template::locate('base.php');
    }

    // ── Plumbing ────────────────────────────────────────────────────────────

    private function siteName(): string
    {
        return \Cms\SEO\Head::globalTokens()['site_name'];
    }

    /** @return array<string, string> */
    private function blankForm(): array
    {
        $form = Checkout::readForm([]);
        $form['billing_country'] = Countries::shopDefault();

        return $form;
    }

    /**
     * A one-shot message across a redirect.
     *
     * In the session rather than a query string: `?added=1` survives being
     * shared, bookmarked and crawled, so a stranger opening the link is told
     * something was added to a basket that is empty.
     *
     * READ AND CLEARED BY `base.php`, not by a controller — so it appears on
     * whichever page the visitor lands on. "Add to basket" returns them to the
     * product page they were reading, and drawing the notice only on the basket
     * template made that button look like it had done nothing.
     */
    private function notice(string $text, string $tone = 'success'): void
    {
        \Cms\Security\Session::ensure();
        $_SESSION['shop_notice'] = ['text' => $text, 'tone' => $tone];
    }

    private function requireCsrf(): void
    {
        if (!Csrf::validate()) {
            http_response_code(400);
            require Template::locate('404.php');
            exit;
        }
    }

    /**
     * Back where they came from, or a stated fallback.
     *
     * ── THE REFERER IS CHECKED AGAINST OUR OWN HOST ────────────────────────
     * `Location: $_SERVER['HTTP_REFERER']` is an open redirect: a form posted
     * from another site sends the customer back to it, and a phishing page can
     * use our domain as the hop. Only a same-origin referer is honoured.
     */
    private function back(string $fallback): void
    {
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $target  = $fallback;

        if ($referer !== '' && str_starts_with($referer, BASE_URL . '/')) {
            $target = $referer;
        }

        $this->redirect($target);
    }

    /**
     * 303, not 302.
     *
     * After a POST, 303 tells the browser to follow with GET. 302's behaviour
     * is under-specified for that and browsers historically differed, which is
     * how a refresh ends up re-posting an order.
     */
    private function redirect(string $url): void
    {
        header('Location: ' . $url, true, 303);
        exit;
    }
}
