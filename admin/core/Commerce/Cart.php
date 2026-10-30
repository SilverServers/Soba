<?php

declare(strict_types=1);

namespace Cms\Commerce;

/**
 * THE CART HOLDS IDS AND QUANTITIES. IT DOES NOT HOLD PRICES.
 *
 * ── THIS IS THE WHOLE SECURITY MODEL OF THE CART, IN ONE SENTENCE ───────────
 * The session is data the visitor influences. If a price lived in it, the
 * question "can a customer set their own price?" would come down to how
 * carefully every write to `$_SESSION['cart']` was reviewed, forever — and one
 * missed write is a shop that sells for a penny. So the session holds
 * `post_id => quantity` and NOTHING ELSE, and every figure is recomputed from
 * `Product` on each read. There is no code path that could put a price in,
 * because there is no field for one.
 *
 * The trade is that prices in a cart are live: a product repriced while it sits
 * in somebody's cart is repriced in their cart too. That is the correct
 * behaviour — the alternative is honouring a price the shop no longer offers —
 * and it is also what makes the ORDER's denormalised copy load-bearing.
 * `orders`' own header covers the other half: once placed, the price freezes.
 *
 * ── AND THE CART IS NOT A CHECK ON WHAT MAY BE BOUGHT ───────────────────────
 * `items()` drops anything `Product::find()` refuses — deleted, unpublished,
 * unpriced. It drops it EVERY TIME rather than at the moment it changes,
 * because there is no moment: a product can be trashed while a cart sits in a
 * closed laptop for a week. Checkout re-reads the same way, so the last word on
 * what may be sold is `Product`, not a snapshot taken when the button was
 * pressed.
 */
final class Cart
{
    private const KEY = 'commerce_cart';

    /** Where `items()` leaves an explanation for a line it had to change. */
    private const NOTICE_KEY = 'commerce_cart_notices';

    /**
     * The discount code this basket is carrying, if any.
     *
     * ── THE CODE, NOT THE DISCOUNT ──────────────────────────────────────────
     * Storing the amount would freeze it: a customer applies 10% to a $20
     * basket, adds another item, and the session still says $2. The code is
     * re-read and re-applied on every render for exactly the reason the PRICES
     * are — see the class header — and a code that has expired or been spent
     * since it was applied stops applying, with a sentence saying so.
     */
    private const COUPON_KEY = 'commerce_cart_coupon';

    /**
     * A ceiling per line, so a typo or a curious stranger cannot ask for
     * 2,000,000,000 of something and overflow the arithmetic that prices it.
     */
    public const MAX_QTY = 999;

    /**
     * The key one line is stored under: `14` or `14:3`.
     *
     * ── A VARIANT IS A DIFFERENT LINE, NOT A DIFFERENT QUANTITY ─────────────
     * A basket holding a small and a large is two rows with two prices and two
     * stock counts. Keyed by product alone they would collapse into one, and
     * the customer would receive two of whichever the last one added was.
     *
     * The colon shape rather than a nested array because the session map is
     * also what `setQuantity()` and `remove()` address a line by, and a form
     * field name has to carry it — `quantity[14:3]` is a name; a nested array
     * is three more places to get the nesting wrong.
     */
    public static function key(int $postId, int $variationId = 0): string
    {
        return $variationId > 0 ? $postId . ':' . $variationId : (string) $postId;
    }

    /** `14:3` back into `[14, 3]`. A malformed key reads as `[0, 0]`. */
    public static function parseKey(string $key): array
    {
        $parts = explode(':', $key, 2);
        $post  = (int) ($parts[0] ?? 0);
        $var   = isset($parts[1]) ? (int) $parts[1] : 0;

        return [max(0, $post), max(0, $var)];
    }

    /** The raw session map, line key => quantity. Internal. */
    private static function raw(): array
    {
        $cart = $_SESSION[self::KEY] ?? [];

        return is_array($cart) ? $cart : [];
    }

    private static function put(array $cart): void
    {
        // The visitor's first session, if this is their first item: a public page
        // starts none until something needs one (`Security\Session`).
        \Cms\Security\Session::ensure();
        $_SESSION[self::KEY] = $cart;
    }

    /**
     * Add to the cart, or raise the quantity of a line already in it.
     *
     * Returns false when the product cannot be bought, which is the same test
     * checkout applies. The caller says why; this says whether.
     */
    public static function add(int $postId, int $quantity = 1, int $variationId = 0): bool
    {
        $product = Product::find($postId);
        if ($product === null || !$product['purchasable']) {
            return false;
        }

        // ── A PRODUCT WITH VARIANTS CANNOT BE BOUGHT WITHOUT ONE ────────────
        // The form makes the choice required, so this is the hand-made POST
        // case — and "one shirt" with no size is an order the shop cannot pack.
        // Refusing is the same answer `purchasable` gives, for the same reason.
        if ($product['variations'] !== []) {
            if ($variationId <= 0) {
                return false;
            }

            $chosen = null;
            foreach ($product['variations'] as $v) {
                if ((int) $v['id'] === $variationId) {
                    $chosen = $v;
                    break;
                }
            }

            // A VARIANT OF A DIFFERENT PRODUCT IS NOT A VARIANT OF THIS ONE.
            // `$product['variations']` is the list for THIS post, so a crafted
            // id belonging elsewhere finds nothing here.
            if ($chosen === null || !Variation::isPurchasable($chosen)) {
                return false;
            }
        } elseif ($variationId > 0) {
            // An id against a product that has none is a request that cannot be
            // honoured, and honouring it as "no variant" would put a line in the
            // basket the customer did not ask for.
            return false;
        }

        $cart = self::raw();
        $key  = self::key($postId, $variationId);
        $have = (int) ($cart[$key] ?? 0);

        $want = self::clamp($have + $quantity);
        if ($want <= 0) {
            unset($cart[$key]);
        } else {
            $cart[$key] = $want;
        }

        self::put($cart);

        return true;
    }

    /**
     * Set a line's quantity outright. Zero removes it.
     *
     * A SEPARATE OPERATION FROM add(), deliberately. The cart page's quantity
     * box is a "set to", and expressing it as `add($id, $new - $old)` means
     * two people editing the same session race to a number neither typed.
     */
    public static function setQuantity(string $key, int $quantity): void
    {
        $cart = self::raw();
        if (!isset($cart[$key])) {
            return;   // not in the cart; setting a quantity does not add it
        }

        $quantity = self::clamp($quantity);
        if ($quantity <= 0) {
            unset($cart[$key]);
        } else {
            $cart[$key] = $quantity;
        }

        self::put($cart);
    }

    /**
     * Take one LINE out — a key, not a product id.
     *
     * A basket holding a small and a large has two lines of one product, and
     * removing "the product" would empty both when the customer asked to lose
     * one of them.
     */
    public static function remove(string $key): void
    {
        $cart = self::raw();
        unset($cart[$key]);
        self::put($cart);
    }

    public static function clear(): void
    {
        unset($_SESSION[self::KEY]);
        // THE CODE GOES WITH THE BASKET. Left behind, the next order the same
        // visitor places in the same session silently carries a discount they
        // did not ask for a second time.
        unset($_SESSION[self::COUPON_KEY]);
    }

    /** The code this basket is carrying, upper-cased, or ''. */
    public static function coupon(): string
    {
        return Coupon::normalise((string) ($_SESSION[self::COUPON_KEY] ?? ''));
    }

    public static function setCoupon(string $code): void
    {
        $code = Coupon::normalise($code);
        if ($code === '') {
            self::clearCoupon();

            return;
        }

        \Cms\Security\Session::ensure();
        $_SESSION[self::COUPON_KEY] = $code;
    }

    public static function clearCoupon(): void
    {
        unset($_SESSION[self::COUPON_KEY]);
    }

    /**
     * The cart as lines a template or an order can use.
     *
     * Every figure here is computed now, from `Product`. Lines whose product
     * has gone are dropped silently from the RETURN but are also removed from
     * the session, so the visitor is not shown a total that quietly changes on
     * the next page load.
     *
     * @return list<array{key: string, product: array, variation: array|null,
     *                     unit_price: int, quantity: int, line_total: int}>
     */
    public static function items(): array
    {
        $cart    = self::raw();
        $out     = [];
        $pruned  = false;
        $notices = [];

        foreach ($cart as $lineKey => $quantity) {
            $lineKey  = (string) $lineKey;
            [$postId, $variationId] = self::parseKey($lineKey);
            $quantity = self::clamp((int) $quantity);
            $product  = $postId > 0 ? Product::find($postId) : null;

            // ── THE VARIANT IS RE-READ WITH THE PRODUCT ────────────────────
            // Same rule as the prices: a variant deleted, renamed or sold out
            // since the basket was filled has to be noticed HERE, not at the
            // checkout. A line pointing at a variant that no longer exists is
            // dropped exactly like a line pointing at a product that does not.
            $variation = null;
            if ($product !== null && $variationId > 0) {
                foreach ($product['variations'] as $v) {
                    if ((int) $v['id'] === $variationId) {
                        $variation = $v;
                        break;
                    }
                }
                if ($variation === null || !Variation::isPurchasable($variation)) {
                    $notices[] = $product['title']
                        . ' is no longer available in the option you chose.';
                    unset($cart[$lineKey]);
                    $pruned = true;
                    continue;
                }
            }

            if ($product === null || !$product['purchasable'] || $quantity <= 0) {
                // ── A LINE THAT VANISHES SILENTLY IS THE WORST OUTCOME ─────
                // This dropped the line and said nothing, so a customer whose
                // last unit was sold to somebody else mid-checkout was told
                // "There is nothing in your basket" — true, useless, and
                // alarming to somebody who had something in it a second ago.
                //
                // Found by racing two browsers for one unit. The information
                // existed and was being thrown away one line above where it
                // was needed.
                if ($product !== null) {
                    $notices[] = $product['title'] . ' sold out while you were checking out.';
                } elseif ($postId > 0) {
                    $notices[] = 'An item in your basket is no longer available.';
                }
                unset($cart[$lineKey]);
                $pruned = true;
                continue;
            }

            // ── NEVER MORE THAN THERE ARE ──────────────────────────────────
            // A basket left open for a week can hold ten of something the shop
            // now has two of. Clamping HERE rather than only at checkout means
            // the customer sees the real number on the basket page, with the
            // total to match, instead of being told at the last step that the
            // figure they have been looking at was never available.
            //
            // The session is corrected too, so the next page load agrees.
            // THE VARIANT'S OWN COUNT WHEN THERE IS ONE. A shirt with ten in
            // stock across three sizes has to clamp against the size, not the
            // shirt — which is the whole reason a variant carries its own.
            $left   = $variation !== null
                ? Variation::available($variation)
                : $product['stock_left'];
            $status = $variation !== null
                ? (string) $variation['stock_status']
                : $product['stock'];

            if ($left !== null && $left < $quantity && $status !== Product::ON_BACKORDER) {
                $notices[] = $left > 0
                    ? 'Only ' . $left . ' of ' . $product['title'] . ' left, so your basket was updated.'
                    : $product['title'] . ' sold out while you were checking out.';
                $quantity = $left;
                $cart[$lineKey] = $quantity;
                $pruned = true;
                if ($quantity <= 0) {
                    unset($cart[$lineKey]);
                    continue;
                }
            }

            // THE VARIANT'S PRICE WHEN IT HAS ONE, and the product's when it
            // does not — sale and all, on the product's sale dates.
            // `Variation::prices()` is the one place that decides, so a null
            // price means "the same as the product" everywhere.
            $unit = $variation !== null
                ? Variation::prices($variation, $product)[1]
                : (int) $product['price'];

            $out[] = [
                'key'        => $lineKey,
                'product'    => $product,
                'variation'  => $variation,
                'unit_price' => $unit,
                'quantity'   => $quantity,
                // Integer multiplication of minor units. Exact, always.
                'line_total' => $unit * $quantity,
            ];
        }

        if ($pruned) {
            self::put($cart);
        }

        // KEPT UNTIL SOMEBODY READS THEM. `items()` is called several times per
        // request — the badge, the page, the totals — and the notices must not
        // depend on which call happened to be last. They accumulate here and
        // `takeNotices()` empties them.
        if ($notices !== []) {
            \Cms\Security\Session::ensure();
            $_SESSION[self::NOTICE_KEY] = array_values(array_unique(array_merge(
                $_SESSION[self::NOTICE_KEY] ?? [],
                $notices
            )));
        }

        return $out;
    }

    /**
     * What was quietly removed or reduced, and why. Reading empties the list.
     *
     * @return list<string>
     */
    public static function takeNotices(): array
    {
        $notices = $_SESSION[self::NOTICE_KEY] ?? [];
        unset($_SESSION[self::NOTICE_KEY]);

        return is_array($notices) ? array_values($notices) : [];
    }

    /** The sum of the line totals, in minor units. */
    public static function subtotal(): int
    {
        $sum = 0;
        foreach (self::items() as $line) {
            $sum += $line['line_total'];
        }

        return $sum;
    }

    /**
     * Is there anything in the basket that actually gets posted?
     *
     * ── ONE DELIVERABLE ITEM CHARGES THE BASKET ─────────────────────────────
     * Not one charge per deliverable line. A shop posting three jars of honey
     * posts ONE parcel, and charging $12 three times for it is the arithmetic
     * a customer checks and the shop cannot defend.
     *
     * A basket of memberships is not charged at all, whatever the shop has
     * configured — see `Commerce\Shipping`'s header.
     */
    public static function hasDeliverable(): bool
    {
        foreach (self::items() as $line) {
            if (!empty($line['product']['delivered'])) {
                return true;
            }
        }

        return false;
    }

    /** How many individual things are in the cart — the number on the badge. */
    public static function count(): int
    {
        $n = 0;
        foreach (self::items() as $line) {
            $n += $line['quantity'];
        }

        return $n;
    }

    public static function isEmpty(): bool
    {
        return self::items() === [];
    }

    /**
     * A quantity from a stranger, made safe.
     *
     * `(int)` on request input turns "abc" into 0 and "1e9" into 1, both of
     * which are fine; what it does NOT do is bound the result. The ceiling is
     * here so that every entry point gets it — `add`, `setQuantity` and the
     * re-read in `items()` all pass through this one function, and a new entry
     * point that forgets it is a line of code that does not exist.
     */
    private static function clamp(int $quantity): int
    {
        return max(0, min(self::MAX_QTY, $quantity));
    }
}
