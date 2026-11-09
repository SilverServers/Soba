<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Models\Order;

/**
 * WHAT ANOTHER PROGRAM IS GIVEN: a product, or an order, as JSON.
 *
 * One shape for each, read by the API's answers and the webhooks' bodies
 * alike (`Controllers\Api\ShopApiController`, `Webhooks`), so a program that
 * reads one reads the other. Amounts are strings as a person writes them in
 * the order's currency ("38.00"), never floats; times are ISO 8601 in UTC;
 * a product's options are inside it, each with its own code, price and count.
 */
final class ShopApi
{
    /** @return array<string, mixed> */
    public static function product(array $product): array
    {
        $money = static fn (int $n): string => Money::edit($n);
        $out   = [
            'id'         => (int) $product['id'],
            'type'       => (string) $product['post_type'],
            'title'      => (string) $product['title'],
            'slug'       => (string) $product['slug'],
            'sku'        => (string) $product['sku'],
            'price'      => $money((int) $product['regular_price']),
            'sale_price' => $product['on_sale'] ? $money((int) $product['price']) : null,
            'currency'   => Money::currency(),
            'stock'      => $product['stock_left'],
            'status'     => (string) $product['stock'],
            'options'    => [],
        ];
        foreach ($product['variations'] as $v) {
            [$regular, $now] = Variation::prices($v, $product);
            $out['options'][] = [
                'id'         => (int) $v['id'],
                'label'      => (string) $v['label'],
                'sku'        => (string) $v['sku'],
                'price'      => $money($regular),
                'sale_price' => $now < $regular ? $money($now) : null,
                'stock'      => Variation::available($v),
                'status'     => (string) $v['stock_status'],
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function order(array $order): array
    {
        $id       = (int) $order['id'];
        $currency = (string) $order['currency'];
        $money    = static fn (int $n): string => Money::edit($n, $currency);
        $refunded = (int) array_sum(array_column(Refund::forOrder($id), 'amount'));
        $address  = static fn (string $p): array => [
            'name'    => (string) $order[$p . '_name'],
            'line1'   => (string) $order[$p . '_line1'],
            'line2'   => (string) $order[$p . '_line2'],
            'city'    => (string) $order[$p . '_city'],
            'region'  => (string) $order[$p . '_region'],
            'post'    => (string) $order[$p . '_post'],
            'country' => (string) $order[$p . '_country'],
        ];
        $utc = static fn (?string $t): ?string => $t === null || $t === '' ? null : gmdate('c', (int) strtotime($t . ' UTC'));

        return [
            'id'         => $id,
            'number'     => Order::number($order),
            'status'     => (string) $order['status'],
            'created_at' => $utc((string) $order['created_at']),
            'paid_at'    => $utc($order['paid_at'] ?? null),
            'currency'   => $currency,
            'totals'     => [
                'goods'    => $money((int) $order['subtotal']),
                'discount' => $money((int) $order['discount_total']),
                'delivery' => $money((int) $order['shipping_total']),
                'tax'      => $money((int) $order['tax_total']),
                'total'    => $money((int) $order['total']),
                'refunded' => $money($refunded),
            ],
            'payment'    => ['method' => (string) $order['payment_method_title'], 'reference' => (string) $order['transaction_ref']],
            'customer'   => ['email' => (string) $order['email'], 'phone' => (string) $order['phone'], 'note' => (string) $order['customer_note']],
            'billing'    => $address('billing'),
            'shipping'   => $address('shipping'),
            'delivery'   => ['method' => (string) $order['shipping_method_title']],
            'tax_place'  => ['country' => (string) $order['tax_country'], 'region' => (string) $order['tax_region']],
            'tracking'   => [
                'carrier' => (string) $order['tracking_carrier'],
                'number'  => (string) $order['tracking_number'],
                'url'     => (string) $order['tracking_url'],
            ],
            'items'      => array_map(static fn (array $i): array => [
                'sku'        => (string) $i['sku'],
                'name'       => (string) $i['name'],
                'option'     => (string) ($i['variation_label'] ?? ''),
                'quantity'   => (int) $i['quantity'],
                'refunded'   => (int) $i['refunded_qty'],
                'unit_price' => $money((int) $i['unit_price']),
                'total'      => $money((int) $i['line_total']),
            ], Order::items($id)),
        ];
    }
}
