<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Database\Database;
use Cms\Helpers\Dates;
use Cms\Models\Order;
use PDO;

/**
 * WHAT THE SHOP SOLD, AND THE TAX IT COLLECTED, OVER A STRETCH OF DAYS.
 *
 * ── THE ORDERS THAT COUNT ───────────────────────────────────────────────────
 * The ones the shop was paid for: Processing and Completed, and Refunded with
 * its refunds taken off, the same orders a customer's spending is made of
 * (`Customers::SPENDING`). An order waiting for its money, on hold, cancelled
 * or failed sold nothing yet.
 *
 * ── DAYS ARE THE SHOP'S ─────────────────────────────────────────────────────
 * An order belongs to the day it was placed in the site's own zone (Settings →
 * General), as the order screen dates it: a Vancouver shop's order at nine in
 * the evening is that day's, not the next day's in UTC. The stretch asked for
 * is whole days, the first to the last, both included.
 *
 * ── NET IS AFTER REFUNDS ────────────────────────────────────────────────────
 * Every figure has its refunds taken off where they belong: an order's total,
 * a product's units and sales by the quantities and amounts refunded on its
 * lines, a province's tax by the tax refunded with it.
 */
final class Reports
{
    /**
     * The first and last days asked for, as the stored clock's bounds.
     *
     * @return array{0: string, 1: string} UTC `Y-m-d H:i:s`, the end exclusive
     */
    public static function bounds(string $from, string $to): array
    {
        $utc   = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable($from . ' 00:00:00', Dates::zone());
        $end   = (new \DateTimeImmutable($to . ' 00:00:00', Dates::zone()))->modify('+1 day');

        return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    /**
     * A period by name, as the screen offers them, in the site's days.
     *
     * @return array{0: string, 1: string} first and last day, `Y-m-d`
     */
    public static function named(string $name): array
    {
        $today = new \DateTimeImmutable(Dates::today(), Dates::zone());

        return match ($name) {
            'last-month' => [$today->modify('first day of last month')->format('Y-m-d'), $today->modify('last day of last month')->format('Y-m-d')],
            'this-year'  => [$today->format('Y') . '-01-01', $today->format('Y-m-d')],
            'last-year'  => [((int) $today->format('Y') - 1) . '-01-01', ((int) $today->format('Y') - 1) . '-12-31'],
            default      => [$today->modify('first day of this month')->format('Y-m-d'), $today->format('Y-m-d')],
        };
    }

    /**
     * The totals for the stretch.
     *
     * @return array{orders: int, items: int, goods: int, discount: int, delivery: int, tax: int, total: int, refunded: int, net: int}
     */
    public static function summary(string $from, string $to): array
    {
        $out = ['orders' => 0, 'items' => 0, 'goods' => 0, 'discount' => 0, 'delivery' => 0, 'tax' => 0, 'total' => 0, 'refunded' => 0, 'net' => 0];
        foreach (self::orders($from, $to) as $o) {
            $out['orders']++;
            $out['items']    += (int) $o['items'];
            $out['goods']    += (int) $o['subtotal'];
            $out['discount'] += (int) $o['discount_total'];
            $out['delivery'] += (int) $o['shipping_total'];
            $out['tax']      += (int) $o['tax_total'];
            $out['total']    += (int) $o['total'];
            $out['refunded'] += (int) $o['refunded'];
        }
        $out['net'] = $out['total'] - $out['refunded'];

        return $out;
    }

    /**
     * The stretch by day or by month, each with its orders, items and money.
     *
     * @return list<array{when: string, orders: int, items: int, total: int, refunded: int, net: int}>
     */
    public static function byDate(string $from, string $to, string $by = 'day'): array
    {
        $rows = [];
        foreach (self::orders($from, $to) as $o) {
            $when = Dates::format((string) $o['created_at'], $by === 'month' ? 'Y-m' : 'Y-m-d');
            $rows[$when] ??= ['when' => $when, 'orders' => 0, 'items' => 0, 'total' => 0, 'refunded' => 0, 'net' => 0];
            $rows[$when]['orders']++;
            $rows[$when]['items']    += (int) $o['items'];
            $rows[$when]['total']    += (int) $o['total'];
            $rows[$when]['refunded'] += (int) $o['refunded'];
            $rows[$when]['net']      += (int) $o['total'] - (int) $o['refunded'];
        }
        ksort($rows);

        return array_values($rows);
    }

    /**
     * What sold: each product, or each option of one, with the units and the
     * money its lines came to, less what was refunded on them; most sold first.
     *
     * @return list<array{name: string, units: int, sales: int}>
     */
    public static function byProduct(string $from, string $to, bool $options = false): array
    {
        $ids = array_map(static fn (array $o): int => (int) $o['id'], self::orders($from, $to));
        if ($ids === []) {
            return [];
        }
        $group = $options ? 'i.post_id, i.variation_id, i.name, i.variation_label' : 'i.post_id, i.name';
        $stmt  = Database::getInstance()->prepare(
            "SELECT i.name, " . ($options ? "i.variation_label" : "''") . " AS option_label,
                    SUM(i.quantity - i.refunded_qty) AS units,
                    SUM(i.line_total - COALESCE(r.refunded, 0)) AS sales
               FROM order_items i
          LEFT JOIN (SELECT order_item_id, SUM(amount) AS refunded FROM order_refund_items GROUP BY order_item_id) r
                 ON r.order_item_id = i.id
              WHERE i.order_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
           GROUP BY $group
           ORDER BY units DESC, sales DESC, i.name ASC"
        );
        $stmt->execute($ids);

        return array_map(static fn (array $r): array => [
            'name'  => (string) $r['name'] . ((string) $r['option_label'] !== '' ? ' — ' . (string) $r['option_label'] : ''),
            'units' => (int) $r['units'],
            'sales' => (int) $r['sales'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The tax collected, by where it was charged and by tax: what a GST/HST
     * return, or a province's own, is filled in from. Each with the orders it
     * was charged on, what was collected, what was refunded with refunds, and
     * what is left.
     *
     * @return list<array{place: string, tax: string, orders: int, collected: int, refunded: int, net: int}>
     */
    public static function taxes(string $from, string $to): array
    {
        $orders = self::orders($from, $to);
        if ($orders === []) {
            return [];
        }
        $place = [];
        foreach ($orders as $o) {
            $place[(int) $o['id']] = trim(Regions::name((string) $o['tax_country'], (string) $o['tax_region']) . ', '
                . Countries::name((string) $o['tax_country']), ', ');
        }
        $in = implode(',', array_fill(0, count($place), '?'));
        $db = Database::getInstance();

        $rows = [];
        $add  = static function (int $orderId, string $label, int $rate, int $collected, int $refunded) use (&$rows, $place): void {
            $key = $place[$orderId] . '|' . $label . '|' . $rate;
            $rows[$key] ??= ['place' => $place[$orderId], 'tax' => TaxRates::line($label, $rate), 'orders' => [], 'collected' => 0, 'refunded' => 0];
            $rows[$key]['orders'][$orderId] = true;
            $rows[$key]['collected'] += $collected;
            $rows[$key]['refunded']  += $refunded;
        };

        $lines = $db->prepare("SELECT order_id, label, rate, amount FROM order_tax_lines WHERE order_id IN ($in)");
        $lines->execute(array_keys($place));
        foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $add((int) $l['order_id'], (string) $l['label'], (int) $l['rate'], (int) $l['amount'], 0);
        }
        $back = $db->prepare(
            "SELECT r.order_id, t.label, t.rate, t.amount FROM order_refund_tax_lines t
               JOIN order_refunds r ON r.id = t.refund_id WHERE r.order_id IN ($in)"
        );
        $back->execute(array_keys($place));
        foreach ($back->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $add((int) $l['order_id'], (string) $l['label'], (int) $l['rate'], 0, (int) $l['amount']);
        }
        ksort($rows);

        return array_values(array_map(static fn (array $r): array => [
            'place'     => $r['place'],
            'tax'       => $r['tax'],
            'orders'    => count($r['orders']),
            'collected' => $r['collected'],
            'refunded'  => $r['refunded'],
            'net'       => $r['collected'] - $r['refunded'],
        ], $rows));
    }

    /**
     * One report as a spreadsheet, the orders spreadsheet's way: plain
     * amounts, and the byte-order mark is the caller's.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, string>      $columns key => heading
     */
    public static function csv(array $rows, array $columns): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_values($columns), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(static function (string $key) use ($row): string {
                $v = $row[$key] ?? '';
                return in_array($key, ['goods', 'discount', 'delivery', 'tax', 'total', 'refunded', 'net', 'sales', 'collected'], true)
                    ? Money::edit((int) $v) : (string) $v;
            }, array_keys($columns)), ',', '"', '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * The orders that count in the stretch, with their refunds and items.
     *
     * @return list<array<string, mixed>>
     */
    private static function orders(string $from, string $to): array
    {
        [$start, $end] = self::bounds($from, $to);
        $stmt = Database::getInstance()->prepare(
            "SELECT o.id, o.created_at, o.subtotal, o.discount_total, o.shipping_total, o.tax_total, o.total,
                    o.tax_country, o.tax_region, COALESCE(r.refunded, 0) AS refunded,
                    (SELECT COALESCE(SUM(i.quantity - i.refunded_qty), 0) FROM order_items i WHERE i.order_id = o.id) AS items
               FROM orders o
          LEFT JOIN (SELECT order_id, SUM(amount) AS refunded FROM order_refunds GROUP BY order_id) r ON r.order_id = o.id
              WHERE o.status IN ('" . implode("', '", Customers::SPENDING) . "')
                AND o.currency = :cur AND o.created_at >= :start AND o.created_at < :end
           ORDER BY o.created_at"
        );
        $stmt->execute([':cur' => Money::currency(), ':start' => $start, ':end' => $end]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
