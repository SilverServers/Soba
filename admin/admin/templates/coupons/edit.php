<?php

declare(strict_types=1);

use Cms\Commerce\Coupon;
use Cms\Commerce\Money;
use Cms\Commerce\Tax;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * ONE DISCOUNT CODE.
 *
 * ── THE FORM READS `$posted` FIRST AND THE ROW SECOND ───────────────────────
 * The same rule the shop settings screen follows: on a failed save the
 * controller hands back what was TYPED, so nothing on the screen is lost
 * because one field was wrong. Reading the stored row here would redraw the old
 * values under an error message complaining about the new ones.
 *
 * @var array<string, mixed>|null $coupon  the stored row, null when new
 * @var array<string, mixed>      $posted  what the form sent, on a failed save
 * @var list<string>              $errors
 */

$isNew = $coupon === null;

// `$posted` wins, then the row, then the default. Written as a closure because
// the alternative is the same three-way `??` chain typed out eight times, and
// the eighth one is where the row is forgotten.
$val = static function (string $key, string $fallback = '') use ($coupon, $posted): string {
    if ($posted !== [] && array_key_exists($key, $posted)) {
        return (string) $posted[$key];
    }

    return (string) ($coupon[$key] ?? $fallback);
};

$kind = $val('kind', Coupon::PERCENT) === Coupon::FIXED ? Coupon::FIXED : Coupon::PERCENT;

// ── THE AMOUNT IS EDITED IN THE UNITS IT IS TYPED IN ────────────────────────
// Stored as hundredths of a percent or minor units; shown as `10` or `5.00`. A
// box that redrew the stored integer would print a $5.00 coupon as 500.
$amountShown = $posted['amount_raw'] ?? (
    $isNew ? '' : (
        $kind === Coupon::FIXED
            ? Money::edit((int) $coupon['amount'])
            : Tax::percent((int) $coupon['amount'])
    )
);
$minShown = $posted['min_raw'] ?? (
    $isNew || (int) $coupon['min_spend'] === 0 ? '' : Money::edit((int) $coupon['min_spend'])
);

// WHAT IT REACHES, as ids: posted after a refusal, else the stored lists.
$picked = static function (string $key) use ($coupon, $posted): array {
    if ($posted !== [] && array_key_exists($key, $posted)) {
        return array_map('intval', (array) $posted[$key]);
    }

    return Coupon::ids($coupon[$key] ?? null);
};

$action = $isNew
    ? Url::admin('coupons/new')
    : Url::admin('coupons/' . (int) $coupon['id'] . '/edit');

ob_start();
?>
<div class="page page--form">

    <a class="page__back" href="<?= h(Url::admin('coupons')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Discount codes
    </a>

    <form method="post" action="<?= h($action) ?>">
        <?= Csrf::field() ?>

        <div class="page__header">
            <div class="page__header-main">
                <h1 class="page__title"><?= $isNew ? 'New discount code' : h((string) $coupon['code']) ?></h1>
            </div>
            <div class="page__header-actions">
                <a class="link" href="<?= h(Url::admin('coupons')) ?>">Cancel</a>
                <button type="submit" class="btn btn--primary">
                    <?= $isNew ? 'Create code' : 'Save changes' ?>
                </button>
            </div>
        </div>

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

        <div class="form-layout">
            <div class="form-main">
                <div class="form-card">

                    <div class="form-card__section">
                        <?= panel_title('The code', 'coupons') ?>

                        <div class="field">
                            <label class="label" for="f-code">Code</label>
                            <input class="input input--mono input--short" type="text" id="f-code" name="code"
                                   value="<?= h($val('code')) ?>" required
                                   autocomplete="off" spellcheck="false"
                                   autocapitalize="characters">
                            <?php // UPPER-CASED ON THE WAY IN, and said so
                                  // before it happens. A customer typing
                                  // `spring20` on a phone and an owner typing
                                  // `SPRING20` on a poster mean the same
                                  // coupon, and only a database disagrees. ?>
                            <p class="form-help">
                                What the customer types into their basket. Case
                                does not matter — it is stored and matched in
                                capitals. Letters, numbers, dashes and
                                underscores.
                            </p>
                        </div>

                        <div class="form-grid form-grid--2">
                            <div class="field">
                                <label class="label" for="f-kind">Takes off</label>
                                <select class="select" id="f-kind" name="kind">
                                    <option value="percent"<?= $kind === Coupon::PERCENT ? ' selected' : '' ?>>
                                        A percentage
                                    </option>
                                    <option value="fixed"<?= $kind === Coupon::FIXED ? ' selected' : '' ?>>
                                        A fixed amount
                                    </option>
                                </select>
                            </div>

                            <div class="field">
                                <label class="label" for="f-amount">How much</label>
                                <input class="input input--short" type="text" id="f-amount" name="amount"
                                       value="<?= h((string) $amountShown) ?>"
                                       autocomplete="off" spellcheck="false"
                                       inputmode="decimal">
                                <p class="form-help">
                                    A percentage like <strong>10</strong> or
                                    <strong>12.5</strong>, or an amount like
                                    <strong><?= h(Money::edit(500)) ?></strong> —
                                    whichever the box beside it says.
                                </p>
                            </div>
                        </div>

                        <div class="field">
                            <label class="check-list__row" for="f-free-shipping">
                                <input type="checkbox" id="f-free-shipping" name="free_shipping" value="1"
                                       <?= $val('free_shipping', '0') === '1' ? 'checked' : '' ?>>
                                <span>Makes delivery free</span>
                            </label>
                            <p class="form-help">
                                Delivery costs nothing while the code applies, on
                                top of what it takes off. Leave <strong>How much</strong>
                                blank for a code that only makes delivery free.
                            </p>
                        </div>

                        <?php // A DISCOUNT COMES OFF BEFORE TAX, which is what a
                              // customer expects and what a seller's discount
                              // requires. `Commerce\Totals` has the reasoning
                              // and this is the only screen that can tell the
                              // owner it is true. ?>
                        <p class="form-help">
                            A discount comes off the goods before tax and before
                            delivery, so a percentage reduces the tax with it. It
                            can never take off more than the basket is worth.
                        </p>
                    </div>

                    <div class="form-card__section">
                        <?= panel_title('What it takes off', 'coupons') ?>

                        <?php // NAMED, OR EVERYTHING. The pickers are the entry
                              // form's own; a code naming none takes off the
                              // whole basket, as it always has. ?>
                        <div class="form-grid form-grid--2">
                            <div class="field">
                                <span class="label">Only these products</span>
                                <?= \Cms\ContentType\FieldRenderer::chipPicker(
                                    'product_ids',
                                    \Cms\Commerce\Coupon::productChoices(),
                                    $picked('product_ids'),
                                    'product'
                                ) ?>
                            </div>
                            <div class="field">
                                <span class="label">Only these categories</span>
                                <?= \Cms\ContentType\FieldRenderer::chipPicker(
                                    'category_ids',
                                    \Cms\Commerce\Coupon::termChoices(),
                                    $picked('category_ids'),
                                    'category'
                                ) ?>
                            </div>
                        </div>
                        <p class="form-help">
                            Blank for the whole basket. With either, the code takes
                            off only what is in them, and a basket with none of it
                            is told the code does not apply.
                        </p>

                        <div class="field">
                            <label class="check-list__row" for="f-exclude-sale">
                                <input type="checkbox" id="f-exclude-sale" name="exclude_sale" value="1"
                                       <?= $val('exclude_sale', '0') === '1' ? 'checked' : '' ?>>
                                <span>Leaves items on sale alone</span>
                            </label>
                            <p class="form-help">
                                Anything already on sale is left out of what the
                                code takes off.
                            </p>
                        </div>
                    </div>

                    <div class="form-card__section">
                        <?= panel_title('When it works', 'tax') ?>

                        <?php // THE AMOUNTS, THEN THE DAYS: each pair on one row, the
                              // dates side by side rather than one under another. ?>
                        <div class="form-grid form-grid--2">
                            <div class="field">
                                <label class="label" for="f-limit">How many times</label>
                                <input class="input" type="number" id="f-limit" name="usage_limit"
                                       min="0" step="1"
                                       value="<?= h($val('usage_limit', '0')) ?>">
                                <p class="form-help">
                                    In total, across every customer. 0 for no
                                    limit.
                                    <?php if (!$isNew): ?>
                                        Used <strong><?= (int) $coupon['times_used'] ?></strong>
                                        <?= (int) $coupon['times_used'] === 1 ? 'time' : 'times' ?>
                                        so far.
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="field">
                                <label class="label" for="f-per-customer">Uses per customer</label>
                                <input class="input" type="number" id="f-per-customer" name="per_customer"
                                       min="0" step="1"
                                       value="<?= h($val('per_customer', '0')) ?>">
                                <p class="form-help">
                                    How many orders one email address may use it on.
                                    0 for no limit of its own.
                                </p>
                            </div>
                        </div>

                        <div class="form-grid form-grid--2">
                            <div class="field">
                                <label class="label" for="f-starts">First day it works</label>
                                <input class="input" type="date" id="f-starts" name="starts_at"
                                       value="<?= h($val('starts_at')) ?>">
                                <p class="form-help">
                                    In this site's own days. Blank for straight away.
                                </p>
                            </div>
                            <div class="field">
                                <label class="label" for="f-expires">Last day it works</label>
                                <input class="input" type="date" id="f-expires" name="expires_at"
                                       value="<?= h($val('expires_at')) ?>">
                                <?php // THE DAY ITSELF, NOT THE DAY BEFORE. A code
                                      // that expires on the 30th works ON the 30th,
                                      // because nobody reads a date field as "the
                                      // day before this" and an owner typing the
                                      // last day of a sale would end it early. ?>
                                <p class="form-help">
                                    The code still works on this day and stops the
                                    day after. Blank for never.
                                </p>
                            </div>
                        </div>

                        <div class="form-grid form-grid--2">
                            <div class="field">
                                <label class="label" for="f-min">Minimum spend</label>
                                <input class="input input--short" type="text" id="f-min" name="min_spend"
                                       value="<?= h((string) $minShown) ?>"
                                       autocomplete="off" spellcheck="false"
                                       inputmode="decimal">
                                <p class="form-help">
                                    The goods have to come to at least this much.
                                    Blank for no minimum. Measured before
                                    delivery, so a customer cannot reach it by
                                    choosing dearer postage.
                                </p>
                            </div>
                        </div>

                        <div class="field">
                            <label class="check-list__row" for="f-active">
                                <input type="checkbox" id="f-active" name="is_active" value="1"
                                       <?= $val('is_active', '1') === '1' ? 'checked' : '' ?>>
                                <span>Accept this code</span>
                            </label>
                            <?php // OFF RATHER THAN DELETED IS THE USUAL ANSWER.
                                  // A deleted code takes its usage history with
                                  // it and the orders that used it point at
                                  // nothing. ?>
                            <p class="form-help">
                                Untick to stop accepting it without deleting it —
                                the orders that used it keep their record, and
                                you can turn it back on.
                            </p>
                        </div>
                    </div>

                    <?php // THE FORM'S OWN FOOT, as every settings form has it:
                          // the page header's buttons scroll away long before
                          // the last field. ?>
                    <div class="form-card__section form-actions">
                        <a class="link link--xs" href="<?= h(Url::admin('coupons')) ?>">Cancel</a>
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn btn--primary"><?= $isNew ? 'Create code' : 'Save changes' ?></button>
                    </div>

                </div>
            </div>
        </div>
    </form>
</div>
<script src="<?= h(\Cms\Helpers\Asset::admin('js/entry-form.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
