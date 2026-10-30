<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Models\Option;

/**
 * WHAT DELIVERY COSTS, BY WHERE IT GOES — and the order a customer collects.
 *
 * ── A ROW IS A PLACE, A RATE AND A THRESHOLD ────────────────────────────────
 * "Ontario, $8, free over $75". "Canada, $15, free over $150". "United
 * States, $25". Places are read as tax rates read them (`TaxRates`): an order
 * is charged its province's row if there is one, else its country's, else the
 * row for everywhere. A place no row reaches is one the shop does not deliver
 * to, and the checkout says so rather than charging nothing.
 *
 * NO ROWS AT ALL means delivery costs nothing, anywhere: a shop that hands
 * everything over in person, or prices delivery into its goods, never has to
 * open this. Migration 097 made a flat rate into one row for everywhere.
 *
 * A ROW PER PLACE, NOT ZONES THAT GROUP REGIONS: a shop that posts by
 * province has nothing to group, and it is the rule the tax already follows,
 * so an owner learns one.
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
 * rows say.
 *
 * ── OR COLLECTED ────────────────────────────────────────────────────────────
 * With pickup on, the checkout offers it beside delivery: no charge, the
 * owner's words for where and when, and the shop's own province's tax, since
 * that is where the sale happens.
 *
 * ── THE ARITHMETIC TAKES ITS INPUTS ─────────────────────────────────────────
 * `compute()` and `rateFor()` take everything they use, so
 * `admin/bin/check-totals.php` asserts them without a database.
 */
final class Shipping
{
    public const RATES_KEY        = 'commerce_ship_rates';
    public const NOTE_KEY         = 'commerce_ship_note';
    public const PICKUP_KEY       = 'commerce_pickup';
    public const PICKUP_WHERE_KEY = 'commerce_pickup_where';

    /** The per-product flag that makes something deliverable. */
    public const NEEDS_DELIVERY = 'product_needs_delivery';

    /** How an order reaches its customer. */
    public const DELIVERY = 'delivery';
    public const PICKUP   = 'pickup';

    /**
     * The rows as stored, cleaned.
     *
     * @return list<array{country: string, region: string, rate: int, free_over: int}>
     */
    public static function rates(): array
    {
        $stored = json_decode((string) (Option::get(self::RATES_KEY) ?? ''), true);

        return self::clean(is_array($stored) ? $stored : []);
    }

    /**
     * Rows made safe to use: a known country or none, a listed province of it
     * or none, a rate and a threshold of nothing or more. A place it does not
     * know drops the row — read as everywhere, a row for a mistyped country
     * would set what every order pays.
     *
     * @param  array<int|string, mixed> $rows
     * @return list<array{country: string, region: string, rate: int, free_over: int}>
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
            if (($country !== '' && !Countries::isValid($country)) || ($typed !== '' && $region === '')
                || !is_numeric($row['rate'] ?? null)) {
                continue;
            }
            $out[] = [
                'country'   => $country,
                'region'    => $region,
                'rate'      => max(0, (int) $row['rate']),
                'free_over' => max(0, (int) ($row['free_over'] ?? 0)),
            ];
        }

        return $out;
    }

    /**
     * What delivery to a place costs: its province's row, else its country's,
     * else everywhere's. Null when the shop has rows and none reaches it — not
     * delivered there. With no rows at all, nothing, anywhere.
     *
     * @param  list<array{country: string, region: string, rate: int, free_over: int}> $rows
     * @return array{rate: int, free_over: int}|null
     */
    public static function rateFor(array $rows, string $country, string $region): ?array
    {
        if ($rows === []) {
            return ['rate' => 0, 'free_over' => 0];
        }

        $country = strtoupper(trim($country));
        $region  = strtoupper(trim($region));
        $places  = [];
        if ($country !== '' && $region !== '') {
            $places[] = [$country, $region];
        }
        if ($country !== '') {
            $places[] = [$country, ''];
        }
        $places[] = ['', ''];

        foreach ($places as [$c, $r]) {
            foreach ($rows as $row) {
                if ($row['country'] === $c && $row['region'] === $r) {
                    return ['rate' => $row['rate'], 'free_over' => $row['free_over']];
                }
            }
        }

        return null;
    }

    /** Do this shop's rows set different charges for different places? */
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
     * What delivery costs on a basket, in minor units.
     *
     * Free when nothing in it needs delivering, when the rate is nothing, or
     * when the goods reach the threshold — measured on the goods BEFORE any
     * discount, so a code never takes a customer back under it.
     */
    public static function compute(int $subtotal, bool $deliverable, int $rate, int $freeOver): int
    {
        if (!$deliverable || $rate <= 0) {
            return 0;
        }
        if ($freeOver > 0 && $subtotal >= $freeOver) {
            return 0;
        }

        return $rate;
    }

    /**
     * Delivery on the basket as it stands, to a place — or to the shop's own
     * place as an estimate before an address is known. Collected, nothing;
     * to a place the shop does not deliver to, nothing here, and the checkout
     * refuses it.
     *
     * @param array{country?: string, region?: string}|null $place
     */
    public static function forCart(?array $place = null, string $method = self::DELIVERY): int
    {
        if ($method === self::PICKUP) {
            return 0;
        }
        $place ??= TaxRates::home();
        $rate    = self::rateFor(self::rates(), (string) ($place['country'] ?? ''), (string) ($place['region'] ?? ''));
        if ($rate === null) {
            return 0;
        }

        return self::compute(Cart::subtotal(), Cart::hasDeliverable(), $rate['rate'], $rate['free_over']);
    }

    /** Does the shop deliver to this place? */
    public static function deliversTo(string $country, string $region): bool
    {
        return self::rateFor(self::rates(), $country, $region) !== null;
    }

    /** Can a customer collect their order? */
    public static function pickup(): bool
    {
        return Option::get(self::PICKUP_KEY, '0') === '1';
    }

    /** Where and when an order is collected, in the owner's words. */
    public static function pickupWhere(): string
    {
        return trim((string) Option::get(self::PICKUP_WHERE_KEY, ''));
    }

    public static function note(): string
    {
        return trim((string) Option::get(self::NOTE_KEY, ''));
    }

    /** Is anything in the basket delivered at all? */
    public static function appliesToCart(): bool
    {
        return Cart::hasDeliverable();
    }

    /**
     * The line under the totals about delivery to this place: how much more
     * makes it free, that it is free, or that the shop does not deliver there.
     * Blank when there is nothing to say.
     *
     * @param array{country?: string, region?: string}|null $place
     */
    public static function noteFor(int $subtotal, ?array $place = null, string $method = self::DELIVERY): string
    {
        if (!self::appliesToCart() || $method === self::PICKUP) {
            return '';
        }
        $place ??= TaxRates::home();
        $country = (string) ($place['country'] ?? '');
        $region  = (string) ($place['region'] ?? '');
        $rate    = self::rateFor(self::rates(), $country, $region);
        if ($rate === null) {
            return self::notDelivered($country, $region);
        }
        if ($rate['rate'] <= 0 || $rate['free_over'] <= 0) {
            return '';
        }
        if ($subtotal >= $rate['free_over']) {
            return 'Delivery is free on this order.';
        }

        return 'Spend ' . Money::format($rate['free_over'] - $subtotal) . ' more and delivery is free.';
    }

    /**
     * "We do not deliver to the United States." The country, unless the shop
     * has rows for some of its provinces or states — then the one asked about,
     * since others of them are delivered to. Naming New York when no state is
     * would suggest the next one over might be.
     */
    public static function notDelivered(string $country, string $region): string
    {
        $byRegion = false;
        foreach (self::rates() as $row) {
            if ($row['country'] === strtoupper(trim($country)) && $row['region'] !== '') {
                $byRegion = true;
                break;
            }
        }
        $name = TaxRates::placeName($country, $byRegion ? $region : '');

        return 'We do not deliver to ' . ($name !== '' ? self::withThe($name) : 'that address') . '.'
             . (self::pickup() ? ' You can collect your order instead.' : '');
    }

    /** "the United States", "the Netherlands" — the names a sentence needs "the" before. */
    private static function withThe(string $name): string
    {
        return preg_match('/^(United |Netherlands$|Philippines$|Bahamas$|Gambia$|Czech Republic$|Dominican Republic$|'
                        . 'Central African Republic$|Maldives$|Marshall Islands$|Solomon Islands$|Cayman Islands$)/', $name) === 1
            ? 'the ' . $name : $name;
    }

    /** The owner's own delivery note, for a basket with something to deliver. */
    public static function noteForCart(): string
    {
        return self::appliesToCart() ? self::note() : '';
    }

    /**
     * How an order reaches its customer, as its receipt says it: "Delivery to
     * Ontario", "Collected: 12 Queen St W, weekdays 10–4". Copied onto the
     * order, so changing the pickup address later does not rewrite a receipt.
     *
     * @param array{country?: string, region?: string} $place
     */
    public static function methodTitle(string $method, array $place): string
    {
        if ($method === self::PICKUP) {
            $where = self::pickupWhere();
            return 'Collected' . ($where !== '' ? ': ' . $where : '');
        }
        $name = TaxRates::placeName((string) ($place['country'] ?? ''), (string) ($place['region'] ?? ''));

        return 'Delivery' . ($name !== '' ? ' to ' . $name : '');
    }
}
