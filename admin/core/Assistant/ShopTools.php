<?php

declare(strict_types=1);

namespace Cms\Assistant;

use Cms\Commerce\Coupon;
use Cms\Commerce\Money;
use Cms\Commerce\Product;
use Cms\Commerce\Reports;
use Cms\Commerce\ShopApi;
use Cms\Commerce\Stock;
use Cms\Database\Database;
use Cms\Helpers\Dates;
use Cms\Helpers\Modules;
use Cms\Models\Order;
use Cms\Models\Post;
use PDO;

/**
 * WHAT THE ASSISTANT READS OF THE SHOP: its orders, what sold, its discount
 * codes and its stock, and a product's options with their counts.
 *
 * ── ONLY WHERE THERE IS A SHOP, AND ONLY FOR WHO MAY SEE IT ─────────────────
 * The tools exist when the shop module is on and a type sells, and each needs
 * a shop manager or an administrator, as the shop's screens do: an order is a
 * customer's name, address and what they bought. A product's prices and
 * counts are on its edit screen, so `read_entry` gives them to whoever may
 * read the product.
 *
 * ── IN THE SHAPES THE API GIVES ─────────────────────────────────────────────
 * An order and a product read as `Commerce\ShopApi` writes them for another
 * program: amounts as a person writes them ("38.00"), each option with its id,
 * code, price and count. Read-only, as every tool is (`ToolCatalog`): a change
 * is proposed, approved on a diff and applied by `ShopOps`.
 */
final class ShopTools
{
    /** @return list<Tool> none when there is no shop */
    public static function all(): array
    {
        if (!self::open()) {
            return [];
        }

        return [self::findOrders(), self::readOrder(), self::salesReport(), self::findCoupons(), self::readStock()];
    }

    /** Whether the site sells: the module on and a type that sells. */
    public static function open(): bool
    {
        return Modules::enabled('commerce') && Product::sellableTypes() !== [];
    }

    /**
     * A product's prices, code, stock and options, for `read_entry`; null for
     * an entry that is not a product.
     *
     * A product not yet on the site is priced as the site would sell it once
     * it is: its status is `read_entry`'s to say.
     *
     * @return array<string, mixed>|null
     */
    public static function product(array $post): ?array
    {
        if (!self::open() || !Product::isSellableType((string) $post['post_type'])) {
            return null;
        }
        $product = Product::fromPost(array_replace($post, ['status' => 'published']));
        if ($product === null) {
            return ['note' => 'Not for sale yet: it has no price.'];
        }
        $shop = ShopApi::product($product);
        // `option_id`, as `set_stock` and the rest name it.
        $shop['options'] = array_map(
            static fn (array $o): array => ['option_id' => $o['id']] + array_diff_key($o, ['id' => true]),
            $shop['options']
        );
        unset($shop['id'], $shop['slug'], $shop['type'], $shop['title']);

        return $shop;
    }

    private static function findOrders(): Tool
    {
        return new Tool(
            name: 'find_orders',
            label: 'Looking through your orders',
            description:
                'Find orders, newest first: by status, by the days they were placed (the site\'s own days), '
                . 'or by the customer\'s name or email. Returns each order\'s id, number, when it was placed, '
                . 'status, customer, total and what was in it. "Order #12" is order_id 12. Call read_order '
                . 'for one in full.',
            inputSchema: [
                'type'       => 'object',
                'properties' => [
                    'status'   => ['type' => 'string', 'enum' => array_keys(Order::STATUSES),
                                   'description' => 'Only orders with this status. processing means paid and not yet sent; completed means sent.'],
                    'since'    => ['type' => 'string', 'description' => 'First day, YYYY-MM-DD, in the site\'s timezone.'],
                    'until'    => ['type' => 'string', 'description' => 'Last day, YYYY-MM-DD, in the site\'s timezone.'],
                    'customer' => ['type' => 'string', 'description' => 'Part of the customer\'s name or email.'],
                    'limit'    => ['type' => 'integer', 'description' => 'Maximum results (default 20, max 50).'],
                ],
                'required'             => [],
                'additionalProperties' => false,
            ],
            run: static function (array $input, ToolContext $ctx): array {
                $where  = [];
                $params = [];
                $status = (string) ($input['status'] ?? '');
                if ($status !== '') {
                    if (!isset(Order::STATUSES[$status])) {
                        return ['error' => 'status is one of ' . implode(', ', array_keys(Order::STATUSES)) . '.'];
                    }
                    $where[]           = 'status = :status';
                    $params[':status'] = $status;
                }
                $since = trim((string) ($input['since'] ?? ''));
                $until = trim((string) ($input['until'] ?? ''));
                foreach (['since' => $since, 'until' => $until] as $name => $day) {
                    if ($day !== '' && !self::isDay($day)) {
                        return ['error' => "{$name} is a day written YYYY-MM-DD."];
                    }
                }
                if ($since !== '' || $until !== '') {
                    [$from, $to]     = Reports::bounds($since !== '' ? $since : '2000-01-01', $until !== '' ? $until : Dates::today());
                    $where[]         = 'created_at >= :from AND created_at < :to';
                    $params[':from'] = $from;
                    $params[':to']   = $to;
                }
                $who = trim((string) ($input['customer'] ?? ''));
                if ($who !== '') {
                    $where[]        = '(billing_name LIKE :who OR email LIKE :who2)';
                    $like           = '%' . addcslashes($who, '%_\\') . '%';
                    $params[':who'] = $like;
                    $params[':who2'] = $like;
                }
                $limit = max(1, min(50, (int) ($input['limit'] ?? 20)));
                $sql   = 'FROM orders' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));
                $db    = Database::getInstance();
                $count = $db->prepare('SELECT COUNT(*) ' . $sql);
                $count->execute($params);
                $stmt = $db->prepare('SELECT * ' . $sql . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit);
                $stmt->execute($params);

                return [
                    'total'  => (int) $count->fetchColumn(),
                    'orders' => array_map(static fn (array $o): array => [
                        'order_id' => (int) $o['id'],
                        'number'   => Order::number($o),
                        'placed'   => Dates::format((string) $o['created_at'], 'Y-m-d H:i'),
                        'status'   => (string) $o['status'],
                        'customer' => (string) $o['billing_name'],
                        'email'    => (string) $o['email'],
                        'total'    => Money::edit((int) $o['total'], (string) $o['currency']) . ' ' . (string) $o['currency'],
                        'items'    => implode(', ', array_map(
                            static fn (array $i): string => (int) $i['quantity'] . ' × ' . (string) $i['name']
                                . ((string) ($i['variation_label'] ?? '') !== '' ? ' — ' . (string) $i['variation_label'] : ''),
                            Order::items((int) $o['id'])
                        )),
                    ], $stmt->fetchAll(PDO::FETCH_ASSOC)),
                ];
            },
            minimumRole: 'shop_manager',
        );
    }

    private static function readOrder(): Tool
    {
        return new Tool(
            name: 'read_order',
            label: 'Reading an order',
            description:
                'Read one order in full: status, when it was placed and paid, the customer and their '
                . 'addresses, each line with its option and code, the totals, the payment, tracking, and its '
                . 'notes. Call this before proposing set_order_status, so "from" is the status you saw.',
            inputSchema: [
                'type'       => 'object',
                'properties' => [
                    'order_id' => ['type' => 'integer', 'description' => 'The order\'s id: "#12" is 12.'],
                ],
                'required'             => ['order_id'],
                'additionalProperties' => false,
            ],
            run: static function (array $input, ToolContext $ctx): array {
                $order = Order::find((int) $input['order_id']);
                if ($order === null) {
                    return ['error' => 'No order #' . (int) $input['order_id'] . '. Call find_orders.'];
                }

                return ['order_id' => (int) $order['id']] + ShopApi::order($order) + [
                    'status_label' => Order::statusLabel((string) $order['status']),
                    'notes'        => array_map(static fn (array $n): array => [
                        'when'        => Dates::format((string) $n['created_at'], 'Y-m-d H:i'),
                        'note'        => (string) $n['body'],
                        'to_customer' => !empty($n['is_customer_note']),
                    ], array_slice(Order::notes((int) $order['id']), 0, 20)),
                ];
            },
            minimumRole: 'shop_manager',
        );
    }

    private static function salesReport(): Tool
    {
        return new Tool(
            name: 'sales_report',
            label: 'Adding up what sold',
            description:
                'What sold between two days (the site\'s own days, both included): the orders paid for, '
                . 'items, goods, discounts, delivery, tax, total, refunds and net; and each product, and each '
                . 'size and colour, with the units and money, most first. "This week" is Monday to today.',
            inputSchema: [
                'type'       => 'object',
                'properties' => [
                    'from' => ['type' => 'string', 'description' => 'First day, YYYY-MM-DD.'],
                    'to'   => ['type' => 'string', 'description' => 'Last day, YYYY-MM-DD.'],
                ],
                'required'             => ['from', 'to'],
                'additionalProperties' => false,
            ],
            run: static function (array $input, ToolContext $ctx): array {
                $from = trim((string) $input['from']);
                $to   = trim((string) $input['to']);
                if (!self::isDay($from) || !self::isDay($to) || $to < $from) {
                    return ['error' => 'from and to are days written YYYY-MM-DD, to on or after from.'];
                }
                $code  = Money::currency();
                $money = static fn (int $n): string => Money::edit($n) . ' ' . $code;
                $sum   = Reports::summary($from, $to);
                foreach (['goods', 'discount', 'delivery', 'tax', 'total', 'refunded', 'net'] as $k) {
                    $sum[$k] = $money((int) $sum[$k]);
                }
                $lines = static fn (bool $options): array => array_map(static fn (array $r): array => [
                    'name'  => (string) $r['name'],
                    'units' => (int) $r['units'],
                    'sales' => $money((int) $r['sales']),
                ], array_slice(Reports::byProduct($from, $to, $options), 0, 30));

                return ['from' => $from, 'to' => $to, 'totals' => $sum, 'by_product' => $lines(false), 'by_option' => $lines(true)];
            },
            minimumRole: 'shop_manager',
        );
    }

    private static function findCoupons(): Tool
    {
        return new Tool(
            name: 'find_coupons',
            label: 'Looking through your discount codes',
            description:
                'Every discount code: what it takes off, the least a basket must be, how many times it has '
                . 'been used and may be, its first and last days, and whether it is on.',
            inputSchema: [
                'type'                 => 'object',
                'properties'           => new \stdClass(),
                'required'             => [],
                'additionalProperties' => false,
            ],
            run: static function (array $input, ToolContext $ctx): array {
                return ['codes' => array_map(static fn (array $c): array => [
                    'code'          => (string) $c['code'],
                    'takes_off'     => Coupon::describe((string) $c['kind'], (int) $c['amount'], !empty($c['free_shipping'])),
                    'min_spend'     => (int) $c['min_spend'] > 0 ? Money::edit((int) $c['min_spend']) : null,
                    'used'          => (int) $c['times_used'],
                    'usage_limit'   => (int) $c['usage_limit'] > 0 ? (int) $c['usage_limit'] : null,
                    'first_day'     => $c['starts_at'] ?? null,
                    'last_day'      => $c['expires_at'] ?? null,
                    'only_on'       => array_map(static fn (int $id): string => (string) (Post::find($id)['title'] ?? $id), Coupon::ids($c['product_ids'] ?? null)),
                    'on'            => !empty($c['is_active']),
                ], Coupon::all())];
            },
            minimumRole: 'shop_manager',
        );
    }

    private static function readStock(): Tool
    {
        return new Tool(
            name: 'read_stock',
            label: 'Checking your stock',
            description:
                'Every product and option with its count, as the Stock screen lists them: all of them, only '
                . 'those running low (at or under the shop\'s low-stock line), or only those sold out. Each '
                . 'with the entry_id and option_id set_stock takes.',
            inputSchema: [
                'type'       => 'object',
                'properties' => [
                    'which' => ['type' => 'string', 'enum' => ['all', 'low', 'sold_out'], 'description' => 'Default all.'],
                    'query' => ['type' => 'string', 'description' => 'Part of a product\'s name, an option\'s or a code.'],
                ],
                'required'             => [],
                'additionalProperties' => false,
            ],
            run: static function (array $input, ToolContext $ctx): array {
                $which = (string) ($input['which'] ?? 'all');
                $query = mb_strtolower(trim((string) ($input['query'] ?? '')));
                $low   = Stock::lowAt();
                $out   = [];
                foreach (Stock::listing() as $row) {
                    $out_  = $row['status'] === Product::OUT_OF_STOCK || ($row['counted'] && (int) $row['count'] <= 0);
                    $short = $row['counted'] && (int) $row['count'] > 0 && (int) $row['count'] <= $low;
                    if (($which === 'low' && !$short) || ($which === 'sold_out' && !$out_)) {
                        continue;
                    }
                    if ($query !== '' && !str_contains(mb_strtolower($row['title'] . ' ' . $row['option'] . ' ' . $row['sku']), $query)) {
                        continue;
                    }
                    $out[] = array_filter([
                        'entry_id'  => (int) $row['post_id'],
                        'option_id' => str_starts_with($row['key'], 'v') ? (int) substr($row['key'], 1) : null,
                        'product'   => $row['title'],
                        'option'    => $row['option'] !== '' ? $row['option'] : null,
                        'sku'       => $row['sku'] !== '' ? $row['sku'] : null,
                        'count'     => $row['counted'] ? (int) $row['count'] : 'not counted',
                        'sold_out'  => $out_,
                    ], static fn ($v): bool => $v !== null);
                }

                return ['low_stock_line' => $low, 'items' => $out];
            },
            minimumRole: 'shop_manager',
        );
    }

    private static function isDay(string $day): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
