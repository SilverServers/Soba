<?php

declare(strict_types=1);

use Cms\Commerce\Checkout;
use Cms\Commerce\Money;
use Cms\Commerce\Shipping;
use Cms\Commerce\Totals;
use Cms\Security\Csrf;

/**
 * CHECKOUT — the address, how they will pay, and what they are buying.
 *
 * ── THE ORDER SUMMARY IS ON THE PAGE, NOT A LINK BACK TO THE BASKET ─────────
 * A customer about to type an address wants to see what they are paying for
 * without losing what they have typed. The summary is rendered from the same
 * `Cart::items()` the order will be built from, so the figures they confirm are
 * the figures that get written.
 *
 * ── A FAILED SUBMIT DOES NOT CLEAR THE FORM ─────────────────────────────────
 * Every field re-renders from `$form`, which is what was posted. The first
 * version of any checkout throws the address away when one field is wrong, and
 * it is the single most common reason a shop loses a sale it had already made.
 *
 * @var array<string, string> $form
 * @var array<string, string> $errors     field => message
 * @var list<array{product: array, quantity: int, line_total: int}> $items
 * @var int                   $subtotal
 * @var array<string, array>  $methods    enabled payment methods
 * @var array<string, string> $countries
 */

/** A field's error message, or ''. */
$err = static fn (string $key): string => $errors[$key] ?? '';

/**
 * One text field, with its label, its error and what was typed.
 *
 * A CLOSURE RATHER THAN NINE COPIES. The nine address fields differ only in
 * name and label, and nine hand-written blocks is nine places for the error
 * markup to be forgotten on one of them — which is how a field ends up
 * rejecting silently.
 */
$field = static function (string $key, string $label, bool $required, array $form, array $errors): void {
    $id      = 'co-' . $key;
    $message = $errors[$key] ?? '';
    ?>
    <div class="shop-field<?= in_array($key, Checkout::WIDE_FIELDS, true) ? ' shop-field--wide' : '' ?><?= $message !== '' ? ' shop-field--error' : '' ?>">
        <label class="shop-field__label" for="<?= htmlspecialchars($id) ?>">
            <?= htmlspecialchars($label) ?><?php if ($required): ?><span class="shop-field__req" aria-hidden="true">*</span><?php endif; ?>
        </label>
        <input class="shop-field__input" type="<?= $key === 'email' ? 'email' : ($key === 'phone' ? 'tel' : 'text') ?>"
               id="<?= htmlspecialchars($id) ?>" name="<?= htmlspecialchars($key) ?>"
               value="<?= htmlspecialchars($form[$key] ?? '') ?>"
               <?= $required ? 'required' : '' ?>
               <?= $message !== '' ? 'aria-invalid="true" aria-describedby="' . htmlspecialchars($id) . '-err"' : '' ?>>
        <?php if ($message !== ''): ?>
            <p class="shop-field__error" id="<?= htmlspecialchars($id) ?>-err"><?= htmlspecialchars($message) ?></p>
        <?php endif; ?>
    </div>
    <?php
};

/** The country select, twice — billing and delivery. */
$countryField = static function (string $key, bool $required, array $form, array $errors, array $countries): void {
    $id      = 'co-' . $key;
    $message = $errors[$key] ?? '';
    ?>
    <div class="shop-field<?= $message !== '' ? ' shop-field--error' : '' ?>">
        <label class="shop-field__label" for="<?= htmlspecialchars($id) ?>">
            Country<?php if ($required): ?><span class="shop-field__req" aria-hidden="true">*</span><?php endif; ?><?php /* audit-ok: the field's own label */ ?>
        </label>
        <select class="shop-field__input" id="<?= htmlspecialchars($id) ?>" name="<?= htmlspecialchars($key) ?>"
                <?= $required ? 'required' : '' ?>>
            <option value="">Choose a country…</option><?php /* audit-ok: the prompt a select opens on */ ?>
            <?php foreach ($countries as $code => $name): ?>
                <option value="<?= htmlspecialchars($code) ?>"
                    <?= ($form[$key] ?? '') === $code ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($message !== ''): ?>
            <p class="shop-field__error"><?= htmlspecialchars($message) ?></p>
        <?php endif; ?>
    </div>
    <?php
};
?>
<div class="shop">
    <h1 class="shop__title">Checkout</h1><?php /* audit-ok: the page's own name, which is also its address */ ?>

    <?php if (isset($errors['cart'])): ?>
        <div class="cms-alert cms-alert--error imprint-alert imprint-alert--error"><?= htmlspecialchars($errors['cart']) ?></div>
        <p><a class="shop__link" href="<?= htmlspecialchars(BASE_URL . '/cart') ?>">Back to the basket</a></p>
    <?php elseif ($items === []): ?>
        <div class="shop-empty">
            <p class="shop-empty__title">There is nothing to check out.</p><?php /* audit-ok: the system saying the basket is empty, which it decided */ ?>
            <p><a class="shop__link" href="<?= htmlspecialchars(BASE_URL . '/') ?>">Continue shopping</a></p>
        </div>
    <?php else: ?>

        <?php if ($errors !== []): ?>
            <?php // A SUMMARY AT THE TOP AND A MESSAGE ON EACH FIELD. The
                  // summary is what a person reading from the top sees; the
                  // per-field message is what they need when they get there.
                  // One without the other means either scrolling to find the
                  // problem or being told there is one and not where. ?>
            <div class="cms-alert cms-alert--error imprint-alert imprint-alert--error">
                Please check the fields marked below.<?php /* audit-ok: the form saying validation failed, which it decided */ ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars(\Cms\Helpers\Url::page('checkout')) ?>" class="shop-checkout">
            <?= Csrf::field() ?>

            <div class="shop-checkout__main">
                <section class="shop-panel">
                    <h2 class="shop-panel__title">Your details</h2><?php /* audit-ok: a section heading over the fields it names */ ?>
                    <div class="shop-fields">
                        <?php foreach (Checkout::FIELDS as $key => [$label, $required]): ?>
                            <?php if ($key === 'billing_country'): ?>
                                <?php $countryField($key, $required, $form, $errors, $countries); ?>
                            <?php else: ?>
                                <?php $field($key, $label, $required, $form, $errors); ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="shop-panel">
                    <h2 class="shop-panel__title">Delivery</h2><?php /* audit-ok: a section heading */ ?>
                    <?php // UNCHECKED MEANS "SEND IT TO THE ADDRESS ABOVE", and
                          // the fields stay in the document rather than being
                          // rendered conditionally — so a customer who ticks the
                          // box, types an address, unticks it and re-ticks it
                          // still has what they typed. The JS only hides. ?>
                    <label class="shop-check">
                        <input type="checkbox" name="ship_elsewhere" value="1"
                               data-ship-toggle
                               <?= ($form['ship_elsewhere'] ?? '') === '1' ? 'checked' : '' ?>>
                        <span>Deliver to a different address</span><?php /* audit-ok: the checkbox's own label */ ?>
                    </label>

                    <div class="shop-fields" data-ship-fields
                         <?= ($form['ship_elsewhere'] ?? '') === '1' ? '' : 'hidden' ?>>
                        <?php foreach (Checkout::SHIPPING_FIELDS as $key => $label): ?>
                            <?php if ($key === 'shipping_country'): ?>
                                <?php $countryField($key, false, $form, $errors, $countries); ?>
                            <?php else: ?>
                                <?php $field($key, $label, false, $form, $errors); ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <div class="shop-field">
                        <label class="shop-field__label" for="co-note">Order notes</label><?php /* audit-ok: a section heading */ ?>
                        <textarea class="shop-field__input" id="co-note" name="customer_note" rows="3"
                                  placeholder="Anything we should know about your order or delivery."><?= htmlspecialchars($form['customer_note'] ?? '') ?></textarea>
                    </div>
                </section>
            </div>

            <aside class="shop-checkout__side">
                <section class="shop-panel">
                    <h2 class="shop-panel__title">Your order</h2><?php /* audit-ok: a section heading */ ?>
                    <ul class="shop-summary">
                        <?php foreach ($items as $line): $p = $line['product']; ?>
                            <li class="shop-summary__row">
                                <span class="shop-summary__name">
                                    <?= htmlspecialchars($p['title']) ?>
                                    <span class="u-text-muted">× <?= (int) $line['quantity'] ?></span>
                                </span>
                                <span class="shop-summary__num"><?= htmlspecialchars(Money::format((int) $line['line_total'])) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php // THE SAME ROWS THE BASKET PRINTED, from the same
                          // `Commerce\Totals` call. A checkout that recomputes
                          // its own figures is a checkout that can show a
                          // different number from the page the customer just
                          // agreed to — which is the one disagreement that
                          // costs money. ?>
                    <?php foreach (Totals::rows($totals) as [$label, $amount, $isNote]): ?>
                        <div class="shop-totals__row<?= $isNote ? ' shop-totals__row--note' : '' ?>">
                            <span><?= htmlspecialchars($label) ?></span>
                            <span><?= htmlspecialchars(Money::format($amount)) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="shop-totals__row shop-totals__row--grand">
                        <span>Total</span><?php /* audit-ok: the totals table's row label */ ?>
                        <strong><?= htmlspecialchars(Money::format($totals['total'])) ?></strong>
                    </div>
                    <?php $freeNote = Shipping::freeOverNote($totals['subtotal']); ?>
                    <?php if ($freeNote !== ''): ?>
                        <p class="shop-totals__note u-text-muted u-text-sm"><?= htmlspecialchars($freeNote) ?></p>
                    <?php endif; ?>
                    <?php if (Shipping::noteForCart() !== ''): ?>
                        <p class="shop-totals__note u-text-muted u-text-sm"><?= htmlspecialchars(Shipping::noteForCart()) ?></p>
                    <?php endif; ?>
                </section>

                <section class="shop-panel">
                    <h2 class="shop-panel__title">Payment</h2><?php /* audit-ok: a section heading */ ?>

                    <?php if ($methods === []): ?>
                        <?php // NOT AN EMPTY BOX. A shop with no payment method
                              // enabled cannot take an order, and saying so is
                              // the only useful thing this space can do. ?>
                        <p class="shop-field__error">
                            This shop is not currently taking payment. Please get in touch instead.<?php /* audit-ok: the system saying no payment method is enabled — a state, not copy */ ?>
                        </p>
                    <?php else: ?>
                        <?php if ($err('payment_method') !== ''): ?>
                            <p class="shop-field__error"><?= htmlspecialchars($err('payment_method')) ?></p>
                        <?php endif; ?>

                        <?php $i = 0; foreach ($methods as $id => $method): $i++; ?>
                            <label class="shop-pay">
                                <input type="radio" name="payment_method" value="<?= htmlspecialchars($id) ?>"
                                    <?= ($form['payment_method'] ?? '') === $id
                                        || (($form['payment_method'] ?? '') === '' && $i === 1) ? 'checked' : '' ?>>
                                <span class="shop-pay__body">
                                    <span class="shop-pay__title"><?= htmlspecialchars($method['title']) ?></span>
                                    <span class="shop-pay__desc u-text-muted u-text-sm"><?= htmlspecialchars($method['description']) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>

                        <button type="submit" class="shop-btn shop-btn--wide">Place order</button><?php /* audit-ok: the verb that submits the order */ ?>
                    <?php endif; ?>
                </section>
            </aside>
        </form>

        <?php // LINKED ONLY ON THE PAGE THAT USES IT, the same rule
              // `Shortcode::renderForm()` follows for its conditional scripts.
              // A site with no shop should not be downloading a checkout
              // script, and a script tag in `base.php` would put it on every
              // page of every install. ?>
        <script src="<?= htmlspecialchars(\Cms\Helpers\Asset::site('assets/js/checkout.js')) ?>" defer></script>
    <?php endif; ?>
</div>
