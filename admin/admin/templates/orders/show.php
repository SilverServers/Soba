<?php

declare(strict_types=1);

use Cms\Commerce\Countries;
use Cms\Commerce\Money;
use Cms\Commerce\Refund;
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
 * @var array<string, string>      $resends  what can be emailed again, key => label
 * @var array<int, list<array<string, mixed>>> $exchanges order_items.id => the options it can become
 * @var list<string>               $errors   what a refused form could not save
 * @var array<string, string>      $typed    that form's boxes, as typed
 */

$currency = (string) $order['currency'];
$oid      = (int) $order['id'];
$errors   = $errors ?? [];
$typed    = $typed ?? [];
// A BOX SHOWS WHAT WAS TYPED when its form was refused, else what is stored.
$val = static fn (string $key): string => (string) ($typed[$key] ?? $order[$key] ?? '');
// POSTED TO AN ADDRESS: tracking is asked for only then.
$ships = !in_array((string) ($order['shipping_method'] ?? ''), ['', \Cms\Commerce\Shipping::PICKUP], true);
// WHAT HAS GONE BACK, AND WHAT IS LEFT TO GIVE.
$refundRows = Refund::rows($order);
$refundLeft = Refund::left($order);
$canRefund  = $refundLeft['amount'] > 0 && (string) $order['status'] !== 'refunded';
$refundTyped = isset($typed['refund']);
$refundQty   = is_array($typed['refund_qty'] ?? null) ? $typed['refund_qty'] : [];

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
        <?php // ON PAPER: the slip that goes in the parcel, and the invoice. ?>
        <div class="page__header-actions">
            <a class="btn" href="<?= h(Url::admin('orders/' . $oid . '/print?doc=slip')) ?>">Print packing slip</a>
            <a class="btn" href="<?= h(Url::admin('orders/' . $oid . '/print?doc=invoice')) ?>">Print invoice</a>
        </div>
    </div>

    <?php if ($errors !== []): ?>
        <div class="alert alert--danger">
            <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__title">Not saved</p>
                <ul class="alert__text">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

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
                                    (int) ($item['refunded_qty'] ?? 0) > 0 ? (int) $item['refunded_qty'] . ' refunded' : '',
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
                    <?php // EACH REFUND UNDER THE TOTAL IT CAME OFF, and what the
                          // order nets after them: the figure the bank shows. ?>
                    <?php foreach ($refundRows as $i => [$label, $amount]): ?>
                        <?php $net = $i === count($refundRows) - 1; ?>
                        <div class="order-totals__row<?= $net ? ' order-totals__row--grand' : '' ?>" data-refund-row>
                            <span><?= h($label) ?></span>
                            <?= $net ? '<strong>' : '<span>' ?><?= h(Money::format($amount, $currency)) ?><?= $net ? '</strong>' : '</span>' ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($canRefund): ?>
                    <?php // ── A REFUND ──────────────────────────────────────────
                          // Folded away until it is wanted, and opened again
                          // when one is refused. The lines are this order's own,
                          // each with what it has left; the figure beside the
                          // button is the server's (order-refund.js asks it). ?>
                    <details class="fold order-refund"<?= $refundTyped ? ' open' : '' ?>>
                        <summary class="fold__head">
                            Refund
                            <?= icon('angle-down', 'fold__caret icon icon--sm') ?>
                        </summary>
                        <div class="fold__body">
                            <form method="post" action="<?= h(Url::admin('orders/' . $oid . '/refund')) ?>"
                                  data-refund-form data-quote="<?= h(Url::admin('orders/' . $oid . '/refund/quote')) ?>"
                                  data-confirm="Refund order <?= h(Order::number($order)) ?>?"
                                  data-confirm-detail="<?= \Cms\Models\Option::get('commerce_mail_refunded', '1') === '1' ? 'The customer is emailed the amount and what it was for. ' : '' ?>The money itself goes back however you send it."
                                  data-confirm-verb="Refund">
                                <?= Csrf::field() ?>
                                <div class="order-refund__lines">
                                    <?php foreach ($items as $item): ?>
                                        <?php $most = (int) $item['quantity'] - (int) ($item['refunded_qty'] ?? 0); ?>
                                        <?php if ($most <= 0) { continue; } ?>
                                        <?php $rid = (int) $item['id']; ?>
                                        <div class="order-refund__row">
                                            <label class="order-refund__name" for="refund-qty-<?= $rid ?>">
                                                <?= h(Refund::name($item)) ?>
                                                <span class="u-text-muted"><?= h(Money::format((int) $item['unit_price'], $currency)) ?> each</span>
                                            </label>
                                            <input class="input order-refund__qty" type="number" id="refund-qty-<?= $rid ?>"
                                                   name="refund_qty[<?= $rid ?>]" min="0" max="<?= $most ?>" step="1"
                                                   value="<?= h((string) ($refundQty[$rid] ?? '0')) ?>">
                                            <span class="u-text-muted order-refund__of">of <?= $most ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="form-grid form-grid--2">
                                    <?php if ($refundLeft['shipping'] > 0): ?>
                                        <div class="field">
                                            <label class="label" for="refund-shipping">Delivery to refund</label>
                                            <input class="input" type="text" id="refund-shipping" name="refund_shipping"
                                                   inputmode="decimal" autocomplete="off" placeholder="0.00"
                                                   value="<?= h((string) ($typed['refund_shipping'] ?? '')) ?>">
                                            <p class="form-help">Up to <?= h(Money::format($refundLeft['shipping'], $currency)) ?>, before its tax.</p>
                                        </div>
                                    <?php endif; ?>
                                    <div class="field">
                                        <label class="label" for="refund-amount">Amount</label>
                                        <input class="input" type="text" id="refund-amount" name="refund_amount"
                                               inputmode="decimal" autocomplete="off"
                                               value="<?= h((string) ($typed['refund_amount'] ?? '')) ?>">
                                        <p class="form-help">Blank refunds what the items and delivery come to, with their tax; up to <?= h(Money::format($refundLeft['amount'], $currency)) ?>.</p>
                                    </div>
                                </div>

                                <div class="field">
                                    <label class="label" for="refund-reason">Reason</label>
                                    <input class="input" type="text" id="refund-reason" name="refund_reason" maxlength="255"
                                           value="<?= h((string) ($typed['refund_reason'] ?? '')) ?>">
                                </div>

                                <?php if (!empty($order['stock_reduced'])): ?>
                                    <label class="check-list__row" for="refund-restock">
                                        <input type="checkbox" id="refund-restock" name="restock" value="1"
                                               <?= ($typed['restock'] ?? '1') === '1' ? 'checked' : '' ?>>
                                        <span>Put the refunded items back in stock</span>
                                    </label>
                                <?php endif; ?>

                                <div class="form-actions form-actions--bare">
                                    <button type="submit" class="btn btn--primary">Refund</button>
                                    <span class="order-refund__sum" data-refund-sum aria-live="polite"></span>
                                </div>
                            </form>
                        </div>
                    </details>
                <?php endif; ?>

                <?php if ($exchanges !== []): ?>
                    <?php // ── AN EXCHANGE ───────────────────────────────────────
                          // The same thing in another size or colour, at the
                          // price paid: the old option back on the shelf, the
                          // new one taken off it. A form per line, each its own. ?>
                    <details class="fold order-exchange"<?= isset($typed['exchange']) ? ' open' : '' ?>>
                        <summary class="fold__head">
                            Exchange
                            <?= icon('angle-down', 'fold__caret icon icon--sm') ?>
                        </summary>
                        <div class="fold__body">
                            <?php foreach ($items as $item): ?>
                                <?php $xid = (int) $item['id']; ?>
                                <?php if (!isset($exchanges[$xid])) { continue; } ?>
                                <?php $xleft = (int) $item['quantity'] - (int) ($item['refunded_qty'] ?? 0); ?>
                                <form method="post" action="<?= h(Url::admin('orders/' . $oid . '/exchange')) ?>" class="order-exchange__row">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="item" value="<?= $xid ?>">
                                    <label class="order-refund__name" for="exchange-to-<?= $xid ?>">
                                        <?= h(Refund::name($item)) ?>
                                        <span class="u-text-muted"><?= $xleft ?> on the order</span>
                                    </label>
                                    <select class="select" id="exchange-to-<?= $xid ?>" name="to_variation">
                                        <?php foreach ($exchanges[$xid] as $option): ?>
                                            <?php $optLeft = \Cms\Commerce\Variation::available($option); ?>
                                            <option value="<?= (int) $option['id'] ?>"<?= $optLeft !== null && $optLeft < 1 ? ' disabled' : '' ?>>
                                                <?= h((string) $option['label']) ?><?= $optLeft !== null ? ' · ' . ($optLeft < 1 ? 'sold out' : $optLeft . ' left') : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input class="input order-refund__qty" type="number" name="quantity" min="1" max="<?= $xleft ?>"
                                           step="1" value="<?= $xleft ?>" aria-label="How many of <?= h(Refund::name($item)) ?> to exchange">
                                    <button type="submit" class="btn">Exchange</button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>
            </div>

            <div class="form-card__section">
                <?= panel_title('Customer', 'customer') ?>
                <div class="order-addresses">
                    <div>
                        <h3 class="order-address__title">Billing</h3>
                        <?php $address($order, 'billing'); ?>
                        <?php if ((string) $order['email'] !== ''): ?>
                            <p><a class="link" href="mailto:<?= h((string) $order['email']) ?>"><?= h((string) $order['email']) ?></a></p>
                            <?php // EVERY ORDER OF THEIRS, one click from this one. ?>
                            <p><a class="link" href="<?= h(Url::admin('customers/' . \Cms\Commerce\Customers::key((string) $order['email']))) ?>" data-order-customer>All their orders</a></p>
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

                <?php // ── CORRECTING THE ADDRESSES ──────────────────────────
                      // Folded until it is wanted, and open again with the
                      // boxes as typed when a correction is refused. What was
                      // charged for the old address stays as it was charged. ?>
                <?php
                $addr = static fn (string $key): string => (string) (isset($typed['addresses']) ? ($typed[$key] ?? '') : ($order[$key] ?? ''));
                $addressFields = static function (string $who) use ($addr): void {
                    $idPrefix = 'oa';
                    require __DIR__ . '/_address.php';
                };
                ?>
                <details class="fold order-edit-addresses"<?= isset($typed['addresses']) ? ' open' : '' ?>>
                    <summary class="fold__head">
                        Correct the addresses
                        <?= icon('angle-down', 'fold__caret icon icon--sm') ?>
                    </summary>
                    <div class="fold__body">
                        <form method="post" action="<?= h(Url::admin('orders/' . $oid . '/addresses')) ?>">
                            <?= Csrf::field() ?>
                            <div class="order-addresses">
                                <fieldset class="order-address-form">
                                    <legend class="order-address__title">Billing</legend>
                                    <div class="form-grid form-grid--2">
                                        <div class="field">
                                            <label class="label" for="oa-email">Email</label>
                                            <input class="input" type="email" id="oa-email" name="email" value="<?= h($addr('email')) ?>">
                                        </div>
                                        <div class="field">
                                            <label class="label" for="oa-phone">Phone</label>
                                            <input class="input" type="tel" id="oa-phone" name="phone" value="<?= h($addr('phone')) ?>">
                                        </div>
                                    </div>
                                    <?php $addressFields('billing'); ?>
                                </fieldset>
                                <?php if ($ships): ?>
                                    <fieldset class="order-address-form">
                                        <legend class="order-address__title">Delivery, when it is not the billing address</legend>
                                        <?php $addressFields('shipping'); ?>
                                    </fieldset>
                                <?php endif; ?>
                            </div>
                            <div class="form-actions form-actions--bare">
                                <button type="submit" class="btn btn--primary">Save the addresses</button>
                            </div>
                        </form>
                    </div>
                </details>
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
                    <?php // WHO IT IS FOR. A private note stays on the order; a
                          // note to the customer is emailed to them, and the
                          // trail below marks it as sent. ?>
                    <div class="form-actions form-actions--bare">
                        <select class="select" id="order-note-type" name="note_type" aria-label="Who the note is for">
                            <option value="private">Private note</option>
                            <option value="customer">Note to the customer, emailed</option>
                        </select>
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

                <?php if ($ships): ?>
                    <?php // ── HOW IT WENT ─────────────────────────────────────
                          // Written when the parcel goes, and printed in the
                          // email that says it is on its way; an order marked
                          // Completed after this is saved carries it. ?>
                    <div class="form-card__section">
                        <?= panel_title('Tracking', 'tracking') ?>
                        <form method="post" action="<?= h(Url::admin('orders/' . $oid . '/tracking')) ?>">
                            <?= Csrf::field() ?>
                            <div class="field">
                                <label class="label" for="order-carrier">Carrier</label>
                                <input class="input" type="text" id="order-carrier" name="tracking_carrier"
                                       value="<?= h($val('tracking_carrier')) ?>" maxlength="60" placeholder="Canada Post">
                            </div>
                            <div class="field">
                                <label class="label" for="order-tracking">Tracking number</label>
                                <input class="input" type="text" id="order-tracking" name="tracking_number"
                                       value="<?= h($val('tracking_number')) ?>" maxlength="80" autocomplete="off" spellcheck="false">
                            </div>
                            <div class="field">
                                <label class="label" for="order-tracking-url">Tracking link</label>
                                <input class="input" type="url" id="order-tracking-url" name="tracking_url"
                                       value="<?= h($val('tracking_url')) ?>" maxlength="500" placeholder="https://">
                            </div>
                            <div class="form-actions form-actions--bare">
                                <button type="submit" class="btn">Save tracking</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

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
                    </div>

                    <?php // THE BANK'S REFERENCE FOR THE MONEY, typed when it
                          // arrives: what matches this order to a line on the
                          // statement. A change is a note with both values. ?>
                    <form method="post" action="<?= h(Url::admin('orders/' . $oid . '/payment')) ?>" class="order-ref-form">
                        <?= Csrf::field() ?>
                        <div class="field">
                            <label class="label" for="order-ref">Payment reference</label>
                            <input class="input" type="text" id="order-ref" name="transaction_ref"
                                   value="<?= h((string) $order['transaction_ref']) ?>" maxlength="120"
                                   autocomplete="off" spellcheck="false">
                        </div>
                        <div class="form-actions form-actions--bare">
                            <button type="submit" class="btn">Save reference</button>
                        </div>
                    </form>
                </div>

                <?php // ── SEND IT AGAIN ───────────────────────────────────────
                      // For the customer who lost the email: the confirmation,
                      // the email of the status the order is in, or the shop's
                      // own copy. Each is built from the order as it is now. ?>
                <div class="form-card__section">
                    <?= panel_title('Emails', 'customer-mail') ?>
                    <form method="post" action="<?= h(Url::admin('orders/' . $oid . '/email')) ?>">
                        <?= Csrf::field() ?>
                        <label class="label" for="order-email">Send again</label>
                        <select class="select" id="order-email" name="email">
                            <?php foreach ($resends as $key => $label): ?>
                                <option value="<?= h($key) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-actions form-actions--bare">
                            <button type="submit" class="btn">Resend</button>
                        </div>
                    </form>
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
<?php if ($canRefund): ?>
    <script src="<?= h(Asset::admin('js/order-refund.js')) ?>"></script>
<?php endif; ?>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
