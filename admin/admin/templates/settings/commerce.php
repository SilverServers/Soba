<?php

declare(strict_types=1);

use Cms\Commerce\Countries;
use Cms\Commerce\Money;
use Cms\Commerce\PaymentMethods;
use Cms\Commerce\Regions;
use Cms\Commerce\Shipping;
use Cms\Commerce\TaxRates;
use Cms\Helpers\Asset;
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
 *   commerce_region          `TaxRates::home()`: where the shop is, which a
 *                            basket's tax is estimated for before an address
 *                            is known.
 *   commerce_tax_rates       `TaxRates::all()`, which `Totals::of()` reads for
 *   commerce_tax_inclusive   the place an order goes — so the basket, the
 *                            checkout, the order, the receipt and the email
 *                            cannot disagree about what it comes to. Rates are
 *                            stored in thousandths of a percent; each box
 *                            takes a percentage and refuses a fourth decimal.
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
 * @var list<array{country: string, region: string, label: string, rate: string}> $taxRows
 * @var list<array{country: string, region: string, rate: string, free_over: string}> $shipRows
 * @var string                     $notice
 */

$opt = static fn (string $k, string $fallback = ''): string
    => (string) ($options[$k] ?? $fallback);

// ── FROM `$options`, NOT FROM THE STORED SETTINGS ───────────────────────────
// The two are the same on an ordinary load and DIFFERENT on a failed save,
// which is the case that matters: `handleCommerce()` hands back what was
// posted so nothing on the screen is lost to one wrong field. The ticked
// methods and the shop's country were read from storage, and a refusal about
// something else untick and re-chose them.
$enabled     = array_values(array_intersect(
    array_map('trim', explode(',', $opt('commerce_payment_methods', 'bacs'))),
    array_keys(PaymentMethods::METHODS)
));
$currency    = Money::currency();
$shopCountry = Countries::isValid($opt('commerce_country')) ? strtoupper($opt('commerce_country')) : Countries::shopDefault();

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

    <?php if ($notice !== ''): ?>
        <div class="alert alert--info">
            <?= icon('circle-info', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__text"><?= h($notice) ?></p>
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
                                    <option value="<?= h($code) ?>"<?= $shopCountry === $code ? ' selected' : '' ?>>
                                        <?= h($name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-help">
                                The country a checkout starts on. A customer can
                                still choose any other.
                            </p>
                        </div>

                        <div class="field">
                            <label class="label" for="f-region">Shop province or state</label>
                            <?php // GROUPED BY COUNTRY, one list for both: the
                                  // codes never overlap, and the save refuses a
                                  // province of a country the shop is not in. ?>
                            <select class="select" id="f-region" name="commerce_region">
                                <option value="">None chosen</option>
                                <?php foreach (Regions::ALL as $regionCountry => $regionList): ?>
                                    <optgroup label="<?= h(Countries::name($regionCountry)) ?>">
                                        <?php foreach ($regionList as $code => $name): ?>
                                            <option value="<?= h($code) ?>"<?= $regionCountry === $shopCountry && strtoupper($opt('commerce_region')) === $code ? ' selected' : '' ?>><?= h($name) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-help">
                                Where the shop is. Until a customer gives an
                                address, the basket's tax is worked out for here.
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

                    <?php
                    // A PLACE AND A PRICE PER ROW, read as the tax rates are:
                    // a province's row, else its country's, else everywhere's.
                    // The same two selects as a tax row, so an owner learns
                    // one way of saying where.
                    $placeSelects = static function (string $name, array $row): string {
                        $out = '<div class="field field--quarter"><label class="label">Country</label>'
                             . '<select class="select" name="' . $name . '[country]">'
                             . '<option value="">Everywhere</option>';
                        foreach (Countries::ALL as $code => $countryName) {
                            $out .= '<option value="' . h($code) . '"' . ($row['country'] === $code ? ' selected' : '') . '>'
                                  . h($countryName) . '</option>';
                        }
                        $out .= '</select></div>';
                        $out .= '<div class="field field--quarter"><label class="label">Province or state</label>'
                              . '<select class="select" name="' . $name . '[region]">'
                              . '<option value="">All of the country</option>';
                        foreach (Regions::ALL as $regionCountry => $regionList) {
                            $out .= '<optgroup label="' . h(Countries::name($regionCountry)) . '">';
                            foreach ($regionList as $code => $regionName) {
                                $on   = $row['country'] === $regionCountry && $row['region'] === $code;
                                $out .= '<option value="' . h($code) . '"' . ($on ? ' selected' : '') . '>' . h($regionName) . '</option>';
                            }
                            $out .= '</optgroup>';
                        }

                        return $out . '</select></div>';
                    };
                    $shipRow = static function (int|string $i, ?array $row) use ($placeSelects): string {
                        $row  = $row ?? ['country' => '', 'region' => '', 'rate' => '', 'free_over' => ''];
                        $name = 'ship_rates[' . $i . ']';

                        return '<div class="repeater__row" data-repeater-row>'
                             . '<div class="repeater__fields form-grid form-grid--12">'
                             . $placeSelects($name, $row)
                             . '<div class="field field--quarter"><label class="label">Delivery costs</label>'
                             . '<input class="input" type="text" name="' . $name . '[rate]" value="' . h($row['rate']) . '"'
                             . ' inputmode="decimal" placeholder="0.00" autocomplete="off" spellcheck="false"></div>'
                             . '<div class="field field--quarter"><label class="label">Free over</label>'
                             . '<input class="input" type="text" name="' . $name . '[free_over]" value="' . h($row['free_over']) . '"'
                             . ' inputmode="decimal" placeholder="Never" autocomplete="off" spellcheck="false"></div>'
                             . '</div>'
                             . '<button type="button" class="icon-btn" data-repeater-remove'
                             . ' data-tip="Remove this rate" aria-label="Remove this rate">'
                             . \Cms\Ui\Icons::render('trash-can', 'icon icon--sm') . '</button>'
                             . '</div>';
                    };

                    // WHAT DELIVERY TO EACH PROVINCE COMES TO, from the rows
                    // on the screen; a row that cannot be read yet is left out.
                    $shipPreview = Shipping::clean(array_map(static fn (array $r): array => [
                        'country'   => $r['country'],
                        'region'    => $r['region'],
                        'rate'      => $r['rate'] === '' ? 0 : (Money::parse($r['rate']) ?? 'x'),
                        'free_over' => $r['free_over'] === '' ? 0 : (Money::parse($r['free_over']) ?? 0),
                    ], $shipRows));
                    $costs = static function (?array $rate): string {
                        if ($rate === null) {
                            return 'Not delivered';
                        }
                        if ($rate['rate'] <= 0) {
                            return 'Free';
                        }
                        return Money::format($rate['rate'])
                             . ($rate['free_over'] > 0 ? ', free over ' . Money::format($rate['free_over']) : '');
                    };
                    $shipCountries = array_values(array_unique(array_filter(array_merge(
                        [$shopCountry],
                        array_column($shipPreview, 'country')
                    ), static fn (string $c): bool => Regions::listed($c))));
                    ?>

                    <p class="form-help">
                        What delivery costs, by where it goes. An order is charged
                        its province's row if it has one, otherwise its country's,
                        otherwise the one for everywhere; a place no row reaches is
                        one you do not deliver to, and the checkout says so. With
                        no rows, delivery costs nothing anywhere.
                    </p>

                    <div class="repeater" data-repeater data-ship-rates>
                        <div class="repeater__rows" data-repeater-rows>
                            <?php foreach ($shipRows as $i => $row): ?>
                                <?= $shipRow($i, $row) ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-actions form-actions--bare">
                            <button type="button" class="btn" data-repeater-add>Add a delivery rate</button>
                        </div>
                        <template data-repeater-template><?= $shipRow('__ROW__', null) ?></template>
                    </div>

                    <?php if ($shipPreview !== []): ?>
                        <div class="field">
                        <?php foreach ($shipCountries as $shipCountry): ?>
                            <p class="label" id="ship-preview-<?= h($shipCountry) ?>">What delivery to <?= h(Countries::name($shipCountry)) ?> costs</p>
                            <table class="table table--compact" data-ship-preview="<?= h($shipCountry) ?>"
                                   aria-labelledby="ship-preview-<?= h($shipCountry) ?>">
                                <tbody>
                                    <?php foreach (Regions::of($shipCountry) as $code => $regionName): ?>
                                        <tr>
                                            <th scope="row"><?= h($regionName) ?></th>
                                            <td><?= h($costs(Shipping::rateFor($shipPreview, $shipCountry, $code))) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endforeach; ?>
                        <p class="form-help" data-ship-elsewhere>
                            Anywhere else: <?= h($costs(Shipping::rateFor($shipPreview, '', ''))) ?>.
                        </p>
                        </div>
                    <?php endif; ?>

                    <div class="field">
                        <label class="check-list__row" for="f-pickup">
                            <input type="checkbox" id="f-pickup" name="commerce_pickup" value="1"
                                   <?= $opt('commerce_pickup') === '1' ? 'checked' : '' ?>>
                            <span>Customers can collect their order</span>
                        </label>
                        <p class="form-help">
                            The checkout offers it beside delivery, at no charge.
                            An order collected is taxed for the shop's own
                            province, set above.
                        </p>
                    </div>

                    <div class="field">
                        <label class="label" for="f-pickup-where">Where and when to collect</label>
                        <textarea class="textarea" id="f-pickup-where" name="commerce_pickup_where" rows="2"
                                  placeholder="12 Queen St W, Toronto — weekdays 10 to 4"><?= h($opt('commerce_pickup_where')) ?></textarea>
                        <p class="form-help">
                            Shown at the checkout and on the receipt and the email
                            of an order that is collected.
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

                    <?php
                    // ONE ROW'S MARKUP, for the rows and for the template
                    // `entry-form.js` clones — the repeater an entry form's
                    // fields use, so this screen adds no script of its own.
                    $rateRow = static function (int|string $i, ?array $row) : string {
                        $row  = $row ?? ['country' => '', 'region' => '', 'label' => '', 'rate' => ''];
                        $name = 'tax_rates[' . $i . ']';
                        $out  = '<div class="repeater__row" data-repeater-row>'
                              . '<div class="repeater__fields form-grid form-grid--12">';

                        $out .= '<div class="field field--quarter"><label class="label">Country</label>'
                              . '<select class="select" name="' . $name . '[country]">'
                              . '<option value="">Everywhere</option>';
                        foreach (Countries::ALL as $code => $countryName) {
                            $out .= '<option value="' . h($code) . '"' . ($row['country'] === $code ? ' selected' : '') . '>'
                                  . h($countryName) . '</option>';
                        }
                        $out .= '</select></div>';

                        $out .= '<div class="field field--quarter"><label class="label">Province or state</label>'
                              . '<select class="select" name="' . $name . '[region]">'
                              . '<option value="">All of the country</option>';
                        foreach (Regions::ALL as $regionCountry => $regionList) {
                            $out .= '<optgroup label="' . h(Countries::name($regionCountry)) . '">';
                            foreach ($regionList as $code => $regionName) {
                                $on   = $row['country'] === $regionCountry && $row['region'] === $code;
                                $out .= '<option value="' . h($code) . '"' . ($on ? ' selected' : '') . '>' . h($regionName) . '</option>';
                            }
                            $out .= '</optgroup>';
                        }
                        $out .= '</select></div>';

                        $out .= '<div class="field field--quarter"><label class="label">Name</label>'
                              . '<input class="input" type="text" name="' . $name . '[label]" value="' . h($row['label']) . '"'
                              . ' maxlength="' . TaxRates::LABEL_MAX . '" placeholder="GST" autocomplete="off"></div>';

                        $out .= '<div class="field field--quarter"><label class="label">Rate (%)</label>'
                              . '<input class="input" type="text" name="' . $name . '[rate]" value="' . h($row['rate']) . '"'
                              . ' inputmode="decimal" placeholder="5" autocomplete="off" spellcheck="false"></div>';

                        $out .= '</div>'
                              . '<button type="button" class="icon-btn" data-repeater-remove'
                              . ' data-tip="Remove this rate" aria-label="Remove this rate">'
                              . \Cms\Ui\Icons::render('trash-can', 'icon icon--sm') . '</button>'
                              . '</div>';

                        return $out;
                    };

                    // WHAT EACH PLACE COMES TO, from the rows on the screen —
                    // so a list just filled in by the Canada button is read
                    // back before it is saved. A row that cannot be read yet
                    // is left out of it, and the save will say why.
                    $previewRows = TaxRates::clean(array_map(static fn (array $r): array => [
                        'country' => $r['country'],
                        'region'  => $r['region'],
                        'label'   => $r['label'],
                        'rate'    => TaxRates::parse($r['rate']) ?? -1,
                    ], $taxRows));
                    $says = static function (array $rates): string {
                        if ($rates === []) {
                            return 'No tax';
                        }
                        return implode(' + ', array_map(
                            static fn (array $r): string => $r['label'] . ' ' . TaxRates::percent($r['rate']) . '%',
                            $rates
                        ));
                    };
                    $previewCountries = array_values(array_unique(array_filter(array_merge(
                        [$shopCountry],
                        array_column($previewRows, 'country')
                    ), static fn (string $c): bool => Regions::listed($c))));
                    ?>

                    <?php // THE RULE IN ONE SENTENCE, because it is the one
                          // thing an owner has to know to read the list:
                          // `Commerce\TaxRates` has the reasoning. ?>
                    <p class="form-help">
                        A rate for each place you charge tax. An order is charged
                        its province's rates if it has any, otherwise its country's,
                        otherwise the ones for everywhere &mdash; so Ontario's HST
                        replaces Canada's GST, and British Columbia's own rows list
                        both its GST and its PST. With no rates, no tax is charged
                        or shown anywhere.
                    </p>

                    <div class="repeater" data-repeater data-tax-rates>
                        <div class="repeater__rows" data-repeater-rows>
                            <?php foreach ($taxRows as $i => $row): ?>
                                <?= $rateRow($i, $row) ?>
                            <?php endforeach; ?>
                        </div>

                        <div class="form-actions form-actions--bare">
                            <button type="button" class="btn" data-repeater-add>Add a rate</button>
                            <?php // A SUBMIT, NOT A SCRIPT: the server fills the
                                  // list and draws the form again without saving,
                                  // so it works however the page is reached. ?>
                            <button type="submit" class="btn" name="tax_preset" value="canada" formnovalidate>
                                Fill in Canada's GST and HST
                            </button>
                        </div>

                        <template data-repeater-template><?= $rateRow('__ROW__', null) ?></template>
                    </div>

                    <p class="form-help">
                        The button puts in the GST everywhere in Canada and the HST
                        in Ontario, New Brunswick, Newfoundland and Labrador, Nova
                        Scotia and Prince Edward Island, at the Canada Revenue
                        Agency's rates as this version knows them &mdash; check
                        them before saving. Provincial sales tax (British
                        Columbia's or Saskatchewan's PST, Manitoba's RST, Quebec's
                        QST) is charged only where you are registered for it: add
                        that province's rows, its GST and its own tax together.
                    </p>

                    <?php foreach ($previewCountries as $previewCountry): ?>
                        <p class="label" id="tax-preview-<?= h($previewCountry) ?>">What an order to <?= h(Countries::name($previewCountry)) ?> is charged</p>
                        <table class="table table--compact" data-tax-preview="<?= h($previewCountry) ?>"
                               aria-labelledby="tax-preview-<?= h($previewCountry) ?>">
                            <tbody>
                                <?php foreach (Regions::of($previewCountry) as $code => $regionName): ?>
                                    <tr>
                                        <th scope="row"><?= h($regionName) ?></th>
                                        <td><?= h($says(TaxRates::forPlace($previewRows, $previewCountry, $code))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endforeach; ?>
                    <p class="form-help" data-tax-elsewhere>
                        Anywhere else: <?= h($says(TaxRates::forPlace($previewRows, '', ''))) ?>.
                    </p>

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
                            <span>My prices already include tax</span>
                        </label>
                        <p class="form-help">
                            Off, tax is added at the checkout: a
                            <?= h(Money::format(1000)) ?> item sent to Ontario costs
                            <?= h(Money::format(1130)) ?> at 13% HST. On, it costs
                            <?= h(Money::format(1000)) ?> and the receipt says how
                            much of that was tax.
                        </p>
                    </div>
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
                    <?= panel_title('Receipts and the checkout', 'receipts') ?>

                    <?php // WHOSE RECEIPT IT IS. A business registered for GST/HST
                          // has to give its registration number on a receipt; the
                          // name and address are where a customer writes to. ?>
                    <div class="form-grid form-grid--2">
                        <div class="field">
                            <label class="label" for="f-business-name">Business name</label>
                            <input class="input" type="text" id="f-business-name" name="commerce_business_name"
                                   value="<?= h($opt('commerce_business_name')) ?>" autocomplete="organization">
                        </div>
                        <div class="field">
                            <label class="label" for="f-tax-number">GST/HST number</label>
                            <input class="input" type="text" id="f-tax-number" name="commerce_tax_number"
                                   value="<?= h($opt('commerce_tax_number')) ?>" autocomplete="off" spellcheck="false"
                                   placeholder="123456789 RT0001">
                        </div>
                    </div>
                    <div class="field">
                        <label class="label" for="f-business-address">Business address</label>
                        <textarea class="textarea" id="f-business-address" name="commerce_business_address" rows="3"
                                  autocomplete="street-address"><?= h($opt('commerce_business_address')) ?></textarea>
                        <p class="form-help">
                            The three are printed at the foot of every receipt and
                            order confirmation. Blank ones are left out.
                        </p>
                    </div>

                    <?php $pages = \Cms\Models\Page::all(); ?>
                    <div class="field">
                        <label class="label" for="f-terms-page">Terms a customer agrees to</label>
                        <select class="select" id="f-terms-page" name="commerce_terms_page">
                            <option value="0">None</option>
                            <?php foreach ($pages as $termsChoice): ?>
                                <option value="<?= (int) $termsChoice['id'] ?>"<?= (int) $opt('commerce_terms_page', '0') === (int) $termsChoice['id'] ? ' selected' : '' ?>>
                                    <?= h((string) $termsChoice['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-help">
                            A page such as your returns policy. The checkout asks
                            for a tick beside a link to it, refuses an order without
                            one, and the order keeps the title agreed to.
                        </p>
                    </div>

                    <div class="field">
                        <label class="check-list__row" for="f-offer-signup">
                            <input type="checkbox" id="f-offer-signup" name="commerce_offer_signup" value="1"
                                   <?= $opt('commerce_offer_signup') === '1' ? 'checked' : '' ?>>
                            <span>Offer a box for news and offers at the checkout</span>
                        </label>
                        <p class="form-help">
                            It starts unticked, as Canada's anti-spam law asks: only
                            a customer who ticks it has asked to hear from you, and
                            the order says so.
                        </p>
                    </div>
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

<script src="<?= h(Asset::admin('js/entry-form.js')) ?>"></script>
