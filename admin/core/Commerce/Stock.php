<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Database\Database;
use Cms\Models\Meta;
use PDO;

/**
 * COUNTING WHAT IS LEFT, AND REFUSING TO SELL WHAT IS NOT.
 *
 * ── THE PROBLEM THIS SOLVES ─────────────────────────────────────────────────
 * `product_stock_status` is a WORD an owner sets by hand — in stock, out of
 * stock, on backorder. It is honest for a plumber selling a service and useless
 * for anybody selling objects: sell five of a thing you have three of and
 * nothing anywhere notices. The owner finds out when they go to pack them.
 *
 * ── THE DATABASE DECIDES, NOT A PHP CHECK ───────────────────────────────────
 * The same rule `Appointments` follows, for the same reason. "Read the count,
 * see it is enough, write the new count" is a race whose window is exactly when
 * two people are buying the last one — both read 1, both write 0, and two
 * strangers have bought the same object.
 *
 *     UPDATE post_meta SET meta_value = meta_value - :n
 *      WHERE post_id = :id AND meta_key = 'product_stock_qty'
 *        AND CAST(meta_value AS SIGNED) >= :n
 *
 * One statement. The row is locked for its duration, the WHERE is evaluated
 * against the CURRENT value, and `rowCount()` says whether it happened. The
 * second buyer's UPDATE matches nothing and they are told so.
 *
 * ── WHY meta AND NOT A `stock` TABLE ────────────────────────────────────────
 * Fewer storage layers, not more. Stock is a fact about a product, products
 * are entries, and entries already have `post_meta` with a UNIQUE key on
 * `(post_id, meta_key)` — which is what makes the statement above safe. A
 * `stock` table would need its own screen, its own writes and its own way of
 * going out of step with the field group the owner actually edits.
 *
 * The cost is `CAST(meta_value AS SIGNED)` on a longtext column, which cannot
 * use an index. At the scale this software is for — a shop with hundreds of
 * products, not millions — that is a full scan of nothing. Named here so the
 * day it stops being true, the reason to change it is already written down.
 *
 * ── AND COUNTING IS OPT-IN ──────────────────────────────────────────────────
 * `product_manage_stock` off means this class does nothing at all and the
 * status word is the whole answer. A membership, a ticket to an unlimited
 * event, a downloadable file — none of them runs out, and forcing a number on
 * them would make every owner type 999 to mean "yes".
 */
final class Stock
{
    /** Meta keys. One list, read by the archetype, the reader and the writer. */
    public const MANAGE = 'product_manage_stock';

    /** Settings → Shop: a count at or below this, above nothing, is running low. */
    public const LOW_KEY = 'commerce_low_stock';

    /** The shop's low mark: 2 until the owner says otherwise, as a shop's first guess. */
    public static function lowAt(): int
    {
        return max(0, (int) (\Cms\Models\Option::get(self::LOW_KEY, '2') ?? '2'));
    }
    public const QTY    = 'product_stock_qty';

    /** Is this product counting units at all? */
    public static function isManaged(array $meta): bool
    {
        return trim((string) ($meta[self::MANAGE] ?? '')) !== ''
            && trim((string) ($meta[self::MANAGE] ?? '')) !== '0';
    }

    /**
     * How many are left, or null when this product does not count.
     *
     * NULL AND ZERO ARE DIFFERENT and every caller has to keep them apart:
     * null is "unlimited", zero is "none left". Returning 0 for an uncounted
     * product would make every membership unbuyable.
     */
    public static function available(array $meta): ?int
    {
        if (!self::isManaged($meta)) {
            return null;
        }

        return max(0, (int) ($meta[self::QTY] ?? 0));
    }

    /**
     * Take `$quantity` off the shelf, or fail.
     *
     * Returns false when there are not enough — which is a real thing that
     * happens between a page rendering and a button being pressed, and is the
     * only correct answer to it.
     *
     * A product that does not count stock always succeeds and touches nothing.
     */
    public static function take(int $postId, int $quantity): bool
    {
        if ($quantity <= 0) {
            return true;
        }

        $meta = Meta::getAll($postId);
        if (!self::isManaged($meta)) {
            return true;
        }

        $stmt = Database::getInstance()->prepare(
            'UPDATE post_meta
                SET meta_value = CAST(meta_value AS SIGNED) - :take
              WHERE post_id = :id
                AND meta_key = :key
                AND CAST(meta_value AS SIGNED) >= :need'
        );
        $stmt->execute([
            ':take' => $quantity,
            ':id'   => $postId,
            ':key'  => self::QTY,
            ':need' => $quantity,
        ]);

        if ($stmt->rowCount() < 1) {
            return false;
        }

        self::syncStatus($postId);
        StockLog::record($postId, null, -$quantity, self::countOf($postId));

        return true;
    }

    /** The product's own count as it stands, or null when it does not count. */
    public static function countOf(int $postId): ?int
    {
        $meta = Meta::getAll($postId);

        return self::isManaged($meta) ? (int) ($meta[self::QTY] ?? 0) : null;
    }

    /**
     * The product's own count set to what the owner typed, IF it is still what
     * the screen showed them: a sale in between changed it, and the typed
     * figure would overwrite the sale. The change is a line in its history.
     */
    public static function setCount(int $postId, int $was, int $now): bool
    {
        if ($now < 0 || !self::isManaged(Meta::getAll($postId))) {
            return false;
        }
        $stmt = Database::getInstance()->prepare(
            'UPDATE post_meta SET meta_value = :now
              WHERE post_id = :id AND meta_key = :key AND CAST(meta_value AS SIGNED) = :was'
        );
        $stmt->execute([':now' => (string) $now, ':id' => $postId, ':key' => self::QTY, ':was' => $was]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        self::syncStatus($postId);
        StockLog::record($postId, null, $now - $was, $now);

        return true;
    }

    /**
     * Every count a product keeps, to compare before and after a save:
     * 'p' => its own, or 'v<id>' => each option's, with the option's name.
     *
     * @return array<string, array{0: int, 1: ?int, 2: int, 3: string}> key => [post, option, count, name]
     */
    public static function countsOf(int $postId): array
    {
        $out = [];
        foreach (Variation::forProduct($postId) as $v) {
            if (!empty($v['manage_stock'])) {
                $out['v' . (int) $v['id']] = [$postId, (int) $v['id'], (int) $v['stock_qty'], (string) $v['label']];
            }
        }
        if ($out === [] && ($own = self::countOf($postId)) !== null) {
            $out['p'] = [$postId, null, $own, ''];
        }

        return $out;
    }

    /**
     * Put `$quantity` back on the shelf.
     *
     * Used when an order is cancelled or refunded, and when a checkout takes
     * two products and the second one fails — the first must not stay reserved
     * for an order that never happened.
     *
     * NO CEILING. A shop that receives more stock types the new number in; this
     * only ever undoes something it took, so it cannot invent units.
     */
    public static function give(int $postId, int $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        $meta = Meta::getAll($postId);
        if (!self::isManaged($meta)) {
            return;
        }

        $stmt = Database::getInstance()->prepare(
            'UPDATE post_meta
                SET meta_value = CAST(meta_value AS SIGNED) + :give
              WHERE post_id = :id AND meta_key = :key'
        );
        $stmt->execute([':give' => $quantity, ':id' => $postId, ':key' => self::QTY]);

        self::syncStatus($postId);
        StockLog::record($postId, null, $quantity, self::countOf($postId));
    }

    /**
     * Keep the STATUS WORD in step with the count.
     *
     * ── TWO FACTS THAT MUST NOT DISAGREE ───────────────────────────────────
     * `product_stock_status` is what the public page prints and what
     * `Product::fromPost()` reads for `purchasable`. Left to itself it would
     * still say "In stock" on a product whose count reached zero — the shop
     * telling a customer one thing while the checkout refuses them, which is
     * worse than either answer alone.
     *
     * ONLY BETWEEN in_stock AND out_of_stock. `on_backorder` is the owner
     * saying "order it anyway, it is coming", and a count of zero is exactly
     * when they mean it — overwriting that would take away the one state
     * counting cannot express.
     */
    private static function syncStatus(int $postId): void
    {
        $meta    = Meta::getAll($postId);
        $current = (string) ($meta[Product::STOCK] ?? Product::IN_STOCK);

        if ($current === Product::ON_BACKORDER) {
            return;
        }

        $left = (int) ($meta[self::QTY] ?? 0);
        $want = $left > 0 ? Product::IN_STOCK : Product::OUT_OF_STOCK;

        if ($current !== $want) {
            Meta::set($postId, Product::STOCK, $want);
        }
    }

    /**
     * Take stock for a whole order, all or nothing.
     *
     * ── PARTIAL IS THE FAILURE MODE TO AVOID ───────────────────────────────
     * A basket of three products where the third is sold out must not leave the
     * first two reserved. Each `take()` is atomic on its own row; this makes the
     * SET atomic by undoing what it took when any one of them fails.
     *
     * @param  list<array{post_id: ?int, quantity: int}> $lines
     * @return array{ok: bool, short: string} the name of what ran out, if any
     */
    public static function takeForOrder(array $lines, array $names = []): array
    {
        $taken = [];

        foreach ($lines as $i => $line) {
            $postId = $line['post_id'] ?? null;
            $qty    = (int) ($line['quantity'] ?? 0);
            if ($postId === null) {
                continue;
            }

            // ── A VARIANT COUNTS ITSELF ────────────────────────────────────
            // A shirt with ten in stock across three sizes must not be able to
            // sell ten smalls, which is the whole reason a variant carries its
            // own count (migration 046). When a line names one, the variant's
            // table is what decides and the product's meta is left alone —
            // decrementing both would take two units off the shelf for one sale.
            $variationId = isset($line['variation_id']) && $line['variation_id'] !== null
                ? (int) $line['variation_id'] : 0;

            $ok = $variationId > 0
                ? Variation::take($variationId, $qty)
                : self::take((int) $postId, $qty);

            if ($ok) {
                $taken[] = [(int) $postId, $variationId, $qty];
                continue;
            }

            // EVERY LINE ALREADY TAKEN GOES BACK. A basket of three where the
            // third ran out must not leave the first two reserved, and each
            // goes back to whichever shelf it came off.
            foreach ($taken as [$id, $vid, $n]) {
                if ($vid > 0) {
                    Variation::give($vid, $n);
                } else {
                    self::give($id, $n);
                }
            }

            return ['ok' => false, 'short' => (string) ($names[$i] ?? 'One of the items')];
        }

        return ['ok' => true, 'short' => ''];
    }

    /** Put a whole order's stock back. Used on cancel and refund. */
    public static function giveForOrder(array $items): void
    {
        foreach ($items as $item) {
            if (($item['post_id'] ?? null) === null) {
                continue;
            }

            // BACK TO THE SHELF IT CAME OFF. An order line that named a variant
            // took the count off THAT variant, and putting it back on the
            // product would invent a unit in one place and lose one in another.
            $variationId = isset($item['variation_id']) && $item['variation_id'] !== null
                ? (int) $item['variation_id'] : 0;

            if ($variationId > 0) {
                Variation::give($variationId, (int) $item['quantity']);
                continue;
            }

            self::give((int) $item['post_id'], (int) $item['quantity']);
        }
    }

    /**
     * Every product of a type that sells, with its own count or each of its
     * options' counts, in title order: the stock screen's rows, and what the
     * assistant's `read_stock` reads. Products that do not count their stock
     * are listed too, saying so, so none is missing from the screen.
     *
     * @return list<array{key: string, post_id: int, title: string, option: string, sku: string,
     *                    counted: bool, count: ?int, status: string}>
     */
    public static function listing(): array
    {
        $types = Product::sellableTypes();
        if ($types === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($types), '?'));
        $stmt  = Database::getInstance()->prepare(
            // The site's own language: a translation sells its original's stock (`Content\Translation`).
            "SELECT * FROM posts WHERE post_type IN ($marks) AND status <> 'trash' AND lang = '' ORDER BY title, id"
        );
        $stmt->execute($types);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ids   = array_map(static fn (array $p): int => (int) $p['id'], $posts);
        $meta  = Meta::getAllFor($ids);
        $opts  = Variation::forProducts($ids);

        $out = [];
        foreach ($posts as $post) {
            $id = (int) $post['id'];
            if (($opts[$id] ?? []) === []) {
                $m       = $meta[$id] ?? [];
                $counted = (string) ($m[self::MANAGE] ?? '') === '1';
                $out[]   = [
                    'key'     => 'p' . $id,
                    'post_id' => $id,
                    'title'   => (string) $post['title'],
                    'option'  => '',
                    'sku'     => (string) ($m[Product::SKU] ?? ''),
                    'counted' => $counted,
                    'count'   => $counted ? (int) ($m[self::QTY] ?? 0) : null,
                    'status'  => (string) ($m[Product::STOCK] ?? Product::IN_STOCK),
                ];
                continue;
            }
            foreach ($opts[$id] as $v) {
                $out[] = [
                    'key'     => 'v' . (int) $v['id'],
                    'post_id' => $id,
                    'title'   => (string) $post['title'],
                    'option'  => (string) $v['label'],
                    'sku'     => (string) $v['sku'],
                    'counted' => !empty($v['manage_stock']),
                    'count'   => !empty($v['manage_stock']) ? (int) $v['stock_qty'] : null,
                    'status'  => (string) $v['stock_status'],
                ];
            }
        }

        return $out;
    }
}
