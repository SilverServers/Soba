<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Models\Option;

/**
 * THE RATES A SHOP CHARGES, AND WHERE.
 *
 * ── A ROW IS A PLACE, A NAME AND A RATE ─────────────────────────────────────
 * "Ontario, HST, 13%". "Canada, GST, 5%". "British Columbia, PST, 7%". A row
 * names a country and, for a country the checkout lists provinces or states
 * of (`Regions`), one of them; a row with no country is everywhere.
 *
 * ── THE MOST PARTICULAR PLACE WINS, WHOLE ──────────────────────────────────
 * An order is charged every row for its province if there are any; else every
 * row for its country; else every row for everywhere. Ontario's HST is the
 * GST with Ontario's part added, so Ontario's own row has to REPLACE Canada's
 * GST rather than sit beside it; British Columbia charges both GST and PST,
 * so its own rows say both. That is the whole rule, and the settings screen
 * prints what it comes to for every province, so an owner reads "British
 * Columbia: GST 5% + PST 7%" rather than working it out.
 *
 * Priorities and a flag for a tax charged on another tax could say the same.
 * Rows that replace are one idea instead of two, and nothing in Canada's taxes
 * compounds since Quebec stopped in 2013.
 *
 * ── THOUSANDTHS OF A PERCENT ────────────────────────────────────────────────
 * 5% is 5000, 13% is 13000, Quebec's QST is 9975. Hundredths could not hold
 * 9.975%, and a rate stored as something the owner did not type is a receipt
 * nobody can check. A rate with more places than three is refused, not
 * rounded, as `Money::parse()` refuses a fraction of a cent.
 *
 * ── WHERE THEY ARE KEPT ─────────────────────────────────────────────────────
 * One option, a list of rows, written by Settings → Shop. Migration 096 made
 * the single rate a shop had into its first row, charged everywhere as before.
 */
final class TaxRates
{
    public const KEY = 'commerce_tax_rates';

    /** The shop's own province, beside `commerce_country`. */
    public const REGION_KEY = 'commerce_region';

    /** 100%, in the unit rates are kept in. */
    public const WHOLE = 100000;

    /** A tax's name is printed on receipts and stored on orders (VARCHAR(60)). */
    public const LABEL_MAX = 60;

    /**
     * The rows as stored, cleaned — what every reader goes through, so a row
     * an import or an older screen wrote is read the same way.
     *
     * @return list<array{country: string, region: string, label: string, rate: int}>
     */
    public static function all(): array
    {
        $stored = json_decode((string) (Option::get(self::KEY) ?? ''), true);

        return self::clean(is_array($stored) ? $stored : []);
    }

    /**
     * Rows made safe to use: a known country or none, a listed province of it
     * or none, a name, and a rate within 0–100%. A row with neither a name nor
     * a rate is a blank line on the form and is dropped.
     *
     * A PLACE OR A RATE IT DOES NOT KNOW DROPS THE ROW. Read as "no country",
     * a row for a mistyped country would be charged on every order everywhere;
     * read as "the whole country", one for a mistyped province would be
     * charged across Canada. The screen offers only lists, so only a crafted
     * post or a damaged import can send one.
     *
     * @param  array<int|string, mixed> $rows
     * @return list<array{country: string, region: string, label: string, rate: int}>
     */
    public static function clean(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $country = strtoupper(trim((string) ($row['country'] ?? '')));
            $typed   = trim((string) ($row['region'] ?? ''));
            $region  = $country !== '' && $typed !== '' ? Regions::code($country, $typed) : '';
            $label   = mb_substr(trim((string) ($row['label'] ?? '')), 0, self::LABEL_MAX);
            $rate    = is_numeric($row['rate'] ?? null) ? (int) $row['rate'] : -1;
            if ($label === '' && $rate <= 0) {
                continue;
            }
            if (($country !== '' && !Countries::isValid($country)) || ($typed !== '' && $region === '')
                || $rate < 0 || $rate > self::WHOLE) {
                continue;
            }
            $out[] = [
                'country' => $country,
                'region'  => $region,
                'label'   => $label !== '' ? $label : 'Tax',
                'rate'    => $rate,
            ];
        }

        return $out;
    }

    /**
     * What an order going here is charged: its province's rows, else its
     * country's, else everywhere's. Each row once, in the owner's order.
     *
     * @param  list<array{country: string, region: string, label: string, rate: int}> $rows
     * @return list<array{label: string, rate: int}>
     */
    public static function forPlace(array $rows, string $country, string $region): array
    {
        $country = strtoupper(trim($country));
        $region  = strtoupper(trim($region));

        $places = [];
        if ($country !== '' && $region !== '') {
            $places[] = [$country, $region];
        }
        if ($country !== '') {
            $places[] = [$country, ''];
        }
        $places[] = ['', ''];

        foreach ($places as [$c, $r]) {
            $hit = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['country'] === $c && $row['region'] === $r
            ));
            if ($hit !== []) {
                return array_map(static fn (array $row): array => ['label' => $row['label'], 'rate' => $row['rate']], $hit);
            }
        }

        return [];
    }

    /**
     * Where the shop is — Settings → Shop's country and province. The place a
     * basket's tax is estimated for before an address is known, and the place
     * an order collected in person is taxed for.
     *
     * @return array{country: string, region: string}
     */
    public static function home(): array
    {
        $country = Countries::shopDefault();

        return ['country' => $country, 'region' => Regions::code($country, (string) (Option::get(self::REGION_KEY) ?? ''))];
    }

    /** "Ontario"; "Canada" for a country asked about whole; blank for nowhere. */
    public static function placeName(string $country, string $region): string
    {
        if ($region !== '' && Regions::isValid($country, $region)) {
            return Regions::name($country, $region);
        }

        return $country !== '' && Countries::isValid($country) ? Countries::name($country) : '';
    }

    /** Do this country's provinces or states have rates of their own? */
    public static function dependOnRegion(array $rows, string $country): bool
    {
        $country = strtoupper(trim($country));
        foreach ($rows as $row) {
            if (($row['country'] ?? '') === $country && ($row['region'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /** Does what an order is charged depend on where it goes? */
    public static function dependOnPlace(array $rows): bool
    {
        foreach ($rows as $row) {
            if (($row['country'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * A typed percentage as thousandths of one: "13" is 13000, "9.975" is
     * 9975, "5%" is 5000. Blank is 0. Null for anything else — a fourth
     * decimal place, a negative, a word, over 100 — so the screen can say so.
     */
    public static function parse(string $typed): ?int
    {
        $typed = str_replace([',', '%', ' '], ['.', '', ''], trim($typed));
        if ($typed === '') {
            return 0;
        }
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,3}))?$/', $typed, $m) !== 1) {
            return null;
        }
        $rate = (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '', 3, '0');

        return $rate <= self::WHOLE ? $rate : null;
    }

    /** A rate as the owner would type it: 13000 is "13", 9975 is "9.975". */
    public static function percent(int $rate): string
    {
        $whole = intdiv($rate, 1000);
        $part  = rtrim(str_pad((string) ($rate % 1000), 3, '0', STR_PAD_LEFT), '0');

        return $part === '' ? (string) $whole : $whole . '.' . $part;
    }

    /** "HST (13%)" — the line a receipt prints. */
    public static function line(string $label, int $rate): string
    {
        return $label . ' (' . self::percent($rate) . '%)';
    }

    /**
     * CANADA'S GST AND HST, as rows to review before saving: the GST
     * everywhere in Canada, and the HST that replaces it in the five provinces
     * that charge one. A business registered for GST/HST charges these
     * wherever its customers are.
     *
     * NOT the provincial taxes — British Columbia's and Saskatchewan's PST,
     * Manitoba's RST, Quebec's QST. A business charges those only where it is
     * registered for them, and then adds that province's rows: GST and the
     * provincial tax together, since the province's rows replace Canada's.
     *
     * Rates as published by the Canada Revenue Agency; Nova Scotia's HST has
     * been 14% since 1 April 2025. The screen says to check them, because a
     * rate changed after this was written is not one this file can know.
     *
     * @return list<array{country: string, region: string, label: string, rate: int}>
     */
    public static function canada(): array
    {
        $rows = [['country' => 'CA', 'region' => '', 'label' => 'GST', 'rate' => 5000]];
        foreach (['ON' => 13000, 'NB' => 15000, 'NL' => 15000, 'NS' => 14000, 'PE' => 15000] as $province => $rate) {
            $rows[] = ['country' => 'CA', 'region' => $province, 'label' => 'HST', 'rate' => $rate];
        }

        return $rows;
    }
}
