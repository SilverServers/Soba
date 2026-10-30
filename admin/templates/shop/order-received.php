<?php

declare(strict_types=1);

use Cms\Commerce\Countries;
use Cms\Commerce\Money;
use Cms\Commerce\Totals;
use Cms\Models\Order;

/**
 * THE RECEIPT — what was ordered, and how to pay for it.
 *
 * ── EVERY FIGURE HERE COMES OFF THE ORDER, NOT OFF THE PRODUCT ──────────────
 * `Money::format($order['total'], $order['currency'])` — the currency comes
 * from the ORDER. If it were read from settings, a shop that switched from CAD
 * to USD would relabel every receipt ever issued, and the customer's copy and
 * ours would disagree about what was charged.
 *
 * ── AND THE PAYMENT INSTRUCTIONS ARE THE POINT OF THE PAGE ──────────────────
 * These are offline methods. A customer told "pay by bank transfer" and given
 * no further detail cannot pay; the instructions block is what turns a placed
 * order into a paid one, so it is above the fold and not in the small print.
 *
 * @var array<string, mixed>       $order
 * @var list<array<string, mixed>> $items
 * @var string                     $instructions
 */
$currency = (string) $order['currency'];

/** Draw an address block, or nothing if there is not one. */
$address = static function (array $order, string $prefix) use ($currency): void {
    $line1 = (string) $order[$prefix . '_line1'];
    if ($line1 === '') {
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
    ?>
    <address class="shop-address">
        <?= implode('<br>', array_map('htmlspecialchars', $parts)) ?>
    </address>
    <?php
};
?>
<div class="shop">
    <h1 class="shop__title"><?= htmlspecialchars(\Cms\Commerce\Words::of('thanks')) ?></h1>
    <p class="shop__lede">
        Your order <strong><?= htmlspecialchars(Order::number($order)) ?></strong> has been received.<?php /* audit-ok: half a sentence around the order number */ ?>
        <?php if ((string) $order['email'] !== ''): ?>
            A confirmation is on its way to <?= htmlspecialchars((string) $order['email']) ?>.<?php /* audit-ok: half a sentence around the customer's email address */ ?>
        <?php endif; ?>
    </p>

    <?php if (trim($instructions) !== ''): ?>
        <section class="shop-panel shop-panel--instructions">
            <h2 class="shop-panel__title">
                How to pay — <?= htmlspecialchars((string) $order['payment_method_title']) ?><?php /* audit-ok: a label in front of the payment method's own name */ ?>
            </h2>
            <?php // nl2br over an escaped string: the owner types these
                  // instructions in settings as plain text with line breaks,
                  // and escaping FIRST means a `<script>` in the settings box
                  // is text, not a script on every customer's receipt. ?>
            <p class="shop-instructions"><?= nl2br(htmlspecialchars($instructions)) ?></p>
        </section>
    <?php endif; ?>

    <section class="shop-panel">
        <h2 class="shop-panel__title">Your order</h2><?php /* audit-ok: a section heading */ ?>
        <table class="shop-table">
            <thead>
                <tr>
                    <th scope="col">Product</th><?php /* audit-ok: a column header */ ?>
                    <th scope="col" class="shop-table__num">Quantity</th><?php /* audit-ok: a column header */ ?>
                    <th scope="col" class="shop-table__num">Total</th><?php /* audit-ok: a column header */ ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <th scope="row" class="shop-table__name">
                            <?= htmlspecialchars((string) $item['name']) ?>
                            <?php // ── WHICH ONE WAS BOUGHT ──────────────────
                                  // The LABEL stored on the line, not a lookup:
                                  // migration 046's header has the reasoning,
                                  // and "Associate" on a receipt for what was
                                  // actually "Associate — Small" is a parcel
                                  // packed wrong. The basket printed it; a
                                  // receipt that drops it is the one document
                                  // the customer keeps. ?>
                            <?php if ((string) ($item['variation_label'] ?? '') !== ''): ?>
                                <span class="shop-table__variant"><?= htmlspecialchars((string) $item['variation_label']) ?></span>
                            <?php endif; ?>
                            <?php if ((string) $item['sku'] !== ''): ?>
                                <span class="u-text-muted u-text-sm"><?= htmlspecialchars((string) $item['sku']) ?></span>
                            <?php endif; ?>
                        </th>
                        <td class="shop-table__num"><?= (int) $item['quantity'] ?></td>
                        <td class="shop-table__num">
                            <?= htmlspecialchars(Money::format((int) $item['line_total'], $currency)) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php // ── THE SAME ROWS THE BASKET PRINTED, FROM THE ORDER'S OWN
              // FIGURES. `Totals::ofOrder()` reads the stored columns —
              // including `tax_rate` and `tax_label`, which migration 044 put
              // on the row so a receipt printed next year says what it said the
              // day it was issued. Written out by hand here, this block said
              // "Tax" against a number and the customer had no way to check it. ?>
        <div class="shop-totals">
            <?php foreach (Totals::rows(Totals::ofOrder($order)) as [$label, $amount, $isNote]): ?>
                <div class="shop-totals__row<?= $isNote ? ' shop-totals__row--note' : '' ?>">
                    <span><?= htmlspecialchars($label) ?></span>
                    <span><?= htmlspecialchars(Money::format($amount, $currency)) ?></span>
                </div>
            <?php endforeach; ?>
            <div class="shop-totals__row shop-totals__row--grand">
                <span>Total</span><?php /* audit-ok: the totals table's row label */ ?>
                <strong><?= htmlspecialchars(Money::format((int) $order['total'], $currency)) ?></strong>
            </div>
        </div>
    </section>

    <section class="shop-panel">
        <h2 class="shop-panel__title">Details</h2><?php /* audit-ok: a section heading */ ?>
        <div class="shop-addresses">
            <div>
                <h3 class="shop-address__title">Billing</h3><?php /* audit-ok: a section heading */ ?>
                <?php $address($order, 'billing'); ?>
                <?php if ((string) $order['phone'] !== ''): ?>
                    <p class="u-text-muted u-text-sm"><?= htmlspecialchars((string) $order['phone']) ?></p>
                <?php endif; ?>
            </div>
            <?php $how = (string) ($order['shipping_method_title'] ?? ''); ?>
            <?php if ((string) ($order['shipping_method'] ?? '') === 'pickup'): ?>
                <?php // COLLECTED: where and when, in the words the order kept. ?>
                <div>
                    <h3 class="shop-address__title">Collection</h3><?php /* audit-ok: a section heading */ ?>
                    <p><?= nl2br(htmlspecialchars($how)) ?></p>
                </div>
            <?php elseif ((string) $order['shipping_line1'] !== ''): ?>
                <div>
                    <h3 class="shop-address__title">Delivery</h3><?php /* audit-ok: a section heading */ ?>
                    <?php if ($how !== ''): ?><p class="u-text-muted u-text-sm"><?= htmlspecialchars($how) ?></p><?php endif; ?>
                    <?php $address($order, 'shipping'); ?>
                </div>
            <?php else: ?>
                <?php // SAYING SO, rather than drawing the same address twice
                      // or leaving a blank column that reads as missing data. ?>
                <div>
                    <h3 class="shop-address__title">Delivery</h3><?php /* audit-ok: a section heading */ ?>
                    <?php if ($how !== ''): ?><p class="u-text-muted u-text-sm"><?= htmlspecialchars($how) ?></p><?php endif; ?>
                    <p class="u-text-muted">Same as the billing address.</p><?php /* audit-ok: the system saying the two addresses matched, which it decided */ ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (trim((string) ($order['customer_note'] ?? '')) !== ''): ?>
            <h3 class="shop-address__title">Your notes</h3><?php /* audit-ok: a section heading */ ?>
            <p><?= nl2br(htmlspecialchars((string) $order['customer_note'])) ?></p>
        <?php endif; ?>
    </section>

    <?php // WHOSE RECEIPT IT IS: the business's name, address and GST/HST
          // number, as Settings → Shop keeps them (`Commerce\Business`). ?>
    <?php $business = \Cms\Commerce\Business::lines(); ?>
    <?php if ($business !== []): ?>
        <p class="shop-business u-text-muted u-text-sm" data-business><?= implode('<br>', array_map('htmlspecialchars', $business)) ?></p>
    <?php endif; ?>

    <p><a class="shop__link" href="<?= htmlspecialchars(BASE_URL . '/') ?>">Continue shopping</a></p>
</div>
