<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Models\Order;

/**
 * TURNING A CART AND A FORM INTO AN ORDER — and refusing to, when it should.
 *
 * ── THE CONTROLLER DOES NOT VALIDATE, THIS DOES ─────────────────────────────
 * Not tidiness. A checkout is the one page on a public site where getting it
 * wrong costs real money, and the validation has to be somewhere that can be
 * called without a browser, a session or a POST. `Public\ShopController` calls
 * it that way.
 *
 * ── THE CART IS RE-READ, NOT TRUSTED ────────────────────────────────────────
 * `place()` calls `Cart::items()` itself rather than accepting lines from the
 * caller. Between the checkout page rendering and the button being pressed, a
 * product can be trashed, unpublished, repriced or sold out — a form left open
 * over lunch is enough. The prices that go on the order are the ones read at
 * THIS moment, and the customer is shown them before they confirm.
 *
 * ── AND THE FIELDS ARE A LIST, DECLARED ONCE ────────────────────────────────
 * `FIELDS` drives the validator, the form and the order write. A field added to
 * the form but not the list would be silently dropped by `Order::place()`'s
 * whitelist — the same failure `FormController::parseFieldsJson()` has a
 * comment about, where the builder posted conditional logic, the editor showed
 * it, and the reload showed it gone.
 */
final class Checkout
{
    /**
     * Why the last `place()` returned null, when it was not a validation error.
     *
     * `place()` can fail for a reason `validate()` cannot see — the last one
     * sold out between the two calls. Returning null with no explanation would
     * put the customer back on the checkout page with nothing marked and
     * nothing to fix.
     */
    private static string $lastError = '';

    public static function lastError(): string
    {
        return self::$lastError;
    }

    /**
     * key => [label, required?]
     *
     * `billing_region` is not required here because plenty of countries do not
     * have one, and a required field a customer cannot fill is a checkout they
     * abandon; `validate()` requires it for a country `Regions` lists, where it
     * decides the tax. `billing_post` IS required: it is what a courier routes
     * on.
     */
    public const FIELDS = [
        'email'           => ['Email address',   true],
        'phone'           => ['Phone',           false],
        'billing_name'    => ['Full name',       true],
        'billing_line1'   => ['Address',         true],
        'billing_line2'   => ['Apartment, suite, etc.', false],
        'billing_city'    => ['Town or city',    true],
        'billing_region'  => ['Province or state', false],
        'billing_post'    => ['Postal code',     true],
        'billing_country' => ['Country',         true],
    ];

    /**
     * Fields that take the whole row rather than half of one.
     *
     * ── NAMED, NOT COUNTED ─────────────────────────────────────────────────
     * The stylesheet did this with `:nth-child(3), :nth-child(4)`, which is
     * correct only for the exact order `FIELDS` happens to have today. Insert
     * one field above them and two unrelated boxes go full width while the
     * street address goes back to half — silently, because nothing is
     * malformed and every guard stays green. A name is a name whatever
     * position it is in.
     */
    public const WIDE_FIELDS = [
        'billing_name', 'billing_line1',
        'shipping_name', 'shipping_line1',
    ];

    /** The same shape again for a separate delivery address. None required. */
    public const SHIPPING_FIELDS = [
        'shipping_name'    => 'Full name',
        'shipping_line1'   => 'Address',
        'shipping_line2'   => 'Apartment, suite, etc.',
        'shipping_city'    => 'Town or city',
        'shipping_region'  => 'Province or state',
        'shipping_post'    => 'Postal code',
        'shipping_country' => 'Country',
    ];

    /**
     * Read the posted form into a clean array. No validation here — this is
     * what gets handed BACK to the template when validation fails, so the
     * customer does not retype an address because one field was wrong.
     *
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    public static function readForm(array $post): array
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $key) {
            $out[$key] = trim((string) ($post[$key] ?? ''));
        }
        foreach (array_keys(self::SHIPPING_FIELDS) as $key) {
            $out[$key] = trim((string) ($post[$key] ?? ''));
        }

        // ── A PROVINCE FROM ITS LIST WHERE THE COUNTRY HAS ONE ─────────────
        // Canada and the United States get a select (`Regions`), whose code
        // is what the order keeps and what decides its tax; anywhere else
        // keeps the free-text box, posted under its own name so the hidden
        // one of the two never overwrites the other.
        foreach (['billing', 'shipping'] as $who) {
            $country = strtoupper($out[$who . '_country']);
            $out[$who . '_region'] = Regions::listed($country)
                ? Regions::code($country, (string) ($post[$who . '_region'] ?? ''))
                : trim((string) ($post[$who . '_region_text'] ?? ''));
        }

        // DELIVERED OR COLLECTED. Anything but "pickup" is delivery, which is
        // what a checkout without the choice has always meant.
        $out['delivery_method'] = (string) ($post['delivery_method'] ?? '') === Shipping::PICKUP
            ? Shipping::PICKUP : Shipping::DELIVERY;

        // WHAT THE CUSTOMER AGREED TO AND ASKED FOR, each a tick.
        $out['accept_terms']     = !empty($post['accept_terms']) ? '1' : '';
        $out['marketing_opt_in'] = !empty($post['marketing_opt_in']) ? '1' : '';

        $out['payment_method'] = trim((string) ($post['payment_method'] ?? ''));
        $out['customer_note']  = trim((string) ($post['customer_note'] ?? ''));
        $out['ship_elsewhere'] = !empty($post['ship_elsewhere']) ? '1' : '';

        return $out;
    }

    /**
     * The page a customer agrees to at the checkout — Settings → Shop's choice,
     * if it still exists and is published.
     *
     * @return array{id: int, title: string, url: string}|null
     */
    public static function termsPage(): ?array
    {
        $id = (int) (\Cms\Models\Option::get('commerce_terms_page', '0') ?? 0);
        $page = $id > 0 ? \Cms\Models\Page::find($id) : null;
        if ($page === null || (string) ($page['status'] ?? '') !== 'published') {
            return null;
        }

        return [
            'id'    => (int) $page['id'],
            'title' => (string) $page['title'],
            'url'   => \Cms\Helpers\Url::page((string) $page['slug']),
        ];
    }

    /** Does the checkout offer a box for news and offers? */
    public static function offersSignup(): bool
    {
        return \Cms\Models\Option::get('commerce_offer_signup', '0') === '1';
    }

    /**
     * Delivered or collected. Collected only when the shop offers it and the
     * basket has something to collect; a basket of memberships is neither.
     *
     * @param array<string, string> $form from `readForm()`
     */
    public static function method(array $form): string
    {
        return ($form['delivery_method'] ?? '') === Shipping::PICKUP && Shipping::pickup() && Cart::hasDeliverable()
            ? Shipping::PICKUP : Shipping::DELIVERY;
    }

    /**
     * Where this order is taxed: where the shop is, for an order collected
     * there; otherwise where it is delivered (`deliveryPlace()`).
     *
     * @param  array<string, string> $form from `readForm()`
     * @return array{country: string, region: string}|null
     */
    public static function taxPlace(array $form): ?array
    {
        return self::method($form) === Shipping::PICKUP ? TaxRates::home() : self::deliveryPlace($form);
    }

    /**
     * Where this order goes: the delivery address when the customer gave one,
     * else the billing address. Null while a country that needs a province has
     * none chosen yet, which the checkout shows as an estimate for the shop's
     * own place.
     *
     * @param  array<string, string> $form from `readForm()`
     * @return array{country: string, region: string}|null
     */
    public static function deliveryPlace(array $form): ?array
    {
        $who     = ($form['ship_elsewhere'] ?? '') === '1' ? 'shipping' : 'billing';
        $country = strtoupper(trim((string) ($form[$who . '_country'] ?? '')));
        if ($country === '' || !Countries::isValid($country)) {
            return null;
        }
        $region = Regions::code($country, (string) ($form[$who . '_region'] ?? ''));
        // NO PROVINCE YET MATTERS ONLY WHERE A PROVINCE CHANGES THE RATES: an
        // American address before its state is chosen is already known to be
        // charged what the United States is.
        if ($region === '' && TaxRates::dependOnRegion(TaxRates::all(), $country)) {
            return null;
        }

        return ['country' => $country, 'region' => $region];
    }

    /**
     * What is wrong with this checkout, as field => message.
     *
     * An EMPTY array means it may be placed. Returning the reasons rather than
     * a bool is what lets the form mark the offending fields rather than saying
     * "something was wrong" above a page of correct answers.
     *
     * @param array<string, string> $form from readForm()
     * @return array<string, string>
     */
    public static function validate(array $form): array
    {
        $errors = [];

        // THE CART FIRST. Every other message is noise on an empty cart, and a
        // checkout that validates an address before noticing there is nothing
        // to buy has told the customer to fix the wrong thing.
        if (Cart::isEmpty()) {
            $errors['cart'] = 'There is nothing in your basket.';

            return $errors;
        }

        foreach (self::FIELDS as $key => [$label, $required]) {
            if ($required && ($form[$key] ?? '') === '') {
                $errors[$key] = $label . ' is required.';
            }
        }

        $email = $form['email'] ?? '';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'That does not look like an email address.';
        }

        $country = $form['billing_country'] ?? '';
        if ($country !== '' && !Countries::isValid($country)) {
            $errors['billing_country'] = 'Please choose a country.';
        }
        // REQUIRED WHERE IT DECIDES THE TAX: a Canadian or American address
        // without its province cannot be charged the right rate.
        if (Regions::listed($country) && ($form['billing_region'] ?? '') === '') {
            $errors['billing_region'] = 'Please choose a ' . strtolower(Regions::word($country)) . '.';
        }

        // A DELIVERY ADDRESS THAT IS HALF FILLED IN IS THE DANGEROUS ONE. Left
        // blank entirely it means "same as billing" and is fine; a street with
        // no town is a parcel that does not arrive, and it is the customer's
        // own typing, so they can fix it if we say so.
        if (($form['ship_elsewhere'] ?? '') === '1') {
            foreach (['shipping_name', 'shipping_line1', 'shipping_city', 'shipping_post', 'shipping_country'] as $key) {
                if (($form[$key] ?? '') === '') {
                    $errors[$key] = self::SHIPPING_FIELDS[$key] . ' is required for the delivery address.';
                }
            }
            $sc = $form['shipping_country'] ?? '';
            if ($sc !== '' && !Countries::isValid($sc)) {
                $errors['shipping_country'] = 'Please choose a country.';
            }
            if (Regions::listed($sc) && ($form['shipping_region'] ?? '') === '') {
                $errors['shipping_region'] = 'Please choose a ' . strtolower(Regions::word($sc))
                                           . ' for the delivery address.';
            }
        }

        // ── DELIVERED WHERE THE SHOP DELIVERS, OR COLLECTED WHERE IT OFFERS ──
        if (Cart::hasDeliverable()) {
            $place = self::deliveryPlace($form);
            if (($form['delivery_method'] ?? '') === Shipping::PICKUP && !Shipping::pickup()) {
                $errors['delivery_method'] = 'Collecting an order is not offered. Please choose delivery.';
            } elseif (self::method($form) === Shipping::DELIVERY && $place !== null
                      && !Shipping::deliversTo($place['country'], $place['region'])) {
                $errors['delivery_method'] = Shipping::notDelivered($place['country'], $place['region']);
            }
        }

        // ── THE SHOP'S TERMS, AGREED TO ─────────────────────────────────────
        $terms = self::termsPage();
        if ($terms !== null && ($form['accept_terms'] ?? '') !== '1') {
            $errors['accept_terms'] = 'Please tick to agree to the ' . $terms['title'] . '.';
        }

        // ── A CODE THIS CUSTOMER HAS ALREADY SPENT ──────────────────────────
        // Known only now, with the email: refused with its reason rather than
        // dropped from the order without a word.
        $code = Cart::coupon();
        if ($code !== '' && filter_var($form['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $coupon = Coupon::find($code);
            if ($coupon !== null && (int) ($coupon['per_customer'] ?? 0) > 0
                && Coupon::usedBy($code, (string) $form['email']) >= (int) $coupon['per_customer']) {
                $errors['coupon'] = 'You have already used the code ' . $code . '. Remove it from your basket to go on.';
            }
        }

        $method = $form['payment_method'] ?? '';
        if ($method === '') {
            $errors['payment_method'] = 'Please choose how you would like to pay.';
        } elseif (!PaymentMethods::isEnabled($method)) {
            // NOT "invalid". A method that was enabled when the page rendered
            // and disabled before it was submitted is not the customer's
            // mistake, and there is no useful difference to them between that
            // and a hand-made POST.
            $errors['payment_method'] = 'That payment method is not available. Please choose another.';
        }

        if (PaymentMethods::enabled() === []) {
            $errors['payment_method'] = 'This shop is not currently taking payment.';
        }

        return $errors;
    }

    /**
     * Place the order and empty the cart.
     *
     * Returns the order row, or null when `validate()` would have refused —
     * which it re-runs itself, because "the caller checked" is how a checkout
     * ends up placeable by anyone who skips the check.
     *
     * @param array<string, string> $form from readForm()
     */
    public static function place(array $form): ?array
    {
        // CLEARED FIRST. Left over from a previous attempt it would explain
        // this failure with the last one's reason — a customer told the wrong
        // product sold out.
        self::$lastError = '';

        if (self::validate($form) !== []) {
            return null;
        }

        // RE-READ. See the class header — this is the price list that goes on
        // the order, not the one the page was rendered with.
        $items = Cart::items();
        if ($items === []) {
            return null;
        }

        $lines = [];
        foreach ($items as $line) {
            $variation = $line['variation'];

            // ── THE VARIANT'S LABEL IS COPIED ONTO THE LINE ────────────────
            // Migration 046's header: the fifth thing in this subsystem that is
            // copied rather than joined. "Associate" on an order that was
            // actually "Associate — Large" is a parcel packed wrong, and a
            // variant renamed next year must not rewrite a packing slip printed
            // today.
            $lines[] = [
                'post_id'         => (int) $line['product']['id'],
                'variation_id'    => $variation !== null ? (int) $variation['id'] : null,
                'variation_label' => $variation !== null ? (string) $variation['label'] : '',
                'name'            => (string) $line['product']['title'],
                // THE VARIANT'S SKU WHEN IT HAS ONE: that is the code on the
                // shelf, and the product's is the family it belongs to.
                'sku'             => $variation !== null && (string) $variation['sku'] !== ''
                                        ? (string) $variation['sku']
                                        : (string) $line['product']['sku'],
                'quantity'        => (int) $line['quantity'],
                // THE LINE'S OWN PRICE, which `Cart::items()` already resolved
                // — a variant may cost more than the product it belongs to, and
                // reading the product's here would sell a large at a small's
                // price on every order.
                'unit_price'      => (int) $line['unit_price'],
            ];
        }

        // ── THE STOCK COMES OFF THE SHELF BEFORE THE ORDER IS WRITTEN ──────
        // In that order because an order that exists without its stock reserved
        // is an oversell nobody notices, whereas stock taken for an order that
        // then fails to write is put straight back below.
        //
        // `takeForOrder()` is all-or-nothing: a basket of three where the third
        // ran out must not leave the first two reserved. The database decides —
        // see `Stock`'s header on why "read then write" is a race.
        $names = array_map(static fn (array $l): string => (string) $l['name'], $lines);
        $stock = StockLog::during('sold', null, 'Customer', static fn (): array => Stock::takeForOrder($lines, $names));
        if (!$stock['ok']) {
            self::$lastError = $stock['short'] . ' sold out while you were checking out.';

            return null;
        }

        $method = (string) $form['payment_method'];
        $fields = $form;

        // A DELIVERY ADDRESS IS ONLY STORED WHEN THERE IS ONE. Copying the
        // billing address into the shipping columns would make every order look
        // like it had a separate delivery address, and the order screen could
        // no longer tell the difference — so it would draw the same address
        // twice, on every order, forever.
        if (($form['ship_elsewhere'] ?? '') !== '1') {
            foreach (array_keys(self::SHIPPING_FIELDS) as $key) {
                $fields[$key] = '';
            }
        }

        $fields['billing_country']  = Countries::code($form['billing_country'] ?? '');
        $fields['shipping_country'] = Countries::code($fields['shipping_country'] ?? '');

        // ── TAXED WHERE IT GOES ────────────────────────────────────────────
        // The delivery address when there is one, else the billing address:
        // the place `Order::place()` charges the rates of, and keeps.
        $place = self::taxPlace($form) ?? ['country' => $fields['billing_country'], 'region' => ''];
        $fields['tax_country'] = $place['country'];
        $fields['tax_region']  = $place['region'];

        // ── DELIVERY IS RE-READ TOO, NOT CARRIED FROM THE FORM ─────────────
        // Same reasoning as the prices above: between the checkout rendering
        // and the button being pressed the owner can change the rate, or the
        // customer can open a second tab and empty the basket of the one
        // deliverable thing in it. What goes on the order is what is true at
        // THIS moment, and `Order::place()` recomputes the tax on top of it.
        // ── AND HOW IT REACHES THEM, with what it cost ──────────────────────
        $how      = self::method($form);
        $delivery = self::deliveryPlace($form) ?? $place;
        $fields['shipping_total']        = Totals::cartShipping($delivery, $how, (string) ($form['email'] ?? ''));
        $fields['shipping_method']       = Cart::hasDeliverable() ? $how : '';
        $fields['shipping_method_title'] = Cart::hasDeliverable() ? Shipping::methodTitle($how, $delivery) : '';

        // ── THE CODE IS SPENT BEFORE THE ORDER IS WRITTEN ──────────────────
        // Same order and the same reason as the stock above: a code counted
        // against an order that then fails to write is a use nobody can get
        // back, and an order written without its use counted is a limit that
        // does not hold.
        //
        // A code that has been spent to its limit BETWEEN the checkout
        // rendering and the button being pressed does not silently drop the
        // discount and charge more than the page said — it refuses, the same
        // way a sold-out product does, so the customer sees the new total
        // before they agree to it rather than after.
        $fields['coupon_code']     = '';
        $fields['coupon_redeemed'] = 0;
        $code   = Cart::coupon();
        $coupon = $code !== '' ? Coupon::find($code) : null;
        $subtotalNow = Cart::subtotal();
        $cartLines   = Coupon::cartLines();
        $usedBy      = $coupon !== null ? Coupon::usedBy((string) $coupon['code'], (string) ($form['email'] ?? '')) : 0;

        // SPENT WHEN IT DOES ANYTHING — money off, or free delivery.
        if ($coupon !== null && Coupon::applies($coupon, $subtotalNow, $cartLines, $usedBy)) {
            if (!Coupon::redeem((int) $coupon['id'])) {
                // BACK TO THE SHELF EACH CAME OFF: the lines keep their
                // option, which a list of products and counts alone lost, and
                // an option's units went back to its product, or nowhere.
                StockLog::during('cancelled', null, 'Customer', static fn () => Stock::giveForOrder($lines));
                self::$lastError = 'The code ' . $code
                    . ' ran out while you were checking out. Your basket is '
                    . 'unchanged — the discount no longer applies.';
                Cart::clearCoupon();

                return null;
            }

            $fields['discount_total']  = Coupon::discountOn($coupon, $subtotalNow, null, $cartLines, $usedBy);
            $fields['coupon_code']     = (string) $coupon['code'];
            $fields['coupon_redeemed'] = 1;
        } elseif ($code !== '') {
            // APPLIED, THEN STOPPED APPLYING — expired, withdrawn, or the
            // basket shrank below its minimum. Not an error: the customer is
            // not being refused, they are being charged the price the checkout
            // showed them, which already had no discount on it.
            Cart::clearCoupon();
        }

        // WHAT WAS AGREED TO, by the title it had, and what was asked for.
        $terms = self::termsPage();
        $fields['terms_agreed']     = $terms !== null && ($form['accept_terms'] ?? '') === '1' ? $terms['title'] : '';
        $fields['marketing_opt_in'] = self::offersSignup() && ($form['marketing_opt_in'] ?? '') === '1' ? 1 : 0;

        $fields['payment_method'] = $method;
        // The TITLE as it reads today, copied onto the order. Renaming the
        // method in settings tomorrow must not rewrite this receipt.
        $fields['payment_method_title'] = PaymentMethods::describe($method)['title'];
        $fields['status'] = 'pending';

        $fields['stock_reduced'] = 1;

        try {
            $placed = Order::place($fields, $lines);
        } catch (\Throwable $e) {
            // THE SHELF IS PUT BACK. `Order::place()` is one transaction, so a
            // throw means no order row exists — and stock reserved for an order
            // that does not exist is stock nobody can ever sell.
            StockLog::during('cancelled', null, 'Customer', static fn () => Stock::giveForOrder($lines));
            throw $e;
        }
        // THE LINES THE STOCK WROTE BEFORE THERE WAS AN ORDER ARE ITS NOW.
        StockLog::claimFor((int) $placed['id']);

        Order::addNote(
            $placed['id'],
            'Order placed by the customer. Payment method: '
                . $fields['payment_method_title'] . '.',
            false,
            'Shop'
        );

        Cart::clear();

        $order = Order::find($placed['id']);

        // ── SENT LAST, AND AFTER THE CART IS CLEARED ───────────────────────
        // Everything that can fail has already succeeded by this point. If mail
        // were sent BEFORE the order row existed there would be nothing to
        // attach a failure note to, and if it were sent before `Cart::clear()`
        // a slow mail server would hold the customer on the checkout page with
        // a full basket and a placed order — the exact state that produces a
        // second order.
        //
        // `afterOrder()` never throws; a failure becomes a note on the order.
        if ($order !== null) {
            OrderMail::afterOrder($order);
        }

        return $order;
    }
}
