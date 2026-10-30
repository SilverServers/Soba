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
 *
 * ── THE TAX IS THE PLACE'S ──────────────────────────────────────────────────
 * Each figure is computed for where the order goes (`TaxRates::forPlace()`):
 * an Ontario order has an HST line, a Quebec one a GST line and a QST line.
 * Before an address is known — the basket — the shop's own province stands
 * in, and the line says it is an estimate for it.
 */
final class Totals
{
    /**
     * Add up an order, taxed for where it goes.
     *
     * @param int $subtotal the sum of the line totals, in minor units
     * @param int $shipping what delivery costs, in minor units
     * @param int $discount what comes off, in minor units — a POSITIVE number
     * @param array{country?: string, region?: string}|null $place where it
     *        goes; null before an address is known, which taxes it for the
     *        shop's own place and marks the tax as an estimate when the rates
     *        depend on where an order goes
     * @return array{
     *     subtotal: int, discount: int, shipping: int, taxable: int, tax: int,
     *     taxLines: list<array{label: string, rate: int, amount: int}>,
     *     total: int, taxIncluded: bool, taxPlace: string, coupon: string
     * }
     */
    public static function of(
        int $subtotal,
        int $shipping = 0,
        int $discount = 0,
        string $couponCode = '',
        ?array $place = null
    ): array {
        $rows     = TaxRates::all();
        $estimate = $place === null;
        $place    = $place ?? TaxRates::home();
        $country  = (string) ($place['country'] ?? '');
        $region   = (string) ($place['region'] ?? '');

        $totals = self::compute(
            $subtotal,
            $shipping,
            $discount,
            TaxRates::forPlace($rows, $country, $region),
            Tax::isInclusive(),
            $couponCode
        );
        if ($estimate && TaxRates::dependOnPlace($rows)) {
            $totals['taxPlace'] = TaxRates::placeName($country, $region);
        }

        return $totals;
    }

    /**
     * The sum of the basket as it stands, ready for a screen: delivered to the
     * place given, or collected — or, before an address is known, estimated
     * for the shop's own place.
     *
     * COLLECTED, IT IS TAXED WHERE THE SHOP IS, because that is where the sale
     * happens, and costs nothing to deliver.
     *
     * @param array{country?: string, region?: string}|null $place where it is delivered
     */
    public static function forCart(?array $place = null, string $method = Shipping::DELIVERY): array
    {
        $pickup = $method === Shipping::PICKUP;
        $totals = self::of(
            Cart::subtotal(),
            self::cartShipping($place, $method),
            self::cartDiscount(),
            Cart::coupon(),
            $pickup ? TaxRates::home() : $place
        );

        // THE LINE UNDER THE FIGURES about delivery to this place, and the
        // delivery line's own estimate, as the tax line has one.
        $totals['deliveryNote'] = Shipping::noteFor(Cart::subtotal(), $place, $method);
        if (Shipping::forCart($place, $method) > 0 && (int) $totals['shipping'] === 0) {
            $totals['deliveryNote'] = 'Delivery is free with ' . Cart::coupon() . '.';
        }
        if ($place === null && !$pickup && Shipping::dependOnPlace(Shipping::rates())) {
            $home = TaxRates::home();
            $totals['shippingPlace'] = TaxRates::placeName($home['country'], $home['region']);
        }

        return $totals;
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

        // OF WHAT IT REACHES in the basket: the products and terms it names,
        // and not what is on sale when it leaves sale items alone.
        return $coupon === null ? 0 : Coupon::discountOn($coupon, Cart::subtotal(), null, Coupon::cartLines());
    }

    /**
     * What delivery costs this basket: to the place given or collected, and
     * nothing while a code that makes delivery free applies — counted against
     * this customer when the email is known.
     *
     * @param array{country?: string, region?: string}|null $place
     */
    public static function cartShipping(?array $place, string $method = Shipping::DELIVERY, string $email = ''): int
    {
        $shipping = Shipping::forCart($place, $method);

        return $shipping > 0 && Coupon::freeDeliveryOnCart($email) ? 0 : $shipping;
    }

    /**
     * The same sum with every input handed in — no settings, no session, no
     * database.
     *
     * @param list<array{label: string, rate: int}> $rates the place's rates,
     *        in thousandths of a percent (`TaxRates::forPlace()`)
     */
    public static function compute(
        int $subtotal,
        int $shipping,
        int $discount,
        array $rates,
        bool $taxInclusive,
        string $couponCode = ''
    ): array {
        // A discount can take the goods to nothing and no further: a $30 code
        // on a $20 basket is a free basket, not a $10 credit.
        $discount = max(0, min($discount, $subtotal));
        $shipping = max(0, $shipping);

        // Discount off the goods, delivery on, then each tax on what is left.
        $taxable = $subtotal - $discount + $shipping;
        $lines   = Tax::lines($taxable, $rates, $taxInclusive);
        $tax     = (int) array_sum(array_column($lines, 'amount'));

        // Included in the prices, the tax is already in `$taxable`; added, it
        // goes on top.
        $total = $taxInclusive ? $taxable : $taxable + $tax;

        return [
            'subtotal'    => $subtotal,
            'discount'    => $discount,
            'shipping'    => $shipping,
            'taxable'     => $taxable,
            'tax'         => $tax,
            'taxLines'    => $lines,
            'total'       => $total,
            'taxIncluded' => $taxInclusive,
            'taxPlace'    => '',
            'shippingPlace' => '',
            'deliveryNote'  => '',
            'coupon'      => $couponCode,
        ];
    }

    /**
     * The same shape, rebuilt from an order row that has already been stored.
     *
     * ── FROM THE ROW, NEVER FROM SETTINGS ───────────────────────────────────
     * An order carries its own tax lines (`order_tax_lines`, migration 096),
     * each with its name, rate and amount, precisely so a receipt printed next
     * year says what it said the day it was issued. Calling `of()` here
     * instead would read today's rates against last year's subtotal and print
     * two figures that do not agree with each other on the same page.
     *
     * An order placed before tax was charged by place has no lines: its one
     * rate is on the row (migration 044), in hundredths of a percent.
     *
     * @param array<string, mixed> $order
     */
    public static function ofOrder(array $order): array
    {
        $lines = is_array($order['tax_lines'] ?? null)
            ? $order['tax_lines']
            : \Cms\Models\Order::taxLines((int) ($order['id'] ?? 0));
        if ($lines === [] && (int) ($order['tax_total'] ?? 0) > 0) {
            $lines = [[
                'label'  => (string) ($order['tax_label'] ?? ''),
                'rate'   => (int) ($order['tax_rate'] ?? 0) * 10,
                'amount' => (int) $order['tax_total'],
            ]];
        }

        return [
            'subtotal'    => (int) ($order['subtotal'] ?? 0),
            'discount'    => (int) ($order['discount_total'] ?? 0),
            'shipping'    => (int) ($order['shipping_total'] ?? 0),
            'taxable'     => 0,
            'tax'         => (int) ($order['tax_total'] ?? 0),
            'taxLines'    => $lines,
            'total'       => (int) ($order['total'] ?? 0),
            'taxIncluded' => !empty($order['tax_inclusive']),
            'taxPlace'    => '',
            'shippingPlace' => '',
            'deliveryNote'  => '',
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
     * ── AND AN ESTIMATE SAYS WHOSE ──────────────────────────────────────────
     * Before an address is known, "HST (13%), estimated for Ontario": the
     * figure is the shop's own province's, and changes at the checkout.
     *
     * @param array $t from `of()` or `compute()`
     * @return list<array{0: string, 1: int, 2: bool}> label, amount, is-a-note
     */
    public static function rows(array $t): array
    {
        $rows = [['Subtotal', (int) $t['subtotal'], false]];

        if ((int) $t['discount'] > 0) {
            $code  = trim((string) ($t['coupon'] ?? ''));
            $label = $code !== '' ? 'Discount (' . $code . ')' : 'Discount';
            $rows[] = [$label, -(int) $t['discount'], false];
        }

        if ((int) $t['shipping'] > 0) {
            $where  = trim((string) ($t['shippingPlace'] ?? ''));
            $rows[] = [$where !== '' ? 'Delivery, estimated for ' . $where : 'Delivery', (int) $t['shipping'], false];
        }

        $estimate = trim((string) ($t['taxPlace'] ?? ''));
        foreach ((array) ($t['taxLines'] ?? []) as $line) {
            if ((int) ($line['amount'] ?? 0) <= 0) {
                continue;
            }
            $label = TaxRates::line((string) $line['label'], (int) $line['rate']);
            if ($estimate !== '') {
                $label .= ', estimated for ' . $estimate;
            }
            $rows[] = [
                !empty($t['taxIncluded']) ? 'Includes ' . $label : $label,
                (int) $line['amount'],
                !empty($t['taxIncluded']),
            ];
        }

        return $rows;
    }
}
