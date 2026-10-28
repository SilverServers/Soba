<?php

declare(strict_types=1);

namespace Cms\Commerce;

use Cms\ContentType\Registry;
use Cms\Models\Meta;
use Cms\Models\Post;

/**
 * A PRODUCT IS AN ENTRY OF A CONTENT TYPE THAT DECLARES THE `product`
 * ARCHETYPE. There is no products table and there is not going to be one.
 *
 * ── WHY, AND WHAT IT COSTS ──────────────────────────────────────────────────
 * Fewer storage layers, not more operations. A `products` table would need its
 * own admin screen, its own editor, its own media handling, its own SEO fields,
 * its own revisions and its own taxonomy support — every one of which the
 * Content Type system already has and every one of which would drift from it.
 * Worse, it would answer "what can I sell?" from a different place than "what
 * content exists?" — and anything answering "what exists in this install?"
 * asks the Registry.
 *
 * The cost is that price and stock live in meta as strings, because that is how
 * the field group the archetype seeds stores them. That is fine as long as
 * NOTHING ELSE reads those strings — this class is the one door, it converts
 * once at the boundary, and everything downstream is an `int` of minor units.
 *
 * ── THE ARCHETYPE IS ADDITIVE, SO "IS THIS A PRODUCT" IS A LIST TEST ────────
 * A Content Type can be an Event AND a Product at once — a ticketed event is
 * exactly that, and the archive template already handles the combination. So
 * the question is never "what type is this", it is "does this type's archetype
 * list include product". `Registry::withArchetype()` answers it, which also
 * means a type registered by the builder, by config or by an import is found
 * the same way. One path in.
 *
 * ── AND ASKING THE REGISTRY IS NOT OPTIONAL ─────────────────────────────────
 * `ContentTypeDefinition::all()` answers "what is in that table", which is a
 * narrower question and the wrong one: types registered in `config/` are not in
 * it. A shop that silently cannot sell a config-registered type would look like
 * a broken product page and be diagnosed as one.
 */
final class Product
{
    /** The meta keys the `product` archetype seeds. One list, read by all of it. */
    public const PRICE       = 'product_price';
    public const SALE_PRICE  = 'product_sale_price';
    public const SKU         = 'product_sku';
    public const STOCK       = 'product_stock_status';
    /**
     * Does this go in a van?
     *
     * OPT-IN, the same shape `product_manage_stock` uses and for the same
     * reason: a membership, a ticket, a download and an hour of somebody's time
     * are all things this shop sells and none of them is posted. Charging $12
     * to deliver a membership is the kind of wrong that makes a customer close
     * the tab, and defaulting it ON would do that to every product on every
     * install that already exists.
     */
    public const NEEDS_DELIVERY = 'product_needs_delivery';

    /** Stock values the archetype's select offers, and what they mean here. */
    public const IN_STOCK     = 'in_stock';
    public const OUT_OF_STOCK = 'out_of_stock';
    public const ON_BACKORDER = 'on_backorder';

    /** Content type keys that can be sold. */
    public static function sellableTypes(): array
    {
        return array_keys(Registry::withArchetype('product'));
    }

    /** Is this content type key one that carries the product archetype? */
    public static function isSellableType(string $postType): bool
    {
        return in_array($postType, self::sellableTypes(), true);
    }

    /**
     * Everything commerce needs about one entry, or null if it is not for sale.
     *
     * Returns null — rather than a zero-priced product — when the entry is not
     * a product type, is not published, or has no readable price. A product
     * with no price is not a free product; it is one the owner has not finished
     * setting up, and putting it in a cart at $0.00 is the worse answer.
     *
     * @return array{id: int, title: string, slug: string, post_type: string,
     *               sku: string, price: int, regular_price: int,
     *               on_sale: bool, stock: string, purchasable: bool}|null
     */
    public static function find(int $postId): ?array
    {
        $post = Post::find($postId);
        if ($post === null) {
            return null;
        }

        return self::fromPost($post);
    }

    /**
     * The same, from a post row already in hand.
     *
     * Separate so a listing that has already loaded fifty rows does not issue
     * fifty more queries to price them.
     *
     * ── AND IT NOW MAKES EXACTLY ONE QUERY OF ITS OWN ───────────────────────
     * `Variation::forProduct()`. Said out loud because the paragraph above is a
     * promise about query counts and this is the one thing that breaks it: a
     * fifty-row listing calling this would issue fifty variation reads.
     *
     * Nothing does today — the archive reads meta directly and this is called
     * once per product page and once per basket line — so there is no batch
     * loader here, because a seam nothing uses is machinery to maintain for a
     * caller that may never exist. Whoever writes that listing should add
     * `Variation::forProducts(array $ids)` and pass the map in, and this note
     * is here so they see the cost before they measure it.
     *
     * @param array<string, mixed> $post
     */
    public static function fromPost(array $post): ?array
    {
        $type = (string) ($post['post_type'] ?? '');
        if (!self::isSellableType($type)) {
            return null;
        }

        // ONLY A PUBLICLY REACHABLE ENTRY IS FOR SALE, and the check belongs
        // HERE rather than in each caller, because the caller that forgets it
        // is the bug.
        //
        // The status enum is published · password · private · trash — there is
        // no `draft`. `private` is this system's draft: it is the DEFAULT that
        // `Post::create()` and `Post::restore()` set, and the public
        // controllers 404 it unless `Auth::check()` passes, so an unfinished
        // product has no page a customer can reach. `trash` is withdrawn.
        //
        // (It used to be `hidden` that played that part, with `private` beside
        // it doing the same job one shade differently. Migration 043 collapsed
        // the two: they 404'd for the same person on the same URL, and two
        // words for one idea is how a status select teaches its user that the
        // software is arbitrary.)
        //
        // `password` IS sellable: the visitor entered the password to reach the
        // page, and a members-only price list is a real thing to want.
        // `private` is NOT — the only person who could buy one is a signed-in
        // administrator, and an admin who wants to sell something publishes it.
        // Selling through a door only we can open is a feature nobody asked for
        // and a support call later.
        if (!in_array((string) ($post['status'] ?? ''), ['published', 'password'], true)) {
            return null;
        }

        $id   = (int) ($post['id'] ?? 0);
        $meta = Meta::getAll($id);

        $regular = Money::parse((string) ($meta[self::PRICE] ?? ''));
        if ($regular === null) {
            return null;   // not finished being set up — see the docblock
        }

        $sale = Money::parse((string) ($meta[self::SALE_PRICE] ?? ''));

        // A SALE PRICE THAT IS NOT LOWER IS NOT A SALE. Typed above the regular
        // price it would otherwise CHARGE MORE while the page says "on sale",
        // struck-through, in the customer's favour by every visual cue. Zero is
        // a legitimate sale price (a free sample), so the test is `< regular`
        // and not `> 0`.
        $onSale = $sale !== null && $sale < $regular;
        $price  = $onSale ? $sale : $regular;

        $stock = (string) ($meta[self::STOCK] ?? self::IN_STOCK);
        if ($stock === '') {
            $stock = self::IN_STOCK;
        }

        // HOW MANY ARE LEFT — null when this product does not count them, which
        // is NOT the same as zero. `Stock::available()`'s docblock has the
        // reasoning; returning 0 for an uncounted product would make every
        // membership unbuyable.
        $left = Stock::available($meta);

        return [
            'stock_left'    => $left,
            'id'            => $id,
            'title'         => (string) ($post['title'] ?? ''),
            'slug'          => (string) ($post['slug'] ?? ''),
            'post_type'     => $type,
            'sku'           => trim((string) ($meta[self::SKU] ?? '')),
            'price'         => $price,
            'regular_price' => $regular,
            'on_sale'       => $onSale,
            'stock'         => $stock,
            // WHETHER DELIVERY APPLIES TO THIS LINE. Read here rather than at
            // the basket, because `fromPost()` is the one place a product's
            // meta becomes a thing the rest of the system can hold — and a
            // second reader of the same key is a second place to forget it.
            'delivered'     => ($meta[self::NEEDS_DELIVERY] ?? '') === '1',
            // ── THE VARIANTS, READ HERE AND NOWHERE ELSE ───────────────────
            // `fromPost()` is the one place a product's storage becomes a thing
            // the rest of the system can hold, and a second reader of the
            // variations table is a second place to forget that a variant can
            // be sold out on its own.
            'variations'    => Variation::forProduct($id),
            // Backorders are purchasable — that is what "on backorder" means.
            // Out of stock is not. AND a counted product with none left is not
            // either, whatever the status word says: `Stock::syncStatus()`
            // keeps the two in step, and this is the belt to that braces.
            'purchasable'   => $stock !== self::OUT_OF_STOCK
                               && ($left === null || $left > 0 || $stock === self::ON_BACKORDER),
        ];
    }

    /**
     * The price block a template draws, already formatted.
     *
     * Here rather than in the template because three templates were formatting
     * it independently with a hard-coded `$` in front, so an install set to GBP
     * would have shown pounds priced in dollars on the archive, the single and
     * the cart. One formatter, one currency, one answer.
     *
     * @param array<string, mixed> $product a row from find()/fromPost()
     * @return array{now: string, was: string|null}
     */
    public static function priceHtmlParts(array $product): array
    {
        return [
            'now' => Money::format((int) $product['price']),
            'was' => ($product['on_sale'] ?? false)
                ? Money::format((int) $product['regular_price'])
                : null,
        ];
    }

    /** How a stock state reads to a customer. */
    public static function stockLabel(string $stock): string
    {
        return match ($stock) {
            self::OUT_OF_STOCK => 'Out of stock',
            self::ON_BACKORDER => 'On backorder',
            default            => 'In stock',
        };
    }
}
