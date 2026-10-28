<?php

declare(strict_types=1);

use Cms\Commerce\Cart;
use Cms\Commerce\Money;
use Cms\Commerce\Coupon;
use Cms\Commerce\Shipping;
use Cms\Commerce\Totals;
use Cms\Security\Csrf;

/**
 * THE BASKET.
 *
 * ── ONE FORM AROUND EVERY LINE ──────────────────────────────────────────────
 * The quantity boxes are `quantity[<line key>]`, so "Update basket" updates all of
 * them. A form per row would mean the customer who changes three quantities and
 * presses one button gets one change and silently loses two — and would have no
 * way to tell, because the page would reload looking almost right.
 *
 * The Remove buttons share that form and carry `name="remove"`, so removing is
 * a submit of the same form rather than a second one nested inside it. Nested
 * forms do not exist, and a remove button inside a nested form would submit
 * the OUTER one.
 *
 * @var list<array{product: array, quantity: int, line_total: int}> $items
 * @var int                                $subtotal
 *
 * The one-shot "added to basket" message is NOT drawn here — `base.php` reads
 * and clears it, so it appears on whichever page the visitor lands on.
 */
?>
<div class="shop">
    <h1 class="shop__title"><?= htmlspecialchars(\Cms\Commerce\Words::of('basket')) ?></h1>

    <?php if ($items === []): ?>
        <?php // AN EMPTY BASKET IS A STATE, NOT AN ERROR. It says what is true
              // and offers the way out, rather than an empty table with a
              // "$0.00" total under it, which reads as a shop that is broken. ?>
        <div class="shop-empty">
            <p class="shop-empty__title">Your <?= htmlspecialchars(\Cms\Commerce\Words::basketInline()) ?> is empty.</p><?php /* audit-ok: the words either side of the shop's own name for a basket */ ?>
            <p><a class="shop__link" href="<?= htmlspecialchars(BASE_URL . '/') ?>">Continue shopping</a></p>
        </div>
    <?php else: ?>
        <?php // ── `id`, SO THE BUTTON CAN LIVE OUTSIDE THE FORM ───────────
              // The reading order is basket, totals, code, then the two things
              // you do next — and the code box cannot be INSIDE this form
              // (nested forms are invalid, and Enter in the code box would run
              // "Update basket" instead of applying the code).
              //
              // HTML's `form=` attribute solves exactly this: the button sits
              // where it belongs on the page and still submits the quantities.
              // Closing this form early and putting the button in a form of
              // its own would post nothing at all. ?>
        <form id="cart-form" method="post"
              action="<?= htmlspecialchars(\Cms\Helpers\Url::page('cart/update')) ?>" class="shop-cart">
            <?= Csrf::field() ?>

            <table class="shop-table">
                <thead>
                    <tr>
                        <th scope="col">Product</th><?php /* audit-ok: a column header */ ?>
                        <th scope="col" class="shop-table__num">Price</th><?php /* audit-ok: a column header */ ?>
                        <th scope="col" class="shop-table__num">Quantity</th><?php /* audit-ok: a column header */ ?>
                        <th scope="col" class="shop-table__num">Total</th><?php /* audit-ok: a column header */ ?>
                        <th scope="col"><span class="u-visually-hidden">Remove</span></th><?php /* audit-ok: a column header */ ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $line): ?>
                        <?php
                        $p   = $line['product'];
                        $v   = $line['variation'];
                        // THE LINE'S KEY, not the product's id. A basket holding
                        // a small and a large is two rows of one product, and a
                        // quantity box named after the product would set both.
                        $key = (string) $line['key'];
                        // THE VARIANT'S SKU WHEN IT HAS ONE — that is the code
                        // on the shelf the picker is looking for, and the
                        // product's is the family it belongs to.
                        $sku = $v !== null && (string) $v['sku'] !== ''
                            ? (string) $v['sku'] : (string) $p['sku'];
                        ?>
                        <tr>
                            <th scope="row" class="shop-table__name">
                                <?= htmlspecialchars($p['title']) ?>
                                <?php if ($v !== null): ?>
                                    <span class="shop-table__variant"><?= htmlspecialchars((string) $v['label']) ?></span>
                                <?php endif; ?>
                                <?php if ($sku !== ''): ?>
                                    <span class="u-text-muted u-text-sm"><?= htmlspecialchars($sku) ?></span>
                                <?php endif; ?>
                            </th>
                            <td class="shop-table__num"><?= htmlspecialchars(Money::format((int) $line['unit_price'])) ?></td>
                            <td class="shop-table__num">
                                <?php // THE CEILING IS THE CART'S OWN, not a
                                      // number typed here. A `max` written as a
                                      // literal would drift from the clamp that
                                      // actually enforces it, and the browser's
                                      // is only a courtesy either way. ?>
                                <label class="u-visually-hidden" for="qty-<?= htmlspecialchars($key) ?>">
                                    Quantity of <?= htmlspecialchars($p['title']) ?><?php /* audit-ok: a hidden label for a screen reader, naming the row's own control */
                                        echo $v !== null ? ', ' . htmlspecialchars((string) $v['label']) : ''; ?>
                                </label>
                                <input class="shop-qty" type="number" inputmode="numeric"
                                       id="qty-<?= htmlspecialchars($key) ?>"
                                       name="quantity[<?= htmlspecialchars($key) ?>]"
                                       value="<?= (int) $line['quantity'] ?>"
                                       min="0" max="<?= Cart::MAX_QTY ?>" step="1">
                            </td>
                            <td class="shop-table__num"><?= htmlspecialchars(Money::format((int) $line['line_total'])) ?></td>
                            <td class="shop-table__num">
                                <button type="submit" name="remove" value="<?= htmlspecialchars($key) ?>"
                                        class="shop-remove">
                                    Remove<span class="u-visually-hidden"> <?= htmlspecialchars($p['title']) ?><?php /* audit-ok: the verb on the row's own button */
                                        echo $v !== null ? ', ' . htmlspecialchars((string) $v['label']) : ''; ?></span>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php // ── ONE PLACE ADDS THIS UP ──────────────────────────────
                  // `Commerce\Totals` is asked here, on the checkout page, by
                  // `Order::place()` and by the receipt. Four screens each
                  // doing their own arithmetic are four chances to disagree, and
                  // the one that disagrees is always the one the customer is
                  // looking at when they ring up. ?>
            <div class="shop-totals">
                <?php foreach (Totals::rows($totals) as [$label, $amount, $isNote]): ?>
                    <?php // THE INCLUSIVE TAX LINE IS NOT A CHARGE. It says how
                          // much of the total was tax, so it is muted and does
                          // not read as another row to add up — printed like
                          // the others it is how a customer decides they have
                          // been billed twice. ?>
                    <div class="shop-totals__row<?= $isNote ? ' shop-totals__row--note' : '' ?>">
                        <span><?= htmlspecialchars($label) ?></span>
                        <span><?= htmlspecialchars(Money::format($amount)) ?></span>
                    </div>
                <?php endforeach; ?>

                <div class="shop-totals__row shop-totals__row--grand">
                    <span>Total</span><?php /* audit-ok: the totals table's row label */ ?>
                    <strong><?= htmlspecialchars(Money::format($totals['total'])) ?></strong>
                </div>

                <?php // ── WHAT THE SHOP WANTS TO SAY, AND HOW TO GET IT
                      // FREE. The second one is the only piece of persuasion in
                      // this system, and it is here because the alternative — a
                      // delivery charge with no explanation of how to avoid it
                      // — is worse for the customer, not just for the shop. ?>
                <?php $freeNote = Shipping::freeOverNote($totals['subtotal']); ?>
                <?php if ($freeNote !== ''): ?>
                    <p class="shop-totals__note u-text-muted u-text-sm"><?= htmlspecialchars($freeNote) ?></p>
                <?php endif; ?>
                <?php if (Shipping::noteForCart() !== ''): ?>
                    <p class="shop-totals__note u-text-muted u-text-sm"><?= htmlspecialchars(Shipping::noteForCart()) ?></p>
                <?php endif; ?>
                <?php // AND WHEN THE SHOP CHARGES NOTHING AND SAYS NOTHING, the
                      // basket says nothing either — no placeholder sentence
                      // promising delivery will be "arranged later". ?>
            </div>

        </form>

        <?php // ── THE DISCOUNT CODE ──────────────────────────────────────
              // ITS OWN FORM, and it has to be: nested forms are invalid, and
              // a code box inside the basket form would mean pressing Enter in
              // it ran "Update basket" instead of applying the code. ?>
        <form class="shop-coupon" method="post"
              action="<?= htmlspecialchars(\Cms\Helpers\Url::page('cart/coupon')) ?>">
            <?= Csrf::field() ?>
            <?php if (Cart::coupon() !== ''): ?>
                <p class="shop-coupon__applied">
                    <strong><?= htmlspecialchars(Cart::coupon()) ?></strong> is applied.<?php /* audit-ok: half a sentence around the code the customer typed */ ?>
                    <button type="submit" name="remove_coupon" value="1" class="shop-remove">
                        Remove<?php /* audit-ok: the verb on the button beside it */ ?>
                    </button>
                </p>
            <?php else: ?>
                <label class="shop-coupon__label" for="coupon-code">Discount code</label><?php /* audit-ok: the field's own label */ ?>
                <div class="shop-coupon__row">
                    <input class="shop-coupon__input" type="text" id="coupon-code"
                           name="coupon_code" autocomplete="off" spellcheck="false"<?php /* audit-ok: the verb on that field's button */ ?>
                           autocapitalize="characters" placeholder="Enter a code">
                    <button type="submit" class="shop-btn shop-btn--quiet">Apply</button><?php /* audit-ok: the verb on that field's button */ ?>
                </div>
            <?php endif; ?>
        </form>

        <div class="shop-cart__actions">
            <?php // OUTSIDE THE FORM, SUBMITTING IT BY `form=`. See the note on
                  // the form's `id` above. ?>
            <button type="submit" form="cart-form" class="shop-btn shop-btn--quiet">Update <?= htmlspecialchars(\Cms\Commerce\Words::basketInline()) ?></button><?php /* audit-ok: the verb in front of the shop's own name for a basket */ ?>
            <a class="shop-btn" href="<?= htmlspecialchars(BASE_URL . '/checkout') ?>">Checkout</a>
        </div>


    <?php endif; ?>
</div>
