<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Models\Option;

/**
 * MONEY IS AN INTEGER NUMBER OF CENTS. It is never a float, anywhere.
 *
 * ── WHY THIS CLASS EXISTS BEFORE ANYTHING ELSE IN COMMERCE ──────────────────
 * `0.1 + 0.2 !== 0.3` in every language with binary floats, PHP included. A
 * shop that adds three line items in floats produces a total a penny out, and
 * it does so on SOME baskets and not others — which is the worst kind of
 * defect, because it looks like a display bug until somebody reconciles a
 * bank statement. Every amount stored, added, multiplied or compared in this
 * subsystem is an `int` of minor units, and the only float in the file is the
 * one this comment forbids.
 *
 * ── AND PARSING IS DONE ON THE STRING, NOT ON A CAST ────────────────────────
 * The tempting one-liner is `(int) round((float) $typed * 100)`. Measured on
 * this PHP, for `1.005`:
 *
 *     (float) '1.005'         1.00499999999999989342
 *     × 100                 100.49999999999998578915
 *     round()                                    101
 *
 * Note the last step. The product is BELOW 100.5, so the arithmetic says 100 —
 * and `round()` returns 101 anyway, because it applies its own pre-rounding
 * correction for representation error. Two layers of fixing-up, neither of them
 * written here, and the answer is a price the owner did not type.
 *
 * The lesson is not "it rounds down" or "it rounds up" — `19.999` comes out
 * 2000, rounded UP to $20.00. It is that the direction cannot be reasoned about
 * from the input at all. `parse()` splits the string at the decimal point and
 * reads each half as an integer, so what the owner typed is what is stored,
 * exactly, or it is refused.
 *
 * ── THE CURRENCY TABLE IS A TABLE, NOT A LOOKUP ─────────────────────────────
 * `CURRENCIES` is a written-down list. There is no exchange-rate call, no
 * currency API and no remote symbol lookup — nothing is fetched from a third
 * party at runtime, and in any case a shop prices in its own currency and
 * converting at display time is a lie about what will be charged. One currency
 * per install; the order stores the code it was placed in so a later change of
 * setting cannot rewrite history.
 *
 * ── EXPONENT, NOT "TIMES 100" ───────────────────────────────────────────────
 * Yen has no minor unit and Dinar has three. `× 100` is right for most of the
 * table and wrong for some of it, and the wrong ones are wrong by a factor of
 * a hundred — an order for ¥1,200 taken as ¥12. The exponent is per currency
 * and every conversion reads it.
 */
final class Money
{
    /**
     * code => [symbol, exponent, name]
     *
     * Deliberately short. These are the currencies this install can be set to;
     * adding one is a line here and nothing else. The set covers where this
     * software is used rather than trying to be exhaustive — a list of 160
     * that nobody checked is not more correct than a list of 14 that somebody
     * did.
     */
    public const CURRENCIES = [
        'CAD' => ['$',   2, 'Canadian dollar'],
        'USD' => ['$',   2, 'US dollar'],
        'EUR' => ['€',   2, 'Euro'],
        'GBP' => ['£',   2, 'Pound sterling'],
        'AUD' => ['$',   2, 'Australian dollar'],
        'NZD' => ['$',   2, 'New Zealand dollar'],
        'CHF' => ['CHF', 2, 'Swiss franc'],
        'SEK' => ['kr',  2, 'Swedish krona'],
        'NOK' => ['kr',  2, 'Norwegian krone'],
        'DKK' => ['kr',  2, 'Danish krone'],
        'MXN' => ['$',   2, 'Mexican peso'],
        'INR' => ['₹',   2, 'Indian rupee'],
        'ZAR' => ['R',   2, 'South African rand'],
        'JPY' => ['¥',   0, 'Japanese yen'],
    ];

    private const FALLBACK = 'CAD';

    /** The install's currency code. Always one of CURRENCIES' keys. */
    public static function currency(): string
    {
        $code = strtoupper(trim((string) Option::get('commerce_currency', self::FALLBACK)));

        return isset(self::CURRENCIES[$code]) ? $code : self::FALLBACK;
    }

    /** How many decimal places this currency has. */
    public static function exponent(?string $code = null): int
    {
        $code = $code !== null ? strtoupper($code) : self::currency();

        return self::CURRENCIES[$code][1] ?? 2;
    }

    /** The symbol drawn in front of an amount. */
    public static function symbol(?string $code = null): string
    {
        $code = $code !== null ? strtoupper($code) : self::currency();

        return self::CURRENCIES[$code][0] ?? '$';
    }

    /** code => "Canadian dollar (CAD)", for a select. */
    public static function options(): array
    {
        $out = [];
        foreach (self::CURRENCIES as $code => [$symbol, $exp, $name]) {
            $out[$code] = $name . ' (' . $code . ')';
        }

        return $out;
    }

    /**
     * A typed amount to minor units — exactly, or null.
     *
     * Accepts what a person actually types: `19.99`, `$19.99`, `1,299.00`,
     * ` 19 `, `19.`, `.99`. Refuses anything else rather than guessing, because
     * a price that silently becomes 0 is a product given away.
     *
     * NEGATIVES ARE REFUSED HERE. A refund or a discount is a negative amount
     * in an order total, and those are built by arithmetic on ints — never by
     * parsing a minus sign out of a text field the owner typed a price into.
     */
    public static function parse(string $typed, ?string $code = null): ?int
    {
        $exp = self::exponent($code);

        // Strip the decoration a person may type around the number, and the
        // thousands separators they may type inside it. NOT the decimal point.
        $s = trim($typed);
        $s = str_replace([',', ' ', "\u{00A0}"], '', $s);

        // A LEADING SYMBOL COMES OFF, AND THE LIST OF SYMBOLS IS THE TABLE'S.
        // Written out by hand as `ltrim($s, "$€£¥₹R")` it is two defects in one
        // line. The double quotes make PHP read `$€£¥₹R` as a variable, so the
        // argument is the empty string and `$19.99` is refused as malformed;
        // and `ltrim`'s character list is BYTES, so a multibyte symbol would
        // be stripped one byte at a time whether or not it was whole.
        // Reading `CURRENCIES` means a symbol added to the table is understood
        // by the parser in the same edit.
        $symbols = array_map(
            static fn (array $c): string => preg_quote($c[0], '/'),
            array_values(self::CURRENCIES)
        );
        $s = (string) preg_replace('/^(?:' . implode('|', array_unique($symbols)) . ')/u', '', $s);
        $s = trim($s);

        if ($s === '' || !preg_match('/^(\d*)(?:\.(\d*))?$/', $s, $m)) {
            return null;
        }

        $whole = $m[1] ?? '';
        $frac  = $m[2] ?? '';
        if ($whole === '' && $frac === '') {
            return null;   // a lone "." is not an amount
        }

        // MORE PLACES THAN THE CURRENCY HAS IS A REFUSAL, NOT A ROUNDING.
        // Rounding here would accept "19.999" and charge 20.00, which is a
        // price the owner never set. Let them see the field rejected instead.
        if (strlen($frac) > $exp) {
            return null;
        }

        $frac = str_pad($frac, $exp, '0');

        // Both halves are digit strings, so this is exact integer arithmetic.
        return (int) ($whole === '' ? '0' : $whole) * (10 ** $exp) + (int) ($frac === '' ? '0' : $frac);
    }

    /**
     * Minor units to the string a person reads — symbol, separators and all.
     *
     * The currency is passed in when the caller has one on record (an order
     * knows what it was placed in); it falls back to the setting otherwise.
     */
    public static function format(int $minor, ?string $code = null): string
    {
        $code = $code !== null ? strtoupper($code) : self::currency();
        $exp  = self::exponent($code);
        $sign = $minor < 0 ? '-' : '';
        $abs  = abs($minor);

        $whole = intdiv($abs, 10 ** $exp);
        $frac  = $abs % (10 ** $exp);

        $text = $sign . self::symbol($code) . number_format((float) $whole, 0, '.', ',');
        if ($exp > 0) {
            $text .= '.' . str_pad((string) $frac, $exp, '0', STR_PAD_LEFT);
        }

        return $text;
    }

    /**
     * Minor units to the bare number, for a form field the owner edits.
     *
     * No symbol and no thousands separator: this goes back INTO an input, and
     * `parse()` has to be able to read it. `format()` and `edit()` are not
     * interchangeable and the names say which is which.
     */
    public static function edit(int $minor, ?string $code = null): string
    {
        $exp = self::exponent($code);
        if ($exp === 0) {
            return (string) $minor;
        }

        $sign  = $minor < 0 ? '-' : '';
        $abs   = abs($minor);
        $whole = intdiv($abs, 10 ** $exp);
        $frac  = $abs % (10 ** $exp);

        return $sign . $whole . '.' . str_pad((string) $frac, $exp, '0', STR_PAD_LEFT);
    }
}
