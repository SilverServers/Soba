<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Helpers\Dates;
use Cms\Models\Order;

/**
 * THE ORDERS AS A SPREADSHEET, FOR THE BOOKKEEPER.
 *
 * One row an order: when, its status, who and where it was taxed, how it was
 * paid and the bank's reference, then the money: the goods, the discount, the
 * delivery, a column for each tax that any of the orders charged ("GST (5%)",
 * "PST (7%)"), the total, what was refunded, and what the order nets.
 *
 * ── PLAIN AMOUNTS IN THE ORDER'S OWN CURRENCY ──────────────────────────────
 * `149.00`, not `$149.00`: a spreadsheet adds a column of numbers and not a
 * column of words. The currency has a column of its own.
 *
 * ── A CELL IS NEVER A FORMULA ──────────────────────────────────────────────
 * A name a customer typed as `=HYPERLINK(...)` would be run by the
 * spreadsheet that opens the file. A text cell that starts with `=`, `+`,
 * `-`, `@` or a tab is written with a `'` in front, which spreadsheets show as
 * text. Amounts are written by this class and never start with one.
 */
final class OrderExport
{
    /**
     * @param list<array<string, mixed>> $orders as `Order::all()` returns them
     */
    public static function csv(array $orders): string
    {
        // ── A COLUMN FOR EACH TAX ANY OF THEM CHARGED, in the order first met.
        $taxes = [];
        $lines = [];
        foreach ($orders as $order) {
            $id = (int) $order['id'];
            foreach (Totals::ofOrder($order)['taxLines'] as $line) {
                $name = TaxRates::line((string) $line['label'], (int) $line['rate']);
                $taxes[$name] = true;
                $lines[$id][$name] = ($lines[$id][$name] ?? 0) + (int) $line['amount'];
            }
        }
        $taxNames = array_keys($taxes);

        $rows   = [];
        $rows[] = array_merge(
            ['Order', 'Date', 'Status', 'Customer', 'Email', 'Country', 'Province or state', 'Payment', 'Reference', 'Currency',
             'Goods', 'Discount', 'Delivery'],
            $taxNames,
            ['Total', 'Refunded', 'Net']
        );
        foreach ($orders as $order) {
            $id       = (int) $order['id'];
            $currency = (string) $order['currency'];
            $money    = static fn (int $n): string => Money::edit($n, $currency);
            $refunded = (int) array_sum(array_column(Refund::forOrder($id), 'amount'));
            $rows[]   = array_merge(
                [
                    Order::number($order),
                    Dates::format((string) $order['created_at'], 'Y-m-d H:i'),
                    Order::statusLabel((string) $order['status']),
                    self::text((string) $order['billing_name']),
                    self::text((string) $order['email']),
                    (string) $order['tax_country'],
                    (string) $order['tax_region'],
                    self::text((string) $order['payment_method_title']),
                    self::text((string) $order['transaction_ref']),
                    $currency,
                    $money((int) $order['subtotal']),
                    $money((int) $order['discount_total']),
                    $money((int) $order['shipping_total']),
                ],
                array_map(static fn (string $tax): string => $money((int) ($lines[$id][$tax] ?? 0)), $taxNames),
                [$money((int) $order['total']), $money($refunded), $money((int) $order['total'] - $refunded)]
            );
        }

        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** A cell typed by somebody, kept from being read as a formula. */
    private static function text(string $value): string
    {
        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
    }
}
