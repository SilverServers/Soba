<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Database\Database;
use Cms\Models\Option;
use Cms\Models\Order;
use PDO;

/**
 * AN ORDER NOBODY PAID FOR, CANCELLED AFTER THE DAYS THE SHOP ALLOWS.
 *
 * An e-Transfer order holds its goods from the moment it is placed. One
 * never paid for would keep them off the shelf until somebody cancelled it by
 * hand; with Settings → Shop's days set, it is cancelled for the shop: the
 * goods go back and its code's use with them (`Order::setStatus()`), the order
 * notes why, and the customer is told in a note emailed to them. The day
 * before, unless Settings → Shop says not to, the customer is reminded to pay,
 * once, with when it will be cancelled and how to pay
 * (`OrderMail::paymentReminder()`).
 *
 * ── LOOKED FOR EVERY TEN MINUTES, ON A VISIT ────────────────────────────────
 * Shared hosting has no cron to rely on, so the check runs on a page request,
 * at most once in ten minutes whatever the traffic, and after that page has
 * been handed back, as the security alerts are (`Security\Alerts`): the
 * visitor whose request it is does not wait for it. The time is claimed
 * before the work, so two visitors at once do not both cancel the same order.
 * `admin/bin/run-due.php` runs it from a real cron where there is one.
 */
final class Lapse
{
    public const DAYS_KEY   = 'commerce_lapse_days';
    public const REMIND_KEY = 'commerce_lapse_remind';
    public const RAN_KEY    = 'commerce_lapse_checked_at';
    private const EVERY   = 600;

    /**
     * On every visit. One read when the shop keeps unpaid orders until they
     * are cancelled, which is what most visits find; the check itself, when
     * it is due, after the page.
     */
    public static function onVisit(): void
    {
        if (self::days() <= 0 || time() - (int) (Option::get(self::RAN_KEY, '0') ?? '0') < self::EVERY) {
            return;
        }
        register_shutdown_function([self::class, 'due']);
    }

    /**
     * The check, when this request wins the claim to it. Never throws: an
     * order that will not cancel must not become a broken page.
     */
    public static function due(): void
    {
        try {
            $days = self::days();
            if ($days <= 0 || !self::claim()) {
                return;
            }
            if (PHP_SAPI !== 'cli' && function_exists('fastcgi_finish_request')) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();      // the next click must see this one's session
                }
                fastcgi_finish_request();
            }
            self::run($days);
        } catch (\Throwable $e) {
            error_log('CMS unpaid orders: ' . $e->getMessage());
        }
    }

    /** The days Settings → Shop allows an unpaid order; 0 keeps it. */
    public static function days(): int
    {
        return max(0, (int) (Option::get(self::DAYS_KEY, '0') ?? '0'));
    }

    /**
     * Every order still Pending payment after `$days` days, cancelled; and
     * each one a day short of that, its customer reminded.
     *
     * @return array{cancelled: int, reminded: int}
     */
    public static function run(int $days): array
    {
        if ($days <= 0) {
            return ['cancelled' => 0, 'reminded' => 0];
        }
        $stmt = Database::getInstance()->prepare(
            // NOW(), THE CLOCK `created_at` IS WRITTEN BY, so the two agree
            // whatever zone the database keeps.
            "SELECT id FROM orders WHERE status = 'pending' AND created_at < NOW() - INTERVAL :d DAY ORDER BY id"
        );
        $stmt->bindValue(':d', $days, PDO::PARAM_INT);
        $stmt->execute();

        $done = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $order = Order::find((int) $id);
            if ($order === null || (string) $order['status'] !== 'pending') {
                continue;
            }
            if (Order::setStatus((int) $id, 'cancelled', 'Shop')) {
                $done++;
                $within = $days . ($days === 1 ? ' day' : ' days');
                Order::addNote((int) $id, 'Cancelled: not paid within ' . $within . '.', false, 'Shop');
                OrderMail::noteToCustomer($order, 'Order ' . Order::number($order) . ' has been cancelled, because no payment for it'
                    . ' arrived within ' . $within . '. If you sent one, reply to this email and we will sort it out.', 'Shop');
            }
        }

        return ['cancelled' => $done, 'reminded' => self::remind($days)];
    }

    /**
     * Each order a day from lapsing, its customer reminded to pay: once, by
     * the write that claims it. Not with a single day allowed, which leaves
     * no day before.
     */
    private static function remind(int $days): int
    {
        if ($days < 2 || (string) (Option::get(self::REMIND_KEY, '1') ?? '1') !== '1') {
            return 0;
        }
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT id FROM orders WHERE status = 'pending' AND payment_reminded_at IS NULL
                AND created_at < NOW() - INTERVAL :d DAY ORDER BY id"
        );
        $stmt->bindValue(':d', $days - 1, PDO::PARAM_INT);
        $stmt->execute();

        $claim = $db->prepare('UPDATE orders SET payment_reminded_at = NOW() WHERE id = :id AND payment_reminded_at IS NULL');
        $done  = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $claim->execute([':id' => (int) $id]);
            $order = $claim->rowCount() === 1 ? Order::find((int) $id) : null;
            if ($order === null || (string) $order['status'] !== 'pending') {
                continue;
            }
            $lapsesAt = (int) strtotime((string) $order['created_at']) + $days * 86400;
            if (OrderMail::paymentReminder($order, $lapsesAt)) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * The time of this check, written by one request only: the one whose
     * write moves the stored time on from the value it read. A later one
     * finds ten minutes have not passed.
     */
    private static function claim(): bool
    {
        $db   = Database::getInstance();
        $now  = time();
        $read = $db->prepare('SELECT option_value FROM options WHERE option_key = :k');
        $read->execute([':k' => self::RAN_KEY]);
        $last = $read->fetchColumn();
        if ($last === false) {
            $stmt = $db->prepare('INSERT IGNORE INTO options (option_key, option_value) VALUES (:k, :v)');
            $stmt->execute([':k' => self::RAN_KEY, ':v' => (string) $now]);

            return $stmt->rowCount() === 1;
        }
        if ($now - (int) $last < self::EVERY) {
            return false;
        }
        $stmt = $db->prepare('UPDATE options SET option_value = :v WHERE option_key = :k AND option_value <=> :was');
        $stmt->execute([':v' => (string) $now, ':k' => self::RAN_KEY, ':was' => $last]);

        return $stmt->rowCount() === 1;
    }
}
