<?php

declare(strict_types=1);

use Cms\Commerce\Checkout;
use Cms\Commerce\Money;
use Cms\Commerce\Regions;
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

/**
 * The province or state, twice — billing and delivery.
 *
 * A LIST WHERE THE COUNTRY HAS ONE (`Commerce\Regions`), because a Canadian
 * order's tax is its province's; a box anywhere else. Both are drawn and one
 * is hidden: `checkout.js` shows the one the chosen country needs, and
 * without it the server reads whichever the country calls for.
 */
$regionField = static function (string $key, bool $required, array $form, array $errors): void {
    $who     = substr($key, 0, -strlen('_region'));
    $country = strtoupper((string) ($form[$who . '_country'] ?? ''));
    $listed  = Regions::listed($country);
    // NEVER `required` ON THE DELIVERY ADDRESS'S, like every other field of
    // it: that block is hidden until its box is ticked, and a hidden required
    // field stops the browser sending the form at all. The server asks for it
    // when it is needed (`Checkout::validate()`).
    $words   = [];
    foreach (array_keys(Regions::ALL) as $listCountry) {
        $words[$listCountry] = Regions::word($listCountry);
    }
    $id      = 'co-' . $key;
    $message = $errors[$key] ?? '';
    ?>
    <div class="shop-field<?= $message !== '' ? ' shop-field--error' : '' ?>" data-region-field="<?= htmlspecialchars($who) ?>"
         data-region-words="<?= htmlspecialchars((string) json_encode($words)) ?>" data-region-default="<?= htmlspecialchars(Regions::word('')) ?>"
         <?= $required ? 'data-region-required' : '' ?>>
        <label class="shop-field__label" for="<?= htmlspecialchars($id) ?>">
            <span data-region-word><?= htmlspecialchars(Regions::word($country)) ?></span><?php if ($required): ?><span class="shop-field__req" aria-hidden="true" data-region-star<?= $listed ? '' : ' hidden' ?>>*</span><?php endif; ?>
        </label>
        <select class="shop-field__input" id="<?= htmlspecialchars($id) ?>" name="<?= htmlspecialchars($key) ?>"
                data-region-select<?= $listed ? ($required ? ' required' : '') : ' hidden' ?>
                <?= $message !== '' ? 'aria-invalid="true" aria-describedby="' . htmlspecialchars($id) . '-err"' : '' ?>>
            <option value="">Choose…</option><?php /* audit-ok: the prompt a select opens on */ ?>
            <?php foreach (Regions::ALL as $listCountry => $list): ?>
                <optgroup label="<?= htmlspecialchars(\Cms\Commerce\Countries::name($listCountry)) ?>" data-region-country="<?= htmlspecialchars($listCountry) ?>">
                    <?php foreach ($list as $code => $name): ?>
                        <option value="<?= htmlspecialchars($code) ?>"<?= $listCountry === $country && ($form[$key] ?? '') === $code ? ' selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
        </select>
        <input class="shop-field__input" type="text" id="<?= htmlspecialchars($id) ?>-text"
               name="<?= htmlspecialchars($key) ?>_text" aria-label="<?= htmlspecialchars(Regions::word('')) ?>"
               value="<?= $listed ? '' : htmlspecialchars($form[$key] ?? '') ?>"
               data-region-text<?= $listed ? ' hidden' : '' ?>>
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
                            <?php elseif ($key === 'billing_region'): ?>
                                <?php $regionField($key, true, $form, $errors); ?>
                            <?php else: ?>
                                <?php $field($key, $label, $required, $form, $errors); ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="shop-panel">
                    <h2 class="shop-panel__title">Delivery</h2><?php /* audit-ok: a section heading */ ?>
                    <?php // DELIVERED OR COLLECTED, when the shop offers both and
                          // the basket has something to collect. Collected, the
                          // address below is still the customer's, for the
                          // receipt, and nothing is charged for delivery. ?>
                    <?php if (\Cms\Commerce\Shipping::pickup() && \Cms\Commerce\Cart::hasDeliverable()): ?>
                        <fieldset class="shop-choice" data-delivery-method>
                            <legend class="shop-field__label">How you get it</legend><?php /* audit-ok: the choice's own name */ ?>
                            <label class="shop-check">
                                <input type="radio" name="delivery_method" value="delivery"
                                       <?= ($form['delivery_method'] ?? '') !== 'pickup' ? 'checked' : '' ?>>
                                <span>Delivered to me</span><?php /* audit-ok: one of the choice's two answers */ ?>
                            </label>
                            <label class="shop-check">
                                <input type="radio" name="delivery_method" value="pickup"
                                       <?= ($form['delivery_method'] ?? '') === 'pickup' ? 'checked' : '' ?>>
                                <span>I'll collect it, at no charge</span><?php /* audit-ok: the other answer */ ?>
                            </label>
                            <?php if (\Cms\Commerce\Shipping::pickupWhere() !== ''): ?>
                                <p class="shop-choice__where u-text-muted u-text-sm"><?= nl2br(htmlspecialchars(\Cms\Commerce\Shipping::pickupWhere())) ?></p>
                            <?php endif; ?>
                        </fieldset>
                    <?php endif; ?>
                    <?php if ($err('delivery_method') !== ''): ?>
                        <p class="shop-field__error" id="co-delivery_method-err"><?= htmlspecialchars($err('delivery_method')) ?></p>
                    <?php endif; ?>
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
                            <?php elseif ($key === 'shipping_region'): ?>
                                <?php $regionField($key, false, $form, $errors); ?>
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
                    <?php // THE FIGURES, in a box `checkout.js` redraws from
                          // `/checkout/totals` when the province changes. The
                          // button does the same without the script: it sends
                          // the form back to be drawn again, placing nothing. ?>
                    <?php if ($err('coupon') !== ''): ?>
                        <p class="shop-field__error" id="co-coupon-err"><?= htmlspecialchars($err('coupon')) ?></p>
                    <?php endif; ?>
                    <div data-checkout-totals="<?= htmlspecialchars(\Cms\Helpers\Url::page('checkout/totals')) ?>" aria-live="polite"><?php /* audit-ok: a route on this site, not words a shop owner would rewrite */ ?>
                        <?php require \Cms\Helpers\Template::locate('shop/totals.php'); ?>
                    </div>
                    <button type="submit" class="shop-btn shop-btn--quiet" name="refresh" value="1" formnovalidate data-totals-refresh>Update the total</button><?php /* audit-ok: the form's own verb */ ?>
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

                        <?php // WHAT THE CUSTOMER AGREES TO, AND ASKS FOR, just
                              // above the button that commits them — the terms
                              // linked by the page's own title, the offers box
                              // unticked until they tick it. ?>
                        <?php $terms = Checkout::termsPage(); ?>
                        <?php if ($terms !== null): ?>
                            <label class="shop-check shop-check--agree">
                                <input type="checkbox" name="accept_terms" value="1" required
                                       <?= ($form['accept_terms'] ?? '') === '1' ? 'checked' : '' ?>
                                       <?= $err('accept_terms') !== '' ? 'aria-invalid="true" aria-describedby="co-accept_terms-err"' : '' ?>>
                                <span>I have read and agree to the <a href="<?= htmlspecialchars($terms['url']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($terms['title']) ?></a></span><?php /* audit-ok: the system asking for the agreement the owner set up; the page's title is the owner's own words */ ?>
                            </label>
                            <?php if ($err('accept_terms') !== ''): ?>
                                <p class="shop-field__error" id="co-accept_terms-err"><?= htmlspecialchars($err('accept_terms')) ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (Checkout::offersSignup()): ?>
                            <label class="shop-check">
                                <input type="checkbox" name="marketing_opt_in" value="1"
                                       <?= ($form['marketing_opt_in'] ?? '') === '1' ? 'checked' : '' ?>>
                                <span>Send me news and offers by email</span><?php /* audit-ok: the box's own label, in the words consent needs */ ?>
                            </label>
                        <?php endif; ?>

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
