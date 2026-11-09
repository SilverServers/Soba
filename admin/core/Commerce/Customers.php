<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Database\Database;
use PDO;

/**
 * THE SHOP'S CUSTOMERS, AS THEIR ORDERS MAKE THEM.
 *
 * ── ONE CUSTOMER, ONE EMAIL ADDRESS ─────────────────────────────────────────
 * A checkout takes an email address and nothing else that is the same each
 * time, so a customer is every order placed with one address, whatever the
 * case it was typed in. Their name, telephone and where they are are the most
 * recent order's: what they told the shop last.
 *
 * ── WHAT THEY HAVE SPENT ────────────────────────────────────────────────────
 * The orders the shop was paid for, less what went back: Processing and
 * Completed, and Refunded, each at its total less its refunds — the Net the
 * orders spreadsheet gives each order. An order waiting for its money, on
 * hold, cancelled or failed is counted as an order and not as spending.
 *
 * ── NAMED IN AN ADDRESS BY A HASH, NOT BY THE EMAIL ─────────────────────────
 * `/admin/customers/{key}`: the first sixteen hex of the address's SHA-256, so
 * a customer's email is not written into every server log a link to them
 * passes through.
 */
final class Customers
{
    /** The statuses whose money the shop has: paid, or paid and given back. */
    public const SPENDING = ['processing', 'completed', 'refunded'];

    /** The ways the list sorts, and what each is called. */
    public const SORTS = ['last' => 'Most recent order', 'spent' => 'Most spent', 'orders' => 'Most orders', 'name' => 'Name'];

    /** A customer's key: their address, lower-cased, hashed, shortened. */
    public static function key(string $email): string
    {
        return substr(hash('sha256', mb_strtolower(trim($email))), 0, 16);
    }

    /**
     * The customers, one row each, newest order first unless sorted otherwise.
     *
     * @return list<array{key: string, email: string, name: string, phone: string, place: string,
     *                    orders: int, spent: int, first_at: string, last_at: string, opt_in: bool}>
     */
    public static function all(string $search = '', string $sort = 'last'): array
    {
        $where  = "o.email <> ''";
        $params = [':cur' => Money::currency()];
        if (trim($search) !== '') {
            $where           .= ' AND (o.email LIKE :q OR o.billing_name LIKE :q2 OR o.phone LIKE :q3)';
            $like             = '%' . addcslashes(trim($search), '%_\\') . '%';
            $params[':q']     = $like;
            $params[':q2']    = $like;
            $params[':q3']    = $like;
        }
        $order = match ($sort) {
            'spent'  => 'spent DESC, last_at DESC',
            'orders' => 'orders DESC, last_at DESC',
            'name'   => 'name = \'\', name ASC, email ASC',
            default  => 'last_at DESC',
        };

        $stmt = Database::getInstance()->prepare(self::summarySql($where) . ' ORDER BY ' . $order);
        $stmt->execute($params);

        return array_map([self::class, 'row'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * One customer by their key, with their orders and the addresses they
     * have used; null when no order has an address with that key.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        $email = self::emailFor($key);
        if ($email === null) {
            return null;
        }
        $stmt = Database::getInstance()->prepare(self::summarySql('LOWER(o.email) = :email'));
        $stmt->execute([':cur' => Money::currency(), ':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $customer           = self::row($row);
        $customer['list']   = self::orders($email);
        $customer['places'] = self::places($customer['list']);

        return $customer;
    }

    /**
     * Every order placed with this address, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function orders(string $email): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT o.*, COALESCE(r.refunded, 0) AS refunded,
                    (SELECT COALESCE(SUM(i.quantity), 0) FROM order_items i WHERE i.order_id = o.id) AS item_count
               FROM orders o
          LEFT JOIN (SELECT order_id, SUM(amount) AS refunded FROM order_refunds GROUP BY order_id) r ON r.order_id = o.id
              WHERE LOWER(o.email) = :email
           ORDER BY o.created_at DESC, o.id DESC'
        );
        $stmt->execute([':email' => mb_strtolower(trim($email))]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The address a key names, from the addresses orders were placed with. */
    public static function emailFor(string $key): ?string
    {
        if (!preg_match('/^[0-9a-f]{16}$/', $key)) {
            return null;
        }
        $stmt = Database::getInstance()->query("SELECT DISTINCT LOWER(email) FROM orders WHERE email <> ''");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
            if (self::key((string) $email) === $key) {
                return (string) $email;
            }
        }

        return null;
    }

    /**
     * Each distinct address an order went to or was billed at, newest first,
     * as a list of its lines.
     *
     * @param list<array<string, mixed>> $orders
     * @return list<list<string>>
     */
    private static function places(array $orders): array
    {
        $seen = [];
        foreach ($orders as $o) {
            foreach (['shipping', 'billing'] as $kind) {
                $lines = array_values(array_filter([
                    (string) $o[$kind . '_name'],
                    (string) $o[$kind . '_line1'],
                    (string) $o[$kind . '_line2'],
                    trim((string) $o[$kind . '_city'] . ' ' . (string) $o[$kind . '_region'] . '  ' . (string) $o[$kind . '_post']),
                    (string) $o[$kind . '_country'] !== '' ? Countries::name((string) $o[$kind . '_country']) : '',
                ], static fn (string $l): bool => trim($l) !== ''));
                if (count($lines) > 1) {
                    $seen[mb_strtolower(implode('|', $lines))] ??= $lines;
                }
            }
        }

        return array_values($seen);
    }

    /** The one query both the list and a customer's own page read. */
    private static function summarySql(string $where): string
    {
        // THE MOST RECENT ORDER'S value of a column: the first of them all,
        // newest first. NULLIF so an empty one does not stand for the rest.
        $latest = static fn (string $col): string =>
            "SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(o.$col, '') ORDER BY o.created_at DESC, o.id DESC SEPARATOR '\\n'), '\\n', 1)";

        return "SELECT LOWER(o.email) AS email,
                       COUNT(*) AS orders,
                       SUM(CASE WHEN o.status IN ('" . implode("', '", self::SPENDING) . "') AND o.currency = :cur
                                THEN o.total - COALESCE(r.refunded, 0) ELSE 0 END) AS spent,
                       MIN(o.created_at) AS first_at,
                       MAX(o.created_at) AS last_at,
                       COALESCE(" . $latest('billing_name') . ", '') AS name,
                       COALESCE(" . $latest('phone') . ", '') AS phone,
                       COALESCE(" . $latest('billing_city') . ", '') AS city,
                       COALESCE(" . $latest('billing_region') . ", '') AS region,
                       COALESCE(" . $latest('billing_country') . ", '') AS country,
                       SUBSTRING_INDEX(GROUP_CONCAT(o.marketing_opt_in ORDER BY o.created_at DESC, o.id DESC), ',', 1) AS opt_in
                  FROM orders o
             LEFT JOIN (SELECT order_id, SUM(amount) AS refunded FROM order_refunds GROUP BY order_id) r ON r.order_id = o.id
                 WHERE $where
              GROUP BY LOWER(o.email)";
    }

    /** A summary row as the screens read it. */
    private static function row(array $r): array
    {
        $place = array_values(array_filter([(string) $r['city'], (string) $r['region'],
            (string) $r['country'] !== '' ? Countries::name((string) $r['country']) : '']));

        return [
            'key'      => self::key((string) $r['email']),
            'email'    => (string) $r['email'],
            'name'     => (string) $r['name'],
            'phone'    => (string) $r['phone'],
            'place'    => implode(', ', $place),
            'orders'   => (int) $r['orders'],
            'spent'    => (int) $r['spent'],
            'first_at' => (string) $r['first_at'],
            'last_at'  => (string) $r['last_at'],
            'opt_in'   => (string) $r['opt_in'] === '1',
        ];
    }
}
