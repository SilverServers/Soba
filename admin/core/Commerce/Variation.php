<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\Database\Database;
use PDO;

/**
 * A PRODUCT SOLD IN MORE THAN ONE FORM — sizes, colours, weights.
 *
 * ── A LIST OF ROWS, TYPED OR MADE ───────────────────────────────────────────
 * Each option is a row with its own name, code, price and count — "Small",
 * "Large / Red", "500g" — and a shop with three of them types them. A shop
 * selling a tee in five sizes and three colours has them made instead: Size
 * and Colour are taxonomies a customer chooses from (`is_option`, migration
 * 095), "Make options" on the entry form adds a row for every combination of
 * the terms ticked on the product, and each row keeps the terms it is made of
 * (`terms`), which is what lets the product page offer a chooser for each. The
 * row is the unit either way: an order line points at it and its stock is
 * counted on it.
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
    /** Options a list has already read, by post id — see `prime()`. */
    private static array $primed = [];

    /**
     * Read the options of every product on a list in one query, so pricing
     * each card through `Product::fromPost()` reads none of its own.
     *
     * Request-scoped, and dropped by any write to the table (`sync()`,
     * `take()`, `give()`), so nothing priced after a change reads the
     * options as they were before it.
     *
     * @param list<int> $postIds
     */
    public static function prime(array $postIds): void
    {
        foreach (self::forProducts($postIds) as $id => $rows) {
            self::$primed[$id] = $rows;
        }
    }

    /** Every variant of a product, in the owner's order. */
    public static function forProduct(int $postId): array
    {
        if (isset(self::$primed[$postId])) {
            return self::$primed[$postId];
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM product_variations
              WHERE post_id = :id
           ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([':id' => $postId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Every option of every product named, in one query: post_id => list.
     *
     * For a list of products — a page of shop cards — so pricing fifty cards
     * reads options once rather than fifty times (`Product::fromPost()`).
     * A product with none is in the map with an empty list.
     *
     * @param  list<int> $postIds
     * @return array<int, list<array<string, mixed>>>
     */
    public static function forProducts(array $postIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $postIds), static fn (int $i): bool => $i > 0)));
        $out = array_fill_keys($ids, []);
        if ($ids === []) {
            return $out;
        }

        // `IN (…)` from INTEGERS cast above, never from the request.
        $stmt = Database::getInstance()->query(
            'SELECT * FROM product_variations WHERE post_id IN (' . implode(',', $ids) . ')
          ORDER BY post_id ASC, sort_order ASC, id ASC'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['post_id']][] = $row;
        }

        return $out;
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
     * What one option costs: its regular price and what it sells for now.
     *
     * ── AN OPTION WITHOUT A PRICE TAKES THE PRODUCT'S, SALE AND ALL ─────────
     * Three sizes priced once are three sizes put on sale once: a blank price
     * means the product's regular price, and the product's sale price while
     * its sale is on. An option with a price of its own has a sale of its own —
     * `sale_price`, on the product's dates — because the product's sale price
     * was set against the product's price, not against this one.
     *
     * A sale price that is not lower is not a sale, the same rule
     * `Product::fromPost()` applies to the product.
     *
     * @param array<string, mixed> $product a row from `Product::fromPost()`
     * @return array{0: int, 1: int} [regular, now], in minor units
     */
    public static function prices(array $variation, array $product): array
    {
        $own = $variation['price'] ?? null;
        if ($own === null || $own === '') {
            return [(int) $product['regular_price'], (int) $product['price']];
        }

        $regular = (int) $own;
        $sale    = $variation['sale_price'] ?? null;
        $now     = !empty($product['sale_open']) && $sale !== null && $sale !== '' && (int) $sale < $regular
            ? (int) $sale
            : $regular;

        return [$regular, $now];
    }

    /**
     * The option's terms: taxonomy key => term id, `[]` for an option typed by hand.
     *
     * @return array<string, int>
     */
    public static function termsOf(array $variation): array
    {
        $raw = $variation['terms'] ?? null;
        $map = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $out = [];
        foreach (is_array($map) ? $map : [] as $tax => $term) {
            if (is_string($tax) && (int) $term > 0) {
                $out[$tax] = (int) $term;
            }
        }

        return $out;
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
        self::$primed = [];
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
            self::logged($id, -$quantity);

            return true;
        }

        // NOT COUNTING IS NOT THE SAME AS NOT HAVING. A variant with
        // `manage_stock` off matches nothing above and must still sell.
        $row = self::find($id);

        return $row !== null && empty($row['manage_stock']);
    }

    /**
     * Option rows written as given, for a product copied from another
     * (`ContentTypeController::handleCopy()`): every column but the id, and the
     * copy's own product. A counted option with nothing in it is sold out.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function copyRows(int $postId, array $rows): void
    {
        self::$primed = [];
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO product_variations
                (post_id, label, terms, sku, price, sale_price, manage_stock, stock_qty, stock_status, image_id, sort_order)
             VALUES (:p, :l, :t, :s, :pr, :sp, :m, :q, :st, :i, :o)'
        );
        foreach ($rows as $row) {
            $managed = !empty($row['manage_stock']);
            $qty     = (int) ($row['stock_qty'] ?? 0);
            $stmt->execute([
                ':p'  => $postId,
                ':l'  => (string) $row['label'],
                ':t'  => $row['terms'] ?? null,
                ':s'  => (string) ($row['sku'] ?? ''),
                ':pr' => $row['price'] ?? null,
                ':sp' => $row['sale_price'] ?? null,
                ':m'  => $managed ? 1 : 0,
                ':q'  => $qty,
                ':st' => $managed && $qty <= 0 ? Product::OUT_OF_STOCK : (string) ($row['stock_status'] ?? Product::IN_STOCK),
                ':i'  => $row['image_id'] ?? null,
                ':o'  => (int) ($row['sort_order'] ?? 0),
            ]);
        }
    }

    /** Put `$quantity` back. */
    public static function give(int $id, int $quantity): void
    {
        self::$primed = [];
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
        if ($stmt->rowCount() === 1) {
            self::logged($id, $quantity);
        }
    }

    /**
     * An option's count set to what the owner typed, IF it is still what the
     * screen showed them (`Stock::setCount()` has the reason).
     */
    public static function setCount(int $id, int $was, int $now): bool
    {
        self::$primed = [];
        if ($now < 0) {
            return false;
        }
        $stmt = Database::getInstance()->prepare(
            'UPDATE product_variations SET stock_qty = :now
              WHERE id = :id AND manage_stock = 1 AND stock_qty = :was'
        );
        $stmt->execute([':now' => $now, ':id' => $id, ':was' => $was]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        self::syncStatus($id);
        self::logged($id, $now - $was);

        return true;
    }

    /**
     * An option's own price and sale price, from the product list's bulk edit
     * (`ProductBulk`). Only on an option with a price of its own: one without
     * follows the product's, and is left to.
     */
    public static function setPrices(int $id, int $price, ?int $sale): void
    {
        self::$primed = [];
        $stmt = Database::getInstance()->prepare(
            'UPDATE product_variations SET price = :p, sale_price = :s WHERE id = :id AND price IS NOT NULL'
        );
        $stmt->execute([':p' => $price, ':s' => $sale, ':id' => $id]);
    }

    /** A change to an option's count, as a line in its product's history. */
    private static function logged(int $id, int $change): void
    {
        $row = self::find($id);
        if ($row !== null) {
            StockLog::record((int) $row['post_id'], $id, $change, (int) $row['stock_qty']);
        }
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
        $db      = Database::getInstance();
        $keep    = [];
        $sort    = 0;
        $post    = \Cms\Models\Post::find($postId);
        $allowed = array_keys(self::optionTaxonomies((string) ($post['post_type'] ?? '')));
        self::$primed = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // A ROW WITH NO LABEL IS NOT A VARIANT (`columns()`).
            $data = self::columns($postId, $row, $allowed, $sort);
            if ($data === null) {
                continue;
            }
            $sort++;

            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $data[':id'] = $id;
                $stmt = $db->prepare(
                    'UPDATE product_variations
                        SET label = :label, terms = :terms, sku = :sku, price = :price,
                            sale_price = :sale, manage_stock = :manage, stock_qty = :qty,
                            stock_status = :status, image_id = :image, sort_order = :sort
                      WHERE id = :id AND post_id = :post'
                );
                $stmt->execute($data);
                $keep[] = $id;
                continue;
            }

            $stmt = $db->prepare(
                'INSERT INTO product_variations
                        (post_id, label, terms, sku, price, sale_price, manage_stock, stock_qty,
                         stock_status, image_id, sort_order)
                 VALUES (:post, :label, :terms, :sku, :price, :sale, :manage, :qty,
                         :status, :image, :sort)'
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
     * One option added to a product, by the same rules as a row the entry form
     * saves (`columns()`), at the end of its list; its id, or null for a row
     * with no name.
     *
     * ONE ROW, NOT THE LIST POSTED BACK. The assistant's `add_option` adds an
     * option to a product people are buying from: writing every row back as it
     * was read would put back each count as it stood, and a sale in between
     * would be undone.
     *
     * @param array<string, mixed> $row as a row of `$_POST['variations']`
     */
    public static function add(int $postId, array $row): ?int
    {
        $db      = Database::getInstance();
        $post    = \Cms\Models\Post::find($postId);
        $allowed = array_keys(self::optionTaxonomies((string) ($post['post_type'] ?? '')));
        $next    = $db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_variations WHERE post_id = :p');
        $next->execute([':p' => $postId]);
        $data = self::columns($postId, $row, $allowed, (int) $next->fetchColumn());
        if ($data === null) {
            return null;
        }
        self::$primed = [];
        $db->prepare(
            'INSERT INTO product_variations
                    (post_id, label, terms, sku, price, sale_price, manage_stock, stock_qty,
                     stock_status, image_id, sort_order)
             VALUES (:post, :label, :terms, :sku, :price, :sale, :manage, :qty,
                     :status, :image, :sort)'
        )->execute($data);

        return (int) $db->lastInsertId();
    }

    /** One option taken off its product: an `add()` undone. */
    public static function remove(int $id): void
    {
        self::$primed = [];
        Database::getInstance()->prepare('DELETE FROM product_variations WHERE id = :id')->execute([':id' => $id]);
    }

    /**
     * A row as the table stores it, from a row as the entry form posts it; null
     * for a row with no name.
     *
     * @param array<string, mixed> $row
     * @param list<string>         $allowed the type's option taxonomies
     * @return array<string, mixed>|null
     */
    private static function columns(int $postId, array $row, array $allowed, int $sort): ?array
    {
        $label = trim((string) ($row['label'] ?? ''));

        // A ROW WITH NO LABEL IS NOT A VARIANT. The template's blank
        // template row posts as one when a repeater is left open, and an
        // unnamed variant is a line on a packing slip that says nothing.
        if ($label === '') {
            return null;
        }

        $price = trim((string) ($row['price'] ?? ''));
        $price = $price === '' ? null : Money::parse($price);
        $sale  = trim((string) ($row['sale_price'] ?? ''));
        $sale  = $sale === '' ? null : Money::parse($sale);
        $image = (int) ($row['image_id'] ?? 0);

        $data = [
            ':post'   => $postId,
            ':label'  => mb_substr($label, 0, 120),
            ':sku'    => mb_substr(trim((string) ($row['sku'] ?? '')), 0, 80),
            // A PRICE THAT WILL NOT PARSE IS LEFT INHERITED rather than
            // stored as zero. `Money::parse()` returns null for anything it
            // cannot hold exactly, and a variant silently priced at nothing
            // is a product given away.
            ':price'  => $price,
            ':sale'   => $sale,
            // WHAT THE OPTION IS MADE OF, from this type's option
            // taxonomies only — `cleanTerms()` drops anything else.
            ':terms'  => self::cleanTerms($allowed, $row['terms'] ?? null),
            // A PHOTO THAT IS NO FILE IS NO PHOTO, and is not stored.
            ':image'  => $image > 0 && \Cms\Models\Media::find($image) !== null ? $image : null,
            ':manage' => !empty($row['manage_stock']) ? 1 : 0,
            ':qty'    => max(0, (int) ($row['stock_qty'] ?? 0)),
            ':status' => in_array((string) ($row['stock_status'] ?? ''), [
                Product::IN_STOCK, Product::OUT_OF_STOCK, Product::ON_BACKORDER,
            ], true) ? (string) $row['stock_status'] : Product::IN_STOCK,
            ':sort'   => $sort,
        ];

        return $data;
    }

    /**
     * The Options panel as it was posted, in the shape `forProduct()` returns.
     *
     * ── A REFUSED SAVE KEEPS WHAT WAS TYPED ─────────────────────────────────
     * The panel was always drawn from the database, so a save refused for any
     * reason (a SKU another product has, a missing title) came back with the
     * stored options and every row the owner had just added or changed gone.
     * After a refusal the panel is drawn from this instead. A row left wholly
     * blank is the repeater's empty one and is dropped; a price that will not
     * parse comes back blank, as `sync()` would store it.
     *
     * @param array<int|string, mixed> $rows
     * @return list<array<string, mixed>>
     */
    public static function fromPost(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            $sku   = trim((string) ($row['sku'] ?? ''));
            $price = trim((string) ($row['price'] ?? ''));
            if ($label === '' && $sku === '' && $price === '') {
                continue;
            }
            $status = (string) ($row['stock_status'] ?? '');
            $out[] = [
                'id'           => (int) ($row['id'] ?? 0),
                'label'        => $label,
                'sku'          => $sku,
                'price'        => $price === '' ? null : Money::parse($price),
                'sale_price'   => trim((string) ($row['sale_price'] ?? '')) === '' ? null
                                  : Money::parse(trim((string) $row['sale_price'])),
                'terms'        => is_array($row['terms'] ?? null) ? (string) json_encode($row['terms']) : null,
                'image_id'     => (int) ($row['image_id'] ?? 0) ?: null,
                'manage_stock' => !empty($row['manage_stock']) ? 1 : 0,
                'stock_qty'    => max(0, (int) ($row['stock_qty'] ?? 0)),
                'stock_status' => in_array($status, [Product::IN_STOCK, Product::OUT_OF_STOCK, Product::ON_BACKORDER], true)
                                  ? $status : Product::IN_STOCK,
            ];
        }

        return $out;
    }

    /**
     * The cheapest and the dearest option, now and at their regular prices.
     *
     * ── A RANGE, BECAUSE ONE NUMBER WOULD BE A LIE ──────────────────────────
     * A shirt whose sizes cost £20 and £25 does not cost £20. Showing the
     * lowest is what most shops do and what most customers then query at the
     * till; the range says what is true before anything is chosen.
     * `Product::priceHtmlParts()` formats it, collapsing it to one figure when
     * the ends meet, and strikes the regular range through when it differs.
     *
     * Over EVERY option, sold out or not: the range is what the product costs,
     * and a size that has run out has not changed its price.
     *
     * @param list<array<string, mixed>> $variations
     * @param array<string, mixed>       $product a row from `Product::fromPost()`
     * @return array{low: int, high: int, was_low: int, was_high: int}
     */
    public static function bounds(array $variations, array $product): array
    {
        if ($variations === []) {
            return [
                'low' => (int) $product['price'], 'high' => (int) $product['price'],
                'was_low' => (int) $product['regular_price'], 'was_high' => (int) $product['regular_price'],
            ];
        }

        $now = [];
        $was = [];
        foreach ($variations as $v) {
            [$regular, $current] = self::prices($v, $product);
            $was[] = $regular;
            $now[] = $current;
        }

        return ['low' => min($now), 'high' => max($now), 'was_low' => min($was), 'was_high' => max($was)];
    }

    /**
     * The taxonomies a customer chooses from on this type — Size, Colour — in
     * the order their choosers are drawn, keyed by taxonomy key.
     *
     * Marked on the taxonomy itself (`is_option`, migration 095) and attached
     * to the type, as any taxonomy is. A category that only files a product is
     * neither, and never becomes a chooser.
     *
     * IN THE ORDER THEY WERE MADE, which is the order the owner thinks of them
     * in: a shop that set up Size before Colour names an option "M / Navy" and
     * asks for the size first. The registry's own order is alphabetical, which
     * would put Colour first in one shop and Size in another for no reason an
     * owner could see or change.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function optionTaxonomies(string $postType): array
    {
        if ($postType === '') {
            return [];
        }

        $taxes = array_filter(
            \Cms\ContentType\TaxonomyRegistry::getForContentType($postType),
            static fn (array $tax): bool => !empty($tax['is_option'])
        );
        uasort($taxes, static fn (array $a, array $b): int => (int) ($a['db_id'] ?? 0) <=> (int) ($b['db_id'] ?? 0));

        return $taxes;
    }

    /**
     * An option's posted terms, as stored: JSON of taxonomy key => term id, or
     * null. Only taxonomies in `$allowed`, in its order, and only a term that
     * belongs to the taxonomy it is filed under — a crafted POST naming a
     * category's term as a size is dropped, not stored.
     *
     * @param list<string> $allowed
     */
    private static function cleanTerms(array $allowed, mixed $posted): ?string
    {
        if (!is_array($posted)) {
            return null;
        }

        $out = [];
        foreach ($allowed as $tax) {
            $id = (int) ($posted[$tax] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $term = \Cms\Models\Term::find($id);
            if ($term !== null && (string) $term['taxonomy_key'] === $tax) {
                $out[$tax] = $id;
            }
        }

        return $out === [] ? null : (string) json_encode($out);
    }
}
