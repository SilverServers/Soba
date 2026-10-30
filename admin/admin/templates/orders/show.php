<?php

declare(strict_types=1);

use Cms\Commerce\Countries;
use Cms\Commerce\Money;
use Cms\Commerce\Totals;
use Cms\Helpers\Asset;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Models\Order;
use Cms\Security\Csrf;
use Cms\Ui\OrderStatus;

/**
 * ONE ORDER.
 *
 * ── EVERY FIGURE COMES OFF THE ORDER ROW, NOT OFF THE PRODUCT ───────────────
 * `Money::format($n, $order['currency'])` throughout — the currency is the
 * ORDER's. Reading the setting instead would relabel every historic order the
 * day a shop switched currency, and this screen and the customer's receipt
 * would then disagree about what was charged.
 *
 * The line items likewise: name, SKU and unit price are the ones stored at
 * checkout. A product renamed or repriced since does not rewrite what was sold.
 * The link back to the product is `post_id`, which is nullable — a deleted
 * product leaves the line intact and only loses its link.
 *
 * ── THE STATUS CONTROL IS THE POINT OF THE SCREEN ───────────────────────────
 * With offline payment the owner's whole job is moving an order along, so the
 * control is at the top of the aside rather than in a menu. It is a real form
 * with a real POST, and `Order::setStatus()` writes the note itself — so the
 * audit trail cannot be lost by a caller that forgets.
 *
 * @var array<string, mixed>       $order
 * @var list<array<string, mixed>> $items
 * @var list<array<string, mixed>> $notes
 */

$currency = (string) $order['currency'];
$oid      = (int) $order['id'];

/** An address block, or a stated absence. */
$address = static function (array $order, string $prefix): void {
    $line1 = (string) $order[$prefix . '_line1'];
    if ($line1 === '') {
        echo '<p class="u-text-muted">Same as the billing address.</p>';
        return;
    }
    $parts = array_filter([
        (string) $order[$prefix . '_name'],
        $line1,
        (string) $order[$prefix . '_line2'],
        trim((string) $order[$prefix . '_city'] . ' ' . (string) $order[$prefix . '_region']),
        (string) $order[$prefix . '_post'],
        Countries::name((string) $order[$prefix . '_country']),
    ], static fn (string $s): bool => trim($s) !== '');
    echo '<address class="order-address">' . implode('<br>', array_map('h', $parts)) . '</address>';
};

ob_start();
?>
<div class="page">

    <a class="page__back" href="<?= h(Url::admin('orders')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Orders
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Order <?= h(Order::number($order)) ?></h1>
            <p class="page__subtitle">
                <?= h(Dates::stamp((string) $order['created_at'])) ?>
                <?php if ($order['paid_at'] !== null): ?>
                    · paid <?= h(Dates::stamp((string) $order['paid_at'])) ?>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php // `.panel__grid` IS THE TWO-COLUMN GRID; `.form-main` and
          // `.form-aside` are its two CHILDREN. With `.form-main` as the
          // wrapper around both, this screen would render as one column with
          // the Status and Payment panels stacked underneath History — and
          // nothing would be malformed. The layout is core.css §4's, the same
          // as `content-types/edit.php`'s. ?>
    <div class="panel__grid">
        <div class="form-main">
        <div class="form-card">

            <div class="form-card__section">
                <?= panel_title('Items', 'order-items') ?>

                <?php // `--bleed` because this list IS the whole of its
                      // section. See core.css §5 — it reaches the card's edges
                      // rather than drawing a second border a few pixels in
                      // from the first, and it paints nothing of its own. ?>
                <div class="list list--bleed">
                    <?php foreach ($items as $item): ?>
                        <div class="list__row order-items__row">
                            <div>
                                <?php // THE PRODUCT LINKS BACK WHEN IT STILL
                                      // EXISTS. `post_id` is ON DELETE SET NULL,
                                      // so a deleted product leaves the line
                                      // readable and only loses the link — the
                                      // record of having sold it survives.
                                      //
                                      // `edit_url` is resolved by the
                                      // CONTROLLER, through the Registry. There
                                      // is no `/admin/posts/{id}/edit` route —
                                      // every entry lives under its own content
                                      // type's admin slug (`/admin/memberships/
                                      // 14/edit`). A template cannot know the slug
                                      // without asking the Registry, and asking
                                      // it once per row is a query per row. ?>
                                <?php if (($item['edit_url'] ?? null) !== null): ?>
                                    <a class="list__row-title"
                                       href="<?= h((string) $item['edit_url']) ?>"><?= h((string) $item['name']) ?></a>
                                <?php else: ?>
                                    <span class="list__row-title"><?= h((string) $item['name']) ?></span>
                                <?php endif; ?>
                                <?php // WHICH OPTION, AND THE CODE FOR IT. The
                                      // owner reading this screen is packing
                                      // the parcel; the option is the half that
                                      // tells them which shelf. ?>
                                <?php
                                $variantSub = trim(implode(' · ', array_filter([
                                    (string) ($item['variation_label'] ?? ''),
                                    (string) $item['sku'],
                                ])));
                                ?>
                                <?php if ($variantSub !== ''): ?>
                                    <div class="list__row-sub"><?= h($variantSub) ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="order-items__num">
                                <?= h(Money::format((int) $item['unit_price'], $currency)) ?>
                                <span class="u-text-muted">× <?= (int) $item['quantity'] ?></span>
                            </div>

                            <div class="order-items__num">
                                <strong><?= h(Money::format((int) $item['line_total'], $currency)) ?></strong>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php // ── THE SAME ROWS THE CUSTOMER WAS SHOWN ─────────────
                      // `Totals::rows(Totals::ofOrder())` is what the receipt
                      // page and the confirmation email both print, so an owner
                      // reading this screen while a customer reads their email
                      // is reading the same document. Written out here it said
                      // "Tax" while the receipt said "GST (5%)".
                      //
                      // Still only when there is one: a row of "$0.00 tax" on
                      // every order of a shop that charges no tax is noise on
                      // the screen its owner reads most often. `rows()` drops
                      // a zero line for that reason. ?>
                <div class="order-totals">
                    <?php foreach (Totals::rows(Totals::ofOrder($order)) as [$label, $amount, $isNote]): ?>
                        <div class="order-totals__row<?= $isNote ? ' order-totals__row--note' : '' ?>">
                            <span><?= h($label) ?></span>
                            <span><?= h(Money::format($amount, $currency)) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="order-totals__row order-totals__row--grand">
                        <span>Total</span>
                        <strong><?= h(Money::format((int) $order['total'], $currency)) ?></strong>
                    </div>
                </div>
            </div>

            <div class="form-card__section">
                <?= panel_title('Customer', 'customer') ?>
                <div class="order-addresses">
                    <div>
                        <h3 class="order-address__title">Billing</h3>
                        <?php $address($order, 'billing'); ?>
                        <?php if ((string) $order['email'] !== ''): ?>
                            <p><a class="link" href="mailto:<?= h((string) $order['email']) ?>"><?= h((string) $order['email']) ?></a></p>
                        <?php endif; ?>
                        <?php if ((string) $order['phone'] !== ''): ?>
                            <p><a class="link" href="tel:<?= h((string) $order['phone']) ?>"><?= h((string) $order['phone']) ?></a></p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if ((string) ($order['shipping_method'] ?? '') === 'pickup'): ?>
                            <h3 class="order-address__title">Collection</h3>
                            <p><?= nl2br(h((string) $order['shipping_method_title'])) ?></p>
                        <?php else: ?>
                            <h3 class="order-address__title">Delivery</h3>
                            <?php if ((string) ($order['shipping_method_title'] ?? '') !== ''): ?>
                                <p class="u-text-muted"><?= h((string) $order['shipping_method_title']) ?></p>
                            <?php endif; ?>
                            <?php $address($order, 'shipping'); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php // WHAT THE CUSTOMER AGREED TO AND ASKED FOR at the checkout. ?>
                <?php if ((string) ($order['terms_agreed'] ?? '') !== '' || !empty($order['marketing_opt_in'])): ?>
                    <p class="u-text-muted" data-order-consent>
                        <?php if ((string) ($order['terms_agreed'] ?? '') !== ''): ?>
                            Agreed to the <?= h((string) $order['terms_agreed']) ?>.
                        <?php endif; ?>
                        <?php if (!empty($order['marketing_opt_in'])): ?>
                            Asked for news and offers by email.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <?php if (trim((string) ($order['customer_note'] ?? '')) !== ''): ?>
                    <h3 class="order-address__title">Customer's note</h3>
                    <p><?= nl2br(h((string) $order['customer_note'])) ?></p>
                <?php endif; ?>
            </div>

            <div class="form-card__section">
                <?= panel_title('History', 'history') ?>

                <?php // THE NOTE FORM IS ABOVE THE NOTES, because the reason
                      // the owner opened this section is usually to add one.
                      // The trail below is newest-first for the same reason. ?>
                <form method="post" action="<?= h(Url::admin('orders/' . $oid . '/note')) ?>"
                      class="order-note-form">
                    <?= Csrf::field() ?>
                    <label class="label" for="order-note">Add a note</label>
                    <textarea class="input" id="order-note" name="note" rows="2"
                              placeholder="Rang the customer, posted Tuesday&hellip;"></textarea>
                    <div class="form-actions form-actions--bare">
                        <button type="submit" class="btn">Add note</button>
                    </div>
                </form>

                <?php if ($notes === []): ?>
                    <p class="u-text-muted">Nothing has happened to this order yet.</p>
                <?php else: ?>
                    <ol class="order-notes">
                        <?php foreach ($notes as $note): ?>
                            <li class="order-notes__item">
                                <p class="order-notes__body"><?= nl2br(h((string) $note['body'])) ?></p>
                                <p class="order-notes__meta u-text-muted u-text-sm">
                                    <?= h(Dates::stamp((string) $note['created_at'])) ?>
                                    <?php if ((string) $note['author'] !== ''): ?>
                                        · <?= h((string) $note['author']) ?>
                                    <?php endif; ?>
                                    <?php if ((bool) $note['is_customer_note']): ?>
                                        · sent to the customer
                                    <?php endif; ?>
                                </p>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </div>

        </div>

        <div class="form-aside">
            <div class="form-card">
                <div class="form-card__section">
                    <?= panel_title('Status', 'order-status') ?>

                    <p class="order-status-now"><?= OrderStatus::render((string) $order['status']) ?></p>

                    <form method="post" action="<?= h(Url::admin('orders/' . $oid . '/status')) ?>">
                        <?= Csrf::field() ?>
                        <label class="label" for="order-status">Move to</label>
                        <select class="select" id="order-status" name="status">
                            <?php foreach (Order::STATUSES as $key => $label): ?>
                                <?php // THE CURRENT STATUS IS NOT IN THE LIST.
                                      // `setStatus()` refuses a no-op change, so
                                      // offering it would be a control that
                                      // does nothing — and it would be the one
                                      // pre-selected, so the obvious action
                                      // would be the inert one. ?>
                                <?php if ($key === (string) $order['status']) { continue; } ?>
                                <option value="<?= h($key) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-actions form-actions--bare">
                            <button type="submit" class="btn btn--primary">Update status</button>
                        </div>
                    </form>
                </div>

                <div class="form-card__section">
                    <?= panel_title('Payment', 'payment') ?>
                    <?php // `.kv-list` IS NOT A <dl>. It is a flex column of
                          // `.kv-list__row` divs, each a two-track grid of
                          // `__key` and `__val` — core.css §20. Written as
                          // dt/dd it would match none of those selectors and
                          // render as four unstyled stacked lines, which look
                          // like a list rather than like a fault. ?>
                    <div class="kv-list">
                        <div class="kv-list__row">
                            <span class="kv-list__key">Method</span>
                            <?php $method = (string) $order['payment_method_title']; ?>
                            <span class="kv-list__val<?= $method === '' ? ' kv-list__val--muted' : '' ?>">
                                <?= $method !== '' ? h($method) : 'Not recorded' ?>
                            </span>
                        </div>
                        <div class="kv-list__row">
                            <span class="kv-list__key">Currency</span>
                            <span class="kv-list__val"><?= h($currency) ?></span>
                        </div>
                        <?php if ((string) $order['transaction_ref'] !== ''): ?>
                            <div class="kv-list__row">
                                <span class="kv-list__key">Reference</span>
                                <span class="kv-list__val"><?= h((string) $order['transaction_ref']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php // A PLAIN TEXT LINK AS THE PANEL'S LAST SECTION — the
                      // same pattern as "Delete content type". A destructive
                      // TRIGGER does not shout; the confirm dialog it opens IS
                      // the alert surface. Floating under the card with nothing
                      // containing it, it would look like a loose part. ?>
                <div class="form-card__section form-aside__danger">
                    <button type="submit" class="link link--xs"
                            form="delete-order">Delete order</button>
                </div>
            </div>

            <?php // THE FORM ITSELF SITS OUTSIDE THE CARD, and the button above
                  // points at it with `form=`. HTML has no nested forms — the
                  // parser drops the inner tag and re-parents its button, so a
                  // delete button inside the status form would SAVE the status
                  // instead. ?>
            <form method="post" id="delete-order"
                  action="<?= h(Url::admin('orders/' . $oid . '/delete')) ?>"
                  data-confirm="Delete order <?= h(Order::number($order)) ?>?"
                  data-confirm-detail="The customer's details, the items and the whole history go with it<?= !empty($order['stock_reduced']) ? ', and the items it holds go back in stock' : '' ?>. This cannot be undone."
                  data-confirm-verb="Delete order">
                <?= Csrf::field() ?>
            </form>
        </div>
    </div>
</div>

<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
