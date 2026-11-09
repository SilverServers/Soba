<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Database\Database;
use Cms\Models\Meta;

/**
 * PRICES AND COUNTS CHANGED ACROSS A SELECTION OF PRODUCTS.
 *
 * The product list's bulk edit, on a type that sells: a price changed to a
 * figure, or raised or lowered by an amount or a percentage; a sale price
 * changed to a figure, set at the price less an amount or a percentage, or
 * ended; a count changed to a number, added to or taken from.
 *
 * ── EVERY PRICE THE PRODUCT IS SOLD AT ──────────────────────────────────────
 * The product's own price and sale price, and those of each option with a
 * price of its own: a hoodie whose XL costs more is raised 10% in every size,
 * and goes on sale at 20% off in every size. An option without a price of its
 * own follows the product's already.
 *
 * ── A SALE SET HERE IS ON NOW ───────────────────────────────────────────────
 * Its days are cleared, so a sale that ended last month is not set again to no
 * effect: a sale started from the list starts now, and one ended ends now. A
 * sale price that would not be below its price is refused, by the product's
 * name, whichever of the two moved.
 *
 * ── EVERY COUNT THE PRODUCT KEEPS ───────────────────────────────────────────
 * Its own, or each option's that counts itself (`Stock::countsOf()`): "Add 5"
 * to a hoodie is five more of every size, a size run delivered. Each through
 * the stock screen's compare-and-set, recorded in the history as a bulk edit.
 * A count that would go under nothing is refused, not floored.
 *
 * ── THE WHOLE CHANGE OR NONE OF IT ──────────────────────────────────────────
 * Every new figure is worked out (`plan()`) before anything is written
 * (`write()`), so a product refused for one of them keeps all of them; and a
 * count sold from between the two leaves the product as it was.
 */
final class ProductBulk
{
    /** What each control offers, as its select says it. */
    public const PRICE = ['set' => 'Change to', 'raise' => 'Raise by', 'lower' => 'Lower by'];
    public const SALE  = ['set' => 'Change to', 'less' => 'The price less', 'end' => 'End the sale'];
    public const STOCK = ['set' => 'Change to', 'add' => 'Add', 'take' => 'Take off'];

    /** Why a figure typed in the panel is not one, for each thing it changes. */
    private const REFUSALS = [
        'price' => [
            'set'   => 'A price is changed to an amount, such as 40.00.',
            'raise' => 'A price is raised by an amount, such as 5.00, or a percentage, such as 10%.',
            'lower' => 'A price is lowered by an amount, such as 5.00, or a percentage up to 100%.',
        ],
        'sale' => [
            'set'  => 'A sale price is changed to an amount, such as 30.00.',
            'less' => 'A sale price is the price less an amount, such as 5.00, or a percentage up to 100%.',
        ],
        'stock' => [
            'set'  => 'A count is changed to a whole number, 0 or more.',
            'add'  => 'A count is added to by a whole number, 1 or more.',
            'take' => 'A count is taken from by a whole number, 1 or more.',
        ],
    ];

    /**
     * What the panel asks of prices and counts, read once for the selection:
     * the changes, or the sentence saying why a figure is not one.
     *
     * @param array<string, mixed> $post
     * @return array{changes: array<string, array{how: string, amount: int, percent: bool}>, error: ?string}
     */
    public static function request(array $post): array
    {
        $changes = [];
        foreach (['price' => self::PRICE, 'sale' => self::SALE, 'stock' => self::STOCK] as $what => $hows) {
            $in  = is_array($post['bulk_' . $what] ?? null) ? $post['bulk_' . $what] : [];
            $how = (string) ($in['how'] ?? '');
            if (!isset($hows[$how])) {
                continue;
            }
            if ($how === 'end') {
                $changes[$what] = ['how' => $how, 'amount' => 0, 'percent' => false];
                continue;
            }
            $figure = self::figure($what, $how, trim((string) ($in['amount'] ?? '')));
            if ($figure === null) {
                return ['changes' => [], 'error' => self::REFUSALS[$what][$how]];
            }
            $changes[$what] = ['how' => $how] + $figure;
        }

        return ['changes' => $changes, 'error' => null];
    }

    /**
     * One product's new figures, worked out and nothing written: what to
     * write, or why it is refused, in a sentence the report puts after its
     * name.
     *
     * @param array<string, array{how: string, amount: int, percent: bool}> $changes from request()
     * @return array{error: ?string, meta: array<string, string>, options: array<int, array{0: int, 1: ?int}>,
     *               counts: list<array{0: ?int, 1: int, 2: int}>}
     */
    public static function plan(int $postId, array $changes): array
    {
        $refuse = static fn (string $why): array => ['error' => $why, 'meta' => [], 'options' => [], 'counts' => []];
        $meta   = Meta::getAll($postId);

        // ── THE PRICES ─────────────────────────────────────────────────────
        $price = Money::parse((string) ($meta[Product::PRICE] ?? ''));
        $sale  = Money::parse((string) ($meta[Product::SALE_PRICE] ?? ''));
        $own   = [];
        foreach (Variation::forProduct($postId) as $o) {
            if ($o['price'] !== null) {
                $own[(int) $o['id']] = [(int) $o['price'], $o['sale_price'] !== null ? (int) $o['sale_price'] : null, (string) $o['label']];
            }
        }
        $newPrice = $price;
        $newSale  = $sale;
        $newOwn   = $own;

        if (isset($changes['price'])) {
            $c = $changes['price'];
            if ($price === null && $c['how'] !== 'set') {
                return $refuse('It has no price to ' . ($c['how'] === 'raise' ? 'raise' : 'lower') . '.');
            }
            $newPrice = $c['how'] === 'set' ? $c['amount'] : self::moved((int) $price, $c);
            foreach ($newOwn as $id => $o) {
                $newOwn[$id][0] = $c['how'] === 'set' ? $c['amount'] : self::moved($o[0], $c);
            }
        }
        if (isset($changes['sale'])) {
            $c = $changes['sale'];
            if ($c['how'] === 'less' && $newPrice === null) {
                return $refuse('It has no price to take a sale from.');
            }
            $saleOf  = static fn (int $from): ?int => match ($c['how']) {
                'end'   => null,
                'set'   => $c['amount'],
                default => self::moved($from, ['how' => 'lower'] + $c),
            };
            $newSale = $saleOf((int) $newPrice);
            foreach ($newOwn as $id => $o) {
                $newOwn[$id][1] = $saleOf($o[0]);
            }
        }
        if (isset($changes['price']) || isset($changes['sale'])) {
            if ($newSale !== null && $newPrice !== null && $newSale >= $newPrice) {
                return $refuse('Its sale price, ' . Money::format($newSale) . ', would not be below its price, '
                    . Money::format($newPrice) . '.');
            }
            foreach ($newOwn as $o) {
                if ($o[1] !== null && $o[1] >= $o[0]) {
                    return $refuse('Its ' . $o[2] . ' would sell at ' . Money::format($o[1]) . ', not below its price, '
                        . Money::format($o[0]) . '.');
                }
            }
        }

        $metaOut = [];
        if (isset($changes['price']) && $newPrice !== $price) {
            $metaOut[Product::PRICE] = Money::edit((int) $newPrice);
        }
        if (isset($changes['sale'])) {
            $metaOut[Product::SALE_PRICE] = $newSale === null ? '' : Money::edit($newSale);
            $metaOut[Product::SALE_FROM]  = '';
            $metaOut[Product::SALE_TO]    = '';
        }
        $optionsOut = [];
        foreach ($newOwn as $id => $o) {
            if ([$o[0], $o[1]] !== [$own[$id][0], $own[$id][1]]) {
                $optionsOut[$id] = [$o[0], $o[1]];
            }
        }

        // ── THE COUNTS ─────────────────────────────────────────────────────
        $counts = [];
        if (isset($changes['stock'])) {
            $c    = $changes['stock'];
            $kept = Stock::countsOf($postId);
            if ($kept === []) {
                return $refuse('It does not count its stock.');
            }
            foreach ($kept as [, $variationId, $was, $name]) {
                $now = match ($c['how']) {
                    'set'   => $c['amount'],
                    'add'   => $was + $c['amount'],
                    default => $was - $c['amount'],
                };
                if ($now < 0) {
                    return $refuse(($name !== '' ? 'Its ' . $name : 'It') . ' has ' . $was . ' in stock, so '
                        . $c['amount'] . ' cannot be taken off.');
                }
                if ($now !== $was) {
                    $counts[] = [$variationId, $was, $now];
                }
            }
        }

        return ['error' => null, 'meta' => $metaOut, 'options' => $optionsOut, 'counts' => $counts];
    }

    /**
     * The planned figures written, the counts first by their compare-and-set:
     * one sold from since the plan leaves the whole product as it was, and
     * says so.
     *
     * @param array{meta: array<string, string>, options: array<int, array{0: int, 1: ?int}>,
     *              counts: list<array{0: ?int, 1: int, 2: int}>} $plan from plan()
     */
    public static function write(int $postId, array $plan, string $by): ?string
    {
        $db  = Database::getInstance();
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            $moved = StockLog::during('bulk', null, $by, static function () use ($postId, $plan): bool {
                foreach ($plan['counts'] as [$variationId, $was, $now]) {
                    $set = $variationId !== null ? Variation::setCount($variationId, $was, $now) : Stock::setCount($postId, $was, $now);
                    if (!$set) {
                        return true;
                    }
                }
                return false;
            });
            if ($moved) {
                if ($own) {
                    $db->rollBack();
                }
                return 'A count changed while this was saved, so it was left as it is now.';
            }
            foreach ($plan['meta'] as $key => $value) {
                Meta::set($postId, $key, $value);
            }
            foreach ($plan['options'] as $id => [$price, $sale]) {
                Variation::setPrices($id, $price, $sale);
            }
            if ($own) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return null;
    }

    /** Whether `plan()` found anything to write. */
    public static function changes(array $plan): bool
    {
        return $plan['meta'] !== [] || $plan['options'] !== [] || $plan['counts'] !== [];
    }

    /**
     * The amount typed, read for what it changes: a whole count, a sum of
     * money, or a percentage kept in hundredths so its arithmetic is whole
     * numbers. Null when it cannot be one.
     *
     * @return array{amount: int, percent: bool}|null
     */
    private static function figure(string $what, string $how, string $typed): ?array
    {
        if ($what === 'stock') {
            if (!preg_match('/^\d{1,6}$/', $typed)) {
                return null;
            }
            return $how === 'set' || (int) $typed > 0 ? ['amount' => (int) $typed, 'percent' => false] : null;
        }
        if (preg_match('/^(\d{1,4})(?:\.(\d{1,2}))?\s*%$/', $typed, $m)) {
            $hundredths = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
            if ($how === 'set' || $hundredths <= 0 || ($how !== 'raise' && $hundredths > 10000)) {
                return null;
            }
            return ['amount' => $hundredths, 'percent' => true];
        }
        $minor = Money::parse($typed);
        if ($minor === null || ($how !== 'set' && $minor <= 0)) {
            return null;
        }

        return ['amount' => $minor, 'percent' => false];
    }

    /**
     * A figure raised or lowered by an amount or a percentage, to the nearest
     * whole cent and never under nothing.
     *
     * @param array{how: string, amount: int, percent: bool} $by
     */
    private static function moved(int $from, array $by): int
    {
        $step = $by['percent'] ? intdiv($from * $by['amount'] + 5000, 10000) : $by['amount'];

        return max(0, $by['how'] === 'raise' ? $from + $step : $from - $step);
    }
}
