<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Models\Option;

/**
 * WHAT DELIVERY COSTS — one flat rate, and a threshold that makes it free.
 *
 * ── WHY NOT ZONES, CLASSES AND WEIGHT ───────────────────────────────────────
 * A full shipping system has ZONES (a list of regions), METHODS per zone,
 * CLASSES per product, and rates that can be a formula over weight, quantity
 * and price. A shop that posts parcels internationally needs all of it.
 *
 * A shop that posts parcels around one province, which is the shop this system
 * is for, needs "delivery is $12, free over $100". Offering the other thing
 * means an owner configuring a zone map before they can sell a jar of honey,
 * and getting it subtly wrong in a way that under-charges them on every order.
 *
 * WHAT WE GIVE UP is charging Vancouver differently from Halifax. That is on
 * the settings screen in words, because an owner who needs it should find out
 * before they price their catalogue, not after a courier invoice.
 *
 * ── AND NOT EVERYTHING IS POSTED ────────────────────────────────────────────
 * A membership, a ticket, a downloadable file and an hour of somebody's time
 * are all things this shop sells, and none of them goes in a van. Charging $12
 * to deliver a membership is the kind of wrong that makes a customer close the
 * tab.
 *
 * So delivery is a PROPERTY OF THE PRODUCT — `product_needs_delivery`, the same
 * opt-in shape `product_manage_stock` already uses and for the same stated
 * reason. A basket with nothing deliverable in it is not charged, whatever the
 * rate says.
 *
 * ── THE ARITHMETIC TAKES ITS INPUTS ─────────────────────────────────────────
 * `compute()` is pure — subtotal in, settings in, number out — and needs no
 * database. `forCart()` is the one line on top that knows where the numbers
 * live.
 */
final class Shipping
{
    public const MODE_KEY      = 'commerce_ship_mode';
    public const FLAT_KEY      = 'commerce_ship_flat';
    public const FREE_OVER_KEY = 'commerce_ship_free_over';
    public const NOTE_KEY      = 'commerce_ship_note';

    /** The per-product opt-in. `Commerce\Product` reads it onto the line. */
    public const NEEDS_DELIVERY = 'product_needs_delivery';

    public const MODE_NONE = 'none';
    public const MODE_FLAT = 'flat';

    /**
     * `none` or `flat`.
     *
     * DEFAULTS TO NONE, and that is not laziness. A shop that has not been told
     * what delivery costs must not invent a figure and add it to somebody's
     * order — and "we do not charge for delivery" is a real answer that many of
     * these shops give, because they hand the thing over in person.
     */
    public static function mode(): string
    {
        $mode = (string) Option::get(self::MODE_KEY, self::MODE_NONE);

        return $mode === self::MODE_FLAT ? self::MODE_FLAT : self::MODE_NONE;
    }

    /** The flat rate in minor units. */
    public static function flat(): int
    {
        return max(0, (int) Option::get(self::FLAT_KEY, '0'));
    }

    /**
     * Spend at or above this and delivery is free. 0 means never.
     *
     * AT OR ABOVE, not above: a threshold of $100 that does not include a
     * basket of exactly $100 is the kind of off-by-one a customer notices
     * immediately and reads as a trick.
     */
    public static function freeOver(): int
    {
        return max(0, (int) Option::get(self::FREE_OVER_KEY, '0'));
    }

    /** What the shop wants to say about delivery. May be empty. */
    public static function note(): string
    {
        return trim((string) Option::get(self::NOTE_KEY, ''));
    }

    /** Is this shop charging for delivery at all? */
    public static function isCharged(): bool
    {
        return self::mode() === self::MODE_FLAT && self::flat() > 0;
    }

    /**
     * What the basket costs to deliver, in minor units.
     *
     * `$deliverable` is whether ANYTHING in the basket needs posting. A basket
     * of memberships is not charged however the shop is configured.
     */
    public static function forCart(): int
    {
        return self::compute(
            Cart::subtotal(),
            Cart::hasDeliverable(),
            self::mode(),
            self::flat(),
            self::freeOver()
        );
    }

    /**
     * The same sum with every input handed in.
     *
     * @param int    $subtotal    the goods, in minor units
     * @param bool   $deliverable is anything in the basket actually posted?
     * @param string $mode        `none` or `flat`
     * @param int    $flat        the flat rate, in minor units
     * @param int    $freeOver    spend at or above this and it is free; 0 never
     */
    public static function compute(
        int $subtotal,
        bool $deliverable,
        string $mode,
        int $flat,
        int $freeOver
    ): int {
        if (!$deliverable || $mode !== self::MODE_FLAT) {
            return 0;
        }

        $flat = max(0, $flat);
        if ($flat === 0) {
            return 0;
        }

        // ── THE THRESHOLD IS MEASURED ON THE GOODS ──────────────────────────
        // Not on the total. Measuring it after delivery has been added is
        // circular — the charge would decide whether the charge applies — and
        // measuring it after tax means the same basket qualifies in one
        // province and not another, which is not what "free over $100" says.
        if ($freeOver > 0 && $subtotal >= $freeOver) {
            return 0;
        }

        return $flat;
    }

    /**
     * Is there anything to say about delivery on THIS basket?
     *
     * ── A BASKET OF MEMBERSHIPS HAS NOTHING TO SAY ──────────────────────────
     * Checking only whether the shop charges for delivery is not enough: a
     * basket holding one membership would be told "Spend $55.00 more and
     * delivery is free" — an offer about a charge that is never going to apply
     * to it. The arithmetic would be right and the sentence nonsense.
     */
    public static function appliesToCart(): bool
    {
        return Cart::hasDeliverable();
    }

    /**
     * The line a basket prints under the total about the free threshold, or ''.
     *
     * TELLING THE CUSTOMER THEY ALMOST QUALIFY is the one piece of shop
     * persuasion in this system, and it is here because the alternative — a
     * delivery charge appearing with no explanation of how to avoid it — is
     * worse for the customer, not just for the shop.
     */
    public static function freeOverNote(int $subtotal): string
    {
        if (!self::isCharged() || !self::appliesToCart()) {
            return '';
        }

        $threshold = self::freeOver();
        if ($threshold <= 0) {
            return '';
        }

        if ($subtotal >= $threshold) {
            return 'Delivery is free on this order.';
        }

        return 'Spend ' . Money::format($threshold - $subtotal)
             . ' more and delivery is free.';
    }

    /**
     * What the shop wants to say about delivery, for THIS basket.
     *
     * Empty when nothing in it is posted — "Posted within two working days"
     * under a membership is the same nonsense as the offer above.
     */
    public static function noteForCart(): string
    {
        return self::appliesToCart() ? self::note() : '';
    }
}
