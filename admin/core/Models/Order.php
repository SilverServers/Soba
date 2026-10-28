<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Commerce\Money;
use Cms\Commerce\Tax;
use Cms\Commerce\Totals;
use Cms\Database\Database;
use PDO;

/**
 * AN ORDER, AND THE MOMENT ITS PRICES STOP MOVING.
 *
 * `migrations/041_commerce_orders.sql` carries the reasoning for the schema —
 * read it before changing anything here, because most of what looks like
 * pointless denormalisation in this file is load-bearing.
 *
 * The one-sentence version: a product is editable and an order is not. Every
 * name, SKU, price and payment-method title is COPIED at `place()` and never
 * read back through a join, so putting a product on sale cannot restate an
 * order already paid.
 */
final class Order
{
    /**
     * The statuses, and what each one means to the owner.
     *
     * These are the statuses online shops commonly use, because a shop owner
     * already knows what "processing" means and inventing a different word for
     * it teaches them nothing. `failed` is here for a gateway that does not
     * exist yet — see the migration's note.
     */
    public const STATUSES = [
        'pending'    => 'Pending payment',
        'processing' => 'Processing',
        'on-hold'    => 'On hold',
        'completed'  => 'Completed',
        'cancelled'  => 'Cancelled',
        'refunded'   => 'Refunded',
        'failed'     => 'Failed',
    ];

    /** Statuses that mean the shop has the money. */
    public const PAID = ['processing', 'completed'];

    public static function statusLabel(string $status): string
    {
        return self::STATUSES[$status] ?? $status;
    }

    // ── Reading ─────────────────────────────────────────────────────────────

    public static function find(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM orders WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * The lookup a guest uses, by the unguessable key.
     *
     * SEPARATE FROM find() ON PURPOSE. If the order-received page took an id,
     * `/order/124` would invite `/order/125`, and that is a stranger's name,
     * address and telephone number. There are no public accounts to gate it
     * behind (see `FieldTypes`' note on User ID), so the key IS the credential
     * and nothing public may accept an id instead.
     */
    public static function findByKey(string $key): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
            return null;
        }

        $stmt = Database::getInstance()->prepare('SELECT * FROM orders WHERE order_key = :k');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public static function items(int $orderId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM order_items WHERE order_id = :id ORDER BY position, id'
        );
        $stmt->execute([':id' => $orderId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public static function notes(int $orderId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM order_notes WHERE order_id = :id ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([':id' => $orderId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The order list, newest first, optionally narrowed to one status.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(?string $status = null, int $limit = 200): array
    {
        $sql    = 'SELECT o.*, (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi
                                 WHERE oi.order_id = o.id) AS item_count
                     FROM orders o';
        $params = [];

        if ($status !== null && isset(self::STATUSES[$status])) {
            $sql .= ' WHERE o.status = :status';
            $params[':status'] = $status;
        }

        $sql .= ' ORDER BY o.created_at DESC, o.id DESC LIMIT ' . max(1, min(1000, $limit));

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** status => how many, for the filter row. Every status, including zeros. */
    public static function countsByStatus(): array
    {
        $out = array_fill_keys(array_keys(self::STATUSES), 0);

        $stmt = Database::getInstance()->query('SELECT status, COUNT(*) AS n FROM orders GROUP BY status');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }

        return $out;
    }

    // ── Writing ─────────────────────────────────────────────────────────────

    /**
     * Write an order and its lines, or write neither.
     *
     * ── ONE TRANSACTION, AND THE REASON IS NOT TIDINESS ────────────────────
     * An `orders` row without its `order_items` is an order for nothing, with a
     * total, that the customer has been told is placed. It is worse than a
     * failed checkout because it looks like a success to everyone: the customer
     * has a receipt, the owner has a line in the list, and neither can tell
     * what was bought. If the second insert fails, the first must not survive.
     *
     * ── THE CALLER PASSES LINES, NOT A CART ────────────────────────────────
     * `Checkout` re-reads the cart and builds the lines; this writes them. That
     * seam is what lets the order be built from something other than a session
     * later — a phone order typed by the owner, an import — without this method
     * learning about `$_SESSION`.
     *
     * @param array<string, mixed> $fields  customer + totals, keys matching columns
     * @param list<array{post_id: ?int, name: string, sku: string,
     *                   quantity: int, unit_price: int}> $lines
     * @return array{id: int, key: string}
     */
    public static function place(array $fields, array $lines): array
    {
        $db  = Database::getInstance();
        $key = bin2hex(random_bytes(16));

        // Money columns are recomputed HERE from the lines rather than trusted
        // from $fields. A caller that passes a subtotal is passing an opinion;
        // the sum of the lines is the fact, and they must not be able to
        // disagree on a row that is about to become a receipt.
        $subtotal = 0;
        foreach ($lines as $line) {
            $subtotal += ((int) $line['unit_price']) * ((int) $line['quantity']);
        }

        // ── AND THE TAX IS NOT TAKEN FROM THE CALLER EITHER ────────────────
        // Same reasoning as the subtotal above, one step further. A caller that
        // passes a tax figure is passing an opinion; the rate the shop has set,
        // applied to the amount this order is actually for, is the fact.
        //
        // `Commerce\Totals` is the ONE place that adds an order up — the
        // basket, the checkout page, this row, the receipt and the customer's
        // email all ask it, so they cannot disagree about what an order comes
        // to. Shipping and discount still arrive from the caller because they
        // are decided AT the checkout; the tax on them is computed here.
        $totals   = Totals::of(
            $subtotal,
            (int) ($fields['shipping_total'] ?? 0),
            (int) ($fields['discount_total'] ?? 0)
        );
        $shipping = $totals['shipping'];
        $tax      = $totals['tax'];
        $discount = $totals['discount'];
        $total    = $totals['total'];

        $columns = [
            'order_key'            => $key,
            'status'               => isset(self::STATUSES[(string) ($fields['status'] ?? '')])
                                        ? (string) $fields['status'] : 'pending',
            // WHETHER THIS ORDER IS HOLDING STOCK RIGHT NOW. Migration 042's
            // header has the reasoning: it is a fact about the present, not a
            // history to infer from, which is what makes cancelling twice safe.
            'stock_reduced'        => !empty($fields['stock_reduced']) ? 1 : 0,
            'currency'             => Money::currency(),
            'subtotal'             => $subtotal,
            'shipping_total'       => $shipping,
            'tax_total'            => $tax,
            // ── THE RATE AND ITS NAME, AS THEY READ TODAY ──────────────────
            // Copied onto the row for the same reason `payment_method_title`
            // is: settings change, and a receipt is a record of what happened
            // rather than a view of what is currently configured. An owner
            // raising their rate in April must not silently restate every
            // receipt they issued in March. Migration 044 has the reasoning.
            'tax_rate'             => Tax::rate(),
            'tax_label'            => Tax::label(),
            'tax_inclusive'        => Tax::isInclusive() ? 1 : 0,
            // WHICH CODE, not which coupon id — the fourth time this subsystem
            // makes that call. A coupon renamed or deleted next year must not
            // rewrite a receipt issued today, and `discount_total` on its own is
            // a number an owner cannot account for.
            'coupon_code'          => (string) ($fields['coupon_code'] ?? ''),
            'coupon_redeemed'      => !empty($fields['coupon_redeemed']) ? 1 : 0,
            'discount_total'       => $discount,
            'total'                => $total,
            'payment_method'       => (string) ($fields['payment_method'] ?? ''),
            'payment_method_title' => (string) ($fields['payment_method_title'] ?? ''),
            'email'                => (string) ($fields['email'] ?? ''),
            'phone'                => (string) ($fields['phone'] ?? ''),
            'billing_name'         => (string) ($fields['billing_name'] ?? ''),
            'billing_line1'        => (string) ($fields['billing_line1'] ?? ''),
            'billing_line2'        => (string) ($fields['billing_line2'] ?? ''),
            'billing_city'         => (string) ($fields['billing_city'] ?? ''),
            'billing_region'       => (string) ($fields['billing_region'] ?? ''),
            'billing_post'         => (string) ($fields['billing_post'] ?? ''),
            'billing_country'      => (string) ($fields['billing_country'] ?? ''),
            'shipping_name'        => (string) ($fields['shipping_name'] ?? ''),
            'shipping_line1'       => (string) ($fields['shipping_line1'] ?? ''),
            'shipping_line2'       => (string) ($fields['shipping_line2'] ?? ''),
            'shipping_city'        => (string) ($fields['shipping_city'] ?? ''),
            'shipping_region'      => (string) ($fields['shipping_region'] ?? ''),
            'shipping_post'        => (string) ($fields['shipping_post'] ?? ''),
            'shipping_country'     => (string) ($fields['shipping_country'] ?? ''),
            'customer_note'        => (string) ($fields['customer_note'] ?? ''),
        ];

        $db->beginTransaction();
        try {
            $names        = array_keys($columns);
            $placeholders = array_map(static fn (string $c): string => ':' . $c, $names);

            $stmt = $db->prepare(
                'INSERT INTO orders (' . implode(', ', $names) . ')
                 VALUES (' . implode(', ', $placeholders) . ')'
            );
            $bind = [];
            foreach ($columns as $c => $v) {
                $bind[':' . $c] = $v;
            }
            $stmt->execute($bind);

            $orderId = (int) $db->lastInsertId();

            $itemStmt = $db->prepare(
                'INSERT INTO order_items
                    (order_id, post_id, variation_id, variation_label,
                     name, sku, quantity, unit_price, line_total, position)
                 VALUES (:order_id, :post_id, :variation_id, :variation_label,
                         :name, :sku, :quantity, :unit_price, :line_total, :position)'
            );
            foreach (array_values($lines) as $i => $line) {
                $qty  = (int) $line['quantity'];
                $unit = (int) $line['unit_price'];
                $itemStmt->execute([
                    ':order_id'   => $orderId,
                    ':post_id'    => $line['post_id'] !== null ? (int) $line['post_id'] : null,
                    // THE ID MAY POINT AT NOTHING LATER and that is allowed —
                    // the LABEL beside it is what a human reads off a packing
                    // slip, and the two have different jobs. Migration 046.
                    ':variation_id' => isset($line['variation_id']) && $line['variation_id'] !== null
                                        ? (int) $line['variation_id'] : null,
                    ':variation_label' => (string) ($line['variation_label'] ?? ''),
                    ':name'       => (string) $line['name'],
                    ':sku'        => (string) ($line['sku'] ?? ''),
                    ':quantity'   => $qty,
                    ':unit_price' => $unit,
                    ':line_total' => $unit * $qty,
                    ':position'   => $i,
                ]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return ['id' => $orderId, 'key' => $key];
    }

    /**
     * Move an order to another status, and record that it happened.
     *
     * `paid_at` is stamped the FIRST time an order reaches a paid status and
     * never restamped — an order refunded and then reinstated was still paid on
     * the original day, and overwriting it would lose the only record of when
     * the money arrived.
     */
    public static function setStatus(int $id, string $status, string $by = ''): bool
    {
        if (!isset(self::STATUSES[$status])) {
            return false;
        }

        $order = self::find($id);
        if ($order === null || (string) $order['status'] === $status) {
            return false;
        }

        $db = Database::getInstance();

        $sql = 'UPDATE orders SET status = :s';
        if (in_array($status, self::PAID, true) && $order['paid_at'] === null) {
            $sql .= ', paid_at = NOW()';
        }
        $sql .= ' WHERE id = :id';

        $stmt = $db->prepare($sql);
        $stmt->execute([':s' => $status, ':id' => $id]);

        // ── THE SHELF FOLLOWS THE STATUS ───────────────────────────────────
        // Cancelled and refunded mean the shop still has the goods; every other
        // status means it does not. Both directions are driven by
        // `stock_reduced`, which says what is TRUE NOW rather than what the
        // previous status was — so clicking Cancel twice puts the stock back
        // once, and reinstating a cancelled order takes it again.
        //
        // Idempotent by construction, which is the only safe shape for
        // something a person can click twice on a slow page.
        $holds  = (bool) $order['stock_reduced'];
        $should = !in_array($status, ['cancelled', 'refunded'], true);

        // ── AND THE CODE'S USE FOLLOWS THE STATUS TOO ──────────────────────
        // Exactly the shape above, on its own flag: a cancelled order did not
        // spend the coupon, and a reinstated one did. `coupon_redeemed` says
        // what is TRUE NOW rather than what the previous status was, so
        // cancelling twice puts one use back and not two.
        //
        // REINSTATING CANNOT FAIL THE ORDER. If the code has since been spent
        // to its limit by somebody else, the order still moves — the owner
        // asked for it — and the note says so, which is the same call the stock
        // branch makes for the same reason.
        $code = trim((string) ($order['coupon_code'] ?? ''));
        if ($code !== '') {
            $held = (bool) ($order['coupon_redeemed'] ?? false);
            if ($held !== $should) {
                if ($should) {
                    $coupon = \Cms\Commerce\Coupon::find($code);
                    $took   = $coupon !== null
                        && \Cms\Commerce\Coupon::redeem((int) $coupon['id']);
                    if (!$took) {
                        self::addNote($id, 'Reinstated, but the code ' . $code
                            . ' could not be counted again — it may have been '
                            . 'used up or withdrawn since.', false, $by);
                    }
                    $held = $took;
                } else {
                    \Cms\Commerce\Coupon::release($code);
                    $held = false;
                }

                $cflag = $db->prepare('UPDATE orders SET coupon_redeemed = :f WHERE id = :id');
                $cflag->execute([':f' => $held ? 1 : 0, ':id' => $id]);
            }
        }

        if ($holds !== $should) {
            $items = self::items($id);
            if ($should) {
                // REINSTATED. If the stock has gone in the meantime the order
                // still moves — the owner asked for it — but the note says so,
                // because a shop that silently goes negative is worse than one
                // that tells you it is short.
                $names = array_map(static fn (array $i): string => (string) $i['name'], $items);
                // THE VARIANT COMES WITH IT. `takeForOrder()` decides which
                // shelf to take from, and a reinstated order whose lines had
                // lost their `variation_id` would take the count off the
                // PRODUCT instead — inventing a unit in one place and losing
                // one in another, on a screen that said "Processing".
                $lines = array_map(static fn (array $i): array => [
                    'post_id'      => $i['post_id'] !== null ? (int) $i['post_id'] : null,
                    'variation_id' => $i['variation_id'] !== null ? (int) $i['variation_id'] : null,
                    'quantity'     => (int) $i['quantity'],
                ], $items);
                $took = \Cms\Commerce\Stock::takeForOrder($lines, $names);
                if (!$took['ok']) {
                    self::addNote($id, 'Reinstated, but there was not enough stock for '
                        . $took['short'] . '. Check the count before shipping.', false, $by);
                }
                $holds = $took['ok'];
            } else {
                \Cms\Commerce\Stock::giveForOrder($items);
                $holds = false;
            }

            $flag = $db->prepare('UPDATE orders SET stock_reduced = :f WHERE id = :id');
            $flag->execute([':f' => $holds ? 1 : 0, ':id' => $id]);
        }

        self::addNote(
            $id,
            'Status changed from ' . self::statusLabel((string) $order['status'])
                . ' to ' . self::statusLabel($status) . '.',
            false,
            $by
        );

        return true;
    }

    public static function addNote(int $orderId, string $body, bool $toCustomer = false, string $author = ''): void
    {
        $body = trim($body);
        if ($body === '') {
            return;
        }

        $stmt = Database::getInstance()->prepare(
            'INSERT INTO order_notes (order_id, author, body, is_customer_note)
             VALUES (:id, :author, :body, :cust)'
        );
        $stmt->execute([
            ':id'     => $orderId,
            ':author' => $author,
            ':body'   => $body,
            ':cust'   => $toCustomer ? 1 : 0,
        ]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::getInstance()->prepare('DELETE FROM orders WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * The order number a person quotes down the telephone.
     *
     * The id, with a `#`. NOT the key — the key is a credential and reading it
     * aloud gives away the order. Not a separate sequence either: a second
     * number to reconcile is a second thing to get wrong, and the id is already
     * unique, already stable and already short.
     */
    public static function number(array $order): string
    {
        return '#' . (int) $order['id'];
    }
}
