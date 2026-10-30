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
    public const NOT_STARTED = 'not_started';
    public const USED_BY_YOU = 'used_by_you';
    public const NOTHING_TO_TAKE = 'nothing_applies';

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
    public static function refusalFor(
        array $coupon,
        int $subtotal,
        string $today,
        ?int $eligible = null,
        int $usedByCustomer = 0
    ): string {
        if (empty($coupon['is_active'])) {
            return self::INACTIVE;
        }

        // ── THE DATE IS THE END OF THAT DAY ────────────────────────────────
        // A coupon that expires on the 30th works ON the 30th. Anything else
        // means an owner who types the last day of a sale ends it a day early,
        // and nobody reads a date field as "the day before this".
        // ITS FIRST DAY WORKS, as its last does: both are the site's days.
        $starts = (string) ($coupon['starts_at'] ?? '');
        if ($starts !== '' && $starts > $today) {
            return self::NOT_STARTED;
        }

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
        // ONCE EACH, by the email an order is placed with: the count is of
        // this customer's orders that spent it, which the checkout reads.
        $each = (int) ($coupon['per_customer'] ?? 0);
        if ($each > 0 && $usedByCustomer >= $each) {
            return self::USED_BY_YOU;
        }

        $min = (int) ($coupon['min_spend'] ?? 0);
        if ($min > 0 && $subtotal < $min) {
            return self::TOO_SMALL;
        }

        // NOTHING IN THE BASKET IT REACHES: a code for the outerwear on a
        // basket of tees, or one that leaves sale items alone on a basket of
        // them. Said, rather than accepted for nothing. A code that makes
        // delivery free still does that.
        if ($eligible !== null && $eligible <= 0 && (int) ($coupon['amount'] ?? 0) > 0 && empty($coupon['free_shipping'])) {
            return self::NOTHING_TO_TAKE;
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
            self::NOT_STARTED => 'That code works from '
                             . \Cms\Helpers\Dates::format((string) ($coupon['starts_at'] ?? ''), 'F j') . '.',
            self::USED_BY_YOU => 'You have already used that code.',
            self::NOTHING_TO_TAKE => 'That code does not apply to anything in your basket'
                             . (!empty($coupon['exclude_sale']) ? ', and it leaves items on sale alone' : '') . '.',
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
    public static function discountOn(
        array $coupon,
        int $subtotal,
        ?string $today = null,
        ?array $lines = null,
        int $usedByCustomer = 0
    ): int {
        $today    = $today ?? \Cms\Helpers\Dates::today();
        $eligible = $lines === null ? $subtotal : self::eligible($coupon, $lines);
        if (self::refusalFor($coupon, $subtotal, $today, $eligible, $usedByCustomer) !== '') {
            return 0;
        }

        // OF WHAT IT REACHES, and never more than that: $20 off a code for
        // the accessories takes at most what the accessories cost.
        return min($eligible, self::discountFor(
            (string) $coupon['kind'],
            (int) $coupon['amount'],
            $eligible
        ));
    }

    /**
     * What in the basket a code takes off: the lines of the products and the
     * terms it names — every line when it names none — less any on sale when
     * it leaves sale items alone.
     *
     * @param list<array{post_id: int, line_total: int, on_sale: bool, term_ids: list<int>}> $lines
     */
    public static function eligible(array $coupon, array $lines): int
    {
        $products = self::ids($coupon['product_ids'] ?? null);
        $terms    = self::ids($coupon['category_ids'] ?? null);
        $sum      = 0;
        foreach ($lines as $line) {
            $named = ($products === [] && $terms === [])
                || in_array((int) $line['post_id'], $products, true)
                || array_intersect(array_map('intval', (array) $line['term_ids']), $terms) !== [];
            if (!$named || (!empty($coupon['exclude_sale']) && !empty($line['on_sale']))) {
                continue;
            }
            $sum += (int) $line['line_total'];
        }

        return $sum;
    }

    /** @return list<int> ids from a stored JSON list */
    public static function ids(mixed $stored): array
    {
        $list = is_array($stored) ? $stored : json_decode((string) $stored, true);

        return array_values(array_unique(array_filter(array_map('intval', is_array($list) ? $list : []))));
    }

    /**
     * Does the code do anything to this basket — money off, or free delivery?
     *
     * @param list<array{post_id: int, line_total: int, on_sale: bool, term_ids: list<int>}> $lines
     */
    public static function applies(array $coupon, int $subtotal, array $lines, int $usedByCustomer = 0): bool
    {
        $refusal = self::refusalFor(
            $coupon,
            $subtotal,
            \Cms\Helpers\Dates::today(),
            self::eligible($coupon, $lines),
            $usedByCustomer
        );
        if ($refusal !== '') {
            return false;
        }

        return !empty($coupon['free_shipping'])
            || self::discountOn($coupon, $subtotal, null, $lines, $usedByCustomer) > 0;
    }

    /** Does the basket's code make its delivery free? */
    public static function freeDeliveryOnCart(string $email = ''): bool
    {
        $code   = Cart::coupon();
        $coupon = $code !== '' ? self::find($code) : null;
        if ($coupon === null || empty($coupon['free_shipping'])) {
            return false;
        }
        $lines = self::cartLines();

        return self::refusalFor(
            $coupon,
            Cart::subtotal(),
            \Cms\Helpers\Dates::today(),
            self::eligible($coupon, $lines),
            $email !== '' ? self::usedBy((string) $coupon['code'], $email) : 0
        ) === '';
    }

    /**
     * The basket's lines as a code reads them: which product, what it comes
     * to, whether it is on sale now, and every term it is filed under.
     *
     * @return list<array{post_id: int, line_total: int, on_sale: bool, term_ids: list<int>}>
     */
    public static function cartLines(): array
    {
        $items = Cart::items();
        $terms = self::termIdsOf(array_map(static fn (array $l): int => (int) $l['product']['id'], $items));
        $out   = [];
        foreach ($items as $line) {
            $product   = $line['product'];
            $variation = $line['variation'];
            [$regular, $now] = $variation !== null
                ? Variation::prices($variation, $product)
                : [(int) $product['regular_price'], (int) $product['price']];
            $out[] = [
                'post_id'    => (int) $product['id'],
                'line_total' => (int) $line['line_total'],
                'on_sale'    => $now < $regular,
                'term_ids'   => $terms[(int) $product['id']] ?? [],
            ];
        }

        return $out;
    }

    /** How many orders this email address has spent the code on. */
    public static function usedBy(string $code, string $email): int
    {
        $email = trim($email);
        if ($email === '' || self::normalise($code) === '') {
            return 0;
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM orders
              WHERE coupon_code = :code AND coupon_redeemed = 1 AND LOWER(email) = LOWER(:email)'
        );
        $stmt->execute([':code' => self::normalise($code), ':email' => $email]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param  list<int> $postIds
     * @return array<int, list<int>> post id => every term id it is filed under
     */
    private static function termIdsOf(array $postIds): array
    {
        $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds))));
        if ($postIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($postIds), '?'));
        $stmt  = Database::getInstance()->prepare(
            'SELECT post_id, term_id FROM post_term_relationships WHERE post_id IN (' . $marks . ')'
        );
        $stmt->execute($postIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['post_id']][] = (int) $row['term_id'];
        }

        return $out;
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
    public static function describe(string $kind, int $amount, bool $freeDelivery = false): string
    {
        $off = $amount <= 0 ? '' : ($kind === self::FIXED ? Money::format($amount) . ' off' : Tax::percent($amount) . '% off');
        if ($freeDelivery) {
            return $off === '' ? 'Free delivery' : $off . ' and free delivery';
        }

        return $off;
    }
}
