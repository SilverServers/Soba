<?php

declare(strict_types=1);

namespace Cms\Commerce;

/**
 * WHAT AN ORDER COMES TO — the one place that adds it up.
 *
 * ── WHY THIS EXISTS AT ALL ──────────────────────────────────────────────────
 * The basket, the checkout page, the order row, the receipt page and the
 * customer's email all print a total. Each adding it up for itself is one more
 * chance to disagree, and the one that disagrees is always the one the
 * customer is looking at when they complain.
 *
 * `Order::place()` takes the same view of the subtotal: a caller that passes a
 * subtotal is passing an opinion; the sum of the lines is the fact. This class
 * applies that rule to the rest of the figures.
 *
 * ── AND IT IS THE SEAM EVERY FIGURE PLUGS INTO ──────────────────────────────
 * Shipping and discount — `orders.shipping_total` and `orders.discount_total`,
 * from migration 041 — are one argument each here, and every screen that
 * prints a total gets them, because every screen asks this.
 *
 * ── THE ORDER OF OPERATIONS IS A DECISION, NOT AN ACCIDENT ──────────────────
 * Discount comes off the goods BEFORE tax, and shipping goes on before tax too.
 * That is what a customer expects — a 10%-off coupon should reduce the tax as
 * well, because they are not buying the discounted amount and then paying tax
 * on the original — and it is what every jurisdiction we could check requires
 * of a discount given by the seller.
 *
 * A discount that does NOT reduce tax exists (a manufacturer's coupon the shop
 * is reimbursed for), and this system does not offer one. That is written down
 * rather than left for someone to discover from a total.
 */
final class Totals
{
    /**
     * Add up an order.
     *
     * @param int $subtotal the sum of the line totals, in minor units
     * @param int $shipping what delivery costs, in minor units
     * @param int $discount what comes off, in minor units — a POSITIVE number
     *
     * @return array{
     *     subtotal: int, discount: int, shipping: int, taxable: int,
     *     tax: int, total: int, taxIncluded: bool, taxLine: string
     * }
     */
    public static function of(
        int $subtotal,
        int $shipping = 0,
        int $discount = 0,
        string $couponCode = ''
    ): array {
        return self::compute(
            $subtotal,
            $shipping,
            $discount,
            Tax::rate(),
            Tax::isInclusive(),
            Tax::label(),
            $couponCode
        );
    }

    /** The sum of the basket as it stands, ready for a screen. */
    public static function forCart(): array
    {
        // DELIVERY AND THE DISCOUNT ARE ASKED FOR HERE, not passed in by a
        // screen. A checkout that worked out its own could show a different
        // figure from the basket the customer just agreed to, which is the one
        // disagreement in this subsystem that costs money.
        return self::of(
            Cart::subtotal(),
            Shipping::forCart(),
            self::cartDiscount(),
            Cart::coupon()
        );
    }

    /**
     * What the basket's code takes off, or 0.
     *
     * ── RE-READ EVERY TIME, NEVER REMEMBERED ────────────────────────────────
     * The session holds the CODE, not the amount. A customer who applies 10% to
     * a $20 basket and then adds an item must get 10% of the new figure, and a
     * code that expired or was spent between the basket and the checkout stops
     * applying at the checkout — which is the only moment that matters, because
     * it is the one that writes an order.
     *
     * `Coupon::discountOn()` returns 0 for every refusal, so nothing here has
     * to know the six reasons. The BASKET says which one, because that is where
     * the customer can do something about it.
     */
    public static function cartDiscount(): int
    {
        $code = Cart::coupon();
        if ($code === '') {
            return 0;
        }

        $coupon = Coupon::find($code);

        return $coupon === null ? 0 : Coupon::discountOn($coupon, Cart::subtotal());
    }

    /**
     * The same sum with every input handed in — no settings, no session, no
     * database.
     */
    public static function compute(
        int $subtotal,
        int $shipping,
        int $discount,
        int $taxRate,
        bool $taxInclusive,
        string $taxLabel,
        string $couponCode = ''
    ): array {
        // ── A DISCOUNT CANNOT EXCEED THE GOODS ──────────────────────────────
        // A £30 coupon against a £20 basket must not produce a £10 refund on a
        // shop that has taken no money. Clamped here rather than where coupons
        // are read, because this is the last place before the number becomes a
        // total and it is the only place every caller passes through.
        $discount = max(0, min($discount, $subtotal));
        $shipping = max(0, $shipping);

        $taxable = $subtotal - $discount + $shipping;
        $tax     = Tax::compute($taxable, $taxRate, $taxInclusive);

        // INCLUSIVE TAX IS NOT ADDED. It is already in the prices the owner
        // typed, so adding it again charges the rate twice — and the arithmetic
        // looks perfectly reasonable while it happens.
        $total = $taxInclusive ? $taxable : $taxable + $tax;

        return [
            'subtotal'    => $subtotal,
            'discount'    => $discount,
            'shipping'    => $shipping,
            'taxable'     => $taxable,
            'tax'         => $tax,
            'total'       => $total,
            'taxIncluded' => $taxInclusive,
            'taxLine'     => Tax::line($taxLabel, $taxRate),
            'coupon'      => $couponCode,
        ];
    }

    /**
     * The same shape, rebuilt from an order row that has already been stored.
     *
     * ── FROM THE ROW, NEVER FROM SETTINGS ───────────────────────────────────
     * An order carries its own `tax_rate`, `tax_label` and `tax_inclusive`
     * (migration 044) precisely so a receipt printed next year says what it
     * said the day it was issued. Calling `of()` here instead would read
     * today's rate against last year's subtotal and print two figures that do
     * not agree with each other on the same page.
     *
     * @param array<string, mixed> $order
     */
    public static function ofOrder(array $order): array
    {
        $inclusive = !empty($order['tax_inclusive']);

        return [
            'subtotal'    => (int) ($order['subtotal'] ?? 0),
            'discount'    => (int) ($order['discount_total'] ?? 0),
            'shipping'    => (int) ($order['shipping_total'] ?? 0),
            'taxable'     => 0,
            'tax'         => (int) ($order['tax_total'] ?? 0),
            // THE STORED TOTAL, not a recomputed one. If the two ever disagree
            // the row is what was charged, and quietly printing a different
            // number would hide that rather than show it.
            'total'       => (int) ($order['total'] ?? 0),
            'taxIncluded' => $inclusive,
            'taxLine'     => Tax::line(
                (string) ($order['tax_label'] ?? ''),
                (int) ($order['tax_rate'] ?? 0)
            ),
            'coupon'      => (string) ($order['coupon_code'] ?? ''),
        ];
    }

    /**
     * The lines a basket or a receipt prints, in order, as `[label, amount]`.
     *
     * ── A ZERO LINE IS NOT PRINTED ──────────────────────────────────────────
     * "Discount $0.00" on every order is a row that means nothing and trains
     * the reader to skip the block it is in. The subtotal is the exception: it
     * is printed even on a free order, because a receipt with only a total is a
     * receipt with no arithmetic in it.
     *
     * ── EXCEPT THE INCLUSIVE TAX LINE, WHICH IS WORDED DIFFERENTLY ──────────
     * When the tax is already in the prices, the line is not a charge — it says
     * how much of the total was tax. Printing it as another row to add up is
     * how a customer decides they have been charged twice.
     *
     * @param array $t from `of()` or `compute()`
     * @return list<array{0: string, 1: int, 2: bool}> label, amount, is-a-note
     */
    public static function rows(array $t): array
    {
        $rows = [['Subtotal', (int) $t['subtotal'], false]];

        if ((int) $t['discount'] > 0) {
            // ── THE CODE IS IN THE LABEL ────────────────────────────────────
            // "Discount −$4.50" is a number an owner cannot account for and a
            // customer cannot check. "Discount (SPRING20) −$4.50" says where it
            // came from, on the basket, the receipt, the email and the order
            // screen — which is the difference between a figure and a record.
            $code  = trim((string) ($t['coupon'] ?? ''));
            $label = $code !== '' ? 'Discount (' . $code . ')' : 'Discount';

            // NEGATIVE, so the column adds up on the page. A discount printed as
            // a positive number in a column of positive numbers is a line the
            // reader has to be told to subtract.
            $rows[] = [$label, -(int) $t['discount'], false];
        }

        if ((int) $t['shipping'] > 0) {
            $rows[] = ['Delivery', (int) $t['shipping'], false];
        }

        if ((int) $t['tax'] > 0) {
            $rows[] = [
                $t['taxIncluded'] ? 'Includes ' . $t['taxLine'] : (string) $t['taxLine'],
                (int) $t['tax'],
                (bool) $t['taxIncluded'],
            ];
        }

        return $rows;
    }
}
