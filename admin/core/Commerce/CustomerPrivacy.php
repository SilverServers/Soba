<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Database\Database;
use Cms\Models\Order;
use PDO;

/**
 * WHAT THE SHOP HOLDS ABOUT A CUSTOMER: given to them, or taken away.
 *
 * ── THEIRS TO SEE ───────────────────────────────────────────────────────────
 * `export()` is everything kept under their address: each order with what was
 * bought, the addresses and telephone, the notes they were sent, and any
 * sold-out size they asked to hear about. What a customer is entitled to ask
 * for, in a file the shop can send them.
 *
 * ── THEIRS TO HAVE ERASED, AND THE BOOKS KEPT ───────────────────────────────
 * `erase()` takes their name, email, telephone, addresses and the note they
 * wrote off every order, and the address off the notes that named it, and
 * forgets their waiting list and sign-in links. What was sold, for how much,
 * the tax on it and when stay: the shop keeps its books for the tax years the
 * law asks. Each order notes that it was done.
 */
final class CustomerPrivacy
{
    /** The columns of an order that say who and where. */
    private const PERSONAL = [
        'email', 'phone', 'customer_note',
        'billing_name', 'billing_line1', 'billing_line2', 'billing_city', 'billing_post',
        'shipping_name', 'shipping_line1', 'shipping_line2', 'shipping_city', 'shipping_post',
    ];

    /**
     * Everything kept under this address, as a file to send them.
     *
     * @return array<string, mixed>
     */
    public static function export(string $email): array
    {
        $email  = mb_strtolower(trim($email));
        $orders = [];
        foreach (Customers::orders($email) as $o) {
            $id       = (int) $o['id'];
            $orders[] = [
                'order'    => Order::number($o),
                'placed'   => (string) $o['created_at'],
                'status'   => Order::statusLabel((string) $o['status']),
                'currency' => (string) $o['currency'],
                'total'    => Money::edit((int) $o['total'], (string) $o['currency']),
                'items'    => array_map(static fn (array $i): array => [
                    'name'     => (string) $i['name'] . ((string) ($i['variation_label'] ?? '') !== '' ? ' — ' . (string) $i['variation_label'] : ''),
                    'quantity' => (int) $i['quantity'],
                    'total'    => Money::edit((int) $i['line_total'], (string) $o['currency']),
                ], Order::items($id)),
                'email'    => (string) $o['email'],
                'phone'    => (string) $o['phone'],
                'billing'  => self::address($o, 'billing'),
                'delivery' => self::address($o, 'shipping'),
                'note'     => (string) $o['customer_note'],
                'marketing_email' => (string) $o['marketing_opt_in'] === '1',
                'notes_sent_to_them' => array_values(array_map(static fn (array $n): string => (string) $n['body'],
                    array_filter(Order::notes($id), static fn (array $n): bool => !empty($n['is_customer_note'])))),
            ];
        }
        $waiting = Database::getInstance()->prepare(
            'SELECT p.title, v.label, w.created_at FROM stock_waitlist w JOIN posts p ON p.id = w.post_id
          LEFT JOIN product_variations v ON v.id = w.variation_id WHERE LOWER(w.email) = :e'
        );
        $waiting->execute([':e' => $email]);

        return [
            'email'       => $email,
            'exported_at' => gmdate('c'),
            'orders'      => $orders,
            'waiting_for' => array_map(static fn (array $w): string => (string) $w['title'] . ($w['label'] !== null ? ' — ' . (string) $w['label'] : ''),
                $waiting->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    /**
     * Their personal details taken off everything kept under this address.
     *
     * @return int how many orders were changed
     */
    public static function erase(string $email, string $by): int
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return 0;
        }
        $db  = Database::getInstance();
        $ids = array_map(static fn (array $o): int => (int) $o['id'], Customers::orders($email));

        $db->beginTransaction();
        try {
            $clear = $db->prepare('UPDATE orders SET ' . implode(', ', array_map(static fn (string $c): string => $c . " = ''", self::PERSONAL))
                . ', marketing_opt_in = 0 WHERE id = :id');
            $named = $db->prepare('UPDATE order_notes SET body = REPLACE(body, :e, :gone) WHERE order_id = :id');
            foreach ($ids as $id) {
                $clear->execute([':id' => $id]);
                $named->execute([':e' => $email, ':gone' => '[erased]', ':id' => $id]);
            }
            $db->prepare('DELETE FROM stock_waitlist WHERE LOWER(email) = :e')->execute([':e' => $email]);
            $db->prepare('DELETE FROM customer_links WHERE email = :e')->execute([':e' => $email]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        foreach ($ids as $id) {
            Order::addNote($id, 'The customer\'s personal details were erased at their request; what was sold, and for how much, is kept.', false, $by);
        }

        return count($ids);
    }

    /** An address as its lines. */
    private static function address(array $o, string $prefix): array
    {
        return array_values(array_filter([
            (string) $o[$prefix . '_name'], (string) $o[$prefix . '_line1'], (string) $o[$prefix . '_line2'],
            (string) $o[$prefix . '_city'], (string) $o[$prefix . '_region'], (string) $o[$prefix . '_post'],
            (string) $o[$prefix . '_country'] !== '' ? Countries::name((string) $o[$prefix . '_country']) : '',
        ], static fn (string $l): bool => trim($l) !== ''));
    }
}
