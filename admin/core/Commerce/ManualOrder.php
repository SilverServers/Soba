<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Database\Database;
use Cms\Models\Order;
use PDO;

/**
 * AN ORDER TAKEN BY THE SHOP: ON THE TELEPHONE, AT A MARKET STALL, BY EMAIL.
 *
 * The owner types what the customer asked for: who they are and where it
 * goes, the products and how many, how they are paying, and whether they have.
 * It becomes an order like any other, written by `Order::place()`, the one way
 * an order is written: today's prices as the shop's pages show them, delivery
 * and tax for the place it goes, and the stock taken all or nothing, as the
 * checkout takes it.
 */
final class ManualOrder
{
    /**
     * What can go on an order: every published product the shop sells, or
     * each of its options, at today's price, with how many are left.
     *
     * @return list<array{value: string, label: string, price: int, left: ?int, available: bool, delivered: bool}>
     */
    public static function choices(): array
    {
        $types = Product::sellableTypes();
        if ($types === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($types), '?'));
        $stmt  = Database::getInstance()->prepare(
            "SELECT * FROM posts WHERE post_type IN ($marks) AND status = 'published' ORDER BY title, id"
        );
        $stmt->execute($types);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $post) {
            $product = Product::fromPost($post);
            if ($product === null) {
                continue;
            }
            if ($product['variations'] === []) {
                $out[] = [
                    'value'     => (string) $product['id'],
                    'label'     => (string) $product['title'],
                    'price'     => (int) $product['price'],
                    'left'      => $product['stock_left'],
                    'available' => (bool) $product['purchasable'],
                    'delivered' => (bool) $product['delivered'],
                ];
                continue;
            }
            foreach ($product['variations'] as $variation) {
                $out[] = [
                    'value'     => (int) $product['id'] . ':' . (int) $variation['id'],
                    'label'     => (string) $product['title'] . ' — ' . (string) $variation['label'],
                    'price'     => Variation::prices($variation, $product)[1],
                    'left'      => Variation::available($variation),
                    'available' => Variation::isPurchasable($variation),
                    'delivered' => (bool) $product['delivered'],
                ];
            }
        }

        return $out;
    }

    /**
     * Write the order the owner typed, or say what stops it.
     *
     * @param array<string, string>      $form  `Checkout::readForm()` of the posted fields
     * @param array<int|string, mixed>   $lines as posted: each a `choice` and a `quantity`
     * @return array{id: ?int, errors: list<string>}
     */
    public static function place(array $form, array $lines, string $status, bool $confirm, string $by = ''): array
    {
        $errors  = [];
        $choices = [];
        foreach (self::choices() as $choice) {
            $choices[$choice['value']] = $choice;
        }

        // ── THE LINES: THE SAME THING TWICE IS ONE LINE OF BOTH ────────────
        $want = [];
        foreach ($lines as $row) {
            $value = trim((string) (is_array($row) ? ($row['choice'] ?? '') : ''));
            $qty   = trim((string) (is_array($row) ? ($row['quantity'] ?? '') : ''));
            if ($value === '') {
                continue;   // a row left blank
            }
            if (!isset($choices[$value])) {
                $errors[] = 'A line names something the shop no longer sells.';
                continue;
            }
            if (!ctype_digit($qty) || (int) $qty < 1) {
                $errors[] = 'How many of ' . $choices[$value]['label'] . ' has to be a whole number, 1 or more.';
                continue;
            }
            $want[$value] = ($want[$value] ?? 0) + (int) $qty;
        }
        if ($want === [] && $errors === []) {
            $errors[] = 'Add at least one thing to the order.';
        }
        foreach ($want as $value => $qty) {
            $choice = $choices[$value];
            if (!$choice['available']) {
                $errors[] = $choice['label'] . ' is sold out.';
            } elseif ($choice['left'] !== null && $choice['left'] < $qty) {
                $errors[] = 'Only ' . $choice['left'] . ' of ' . $choice['label'] . ' ' . ($choice['left'] === 1 ? 'is' : 'are') . ' left.';
            }
        }

        // ── THE CUSTOMER ───────────────────────────────────────────────────
        if (($form['billing_name'] ?? '') === '') {
            $errors[] = 'The customer\'s name is required.';
        }
        if (($form['email'] ?? '') !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'The email address does not look like one.';
        }

        // ── HOW IT REACHES THEM ────────────────────────────────────────────
        // Posted to the address when anything on it is, unless collected; the
        // address is then required, and has to be one the shop delivers to.
        $deliverable = false;
        foreach (array_keys($want) as $value) {
            $deliverable = $deliverable || $choices[$value]['delivered'];
        }
        $method  = ($form['delivery_method'] ?? '') === Shipping::PICKUP && Shipping::pickup()
            ? Shipping::PICKUP : Shipping::DELIVERY;
        $country = Countries::code((string) ($form['billing_country'] ?? ''));
        $region  = Regions::listed($country) ? Regions::code($country, (string) ($form['billing_region'] ?? '')) : '';
        if ($deliverable && $method === Shipping::DELIVERY) {
            foreach (['billing_line1' => 'The street address', 'billing_city' => 'The town or city',
                      'billing_post' => 'The postal code'] as $key => $what) {
                if (($form[$key] ?? '') === '') {
                    $errors[] = $what . ' is required to deliver the order.';
                }
            }
            if ($country === '') {
                $errors[] = 'Choose the country the order is delivered to.';
            } elseif (Regions::listed($country) && $region === '') {
                $errors[] = 'Choose the ' . strtolower(Regions::word($country)) . ' the order is delivered to.';
            } elseif (!Shipping::deliversTo($country, $region)) {
                $errors[] = Shipping::notDelivered($country, $region);
            }
        }

        // ── HOW THEY PAY, AND WHETHER THEY HAVE ────────────────────────────
        // Any of the shop's ways of being paid, switched on at the checkout or
        // not: a customer on the telephone may pay as the checkout does not.
        $payment = (string) ($form['payment_method'] ?? '');
        if (!isset(PaymentMethods::METHODS[$payment])) {
            $errors[] = 'Choose how the customer is paying.';
        }
        $status = $status === 'processing' ? 'processing' : 'pending';

        if ($errors !== []) {
            return ['id' => null, 'errors' => array_values(array_unique($errors))];
        }

        // ── THE LINES AT TODAY'S PRICES, AND THE STOCK, ALL OR NOTHING ─────
        $orderLines = [];
        $names      = [];
        $subtotal   = 0;
        foreach ($want as $value => $qty) {
            [$postId, $variationId] = array_map('intval', explode(':', $value . ':0'));
            $product   = Product::find($postId);
            $variation = null;
            foreach ($product['variations'] ?? [] as $v) {
                if ((int) $v['id'] === $variationId) {
                    $variation = $v;
                }
            }
            $unit = $variation !== null ? Variation::prices($variation, $product)[1] : (int) $product['price'];
            $orderLines[] = [
                'post_id'         => $postId,
                'variation_id'    => $variation !== null ? (int) $variation['id'] : null,
                'variation_label' => $variation !== null ? (string) $variation['label'] : '',
                'name'            => (string) $product['title'],
                'sku'             => $variation !== null && (string) $variation['sku'] !== ''
                                        ? (string) $variation['sku'] : (string) $product['sku'],
                'quantity'        => $qty,
                'unit_price'      => $unit,
            ];
            $names[]   = $choices[$value]['label'];
            $subtotal += $unit * $qty;
        }

        $took = StockLog::during('sold', null, $by, static fn (): array => Stock::takeForOrder($orderLines, $names));
        if (!$took['ok']) {
            return ['id' => null, 'errors' => [$took['short'] . ' ran out while the order was being taken.']];
        }

        $place    = $method === Shipping::PICKUP ? TaxRates::home() : ['country' => $country, 'region' => $region];
        $shipping = 0;
        if ($deliverable && $method === Shipping::DELIVERY) {
            $rate     = Shipping::rateFor(Shipping::rates(), $country, $region);
            $shipping = $rate === null ? 0 : Shipping::compute($subtotal, true, $rate['rate'], $rate['free_over']);
        }

        $fields = [
            'status'                => $status,
            'stock_reduced'         => 1,
            'shipping_total'        => $shipping,
            'shipping_method'       => $deliverable ? $method : '',
            'shipping_method_title' => $deliverable ? Shipping::methodTitle($method, $place) : '',
            'payment_method'        => $payment,
            'payment_method_title'  => PaymentMethods::describe($payment)['title'],
            'customer_note'         => (string) ($form['customer_note'] ?? ''),
            'tax_country'           => (string) ($place['country'] ?? ''),
            'tax_region'            => (string) ($place['region'] ?? ''),
        ];
        foreach (Order::ADDRESS_FIELDS as $key) {
            if (str_starts_with($key, 'shipping_')) {
                continue;   // delivered to the billing address; corrected on the order if not
            }
            $fields[$key] = $key === 'billing_country' ? $country
                : ($key === 'billing_region' ? (Regions::listed($country) ? $region : trim((string) ($form['billing_region'] ?? '')))
                : trim((string) ($form[$key] ?? '')));
        }

        try {
            $placed = Order::place($fields, $orderLines);
        } catch (\Throwable $e) {
            StockLog::during('cancelled', null, $by, static fn () => Stock::giveForOrder($orderLines));
            throw $e;
        }
        $id = (int) $placed['id'];
        StockLog::claimFor($id);

        // PAID ALREADY: stamped as the status change would stamp it, without
        // the payment email, which the confirmation below says instead.
        if ($status === 'processing') {
            $stmt = Database::getInstance()->prepare('UPDATE orders SET paid_at = NOW() WHERE id = :id');
            $stmt->execute([':id' => $id]);
        }
        Order::addNote(
            $id,
            'Order taken by ' . ($by !== '' ? $by : 'the shop') . '. Payment method: '
                . (string) $fields['payment_method_title'] . '.' . ($status === 'processing' ? ' Paid when it was taken.' : ''),
            false,
            $by !== '' ? $by : 'Shop'
        );

        // EMAILED WHEN ASKED AND THERE IS AN ADDRESS TO SEND IT TO: a customer
        // on the telephone may have none, and the order notes it only when it went.
        $order = Order::find($id);
        if ($confirm && $order !== null) {
            OrderMail::confirmation($order, $by);
        }

        return ['id' => $id, 'errors' => []];
    }
}
