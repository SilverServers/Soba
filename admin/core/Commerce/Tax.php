<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Models\Option;

/**
 * THE TAX ON AN ORDER — a line for each rate the place it goes to charges,
 * exact in integers.
 *
 * ── WHICH RATES ─────────────────────────────────────────────────────────────
 * `TaxRates` decides: an Ontario order is charged 13% HST, an Alberta one 5%
 * GST, a Quebec one GST and QST on two lines. This class does the sums for
 * whatever rates it is handed, and knows nothing about places.
 *
 * ── ROUNDING IS DECIDED HERE, ONCE PER LINE ─────────────────────────────────
 * Half up, on the whole taxable amount, once for each tax — never per item.
 * Per-item rounding makes the tax on ten copies of a $0.99 item differ from
 * the tax on one $9.90 item, and the difference shows up as a cent the
 * customer can see and the owner cannot explain. A receipt with GST and QST
 * rounds each, as the two governments each expect their own figure.
 *
 * ── INCLUDED OR ADDED ───────────────────────────────────────────────────────
 * Added, each line is its rate of the amount. Included, the amount already
 * holds every line, so each is its rate's share of what the amount holds:
 * 13% HST inside $113.00 is $13.00, not 13% of $113.00.
 *
 * ── THE ARITHMETIC TAKES ITS INPUTS; IT DOES NOT FETCH THEM ─────────────────
 * `lines()`, `percent()` and `parsePercent()` are PURE — amount in, rates in,
 * numbers out — so `admin/bin/check-totals.php` asserts every case without a
 * database. The alternative is a seam on `Option`, a back door into a
 * production model that exists only so a script can lie to it.
 *
 * ── HUNDREDTHS FOR COUPONS, THOUSANDTHS FOR TAX ─────────────────────────────
 * A tax rate is kept in thousandths of a percent (`TaxRates`), because
 * Quebec's 9.975% has three places. A percentage coupon is still kept in
 * hundredths, which `parsePercent()` and `percent()` read and write: 12.5% off
 * is as fine as a discount needs to be.
 */
final class Tax
{
    public const INCLUSIVE_KEY = 'commerce_tax_inclusive';

    /** 100% in hundredths, the unit a percentage coupon is kept in. */
    public const MAX_RATE = 10000;

    /** Do the shop's prices already include tax? */
    public static function isInclusive(): bool
    {
        return Option::get(self::INCLUSIVE_KEY, '0') === '1';
    }

    /**
     * The tax on an amount: one line per rate, each rounded half up once.
     *
     * A rate outside 0–100% — hand-edited, or left by an older screen — is
     * held to it rather than trusted: no stored number can charge 900%.
     *
     * @param  list<array{label: string, rate: int}> $rates thousandths of a percent
     * @return list<array{label: string, rate: int, amount: int}>
     */
    public static function lines(int $amount, array $rates, bool $inclusive): array
    {
        $rates = array_map(static fn (array $r): array => [
            'label' => trim((string) ($r['label'] ?? '')) !== '' ? trim((string) $r['label']) : 'Tax',
            'rate'  => max(0, min((int) ($r['rate'] ?? 0), TaxRates::WHOLE)),
        ], $rates);
        $all = array_sum(array_column($rates, 'rate'));

        $out = [];
        foreach ($rates as $r) {
            $amountOf = 0;
            if ($r['rate'] > 0 && $amount > 0) {
                $numerator   = $amount * $r['rate'];
                $denominator = $inclusive ? TaxRates::WHOLE + $all : TaxRates::WHOLE;
                $amountOf    = intdiv($numerator * 2 + $denominator, $denominator * 2);
            }
            $out[] = ['label' => $r['label'], 'rate' => $r['rate'], 'amount' => $amountOf];
        }

        return $out;
    }

    /**
     * A coupon's percentage as typed: 500 → "5", 1250 → "12.5", 888 → "8.88".
     * Hundredths; a tax rate goes through `TaxRates::percent()`.
     */
    public static function percent(int $rate): string
    {
        $text = number_format(max(0, $rate) / 100, 2, '.', '');
        $text = rtrim(rtrim($text, '0'), '.');

        return $text !== '' ? $text : '0';
    }

    /**
     * A typed percentage as hundredths of one, for a coupon: "12.5" is 1250.
     * Blank is 0. Null for a third decimal place, a negative, a word or more
     * than 100 — REFUSED rather than rounded, because a stored number the
     * owner did not type is one nobody can check against the screen.
     */
    public static function parsePercent(string $typed): ?int
    {
        $typed = trim(str_replace('%', '', trim($typed)));
        if ($typed === '') {
            return 0;
        }

        $typed = str_replace(',', '.', $typed);
        if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $typed)) {
            return null;
        }

        $rate = (int) round(((float) $typed) * 100);

        return $rate >= 0 && $rate <= self::MAX_RATE ? $rate : null;
    }

    /**
     * An order placed before tax was charged by place carries one rate, in
     * hundredths, on its own row: "GST (5%)". Read only for those.
     */
    public static function line(string $label, int $rate): string
    {
        $label = trim($label) !== '' ? trim($label) : 'Tax';

        return $label . ' (' . self::percent($rate) . '%)';
    }
}
