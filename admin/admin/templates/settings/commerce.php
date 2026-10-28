<?php

declare(strict_types=1);

use Cms\Commerce\Countries;
use Cms\Commerce\Money;
use Cms\Commerce\PaymentMethods;
use Cms\Commerce\Tax;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * SETTINGS → SHOP.
 *
 * A FRAGMENT — the controller owns `ob_start()` and `layout.php`.
 *
 * ── WHAT ACTUALLY READS THESE ───────────────────────────────────────────────
 * Every hint on this screen is a claim about behaviour, so here is each one's
 * consumer:
 *
 *   commerce_currency        `Money::currency()`, which every format and parse
 *                            in the subsystem goes through. NOT read when
 *                            rendering an existing order — that carries its own
 *                            `currency` column, so changing this does not
 *                            restate anything already sold.
 *   commerce_country         `Countries::shopDefault()`, the country a checkout
 *                            pre-selects. Nothing else.
 *   commerce_payment_methods `PaymentMethods::enabled()`, which the checkout
 *                            offers and `Checkout::validate()` refuses anything
 *                            outside of.
 *   commerce_pay_title_*     `PaymentMethods::describe()`. Copied ONTO an order
 *   commerce_pay_instr…_*    at checkout, so renaming one here does not rewrite
 *                            a receipt already sent.
 *   commerce_tax_rate        `Tax::rate()`, which `Totals::of()` is the only
 *   commerce_tax_label       caller of — so the basket, the checkout, the order
 *   commerce_tax_inclusive   row, the receipt and the email cannot disagree
 *                            about what an order comes to. The rate is stored
 *                            in hundredths of a percent; the box takes a
 *                            percentage and refuses more than two decimals.
 *
 * ── AND WHY THERE IS NO CARD GATEWAY TO CONFIGURE ───────────────────────────
 * Said on the screen rather than left as an absence. No third-party script runs
 * on these pages, and that is exactly what Stripe Elements and PayPal Buttons
 * are; taking a card number on our own server puts the install
 * inside PCI DSS, which is a compliance regime rather than a feature. An owner
 * looking for "where do I put my Stripe key" deserves the answer, not a screen
 * that silently does not have the field.
 *
 * @var array<string, string|null> $options
 * @var bool                       $saved
 * @var list<string>               $errors
 * @var array<string, mixed>       $user
 */

$opt = static fn (string $k, string $fallback = ''): string
    => (string) ($options[$k] ?? $fallback);

$enabled  = array_keys(PaymentMethods::enabled());
$currency = Money::currency();

// ── THE RATE COMES FROM `$options`, NOT FROM `Tax::rate()` ──────────────────
// The two are the same on an ordinary load and DIFFERENT on a failed save,
// which is the case that matters: `handleCommerce()` hands back what was
// posted so nothing on the screen is lost to one wrong field. Reading the
// stored rate here would redraw the box with the OLD number while the error
// message above it complained about the new one.
$taxRate = (int) $opt('commerce_tax_rate', '0');

// THE SAME REASONING FOR DELIVERY: `$options`, not the stored settings, so a
// failed save redraws what was typed rather than what is on disk.
$shipMode = $opt('commerce_ship_mode', 'none') === 'flat' ? 'flat' : 'none';
$shipFlat = (int) $opt('commerce_ship_flat', '0');
$shipFree = (int) $opt('commerce_ship_free_over', '0');
?>
<div class="page">

    <a class="page__back" href="<?= h(Url::admin('settings')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Settings
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Shop</h1>
        </div>
    </div>

    <?php if ($saved && $errors === []): ?>
        <div class="flashes">
            <div class="flash flash--success">Settings saved.</div>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert--danger">
            <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__title">
                    <?= count($errors) === 1 ? 'One thing needs fixing' : count($errors) . ' things need fixing' ?>
                </p>
                <ul class="alert__text">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= h(Url::admin('settings/commerce')) ?>">
        <?= Csrf::field() ?>

        <div class="form-main">
            <div class="form-card">

                <div class="form-card__section">
                    <?= panel_title('Money', 'payment') ?>

                    <div class="form-grid form-grid--2">
                        <div class="field">
                            <label class="label" for="f-currency">Currency</label>
                            <select class="select" id="f-currency" name="commerce_currency">
                                <?php // THE LIST IS `Money::CURRENCIES`. A code
                                      // typed here that the table does not know
                                      // would be silently refused by
                                      // `Money::currency()` and fall back to CAD
                                      // — a select whose choice does not stick. ?>
                                <?php foreach (Money::options() as $code => $label): ?>
                                    <option value="<?= h($code) ?>"<?= $currency === $code ? ' selected' : '' ?>>
                                        <?= h($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php // WHAT IT DOES NOT DO IS THE IMPORTANT HALF.
                                  // An owner about to switch currency needs to
                                  // know whether it rewrites their history. It
                                  // does not: every order stores the currency it
                                  // was placed in. ?>
                            <p class="form-help">
                                What new prices and new orders are in. Orders
                                already placed keep the currency they were placed
                                in, so changing this does not restate them.
                            </p>
                        </div>

                        <div class="field">
                            <label class="label" for="f-country">Shop country</label>
                            <select class="select" id="f-country" name="commerce_country">
                                <?php foreach (Countries::ALL as $code => $name): ?>
                                    <option value="<?= h($code) ?>"<?= Countries::shopDefault() === $code ? ' selected' : '' ?>>
                                        <?= h($name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-help">
                                The country a checkout starts on. A customer can
                                still choose any other.
                            </p>
                        </div>
                    </div>

                    <div class="field">
                        <label class="label" for="f-admin-email">Order notifications</label>
                        <input class="input" type="email" id="f-admin-email"
                               name="commerce_admin_email"
                               value="<?= h($opt('commerce_admin_email')) ?>"
                               autocomplete="off" spellcheck="false">
                        <?php // WHAT HAPPENS IF IT IS BLANK, SAID PLAINLY.
                              // `Mailer::adminAddress()` falls back to the
                              // Settings → Email sender and then to nothing —
                              // and `OrderMail` treats "nothing" as a choice
                              // rather than a failure, so no note is written
                              // and no error appears anywhere. Without this
                              // sentence a blank box looks like it should be
                              // working. ?>
                        <p class="form-help">
                            Where new orders are emailed. Left blank, the From
                            address on Settings &rarr; Email is used; with
                            neither set, nobody is emailed when an order arrives
                            — the order still appears under Shop.
                        </p>
                    </div>
                </div>

                <div class="form-card__section">
                    <?= panel_title('Delivery', 'delivery') ?>

                    <div class="form-grid form-grid--2">
                        <div class="field">
                            <label class="label" for="f-ship-mode">What delivery costs</label>
                            <select class="select" id="f-ship-mode" name="commerce_ship_mode">
                                <option value="none"<?= $shipMode === 'none' ? ' selected' : '' ?>>
                                    Nothing — I do not charge for delivery
                                </option>
                                <option value="flat"<?= $shipMode === 'flat' ? ' selected' : '' ?>>
                                    One flat rate per order
                                </option>
                            </select>
                            <?php // DEFAULTING TO "NOTHING" IS THE POINT. A
                                  // shop that has not been told what delivery
                                  // costs must not invent a figure and add it
                                  // to somebody's order — and plenty of these
                                  // shops hand the thing over in person. ?>
                            <p class="form-help">
                                One rate for the whole order, however many
                                things are in it — posting three jars is one
                                parcel. This site does not charge different rates
                                to different places; if you need that, this is
                                not enough for your shop.
                            </p>
                        </div>

                        <div class="field">
                            <label class="label" for="f-ship-flat">The rate</label>
                            <input class="input" type="text" id="f-ship-flat"
                                   name="commerce_ship_flat"
                                   value="<?= h($shipFlat > 0 ? Money::edit($shipFlat) : '') ?>"
                                   autocomplete="off" spellcheck="false"
                                   inputmode="decimal"
                                   placeholder="<?= h(Money::edit(0)) ?>">
                            <p class="form-help">
                                In <?= h(Money::currency()) ?>. Leave it blank
                                or at nothing and delivery is free.
                            </p>
                        </div>
                    </div>

                    <div class="form-grid form-grid--2">
                        <div class="field">
                            <label class="label" for="f-ship-free">Free over</label>
                            <input class="input" type="text" id="f-ship-free"
                                   name="commerce_ship_free_over"
                                   value="<?= h($shipFree > 0 ? Money::edit($shipFree) : '') ?>"
                                   autocomplete="off" spellcheck="false"
                                   inputmode="decimal">
                            <?php // AT OR ABOVE, and the word matters. A
                                  // threshold of $100 that does not include a
                                  // basket of exactly $100 is an off-by-one the
                                  // customer notices immediately and reads as a
                                  // trick. ?>
                            <p class="form-help">
                                Spend this much or more on the goods and
                                delivery is free. Blank for never. The basket
                                tells a customer how much more they need.
                            </p>
                        </div>

                        <div class="field">
                            <label class="label" for="f-ship-note">What to tell customers</label>
                            <input class="input" type="text" id="f-ship-note"
                                   name="commerce_ship_note"
                                   value="<?= h($opt('commerce_ship_note')) ?>"
                                   autocomplete="off"
                                   placeholder="Posted within two working days">
                            <p class="form-help">
                                Shown under the total on the basket and the
                                checkout. Blank for nothing.
                            </p>
                        </div>
                    </div>

                    <?php // THE PRODUCT DECIDES, AND THE OWNER HAS TO KNOW
                          // THAT. A rate set here does nothing at all until
                          // something is ticked as needing delivering, and an
                          // owner who does not know that has a delivery charge
                          // that silently never appears. ?>
                    <div class="alert alert--info">
                        <?= icon('circle-info', 'alert__icon icon', '', 18) ?>
                        <div class="alert__body">
                            <p class="alert__text">
                                Delivery is only charged when the basket holds
                                something ticked <strong>Needs delivering</strong>
                                on its own page. A basket of memberships,
                                tickets or downloads is never charged, whatever
                                is set here.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="form-card__section">
                    <?= panel_title('Tax', 'tax') ?>

                    <div class="form-grid form-grid--2">
                        <div class="field">
                            <label class="label" for="f-tax-rate">Rate</label>
                            <input class="input" type="text" id="f-tax-rate"
                                   name="commerce_tax_rate"
                                   value="<?= h(Tax::percent($taxRate)) ?>"
                                   autocomplete="off" spellcheck="false"
                                   inputmode="decimal">
                            <?php // WHAT IS REFUSED, SAID BEFORE IT IS REFUSED.
                                  // Two decimal places is the floor `Money`
                                  // sets for money and `Tax` sets for rates: a
                                  // number the system cannot hold exactly is
                                  // one it must not pretend to hold. An owner
                                  // who types 8.875 is told here rather than
                                  // finding 8.88 saved. ?>
                            <p class="form-help">
                                A percentage. Up to two decimal places — 5,
                                12.5, 8.88. Leave it at 0 and no tax is charged
                                or shown anywhere.
                            </p>
                        </div>

                        <div class="field">
                            <label class="label" for="f-tax-label">What it is called</label>
                            <input class="input" type="text" id="f-tax-label"
                                   name="commerce_tax_label"
                                   value="<?= h($opt('commerce_tax_label')) ?>"
                                   placeholder="Tax" autocomplete="off">
                            <p class="form-help">
                                GST, VAT, Sales tax. It is printed on the
                                basket, the receipt and the customer's email
                                with the rate beside it &mdash;
                                <strong><?= h(Tax::line($opt('commerce_tax_label'), $taxRate)) ?></strong>.
                            </p>
                        </div>
                    </div>

                    <div class="field">
                        <?php // A CHECKBOX AND NOT TWO RADIOS, because the two
                              // answers are not equal in weight: adding tax at
                              // the checkout is what most shops do, and the
                              // other is the exception. `check-list__row` is
                              // the same component the payment methods use
                              // below, so the two blocks read as one screen. ?>
                        <label class="check-list__row" for="f-tax-inclusive">
                            <input type="checkbox" id="f-tax-inclusive"
                                   name="commerce_tax_inclusive" value="1"
                                   <?= $opt('commerce_tax_inclusive') === '1' ? 'checked' : '' ?>>
                            <span>My prices already include this tax</span>
                        </label>
                        <?php // THE DIFFERENCE IN NUMBERS, not in words. An
                              // owner reading "inclusive" and "exclusive" has
                              // to work out which one they are; an owner
                              // reading what a $10 item costs does not. ?>
                        <p class="form-help">
                            <?php if ($taxRate > 0): ?>
                                Off: a <?= h(Money::format(1000)) ?> item costs
                                <strong><?= h(Money::format(1000 + Tax::compute(1000, $taxRate, false))) ?></strong>
                                and the tax is
                                <?= h(Money::format(Tax::compute(1000, $taxRate, false))) ?>.
                                On: it costs <strong><?= h(Money::format(1000)) ?></strong>
                                and <?= h(Money::format(Tax::compute(1000, $taxRate, true))) ?>
                                of that is tax.
                            <?php else: ?>
                                With the rate at 0 this changes nothing. Set a
                                rate above and this line shows what a
                                <?= h(Money::format(1000)) ?> item would cost
                                either way.
                            <?php endif; ?>
                        </p>
                    </div>

                    <?php // THE LIMIT, WRITTEN ON THE SCREEN AND NOT ONLY IN
                          // THE CODE. An owner who needs per-province rates has
                          // to find that out BEFORE they build a price list on
                          // top of one rate, not after a customer queries a
                          // receipt. `Commerce\Tax`'s header has the reasoning. ?>
                    <p class="form-help">
                        One rate, for the whole shop. This site does not charge
                        different rates to different provinces or states &mdash;
                        if you need that, this is not enough for your shop and
                        it is better to know now.
                    </p>
                </div>

                <div class="form-card__section">
                    <?= panel_title('How customers pay', 'payment') ?>

                    <?php // THE CONSEQUENCE OF TURNING THEM ALL OFF, SAID ONCE
                          // AND HERE. `Checkout::validate()` refuses an order
                          // with no method, so an owner who unticks everything
                          // has closed their shop — which is a legitimate thing
                          // to do and a terrible thing to discover by accident. ?>
                    <?php if ($enabled === []): ?>
                        <?php // A BARE `.alert` IS THE WARNING TONE —
                              // core.css §10: the base carries `--warning-soft`
                              // and a `--warning` top edge, and the variants are
                              // `--danger` (broken) and `--info` (no alert
                              // colour at all). `.alert--warning` reads as the
                              // obvious class name and is not one; written that
                              // way it would add a class nothing defines, which
                              // changes nothing visually and is exactly the
                              // kind of dead markup that accumulates.
                              // Degraded, not broken: the shop
                              // still runs, it just cannot sell. ?>
                        <div class="alert">
                            <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
                            <div class="alert__body">
                                <p class="alert__title">This shop cannot take an order</p>
                                <p class="alert__text">
                                    With no payment method enabled, the checkout
                                    tells customers so and refuses to place an
                                    order. Tick at least one below.
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php foreach (PaymentMethods::METHODS as $id => $default): ?>
                        <?php
                        $on    = in_array($id, $enabled, true);
                        $shown = PaymentMethods::describe($id);
                        ?>
                        <div class="pay-method">
                            <?php // `.check-list__row`, the admin's own checkbox
                                  // row — `.checkbox` is not a class in this
                                  // system. Wrapped in `.check-list` even for a
                                  // single row, because the gap between rows is
                                  // the list's and a lone row outside one sits
                                  // at a different rhythm from every other
                                  // checkbox in the admin. ?>
                            <div class="check-list">
                                <label class="check-list__row">
                                    <input type="checkbox" name="methods[]" value="<?= h($id) ?>"
                                           <?= $on ? 'checked' : '' ?>>
                                    <span><?= h($default['title']) ?></span>
                                </label>
                            </div>
                            <p class="form-help"><?= h($default['description']) ?></p>

                            <?php // THE OVERRIDES ARE ALWAYS IN THE DOCUMENT,
                                  // never rendered conditionally on the tick.
                                  // Wording typed for a method, then unticked
                                  // while deciding, must still be there when it
                                  // is ticked again — and the POST must carry it
                                  // either way or saving would wipe it. ?>
                            <div class="form-grid form-grid--2">
                                <div class="field">
                                    <label class="label" for="f-title-<?= h($id) ?>">What to call it</label>
                                    <input class="input" type="text" id="f-title-<?= h($id) ?>"
                                           name="title[<?= h($id) ?>]"
                                           value="<?= h($opt(PaymentMethods::titleOptionKey($id))) ?>"
                                           placeholder="<?= h($default['title']) ?>">
                                    <p class="form-help">
                                        Left blank, customers see
                                        &ldquo;<?= h($default['title']) ?>&rdquo;.
                                    </p>
                                </div>

                                <div class="field">
                                    <label class="label" for="f-instr-<?= h($id) ?>">How to pay</label>
                                    <textarea class="input" id="f-instr-<?= h($id) ?>"
                                              name="instructions[<?= h($id) ?>]" rows="3"
                                              placeholder="<?= h($default['instructions']) ?>"><?= h($opt(PaymentMethods::instructionsOptionKey($id))) ?></textarea>
                                    <?php // THIS IS THE FIELD THAT GETS PAID.
                                          // "Pay by bank transfer" with no
                                          // account number is an order the
                                          // customer cannot complete; they email
                                          // instead, if you are lucky. ?>
                                    <p class="form-help">
                                        Shown on the order confirmation and in the
                                        email. Put your account details here —
                                        without them the customer has no way to pay.
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="form-card__section">
                    <?= panel_title('Card payments', 'payment') ?>
                    <?php // AN ABSENCE, EXPLAINED. An owner hunting for "where
                          // does my Stripe key go" should find the answer here
                          // rather than conclude the screen is unfinished. ?>
                    <p class="form-help">
                        This site does not take card details on your site, and there
                        is no gateway key to enter. Card fields from Stripe or
                        PayPal are scripts loaded from those companies&rsquo;
                        servers, running inside your checkout — this site ships no
                        third-party code. Accepting a card number on your own
                        server instead would put this site inside the card
                        industry&rsquo;s PCI compliance regime, which is not
                        something software can satisfy on your behalf.
                    </p>
                    <p class="form-help">
                        Take the order here, take the money the way you already
                        do.
                    </p>
                </div>

                <div class="form-card__section">
                    <?= panel_title('Wording', 'content') ?>
                    <?php // ── THE THREE WORDS A SHOP'S VOICE OWNS ────────────
                          // The shop's templates hold many fixed strings. Most
                          // are furniture — a table's "Quantity" header, a
                          // form's "Country" label — and are marked at their
                          // line with a reason: the answer to unreachable text
                          // is not a new store per string.
                          //
                          // These three are the ones shops actually ask for.
                          // BLANK MEANS THE WORD THE TEMPLATES ALREADY USE, so
                          // nothing on any site moves until somebody types. ?>
                    <p class="form-help">
                        Leave these empty to keep the words the shop already uses.
                    </p>

                    <div class="form-grid form-grid--2">
                        <div class="field">
                            <label class="label" for="f-word-basket">What a basket is called</label>
                            <input class="input" type="text" id="f-word-basket"
                                   name="commerce_word_basket"
                                   value="<?= h($opt('commerce_word_basket')) ?>"
                                   autocomplete="off" placeholder="Basket">
                            <p class="form-help">
                                Used in the header, on the basket page and in the
                                buttons that lead there. &ldquo;Cart&rdquo; and
                                &ldquo;Bag&rdquo; are the usual alternatives.
                            </p>
                        </div>

                        <div class="field">
                            <label class="label" for="f-word-add">The buy button</label>
                            <input class="input" type="text" id="f-word-add"
                                   name="commerce_word_add"
                                   value="<?= h($opt('commerce_word_add')) ?>"
                                   autocomplete="off" placeholder="Add to basket">
                            <p class="form-help">
                                On every product page. Some shops want
                                &ldquo;Buy now&rdquo;, and a shop selling one
                                thing at a time often does.
                            </p>
                        </div>
                    </div>

                    <div class="field">
                        <label class="label" for="f-thanks">What the receipt says first</label>
                        <input class="input" type="text" id="f-thanks"
                               name="commerce_thanks"
                               value="<?= h($opt('commerce_thanks')) ?>"
                               autocomplete="off" placeholder="Thank you">
                        <p class="form-help">
                            The heading a customer sees the moment they have
                            ordered, above the order number.
                        </p>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">Save changes</button>
                </div>
            </div>
        </div>
    </form>
</div>
