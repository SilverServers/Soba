<?php

declare(strict_types=1);

use Cms\Commerce\Money;
use Cms\Commerce\PaymentMethods;
use Cms\Commerce\Shipping;
use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * A NEW ORDER, TAKEN BY THE SHOP: on the telephone, at a stall, by email.
 *
 * What was asked for, line by line, at today's prices; who it is for and
 * where it goes; how they are paying and whether they have. `ManualOrder`
 * places it the way the checkout does, through `Order::place()`, so it is an
 * order like any other from the moment it exists.
 *
 * @var list<array{value: string, label: string, price: int, left: ?int, available: bool, delivered: bool}> $choices
 * @var array<string, string>      $form    the boxes, as typed or as they start
 * @var list<array<string, mixed>> $lines   the lines, as typed or one blank one
 * @var list<string>               $errors
 */

$val = static fn (string $key, string $or = ''): string => (string) ($form[$key] ?? $or);

// ONE LINE OF THE ORDER: what, and how many. The template row below is the
// same markup with `__ROW__`, which `entry-form.js` numbers when a line is added.
$lineRow = static function (int|string $i, array $row) use ($choices): string {
    $name   = 'lines[' . $i . ']';
    $chosen = (string) ($row['choice'] ?? '');
    $out    = '<div class="repeater__row" data-repeater-row>'
            . '<div class="repeater__fields order-new__line">'
            . '<div class="field"><label class="label" for="nl-' . h((string) $i) . '">Product</label>'
            . '<select class="select" id="nl-' . h((string) $i) . '" name="' . $name . '[choice]">'
            . '<option value="">Choose…</option>';
    foreach ($choices as $choice) {
        $left = $choice['left'] === null ? '' : ' · ' . ($choice['left'] > 0 ? $choice['left'] . ' left' : 'sold out');
        $out .= '<option value="' . h($choice['value']) . '"'
              . ($chosen === $choice['value'] ? ' selected' : '')
              . (!$choice['available'] ? ' disabled' : '') . '>'
              . h($choice['label'] . ' · ' . Money::format($choice['price']) . $left) . '</option>';
    }
    $out .= '</select></div>'
          . '<div class="field"><label class="label" for="nq-' . h((string) $i) . '">How many</label>'
          . '<input class="input" type="number" id="nq-' . h((string) $i) . '" name="' . $name . '[quantity]"'
          . ' min="1" step="1" value="' . h((string) ($row['quantity'] ?? '1')) . '"></div>'
          . '</div>'
          . '<button type="button" class="icon-btn" data-repeater-remove'
          . ' data-tip="Remove this line" aria-label="Remove this line">'
          . \Cms\Ui\Icons::render('trash-can', 'icon icon--sm') . '</button>'
          . '</div>';

    return $out;
};

ob_start();
?>
<div class="page">

    <a class="page__back" href="<?= h(Url::admin('orders')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Orders
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">New order</h1>
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

    <?php // IN THE DOCUMENT COLUMN, so its fields are wells cut into the card
          // rather than white boxes on white (`.form-main .input`). ?>
    <div class="form-main">
    <form method="post" action="<?= h(Url::admin('orders/new')) ?>" class="form-card order-new">
        <?= Csrf::field() ?>

        <div class="form-card__section">
            <?= panel_title('Items', 'order-items') ?>
            <?php if ($choices === []): ?>
                <p class="form-help">
                    Nothing is for sale yet: a published entry of a type that
                    carries the Product kind is what an order is made of.
                </p>
            <?php else: ?>
                <div class="repeater" data-repeater>
                    <div class="repeater__rows" data-repeater-rows>
                        <?php foreach ($lines as $i => $row): ?>
                            <?= $lineRow($i, $row) ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-actions form-actions--bare">
                        <button type="button" class="btn" data-repeater-add>Add a line</button>
                    </div>
                    <template data-repeater-template><?= $lineRow('__ROW__', []) ?></template>
                </div>
                <p class="form-help">
                    At today's prices, sales included. Delivery and tax are added
                    for where it goes, as the checkout adds them.
                </p>
            <?php endif; ?>
        </div>

        <div class="form-card__section">
            <?= panel_title('Customer', 'customer') ?>
            <div class="form-grid form-grid--2">
                <div class="field">
                    <label class="label" for="no-email">Email</label>
                    <input class="input" type="email" id="no-email" name="email" value="<?= h($val('email')) ?>">
                </div>
                <div class="field">
                    <label class="label" for="no-phone">Phone</label>
                    <input class="input" type="tel" id="no-phone" name="phone" value="<?= h($val('phone')) ?>">
                </div>
            </div>
            <?php
            $who      = 'billing';
            $idPrefix = 'no';
            $addr     = static fn (string $key): string => (string) ($form[$key] ?? '');
            require __DIR__ . '/_address.php';
            ?>

            <fieldset class="order-new__choice">
                <legend class="label">How it reaches them</legend>
                <label class="check-list__row" for="no-delivered">
                    <input type="radio" id="no-delivered" name="delivery_method" value="<?= h(Shipping::DELIVERY) ?>"
                           <?= $val('delivery_method') !== Shipping::PICKUP ? 'checked' : '' ?>>
                    <span>Delivered to the address above</span>
                </label>
                <?php if (Shipping::pickup()): ?>
                    <label class="check-list__row" for="no-collected">
                        <input type="radio" id="no-collected" name="delivery_method" value="<?= h(Shipping::PICKUP) ?>"
                               <?= $val('delivery_method') === Shipping::PICKUP ? 'checked' : '' ?>>
                        <span><?= h(Shipping::methodTitle(Shipping::PICKUP, [])) ?></span>
                    </label>
                <?php endif; ?>
            </fieldset>

            <div class="field">
                <label class="label" for="no-note">Customer's note</label>
                <textarea class="textarea" id="no-note" name="customer_note" rows="2"><?= h($val('customer_note')) ?></textarea>
            </div>
        </div>

        <div class="form-card__section">
            <?= panel_title('Payment', 'payment') ?>
            <div class="form-grid form-grid--2">
                <div class="field">
                    <label class="label" for="no-method">How they are paying</label>
                    <select class="select" id="no-method" name="payment_method">
                        <?php foreach (array_keys(PaymentMethods::METHODS) as $methodId): ?>
                            <option value="<?= h($methodId) ?>"<?= $val('payment_method') === $methodId ? ' selected' : '' ?>>
                                <?= h(PaymentMethods::describe($methodId)['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <fieldset class="order-new__choice">
                    <legend class="label">Paid</legend>
                    <label class="check-list__row" for="no-unpaid">
                        <input type="radio" id="no-unpaid" name="status" value="pending" <?= $val('status', 'pending') !== 'processing' ? 'checked' : '' ?>>
                        <span>Not yet: Pending payment</span>
                    </label>
                    <label class="check-list__row" for="no-paid">
                        <input type="radio" id="no-paid" name="status" value="processing" <?= $val('status') === 'processing' ? 'checked' : '' ?>>
                        <span>Paid: Processing</span>
                    </label>
                </fieldset>
            </div>

            <label class="check-list__row" for="no-confirm">
                <input type="checkbox" id="no-confirm" name="confirm" value="1" <?= $val('confirm', '1') === '1' ? 'checked' : '' ?>>
                <span>Email the confirmation to the customer</span>
            </label>
        </div>

        <div class="form-card__section form-actions">
            <button type="submit" class="btn btn--primary">Place the order</button>
        </div>
    </form>
    </div>
</div>

<script src="<?= h(Asset::admin('js/entry-form.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
