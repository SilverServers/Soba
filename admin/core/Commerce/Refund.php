<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Database\Database;
use Cms\Helpers\Dates;
use Cms\Models\Order;
use PDO;

/**
 * MONEY GIVEN BACK ON AN ORDER.
 *
 * ── BY THE LINE, AT THE PRICE IT WAS SOLD AT ────────────────────────────────
 * A refund names the lines that came back and how many: the goods at the price
 * on the order, less their share of any discount, with whatever delivery the
 * owner gives back, and the tax on both at the order's own rates. A refund of
 * every line and all the delivery therefore comes to what was charged. An
 * amount typed instead is honoured, and its tax is the same share of it.
 *
 * THE DISCOUNT'S SHARE IS BY VALUE: a code that took $10 off a $100 basket
 * comes off a $40 jacket's refund as $4. A code that reached only some of the
 * goods was taken off those, and the order does not say which, so a refund of
 * one of them may want its amount typed.
 *
 * ── THE STOCK FOLLOWS THE OWNER'S TICK ──────────────────────────────────────
 * Goods that came back go on the shelf when the box is ticked; goods the
 * customer kept, or that came back damaged, do not. `restocked_qty` keeps
 * count, so the order cancelled or deleted later gives back only the rest.
 *
 * ── ALL OF IT, EITHER WAY ───────────────────────────────────────────────────
 * The refund that leaves nothing moves the order to Refunded; and an order
 * moved to Refunded by its status records whatever was left as a refund
 * (`remainder()`), so the two ways of saying "all of it went back" leave the
 * same record: the refunds add up to the order's total, tax line by tax line.
 */
final class Refund
{
    /**
     * An order's refunds, oldest first, each with its `items` and `taxLines`.
     *
     * @return list<array<string, mixed>>
     */
    public static function forOrder(int $orderId): array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM order_refunds WHERE order_id = :id ORDER BY created_at, id');
        $stmt->execute([':id' => $orderId]);
        $refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($refunds === []) {
            return [];
        }

        $items = $db->prepare(
            'SELECT ri.quantity, ri.amount, ri.order_item_id, oi.name, oi.variation_label
               FROM order_refund_items ri JOIN order_items oi ON oi.id = ri.order_item_id
              WHERE ri.refund_id = :r ORDER BY ri.id'
        );
        $tax = $db->prepare('SELECT label, rate, amount, position FROM order_refund_tax_lines WHERE refund_id = :r ORDER BY position, id');
        foreach ($refunds as $i => $refund) {
            $items->execute([':r' => (int) $refund['id']]);
            $refunds[$i]['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
            $tax->execute([':r' => (int) $refund['id']]);
            $refunds[$i]['taxLines'] = $tax->fetchAll(PDO::FETCH_ASSOC);
        }

        return $refunds;
    }

    /**
     * What is left to refund: the money, the delivery, and each tax line, by
     * the line's place on the order.
     *
     * @return array{amount: int, shipping: int, taxLines: array<int, int>}
     */
    public static function left(array $order): array
    {
        $refunds = self::forOrder((int) $order['id']);

        $taxLeft = [];
        foreach (Totals::ofOrder($order)['taxLines'] as $i => $line) {
            $taxLeft[$i] = (int) $line['amount'];
        }
        foreach ($refunds as $refund) {
            foreach ($refund['taxLines'] as $line) {
                $at = (int) $line['position'];
                $taxLeft[$at] = ($taxLeft[$at] ?? 0) - (int) $line['amount'];
            }
        }

        return [
            'amount'   => max(0, (int) $order['total'] - (int) array_sum(array_column($refunds, 'amount'))),
            'shipping' => max(0, (int) $order['shipping_total'] - (int) array_sum(array_column($refunds, 'shipping'))),
            'taxLines' => array_map(static fn (int $n): int => max(0, $n), $taxLeft),
        ];
    }

    /**
     * What a refund of these lines, and this much delivery, comes to.
     *
     * @param array<string, mixed>       $order
     * @param list<array<string, mixed>> $items `Order::items()`
     * @param array<int, int>            $qty   order_items.id => how many
     * @return array{goods: int, discount: int, shipping: int, tax: int, total: int,
     *               taxLines: list<array{label: string, rate: int, amount: int}>,
     *               lines: list<array{item: array<string, mixed>, quantity: int, amount: int}>}
     */
    public static function quote(array $order, array $items, array $qty, int $shipping): array
    {
        $lines = [];
        $goods = 0;
        foreach ($items as $item) {
            $q = max(0, (int) ($qty[(int) $item['id']] ?? 0));
            if ($q === 0) {
                continue;
            }
            $amount  = (int) $item['unit_price'] * $q;
            $goods  += $amount;
            $lines[] = ['item' => $item, 'quantity' => $q, 'amount' => $amount];
        }

        $subtotal = (int) $order['subtotal'];
        $discount = 0;
        if ($goods > 0 && $subtotal > 0 && (int) $order['discount_total'] > 0) {
            $discount = min($goods, intdiv((int) $order['discount_total'] * $goods * 2 + $subtotal, $subtotal * 2));
        }

        $shipping  = max(0, $shipping);
        $inclusive = !empty($order['tax_inclusive']);
        $rates     = array_map(
            static fn (array $l): array => ['label' => (string) $l['label'], 'rate' => (int) $l['rate']],
            Totals::ofOrder($order)['taxLines']
        );
        $taxLines = Tax::lines($goods - $discount + $shipping, $rates, $inclusive);
        $tax      = (int) array_sum(array_column($taxLines, 'amount'));

        return [
            'goods'    => $goods,
            'discount' => $discount,
            'shipping' => $shipping,
            'tax'      => $tax,
            'total'    => $goods - $discount + $shipping + ($inclusive ? 0 : $tax),
            'taxLines' => $taxLines,
            'lines'    => $lines,
        ];
    }

    /**
     * What a refund of these lines and this much delivery records.
     *
     * ── EVERYTHING THAT IS LEFT, CHOSEN, IS WHAT IS LEFT ────────────────────
     * Every line's remaining count and all the delivery still owed: the
     * refund is the rest of the order to the cent, and each tax line the rest
     * of it. Worked out from the parts instead, earlier refunds' rounding
     * could make it a cent more than is left, and refused, or a cent less,
     * and leave one on an order that was all given back.
     *
     * The figure beside the refund button is this, so it is what is recorded.
     *
     * @param array<int, int> $chosen order_items.id => how many
     * @return array{amount: int, taxLines: list<array{label: string, rate: int, amount: int}>,
     *               everything: bool, quote: array<string, mixed>, left: array<string, mixed>}
     */
    public static function workOut(array $order, array $items, array $chosen, int $shipping): array
    {
        $left  = self::left($order);
        $quote = self::quote($order, $items, $chosen, $shipping);

        $everything = ($chosen !== [] || $shipping > 0) && $shipping === $left['shipping'];
        foreach ($items as $item) {
            $most = (int) $item['quantity'] - (int) $item['refunded_qty'];
            if ($most > 0 && (int) ($chosen[(int) $item['id']] ?? 0) !== $most) {
                $everything = false;
            }
        }

        if (!$everything) {
            return ['amount' => $quote['total'], 'taxLines' => $quote['taxLines'], 'everything' => false,
                    'quote' => $quote, 'left' => $left];
        }

        $taxLines = [];
        foreach (Totals::ofOrder($order)['taxLines'] as $i => $line) {
            $taxLines[] = ['label' => (string) $line['label'], 'rate' => (int) $line['rate'],
                           'amount' => (int) ($left['taxLines'][$i] ?? 0)];
        }

        return ['amount' => $left['amount'], 'taxLines' => $taxLines, 'everything' => true,
                'quote' => $quote, 'left' => $left];
    }

    /**
     * Record a refund the owner asked for, and do what it says: the goods back
     * on the shelf when ticked, the customer told, and the order moved to
     * Refunded when nothing is left.
     *
     * @param array<int|string, mixed> $qty order_items.id => how many, as typed
     * @return string|null what stops it, in a sentence, or null when recorded
     */
    public static function record(
        int $orderId,
        array $qty,
        string $shippingTyped,
        string $amountTyped,
        bool $restock,
        string $reason,
        string $by = ''
    ): ?string {
        $order = Order::find($orderId);
        if ($order === null) {
            return 'That order no longer exists.';
        }
        $currency = (string) $order['currency'];
        $items    = Order::items($orderId);
        $left     = self::left($order);

        // ── EACH LINE, WITHIN WHAT IT HAS LEFT ─────────────────────────────
        $chosen = [];
        foreach ($items as $item) {
            $raw = trim((string) ($qty[(int) $item['id']] ?? ''));
            if ($raw === '' || $raw === '0') {
                continue;
            }
            if (!ctype_digit($raw)) {
                return 'How many of ' . self::name($item) . ' to refund has to be a whole number.';
            }
            $most = (int) $item['quantity'] - (int) $item['refunded_qty'];
            if ((int) $raw > $most) {
                return $most === 0
                    ? self::name($item) . ' has already been refunded.'
                    : 'Only ' . $most . ' of ' . self::name($item) . ' can be refunded.';
            }
            $chosen[(int) $item['id']] = (int) $raw;
        }

        $shipping = trim($shippingTyped) === '' ? 0 : Money::parse($shippingTyped, $currency);
        if ($shipping === null) {
            return 'The delivery to refund is not an amount.';
        }
        if ($shipping > $left['shipping']) {
            return 'Only ' . Money::format($left['shipping'], $currency) . ' of the delivery is left to refund.';
        }

        $worked   = self::workOut($order, $items, $chosen, $shipping);
        $quote    = $worked['quote'];
        $amount   = $worked['amount'];
        $taxLines = $worked['taxLines'];

        // ── AN AMOUNT TYPED INSTEAD ────────────────────────────────────────
        // Its tax is the same share of it as the chosen lines' tax was of
        // theirs, or, with nothing chosen, as the order's tax was of its total.
        if (trim($amountTyped) !== '') {
            $typed = Money::parse($amountTyped, $currency);
            if ($typed === null) {
                return 'The amount to refund is not an amount.';
            }
            if ($typed !== $amount) {
                $basis      = $quote['total'] > 0 ? $quote['taxLines'] : Totals::ofOrder($order)['taxLines'];
                $basisTotal = $quote['total'] > 0 ? $quote['total'] : (int) $order['total'];
                $share      = static fn (int $n): int => $basisTotal > 0
                    ? intdiv($n * $typed * 2 + $basisTotal, $basisTotal * 2) : 0;
                $taxLines   = array_map(static fn (array $l): array => [
                    'label'  => (string) $l['label'],
                    'rate'   => (int) $l['rate'],
                    'amount' => $share((int) $l['amount']),
                ], array_values($basis));
                // AND THE DELIVERY IS THE SAME SHARE: $5 typed against $9.45
                // of delivery and its tax gave back part of the delivery, and
                // the rest of it can still be refunded.
                $shipping = $quote['total'] > 0 ? min($share($shipping), $left['shipping']) : 0;
                $amount   = $typed;
            }
        }

        if ($amount <= 0) {
            return 'Choose what to refund: how many of an item, some of the delivery, or an amount.';
        }
        if ($amount > $left['amount']) {
            return 'Only ' . Money::format($left['amount'], $currency) . ' is left to refund on this order.';
        }

        // THE LAST OF IT TAKES EXACTLY WHAT IS LEFT OF EACH TAX, so the
        // order's refunds add up to its tax to the cent; none takes more.
        $completes = $amount === $left['amount'];
        foreach ($taxLines as $i => $line) {
            $taxLines[$i]['amount'] = $completes
                ? (int) ($left['taxLines'][$i] ?? 0)
                : min((int) $line['amount'], (int) ($left['taxLines'][$i] ?? 0));
        }

        $restocked = $restock && !empty($order['stock_reduced']) && $chosen !== [];
        $refundId  = self::write($order, $items, $chosen, $amount, $shipping, $taxLines, $reason, $restocked, true, $by);

        self::afterRecording($order, $refundId, $by);

        // ── NOTHING LEFT: THE ORDER IS REFUNDED ────────────────────────────
        // It no longer holds stock: what came back went on the shelf above, as
        // ticked, and the rest stays with the customer. Cleared first, so the
        // status change does not put back goods the owner chose not to.
        if ($completes && (string) $order['status'] !== 'refunded') {
            $stmt = Database::getInstance()->prepare('UPDATE orders SET stock_reduced = 0 WHERE id = :id');
            $stmt->execute([':id' => $orderId]);
            Order::setStatus($orderId, 'refunded', $by);
        }

        return null;
    }

    /**
     * Whatever is left of an order, refunded whole: what moving it to Refunded
     * by its status records. Its goods are the ones not refunded already, and
     * whether they went back on the shelf was the status change's to decide.
     */
    public static function remainder(int $orderId, bool $restocked, string $by = ''): void
    {
        $order = Order::find($orderId);
        if ($order === null) {
            return;
        }
        $left = self::left($order);
        if ($left['amount'] <= 0) {
            return;
        }

        $items = Order::items($orderId);
        $qty   = [];
        foreach ($items as $item) {
            $q = (int) $item['quantity'] - (int) $item['refunded_qty'];
            if ($q > 0) {
                $qty[(int) $item['id']] = $q;
            }
        }
        $taxLines = [];
        foreach (Totals::ofOrder($order)['taxLines'] as $i => $line) {
            $taxLines[] = ['label' => (string) $line['label'], 'rate' => (int) $line['rate'],
                           'amount' => (int) ($left['taxLines'][$i] ?? 0)];
        }

        $refundId = self::write($order, $items, $qty, $left['amount'], $left['shipping'], $taxLines, '', $restocked, false, $by);
        self::afterRecording($order, $refundId, $by);
    }

    /**
     * The lines an order prints under its total once anything has gone back:
     * each refund, and what the order nets.
     *
     * @return list<array{0: string, 1: int}> label, amount (a refund negative)
     */
    public static function rows(array $order): array
    {
        $refunds = self::forOrder((int) $order['id']);
        if ($refunds === []) {
            return [];
        }

        $rows = [];
        foreach ($refunds as $refund) {
            $reason = trim((string) $refund['reason']);
            $rows[] = ['Refunded ' . Dates::format((string) $refund['created_at'], 'j M Y')
                       . ($reason !== '' ? ' · ' . $reason : ''), -(int) $refund['amount']];
        }
        $rows[] = ['Net payment', (int) $order['total'] - (int) array_sum(array_column($refunds, 'amount'))];

        return $rows;
    }

    /** How a line is named in a sentence: "Field Jacket (M)". */
    public static function name(array $item): string
    {
        $label = trim((string) ($item['variation_label'] ?? ''));

        return (string) $item['name'] . ($label !== '' ? ' (' . $label . ')' : '');
    }

    /**
     * The refund's rows, its lines' counts and, when it says so, the shelf:
     * all of it or none of it.
     *
     * @param array<int, int> $qty
     * @param list<array{label: string, rate: int, amount: int}> $taxLines in the order's own order
     */
    private static function write(
        array $order,
        array $items,
        array $qty,
        int $amount,
        int $shipping,
        array $taxLines,
        string $reason,
        bool $restocked,
        bool $restockLines,
        string $by
    ): int {
        $db  = Database::getInstance();
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            $stmt = $db->prepare(
                'INSERT INTO order_refunds (order_id, amount, shipping, tax, reason, restocked, author)
                 VALUES (:o, :a, :s, :t, :r, :k, :b)'
            );
            $stmt->execute([
                ':o' => (int) $order['id'],
                ':a' => $amount,
                ':s' => $shipping,
                ':t' => (int) array_sum(array_column($taxLines, 'amount')),
                ':r' => mb_substr(trim($reason), 0, 255),
                ':k' => $restocked ? 1 : 0,
                ':b' => mb_substr($by, 0, 120),
            ]);
            $refundId = (int) $db->lastInsertId();

            $line  = $db->prepare('INSERT INTO order_refund_items (refund_id, order_item_id, quantity, amount) VALUES (:r, :i, :q, :a)');
            $count = $db->prepare(
                'UPDATE order_items SET refunded_qty = refunded_qty + :q, restocked_qty = restocked_qty + :k WHERE id = :i'
            );
            $shelf = [];
            foreach ($items as $item) {
                $q = (int) ($qty[(int) $item['id']] ?? 0);
                if ($q <= 0) {
                    continue;
                }
                $line->execute([':r' => $refundId, ':i' => (int) $item['id'], ':q' => $q, ':a' => (int) $item['unit_price'] * $q]);
                $back = $restocked && $restockLines ? $q : 0;
                $count->execute([':q' => $q, ':k' => $back, ':i' => (int) $item['id']]);
                if ($back > 0) {
                    $shelf[] = ['post_id' => $item['post_id'], 'variation_id' => $item['variation_id'], 'quantity' => $back];
                }
            }

            $tax = $db->prepare(
                'INSERT INTO order_refund_tax_lines (refund_id, label, rate, amount, position) VALUES (:r, :l, :t, :a, :p)'
            );
            foreach (array_values($taxLines) as $i => $taxLine) {
                if ((int) $taxLine['amount'] === 0) {
                    continue;
                }
                $tax->execute([':r' => $refundId, ':l' => (string) $taxLine['label'], ':t' => (int) $taxLine['rate'],
                               ':a' => (int) $taxLine['amount'], ':p' => $i]);
            }

            // BACK TO THE SHELF EACH CAME OFF, the option's or the product's.
            StockLog::during('refunded', (int) $order['id'], $by, static fn () => Stock::giveForOrder($shelf));

            if ($own) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                $db->rollBack();
            }
            throw $e;
        }

        return $refundId;
    }

    /** The order's note for a refund, and the customer's email. */
    private static function afterRecording(array $order, int $refundId, string $by): void
    {
        $refund = null;
        foreach (self::forOrder((int) $order['id']) as $r) {
            if ((int) $r['id'] === $refundId) {
                $refund = $r;
            }
        }
        if ($refund === null) {
            return;
        }

        $what = array_map(
            static fn (array $i): string => (int) $i['quantity'] . ' × ' . self::name($i),
            $refund['items']
        );
        if ((int) $refund['shipping'] > 0) {
            $what[] = 'delivery';
        }
        $reason = trim((string) $refund['reason']);
        Order::addNote(
            (int) $order['id'],
            'Refunded ' . Money::format((int) $refund['amount'], (string) $order['currency'])
                . ($what !== [] ? ': ' . implode(', ', $what) : '') . '.'
                . ($reason !== '' ? ' Reason: ' . $reason . '.' : '')
                . ((int) $refund['restocked'] === 1 ? ' Put back in stock.' : ''),
            false,
            $by !== '' ? $by : 'Shop'
        );

        OrderMail::refunded((int) $order['id'], $refund, $by);
    }
}
