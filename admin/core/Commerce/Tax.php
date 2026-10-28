<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Models\Option;

/**
 * THE TAX ON AN ORDER — one rate, named by the owner, exact in integers.
 *
 * ── WHY ONE RATE AND NOT A TABLE OF THEM ────────────────────────────────────
 * A full tax table has CLASSES, rates by country, state, postcode and city, a
 * priority column, compound rates, and a setting for which address decides.
 * That machinery exists for shops that need it, and every one of those rows is
 * a thing an owner can get wrong.
 *
 * The CMS's shop takes offline payment from the customers of one business. The
 * honest shape of "what tax do I charge?" for that business is a number and a
 * name — 5% GST, 20% VAT, 8.875% sales tax — and a decision about whether the
 * prices already include it.
 *
 * WHAT WE GIVE UP is a shop that charges different rates to different
 * provinces. That is a real limitation and it is written on the settings
 * screen, not buried here: an owner who needs it can see that they need
 * something else before they build a price list on top of this.
 *
 * ── HUNDREDTHS OF A PERCENT, NOT A FLOAT ────────────────────────────────────
 * 5% is 500. 12.5% is 1250. 8.88% is 888.
 *
 * A rate with more precision than that — 8.875% — is REFUSED by the settings
 * screen rather than quietly rounded to 8.88, because a stored rate the owner
 * did not type is a receipt nobody can check against the screen that produced
 * it. That is the same call `Money::parse()` makes about fractions of a cent:
 * a number this system cannot hold exactly is one it must not pretend to hold.
 *
 * Two decimal places of a percent covers every published sales-tax rate; the
 * fractions above that belong to interest, not tax.
 *
 * ── ROUNDING IS DECIDED HERE, ONCE ──────────────────────────────────────────
 * Half up, on the whole taxable amount, never per line. Per-line rounding makes
 * the tax on ten copies of a $0.99 item differ from the tax on one $9.90 item,
 * and the difference shows up as a cent the customer can see and the owner
 * cannot explain.
 *
 * ── THE ARITHMETIC TAKES ITS INPUTS; IT DOES NOT FETCH THEM ─────────────────
 * `compute()`, `format()` and `parsePercent()` are PURE — amount in, rate in,
 * number out. `on()` and `describe()` are one line each on top of them,
 * supplying what the shop has stored.
 *
 * That split means every case can be computed without touching the database.
 * The alternative is a seam on `Option` — a back door into a production model
 * that exists only so a script can lie to it, which is the sort of thing that
 * is still there in five years being used by something else.
 */
final class Tax
{
    public const RATE_KEY      = 'commerce_tax_rate';
    public const LABEL_KEY     = 'commerce_tax_label';
    public const INCLUSIVE_KEY = 'commerce_tax_inclusive';

    /** The largest rate the settings screen will accept: 100%. */
    public const MAX_RATE = 10000;

    /**
     * The rate in hundredths of a percent. 0 means this shop does not charge
     * tax, which is a legitimate answer and the default.
     */
    public static function rate(): int
    {
        $raw = (int) Option::get(self::RATE_KEY, '0');

        // CLAMPED ON THE WAY OUT as well as on the way in. A row edited by hand
        // in the database, or left behind by an older version of the settings
        // screen, must not be able to charge 900% — and the clamp belongs where
        // the value is READ, because that is the only place every caller passes
        // through.
        return max(0, min($raw, self::MAX_RATE));
    }

    /** What this tax is CALLED on the receipt. Never blank. */
    public static function label(): string
    {
        $label = trim((string) Option::get(self::LABEL_KEY, ''));

        // "Tax" AND NOT AN EMPTY LINE. A total column with a number against no
        // word is a charge the customer cannot identify, which is the one thing
        // a receipt exists to prevent.
        return $label !== '' ? $label : 'Tax';
    }

    /**
     * Do the prices the owner typed ALREADY contain the tax?
     *
     * The difference is not cosmetic. Exclusive: a $10 item at 5% costs $10.50
     * and the tax is $0.50. Inclusive: it costs $10 and 48¢ of that is tax.
     * Getting it backwards over-charges every customer by the rate, and nothing
     * in the arithmetic looks wrong when it happens.
     */
    public static function isInclusive(): bool
    {
        return Option::get(self::INCLUSIVE_KEY, '0') === '1';
    }

    /** Is there any tax to charge or show at all? */
    public static function isCharged(): bool
    {
        return self::rate() > 0;
    }

    /**
     * The tax on `$amount` minor units.
     *
     * EXCLUSIVE: the tax is added on top, so it is `amount × rate`.
     * INCLUSIVE: the amount already contains it, so the tax is the part of it
     * that is tax — `amount × rate / (100% + rate)`, which is NOT the same
     * number and is the mistake worth guarding against.
     *
     * @param int|null $rate override, for showing an owner what a rate would do
     */
    public static function on(int $amount): int
    {
        return self::compute($amount, self::rate(), self::isInclusive());
    }

    /**
     * The same sum with every input handed in — no settings, no database.
     *
     * @param int  $rate      hundredths of a percent
     * @param bool $inclusive is the tax already inside `$amount`?
     */
    public static function compute(int $amount, int $rate, bool $inclusive): int
    {
        $rate = max(0, min($rate, self::MAX_RATE));
        if ($rate === 0 || $amount === 0) {
            return 0;
        }

        // ── HALF UP, IN INTEGERS ───────────────────────────────────────────
        // `intdiv($n * 2 + $d, $d * 2)` is half-up without ever making a float.
        // Written as `round($n / $d)` it would go through a double, and the
        // whole reason `Money` stores minor units is that a double cannot hold
        // 0.1 — a shop that is out by a cent on some orders and not others has
        // an accounting problem nobody can reproduce.
        $numerator   = $amount * $rate;
        $denominator = $inclusive ? (10000 + $rate) : 10000;

        return intdiv($numerator * 2 + $denominator, $denominator * 2);
    }

    /**
     * The rate as a percentage string for a screen: `500` becomes `5`, `1250`
     * becomes `12.5`, `888` becomes `8.88`.
     *
     * Trailing zeros are trimmed because "5.00%" on a receipt reads as a
     * precision the owner did not ask for.
     */
    public static function percent(?int $rate = null): string
    {
        $rate = $rate === null ? self::rate() : $rate;
        $text = number_format(max(0, $rate) / 100, 2, '.', '');
        $text = rtrim(rtrim($text, '0'), '.');

        // `rtrim('0.00', '0')` leaves `0.` and then `.` leaves ''. A rate of
        // nothing has to read as "0", not as a gap where a number should be.
        return $text !== '' ? $text : '0';
    }

    /**
     * Parse what an owner typed into hundredths of a percent, or null.
     *
     * REFUSED RATHER THAN ROUNDED. `8.875` is not representable here, and
     * silently storing 8.88 would mean the screen shows a rate the owner did
     * not type and every receipt is a tenth of a cent out on a big order. The
     * settings screen says so and asks again — the same call `Money::parse()`
     * makes about fractions of a cent.
     */
    public static function parsePercent(string $typed): ?int
    {
        $typed = trim($typed);
        $typed = str_replace('%', '', $typed);
        $typed = trim($typed);
        if ($typed === '') {
            return 0;
        }

        // A COMMA IS A DECIMAL POINT in most of the world, and an owner typing
        // `12,5` means twelve and a half — not a hundred and twenty five.
        $typed = str_replace(',', '.', $typed);

        if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $typed)) {
            return null;
        }

        $rate = (int) round(((float) $typed) * 100);

        return $rate >= 0 && $rate <= self::MAX_RATE ? $rate : null;
    }

    /**
     * The line a receipt or a basket prints: "GST (5%)".
     *
     * The RATE IS IN IT, because a customer reading "GST $2.50" against a $50
     * basket cannot check it and a customer reading "GST (5%) $2.50" can.
     */
    public static function describe(?int $rate = null): string
    {
        return self::label() . ' (' . self::percent($rate) . '%)';
    }

    /** The same line with both halves handed in, for a screen showing a preview. */
    public static function line(string $label, int $rate): string
    {
        $label = trim($label) !== '' ? trim($label) : 'Tax';

        return $label . ' (' . self::percent($rate) . '%)';
    }
}
