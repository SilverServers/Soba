<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Commerce\Money;
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
     * The order list, newest first, optionally narrowed to one status and to
     * one month.
     *
     * THE MONTH IS THE SITE'S: October in Vancouver starts at 07:00 UTC on the
     * first, and an order placed at 9 pm on the 31st is October's, as the
     * order screen and the receipt date it.
     *
     * @param string|null $month 'YYYY-MM', in the site's own zone
     * @return list<array<string, mixed>>
     */
    public static function all(?string $status = null, int $limit = 200, ?string $month = null): array
    {
        $sql    = 'SELECT o.*, (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi
                                 WHERE oi.order_id = o.id) AS item_count
                     FROM orders o WHERE 1 = 1';
        $params = [];

        if ($status !== null && isset(self::STATUSES[$status])) {
            $sql .= ' AND o.status = :status';
            $params[':status'] = $status;
        }
        $bounds = $month !== null ? self::monthBounds($month) : null;
        if ($bounds !== null) {
            $sql .= ' AND o.created_at >= :from AND o.created_at < :to';
            $params[':from'] = $bounds[0];
            $params[':to']   = $bounds[1];
        }

        $sql .= ' ORDER BY o.created_at DESC, o.id DESC LIMIT ' . max(1, min(100000, $limit));

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * A month of the site's, as the UTC times it starts and ends at.
     *
     * @return array{0: string, 1: string}|null null for anything but 'YYYY-MM'
     */
    public static function monthBounds(string $month): ?array
    {
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month)) {
            return null;
        }
        $utc   = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable($month . '-01 00:00:00', \Cms\Helpers\Dates::zone());

        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $start->modify('+1 month')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /** The month an order was placed in, in the site's zone: 'YYYY-MM'. */
    public static function monthOf(array $order): string
    {
        return \Cms\Helpers\Dates::format((string) $order['created_at'], 'Y-m');
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
     * Where an order is taxed: the place the checkout handed in, else the
     * delivery address when there is one, else the billing address.
     *
     * @param  array<string, mixed> $fields
     * @return array{country: string, region: string}
     */
    private static function taxPlace(array $fields): array
    {
        if (isset($fields['tax_country'])) {
            $country = strtoupper(trim((string) $fields['tax_country']));
            $region  = (string) ($fields['tax_region'] ?? '');
        } elseif (trim((string) ($fields['shipping_country'] ?? '')) !== '') {
            $country = strtoupper(trim((string) $fields['shipping_country']));
            $region  = (string) ($fields['shipping_region'] ?? '');
        } else {
            $country = strtoupper(trim((string) ($fields['billing_country'] ?? '')));
            $region  = (string) ($fields['billing_region'] ?? '');
        }

        return ['country' => $country, 'region' => \Cms\Commerce\Regions::code($country, $region)];
    }

    /**
     * An order's tax lines, in the order they were charged.
     *
     * @return list<array{label: string, rate: int, amount: int}>
     */
    public static function taxLines(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT label, rate, amount FROM order_tax_lines WHERE order_id = :id ORDER BY position, id'
        );
        $stmt->execute([':id' => $orderId]);

        return array_map(static fn (array $r): array => [
            'label' => (string) $r['label'], 'rate' => (int) $r['rate'], 'amount' => (int) $r['amount'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

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
        // TAXED FOR WHERE IT GOES. The checkout says where — the delivery
        // address, the billing one, or the shop's own for an order collected
        // in person; a caller that does not is read from the addresses.
        $place = self::taxPlace($fields);
        $totals   = Totals::of(
            $subtotal,
            (int) ($fields['shipping_total'] ?? 0),
            (int) ($fields['discount_total'] ?? 0),
            '',
            $place
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
            'shipping_method'      => (string) ($fields['shipping_method'] ?? ''),
            'shipping_method_title' => mb_substr((string) ($fields['shipping_method_title'] ?? ''), 0, 255),
            'tax_total'            => $tax,
            // ── THE RATE AND ITS NAME, AS THEY READ TODAY ──────────────────
            // Copied onto the row for the same reason `payment_method_title`
            // is: settings change, and a receipt is a record of what happened
            // rather than a view of what is currently configured. An owner
            // raising their rate in April must not silently restate every
            // receipt they issued in March. Migration 044 has the reasoning.
            // ITS TAX IS ON ITS OWN LINES (`order_tax_lines`, below); the one
            // rate and name these two held before migration 096 stay empty.
            'tax_rate'             => 0,
            'tax_label'            => '',
            'tax_inclusive'        => $totals['taxIncluded'] ? 1 : 0,
            'tax_country'          => $place['country'],
            'tax_region'           => $place['region'],
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
            'terms_agreed'         => mb_substr((string) ($fields['terms_agreed'] ?? ''), 0, 255),
            'marketing_opt_in'     => !empty($fields['marketing_opt_in']) ? 1 : 0,
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

            // EACH TAX ON ITS OWN LINE — GST and QST are two figures on a
            // Quebec receipt, and each government is owed its own.
            $taxStmt = $db->prepare(
                'INSERT INTO order_tax_lines (order_id, label, rate, amount, position)
                 VALUES (:order_id, :label, :rate, :amount, :position)'
            );
            foreach ($totals['taxLines'] as $i => $taxLine) {
                $taxStmt->execute([
                    ':order_id' => $orderId,
                    ':label'    => (string) $taxLine['label'],
                    ':rate'     => (int) $taxLine['rate'],
                    ':amount'   => (int) $taxLine['amount'],
                    ':position' => $i,
                ]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // ANOTHER PROGRAM TOLD, IF IT ASKED (`Commerce\Webhooks`).
        \Cms\Commerce\Webhooks::emit('order.created', $orderId);

        return ['id' => $orderId, 'key' => $key];
    }

    /**
     * An order's paid date put back as it was, by an assistant's change undone:
     * the one thing that may unstamp it, because the move that stamped it is
     * being taken back and no money arrived that day.
     */
    public static function restorePaidAt(int $id, ?string $paidAt): void
    {
        Database::getInstance()->prepare('UPDATE orders SET paid_at = :at WHERE id = :id')->execute([':at' => $paidAt, ':id' => $id]);
    }

    /**
     * Move an order to another status, and record that it happened.
     *
     * `paid_at` is stamped the FIRST time an order reaches a paid status and
     * never restamped — an order refunded and then reinstated was still paid on
     * the original day, and overwriting it would lose the only record of when
     * the money arrived.
     *
     * `$tell` false moves it without the customer's email: an assistant's
     * change undone puts an order back where it was, and "your payment is
     * received" a second time is not news to them.
     */
    public static function setStatus(int $id, string $status, string $by = '', bool $tell = true): bool
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
        // WHETHER THIS CHANGE PUTS THE GOODS BACK: a refund recorded for the
        // rest of the order says so.
        $givesBack = $holds && !$should;

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
            $items = self::held(self::items($id));
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
                $took = \Cms\Commerce\StockLog::during('reinstated', $id, $by,
                    static fn (): array => \Cms\Commerce\Stock::takeForOrder($lines, $names));
                if (!$took['ok']) {
                    self::addNote($id, 'Reinstated, but there was not enough stock for '
                        . $took['short'] . '. Check the count before shipping.', false, $by);
                }
                $holds = $took['ok'];
            } else {
                \Cms\Commerce\StockLog::during($status === 'refunded' ? 'refunded' : 'cancelled', $id, $by,
                    static fn () => \Cms\Commerce\Stock::giveForOrder($items));
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

        // ── REFUNDED BY ITS STATUS: WHAT WAS LEFT WENT BACK ────────────────
        // Recorded as a refund, as one from the refund form would be, and the
        // customer told by that. Nothing is recorded when refunds already
        // came to the whole order.
        if ($status === 'refunded') {
            \Cms\Commerce\Refund::remainder($id, $givesBack, $by);
        }

        // ── AND THE CUSTOMER IS TOLD, WHEREVER THE CHANGE CAME FROM ────────
        // Paid and sent each have an email (Settings → Shop turns either off).
        // Sent from here rather than by the screen that asked, so a status
        // changed from the list, several at once, says the same as one changed
        // on the order. It never throws: a failure is a note on the order.
        if ($tell) {
            \Cms\Commerce\OrderMail::statusChanged($id, $status, $by);
        }

        // ── AND ANOTHER PROGRAM, IF IT ASKED (`Commerce\Webhooks`): paid when
        // it first reaches a paid status, and completed, cancelled or refunded.
        if (in_array($status, self::PAID, true) && !in_array((string) $order['status'], self::PAID, true)) {
            \Cms\Commerce\Webhooks::emit('order.paid', $id);
        }
        if (in_array($status, ['completed', 'cancelled', 'refunded'], true)) {
            \Cms\Commerce\Webhooks::emit('order.' . $status, $id);
        }

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

    /**
     * The payment's own reference, as the owner reads it off the bank: an
     * e-Transfer's reference number, a cheque's number.
     *
     * It is what matches the order to the money when the bank statement is
     * reconciled, so a change is a note with both values: a reference typed
     * over without a trace is a payment nobody can find again.
     */
    public static function setPaymentRef(int $id, string $ref, string $by = ''): bool
    {
        $order = self::find($id);
        if ($order === null) {
            return false;
        }

        $ref = mb_substr(trim($ref), 0, 120);
        $was = (string) $order['transaction_ref'];
        if ($ref === $was) {
            return false;
        }

        $stmt = Database::getInstance()->prepare('UPDATE orders SET transaction_ref = :r WHERE id = :id');
        $stmt->execute([':r' => $ref, ':id' => $id]);

        self::addNote($id, match (true) {
            $ref === '' => 'Payment reference ' . $was . ' removed.',
            $was === '' => 'Payment reference recorded: ' . $ref . '.',
            default     => 'Payment reference changed from ' . $was . ' to ' . $ref . '.',
        }, false, $by);

        return true;
    }

    /** Why a parcel's carrier, number and link cannot be kept, in a sentence, or null. */
    public static function trackingProblem(string $carrier, string $number, string $url): ?string
    {
        $carrier = trim($carrier);
        $number  = trim($number);
        $url     = trim($url);
        if (mb_strlen($carrier) > 60) {
            return 'The carrier\'s name is longer than 60 characters.';
        }
        if (mb_strlen($number) > 80) {
            return 'The tracking number is longer than 80 characters.';
        }
        if ($url !== '' && (strlen($url) > 500 || !preg_match('#^https?://#i', $url)
                || filter_var($url, FILTER_VALIDATE_URL) === false)) {
            return 'The tracking link has to be a web address starting with https:// or http://.';
        }

        return null;
    }

    /**
     * How the order went: the carrier, its tracking number, and the carrier's
     * own page for the parcel.
     *
     * A NUMBER TOO LONG IS REFUSED, NOT CUT. A tracking number with its last
     * digits missing finds somebody else's parcel or nothing, and the customer
     * is the one who finds out. The link is the carrier's, pasted as given:
     * only an http or https address, so nothing else can be put in front of a
     * customer as a link in the shop's own email.
     *
     * @return string|null what cannot be saved, in a sentence, or null when saved
     */
    public static function setTracking(int $id, string $carrier, string $number, string $url, string $by = ''): ?string
    {
        $order = self::find($id);
        if ($order === null) {
            return 'That order no longer exists.';
        }

        $carrier = trim($carrier);
        $number  = trim($number);
        $url     = trim($url);

        $problem = self::trackingProblem($carrier, $number, $url);
        if ($problem !== null) {
            return $problem;
        }

        $was = [(string) $order['tracking_carrier'], (string) $order['tracking_number'], (string) $order['tracking_url']];
        if ($was === [$carrier, $number, $url]) {
            return null;
        }

        $stmt = Database::getInstance()->prepare(
            'UPDATE orders SET tracking_carrier = :c, tracking_number = :n, tracking_url = :u WHERE id = :id'
        );
        $stmt->execute([':c' => $carrier, ':n' => $number, ':u' => $url, ':id' => $id]);

        $now = trim($carrier . ' ' . $number);
        self::addNote($id, $now === '' && $url === ''
            ? 'Tracking removed.'
            : 'Tracking recorded: ' . ($now !== '' ? $now : $url) . '.', false, $by);

        return null;
    }

    /**
     * Delete an order, and put back whatever it was holding.
     *
     * ── A DELETED ORDER WILL NEVER BE FILLED, SO ITS STOCK GOES BACK ────────
     * This took the rows and nothing else, so an unpaid order cleared out by
     * deleting rather than cancelling kept its goods off the shelf for ever:
     * the shop audit deleted an unpaid tote order and the count never came
     * back. `stock_reduced` and `coupon_redeemed` say what the order holds
     * RIGHT NOW, exactly as `setStatus()` reads them, so an order cancelled
     * first gives nothing back twice.
     */
    public static function delete(int $id): void
    {
        $db    = Database::getInstance();
        $order = self::find($id);
        if ($order === null) {
            return;
        }

        // Joined to a caller's transaction when there is one — PDO cannot
        // nest them (see `Term::setPostTerms()`).
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            if (!empty($order['stock_reduced'])) {
                \Cms\Commerce\StockLog::during('deleted', $id, '',
                    static fn () => \Cms\Commerce\Stock::giveForOrder(self::held(self::items($id))));
            }
            $code = trim((string) ($order['coupon_code'] ?? ''));
            if ($code !== '' && !empty($order['coupon_redeemed'])) {
                \Cms\Commerce\Coupon::release($code);
            }

            $stmt = $db->prepare('DELETE FROM orders WHERE id = :id');
            $stmt->execute([':id' => $id]);
            if ($own) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** The fields `setAddresses()` keeps, in the order the note lists them. */
    public const ADDRESS_FIELDS = [
        'email', 'phone',
        'billing_name', 'billing_line1', 'billing_line2', 'billing_city', 'billing_region', 'billing_post', 'billing_country',
        'shipping_name', 'shipping_line1', 'shipping_line2', 'shipping_city', 'shipping_region', 'shipping_post', 'shipping_country',
    ];

    /**
     * The order's addresses and how to reach the customer, corrected.
     *
     * ── WHAT WAS CHARGED STAYS WHAT WAS CHARGED ────────────────────────────
     * The tax and the delivery were worked out for the address the order was
     * placed with, and a corrected street does not restate them. A move to
     * another province that changes what is owed is the owner's to settle,
     * with a refund or a note to the customer.
     *
     * The same rules as the checkout's: a name, street, town, postal code and
     * country, the province where the country has a list of them, and a
     * delivery address that is either left blank, the billing one, or whole.
     * The note keeps the old and the new, because a parcel sent to the old one
     * is the question it answers next week.
     *
     * @param array<string, string> $form `Checkout::readForm()` of the posted fields
     * @return list<string> what cannot be saved, in sentences; empty when saved
     */
    public static function setAddresses(int $id, array $form, string $by = ''): array
    {
        $order = self::find($id);
        if ($order === null) {
            return ['That order no longer exists.'];
        }

        $errors = [];
        foreach (\Cms\Commerce\Checkout::FIELDS as $key => [$label, $required]) {
            if ($required && ($form[$key] ?? '') === '') {
                $errors[] = $label . ' is required.';
            }
        }
        if (($form['email'] ?? '') !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'The email address does not look like one.';
        }
        foreach (['billing' => '', 'shipping' => ' of the delivery address'] as $who => $whose) {
            $country = (string) ($form[$who . '_country'] ?? '');
            if ($who === 'shipping' && implode('', array_map(
                static fn (string $k): string => (string) ($form[$k] ?? ''),
                array_keys(\Cms\Commerce\Checkout::SHIPPING_FIELDS)
            )) === '') {
                continue;   // blank: delivered to the billing address
            }
            if ($who === 'shipping') {
                foreach (['shipping_name', 'shipping_line1', 'shipping_city', 'shipping_post', 'shipping_country'] as $key) {
                    if (($form[$key] ?? '') === '') {
                        $errors[] = \Cms\Commerce\Checkout::SHIPPING_FIELDS[$key] . ' is required for the delivery address.';
                    }
                }
            }
            if ($country !== '' && !\Cms\Commerce\Countries::isValid($country)) {
                $errors[] = 'Choose the country' . $whose . ' from the list.';
            } elseif (\Cms\Commerce\Regions::listed($country) && ($form[$who . '_region'] ?? '') === '') {
                $errors[] = 'Choose the ' . strtolower(\Cms\Commerce\Regions::word($country)) . $whose . '.';
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        $new = [];
        foreach (self::ADDRESS_FIELDS as $key) {
            $value     = trim((string) ($form[$key] ?? ''));
            $new[$key] = str_ends_with($key, '_country') ? \Cms\Commerce\Countries::code($value) : $value;
        }
        $changed = array_filter(self::ADDRESS_FIELDS, static fn (string $k): bool => (string) $order[$k] !== $new[$k]);
        if ($changed === []) {
            return [];
        }

        $sets = implode(', ', array_map(static fn (string $k): string => $k . ' = :' . $k, self::ADDRESS_FIELDS));
        $stmt = Database::getInstance()->prepare('UPDATE orders SET ' . $sets . ' WHERE id = :id');
        $bind = [':id' => $id];
        foreach ($new as $k => $v) {
            $bind[':' . $k] = $v;
        }
        $stmt->execute($bind);

        $line = static fn (array $from, string $who): string => implode(', ', array_filter([
            (string) $from[$who . '_name'], (string) $from[$who . '_line1'], (string) $from[$who . '_line2'],
            trim((string) $from[$who . '_city'] . ' ' . (string) $from[$who . '_region']),
            (string) $from[$who . '_post'], (string) $from[$who . '_country'],
        ], static fn (string $s): bool => trim($s) !== ''));
        $said = [];
        foreach (['billing' => 'Billing address', 'shipping' => 'Delivery address'] as $who => $what) {
            $was = $line($order, $who);
            $now = $line($new, $who);
            if ($was !== $now) {
                $said[] = $what . ' changed from ' . ($was !== '' ? $was : 'the billing address')
                        . ' to ' . ($now !== '' ? $now : 'the billing address') . '.';
            }
        }
        foreach (['email' => 'Email', 'phone' => 'Phone'] as $key => $what) {
            if ((string) $order[$key] !== $new[$key]) {
                $said[] = $what . ' changed from ' . ((string) $order[$key] !== '' ? (string) $order[$key] : 'none')
                        . ' to ' . ($new[$key] !== '' ? $new[$key] : 'none') . '.';
            }
        }
        self::addNote($id, implode("\n", $said), false, $by);

        return [];
    }

    /**
     * One option of a line exchanged for another of the same product: the M
     * tees back on the shelf, as many L taken off it, and the line reading L.
     *
     * ── AT THE PRICE IT WAS SOLD AT ─────────────────────────────────────────
     * An exchange is the same thing in another size or colour, so the order's
     * figures do not move. An option priced differently is the owner's to
     * settle, with a refund or a note to the customer.
     *
     * PART OF A LINE SPLITS IT: one tee of two exchanged leaves a line of one M
     * and a new line of one L, each at the price paid. What was refunded stays
     * on the old line; only what is left of it can be exchanged.
     *
     * @return string|null what stops it, in a sentence, or null when done
     */
    public static function exchange(int $id, int $itemId, int $toVariation, int $qty, string $by = ''): ?string
    {
        $order = self::find($id);
        if ($order === null) {
            return 'That order no longer exists.';
        }
        $item = null;
        foreach (self::items($id) as $row) {
            if ((int) $row['id'] === $itemId) {
                $item = $row;
            }
        }
        if ($item === null) {
            return 'That line is not on this order.';
        }
        $from = $item['variation_id'] !== null ? \Cms\Commerce\Variation::find((int) $item['variation_id']) : null;
        $name = \Cms\Commerce\Refund::name($item);
        if ($item['variation_id'] === null) {
            return $name . ' has no options to exchange.';
        }
        $to = \Cms\Commerce\Variation::find($toVariation);
        if ($to === null || $item['post_id'] === null || (int) $to['post_id'] !== (int) $item['post_id']) {
            return 'Choose another option of ' . (string) $item['name'] . ' to exchange for.';
        }
        if ((int) $to['id'] === (int) $item['variation_id']) {
            return 'Choose an option other than ' . (string) $item['variation_label'] . '.';
        }
        $most = (int) $item['quantity'] - (int) $item['refunded_qty'];
        if ($qty < 1 || $qty > $most) {
            return 'Only ' . $most . ' of ' . $name . ' can be exchanged.';
        }

        $db  = Database::getInstance();
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            // THE SHELF, when the order holds its goods: the new option taken
            // first, so a size that has run out refuses before anything moves.
            if (!empty($order['stock_reduced'])) {
                $took = \Cms\Commerce\StockLog::during('exchanged', $id, $by,
                    static fn (): bool => \Cms\Commerce\Variation::take((int) $to['id'], $qty));
                if (!$took) {
                    if ($own) {
                        $db->rollBack();
                    }
                    $left = \Cms\Commerce\Variation::available($to);

                    return 'Only ' . (int) $left . ' of ' . (string) $item['name'] . ' (' . (string) $to['label'] . ') '
                         . ((int) $left === 1 ? 'is' : 'are') . ' in stock.';
                }
                \Cms\Commerce\StockLog::during('exchanged', $id, $by,
                    static fn () => \Cms\Commerce\Variation::give((int) $item['variation_id'], $qty));
            }

            $sku = (string) $to['sku'] !== '' ? (string) $to['sku'] : (string) ($from['sku'] ?? $item['sku']);
            if ($qty === (int) $item['quantity']) {
                $stmt = $db->prepare(
                    'UPDATE order_items SET variation_id = :v, variation_label = :l, sku = :s WHERE id = :id'
                );
                $stmt->execute([':v' => (int) $to['id'], ':l' => (string) $to['label'], ':s' => $sku, ':id' => $itemId]);
            } else {
                $keep = (int) $item['quantity'] - $qty;
                $stmt = $db->prepare('UPDATE order_items SET quantity = :q, line_total = :t WHERE id = :id');
                $stmt->execute([':q' => $keep, ':t' => (int) $item['unit_price'] * $keep, ':id' => $itemId]);
                $add = $db->prepare(
                    'INSERT INTO order_items
                        (order_id, post_id, variation_id, variation_label, name, sku, quantity, unit_price, line_total, position)
                     VALUES (:o, :p, :v, :l, :n, :s, :q, :u, :t, :pos)'
                );
                $add->execute([
                    ':o' => $id, ':p' => (int) $item['post_id'], ':v' => (int) $to['id'], ':l' => (string) $to['label'],
                    ':n' => (string) $item['name'], ':s' => $sku, ':q' => $qty, ':u' => (int) $item['unit_price'],
                    ':t' => (int) $item['unit_price'] * $qty, ':pos' => (int) $item['position'],
                ]);
            }

            if ($own) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        self::addNote($id, 'Exchanged ' . $qty . ' × ' . $name . ' for ' . (string) $to['label'] . ', at the price paid.', false, $by);

        return null;
    }

    /**
     * An order's lines as the shelf counts them: each less what a refund has
     * already put back, so nothing goes back twice and nothing is taken again
     * that was never returned to the shelf by the order.
     *
     * @param  list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function held(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $item['quantity'] = (int) $item['quantity'] - (int) ($item['restocked_qty'] ?? 0);
            if ($item['quantity'] > 0) {
                $out[] = $item;
            }
        }

        return $out;
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
