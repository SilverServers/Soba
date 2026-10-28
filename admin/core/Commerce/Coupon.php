<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Database\Database;
use PDO;

/**
 * DISCOUNT CODES — what they take off, and every reason to refuse one.
 *
 * ── REFUSING IS MOST OF THIS CLASS, AND THAT IS THE RIGHT SHAPE ─────────────
 * A coupon that applies is one line of arithmetic. A coupon that does NOT
 * apply is six different situations, and the customer has to be told WHICH —
 * "that code is not valid" against a code they read off a poster is the message
 * that generates the phone call. Expired, spent, not started yet, too small a
 * basket, switched off, never existed: each says so.
 *
 * ── THE ARITHMETIC TAKES ITS INPUTS ─────────────────────────────────────────
 * `discountFor()` is pure — kind, amount and subtotal in, minor units out — and
 * needs no database. `Totals` clamps the result to the goods, which is where a
 * £30 coupon against a £20 basket stops being a refund.
 *
 * ── AND REDEEMING IS A RACE, SO THE DATABASE DECIDES ────────────────────────
 * `Commerce\Stock`'s header has the argument in full and it is the same one
 * here: "read the count, see it is under the limit, write the new count" has a
 * window exactly when two people are spending the last use of a code.
 * `redeem()` is one atomic UPDATE with the guard in its WHERE and `rowCount()`
 * decides — so a code limited to one use is used once, whatever the traffic.
 */
final class Coupon
{
    public const PERCENT = 'percent';
    public const FIXED   = 'fixed';

    /** Why a code was refused. Each one is a different sentence to the customer. */
    public const NOT_FOUND  = 'not_found';
    public const INACTIVE   = 'inactive';
    public const EXPIRED    = 'expired';
    public const SPENT      = 'spent';
    public const TOO_SMALL  = 'too_small';

    /**
     * Normalise a typed code.
     *
     * UPPER-CASED AND TRIMMED, because a customer types `spring20 ` on a phone
     * and the owner typed `SPRING20` on a poster, and those are the same coupon
     * to everybody except a database. The column is unique on this form, so an
     * owner also cannot create two codes that differ only in case.
     */
    public static function normalise(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** One coupon by code, or null. */
    public static function find(string $code): ?array
    {
        $code = self::normalise($code);
        if ($code === '') {
            return null;
        }

        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM coupons WHERE code = :code LIMIT 1'
        );
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** Every coupon, newest first — for the admin list. */
    public static function all(): array
    {
        return Database::getInstance()
            ->query('SELECT * FROM coupons ORDER BY id DESC')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Remove one coupon.
     *
     * HERE RATHER THAN IN THE CONTROLLER so every caller deletes a coupon the
     * same way: a second hand-written `DELETE FROM coupons` elsewhere is how a
     * table ends up with two ideas about what deleting one means.
     */
    public static function delete(int $id): void
    {
        $stmt = Database::getInstance()->prepare('DELETE FROM coupons WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM coupons WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Why this coupon cannot be used on a basket of `$subtotal`, or '' if it can.
     *
     * @param array<string, mixed> $coupon a row from `find()`
     * @param string               $today  'Y-m-d'; passed in so this is testable
     */
    public static function refusalFor(array $coupon, int $subtotal, string $today): string
    {
        if (empty($coupon['is_active'])) {
            return self::INACTIVE;
        }

        // ── THE DATE IS THE END OF THAT DAY ────────────────────────────────
        // A coupon that expires on the 30th works ON the 30th. Anything else
        // means an owner who types the last day of a sale ends it a day early,
        // and nobody reads a date field as "the day before this".
        $expires = (string) ($coupon['expires_at'] ?? '');
        if ($expires !== '' && $expires < $today) {
            return self::EXPIRED;
        }

        $limit = (int) ($coupon['usage_limit'] ?? 0);
        if ($limit > 0 && (int) ($coupon['times_used'] ?? 0) >= $limit) {
            return self::SPENT;
        }

        // MEASURED ON THE GOODS, not on the total. Measured after delivery, a
        // customer could reach a $50 minimum by choosing a more expensive
        // postage option, which is not what "spend $50" means. Measured after
        // tax, the same basket qualifies in one province and not another.
        $min = (int) ($coupon['min_spend'] ?? 0);
        if ($min > 0 && $subtotal < $min) {
            return self::TOO_SMALL;
        }

        return '';
    }

    /**
     * What to tell the customer, in their words rather than ours.
     *
     * A refusal the customer cannot act on is the one that generates the phone
     * call, so each of these says what to do about it where there is anything
     * to do. `TOO_SMALL` carries the number because "spend more" without the
     * figure is an instruction nobody can follow.
     *
     * @param array<string, mixed>|null $coupon
     */
    public static function explain(string $refusal, ?array $coupon = null): string
    {
        return match ($refusal) {
            self::NOT_FOUND => 'That code was not recognised. Check it and try again.',
            self::INACTIVE  => 'That code is no longer being accepted.',
            self::EXPIRED   => 'That code has expired.',
            self::SPENT     => 'That code has already been used as many times as it can be.',
            self::TOO_SMALL => 'That code needs a basket of at least '
                             . Money::format((int) ($coupon['min_spend'] ?? 0)) . '.',
            default         => '',
        };
    }

    /**
     * What this coupon takes off a subtotal, in minor units.
     *
     * NOT CLAMPED HERE. `Totals::compute()` does that, because it is the last
     * place before the number becomes a total and the only place every caller
     * passes through — see its note about a £30 coupon on a £20 basket.
     */
    public static function discountFor(string $kind, int $amount, int $subtotal): int
    {
        $amount = max(0, $amount);
        if ($amount === 0 || $subtotal <= 0) {
            return 0;
        }

        if ($kind === self::FIXED) {
            return $amount;
        }

        // HALF UP, IN INTEGERS — the same sum `Tax::compute()` does and for the
        // same reason: a double cannot hold 0.1, and a shop out by a cent on
        // some orders and not others has an accounting problem nobody can
        // reproduce.
        $amount = min($amount, 10000);

        return intdiv($subtotal * $amount * 2 + 10000, 20000);
    }

    /** The discount this row gives on this basket, or 0 if it cannot be used. */
    public static function discountOn(array $coupon, int $subtotal, ?string $today = null): int
    {
        $today = $today ?? date('Y-m-d');
        if (self::refusalFor($coupon, $subtotal, $today) !== '') {
            return 0;
        }

        return self::discountFor(
            (string) $coupon['kind'],
            (int) $coupon['amount'],
            $subtotal
        );
    }

    /**
     * Take one use off the code, atomically. True if it was taken.
     *
     * ── THE GUARD IS IN THE WHERE, NOT IN PHP ──────────────────────────────
     * `Commerce\Stock` again: two customers redeeming the last use of a code at
     * the same moment both read `times_used = 4` against a limit of 5, both
     * decide it is fine, and both write 5. The database has to be the one that
     * decides, and `rowCount()` is the answer.
     *
     * A coupon with NO limit still counts up — the number is what tells an
     * owner whether a campaign worked, and it is the only record they get.
     */
    public static function redeem(int $id): bool
    {
        $stmt = Database::getInstance()->prepare(
            'UPDATE coupons
                SET times_used = times_used + 1
              WHERE id = :id
                AND is_active = 1
                AND (usage_limit = 0 OR times_used < usage_limit)'
        );
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /** Put a use back — a cancelled order did not spend the code. */
    public static function release(string $code): void
    {
        $code = self::normalise($code);
        if ($code === '') {
            return;
        }

        // GREATEST(…, 0) rather than a bare subtraction: a counter that can go
        // negative is a counter that then lets the code be used more times than
        // its limit allows, which is the bug this whole mechanism exists to
        // prevent.
        $stmt = Database::getInstance()->prepare(
            'UPDATE coupons SET times_used = GREATEST(times_used - 1, 0) WHERE code = :code'
        );
        $stmt->execute([':code' => $code]);
    }

    /** "10% off" / "$5.00 off" — how the row reads on a screen. */
    public static function describe(string $kind, int $amount): string
    {
        if ($kind === self::FIXED) {
            return Money::format($amount) . ' off';
        }

        return Tax::percent($amount) . '% off';
    }
}
