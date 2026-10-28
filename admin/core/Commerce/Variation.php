<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Database\Database;
use PDO;

/**
 * A PRODUCT SOLD IN MORE THAN ONE FORM — sizes, colours, weights.
 *
 * ── A LIST THE OWNER TYPES, NOT A MATRIX THE SYSTEM GENERATES ───────────────
 * Migration 046's header has the argument in full. Rather than attributes that
 * generate combinations, this is a list of named variants the owner writes
 * out — "Small", "Large / Red", "500g" — because the shop this
 * system is for sells four of them, not sixty, and typing four is faster than
 * configuring two attribute sets and filling in a grid.
 *
 * ── EVERY VARIANT INHERITS WHAT IT DOES NOT OVERRIDE ────────────────────────
 * A null price means the product's price. An owner listing three sizes at the
 * same money should not have to type it three times and then keep four numbers
 * in step — every one they forget is a variant quietly selling at last year's
 * price.
 *
 * ── AND STOCK IS THE REASON THIS IS A TABLE ─────────────────────────────────
 * `Commerce\Stock`'s argument, one level down: the count has to be decremented
 * by the database in one statement with the guard in its WHERE. A shop with ten
 * shirts across three sizes must not be able to sell ten smalls.
 */
final class Variation
{
    /** Every variant of a product, in the owner's order. */
    public static function forProduct(int $postId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM product_variations
              WHERE post_id = :id
           ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([':id' => $postId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM product_variations WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** Does this product have any variants at all? */
    public static function has(int $postId): bool
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM product_variations WHERE post_id = :id'
        );
        $stmt->execute([':id' => $postId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * What one variant costs, in minor units.
     *
     * `$productPrice` is what the product costs when the variant does not say.
     */
    public static function priceOf(array $variation, int $productPrice): int
    {
        $own = $variation['price'];

        return $own === null || $own === '' ? $productPrice : (int) $own;
    }

    /**
     * How many are left, or null when this variant does not count.
     *
     * NULL IS NOT ZERO, the same distinction `Stock::available()` draws: a
     * variant that does not count stock is not one with none left, and
     * returning 0 would make every ticket unbuyable.
     */
    public static function available(array $variation): ?int
    {
        if (empty($variation['manage_stock'])) {
            return null;
        }

        return max(0, (int) $variation['stock_qty']);
    }

    /** Can this variant be bought right now? */
    public static function isPurchasable(array $variation): bool
    {
        $status = (string) ($variation['stock_status'] ?? Product::IN_STOCK);
        if ($status === Product::OUT_OF_STOCK) {
            return false;
        }

        $left = self::available($variation);

        return $left === null || $left > 0 || $status === Product::ON_BACKORDER;
    }

    /**
     * Take `$quantity` off one variant, atomically. True if it was taken.
     *
     * ── THE GUARD IS IN THE WHERE ───────────────────────────────────────────
     * `Commerce\Stock` has the argument and this is the same statement one
     * table across: two customers buying the last small both read 1, both
     * decide it is fine, and both write 0. `rowCount()` is what decides, and it
     * decides once.
     */
    public static function take(int $id, int $quantity): bool
    {
        if ($quantity <= 0) {
            return true;
        }

        $stmt = Database::getInstance()->prepare(
            'UPDATE product_variations
                SET stock_qty = stock_qty - :take
              WHERE id = :id
                AND manage_stock = 1
                AND stock_qty >= :need'
        );
        $stmt->execute([':take' => $quantity, ':id' => $id, ':need' => $quantity]);

        if ($stmt->rowCount() === 1) {
            self::syncStatus($id);

            return true;
        }

        // NOT COUNTING IS NOT THE SAME AS NOT HAVING. A variant with
        // `manage_stock` off matches nothing above and must still sell.
        $row = self::find($id);

        return $row !== null && empty($row['manage_stock']);
    }

    /** Put `$quantity` back. */
    public static function give(int $id, int $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        $stmt = Database::getInstance()->prepare(
            'UPDATE product_variations
                SET stock_qty = stock_qty + :give
              WHERE id = :id AND manage_stock = 1'
        );
        $stmt->execute([':give' => $quantity, ':id' => $id]);
        self::syncStatus($id);
    }

    /**
     * Keep the status word in step with the count.
     *
     * The same duty `Stock::syncStatus()` performs for a product: a variant
     * counted down to nothing that still says "In stock" is a listing that
     * takes an order it cannot fill, and one restocked to three that still says
     * "Out of stock" is a sale the shop refuses to make.
     */
    private static function syncStatus(int $id): void
    {
        $db  = Database::getInstance();
        $row = self::find($id);
        if ($row === null || empty($row['manage_stock'])) {
            return;
        }

        $left = (int) $row['stock_qty'];
        $now  = (string) $row['stock_status'];

        // BACKORDER IS THE OWNER'S DECISION and is left alone. It means "you
        // may order this and it will come", which running out does not change.
        if ($now === Product::ON_BACKORDER) {
            return;
        }

        $should = $left > 0 ? Product::IN_STOCK : Product::OUT_OF_STOCK;
        if ($should === $now) {
            return;
        }

        $stmt = $db->prepare('UPDATE product_variations SET stock_status = :s WHERE id = :id');
        $stmt->execute([':s' => $should, ':id' => $id]);
    }

    /**
     * Replace a product's variants with what the entry form posted.
     *
     * ── MATCHED BY ID, SO A RENAME DOES NOT RESET THE COUNT ─────────────────
     * Every row carries its own id in a hidden input. Matching on the LABEL
     * instead would be simpler and wrong: renaming "Small" to "S" would read as
     * a delete and an insert, and the shop would lose the count of how many
     * smalls it has — silently, on a save that looked like a spelling fix.
     *
     * ROWS NOT IN THE POST ARE DELETED, which is how a repeater has to behave:
     * the form IS the list, and a row the owner removed from it is a row they
     * removed.
     *
     * @param array<int, array<string, mixed>> $rows from `$_POST['variations']`
     */
    public static function sync(int $postId, array $rows): void
    {
        $db   = Database::getInstance();
        $keep = [];
        $sort = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));

            // A ROW WITH NO LABEL IS NOT A VARIANT. The template's blank
            // template row posts as one when a repeater is left open, and an
            // unnamed variant is a line on a packing slip that says nothing.
            if ($label === '') {
                continue;
            }

            $price = trim((string) ($row['price'] ?? ''));
            $price = $price === '' ? null : Money::parse($price);

            $data = [
                ':post'   => $postId,
                ':label'  => mb_substr($label, 0, 120),
                ':sku'    => mb_substr(trim((string) ($row['sku'] ?? '')), 0, 80),
                // A PRICE THAT WILL NOT PARSE IS LEFT INHERITED rather than
                // stored as zero. `Money::parse()` returns null for anything it
                // cannot hold exactly, and a variant silently priced at nothing
                // is a product given away.
                ':price'  => $price,
                ':manage' => !empty($row['manage_stock']) ? 1 : 0,
                ':qty'    => max(0, (int) ($row['stock_qty'] ?? 0)),
                ':status' => in_array((string) ($row['stock_status'] ?? ''), [
                    Product::IN_STOCK, Product::OUT_OF_STOCK, Product::ON_BACKORDER,
                ], true) ? (string) $row['stock_status'] : Product::IN_STOCK,
                ':sort'   => $sort++,
            ];

            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $data[':id'] = $id;
                $stmt = $db->prepare(
                    'UPDATE product_variations
                        SET label = :label, sku = :sku, price = :price,
                            manage_stock = :manage, stock_qty = :qty,
                            stock_status = :status, sort_order = :sort
                      WHERE id = :id AND post_id = :post'
                );
                $stmt->execute($data);
                $keep[] = $id;
                continue;
            }

            $stmt = $db->prepare(
                'INSERT INTO product_variations
                        (post_id, label, sku, price, manage_stock, stock_qty, stock_status, sort_order)
                 VALUES (:post, :label, :sku, :price, :manage, :qty, :status, :sort)'
            );
            $stmt->execute($data);
            $keep[] = (int) $db->lastInsertId();
        }

        if ($keep === []) {
            $stmt = $db->prepare('DELETE FROM product_variations WHERE post_id = :id');
            $stmt->execute([':id' => $postId]);

            return;
        }

        // `IN (…)` built from INTEGERS THIS METHOD PRODUCED, never from the
        // POST — `$keep` holds ids that came back from the database, so there
        // is nothing of the request's in the string.
        $marks = implode(',', array_map('intval', $keep));
        $stmt  = $db->prepare(
            "DELETE FROM product_variations WHERE post_id = :id AND id NOT IN ($marks)"
        );
        $stmt->execute([':id' => $postId]);
    }

    /**
     * The price range a product with variants shows: "$20.00 – $25.00".
     *
     * ── A RANGE, BECAUSE ONE NUMBER WOULD BE A LIE ──────────────────────────
     * A shirt whose sizes cost £20 and £25 does not cost £20. Showing the
     * lowest is what most shops do and what most customers then query at the
     * till; showing the range says what is true before anything is chosen.
     *
     * When every variant costs the same it collapses to one figure, because
     * "£20.00 – £20.00" reads as a system with a bug in it.
     */
    public static function priceRange(array $variations, int $productPrice): string
    {
        [$low, $high] = self::priceBounds($variations, $productPrice);

        return $low === $high
            ? Money::format($low)
            : Money::format($low) . ' – ' . Money::format($high);
    }

    /**
     * The cheapest and the dearest, as minor units.
     *
     * SPLIT OUT so the arithmetic needs no database: `Money::format()` reads
     * the shop's currency, and formatting is `Money`'s job, not this one's.
     *
     * @return array{0: int, 1: int}
     */
    public static function priceBounds(array $variations, int $productPrice): array
    {
        if ($variations === []) {
            return [$productPrice, $productPrice];
        }

        $prices = array_map(
            static fn (array $v): int => self::priceOf($v, $productPrice),
            $variations
        );

        return [min($prices), max($prices)];
    }
}
