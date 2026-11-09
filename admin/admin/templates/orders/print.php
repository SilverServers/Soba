<?php

declare(strict_types=1);

use Cms\Commerce\Business;
use Cms\Commerce\Countries;
use Cms\Commerce\Money;
use Cms\Commerce\Refund;
use Cms\Commerce\Totals;
use Cms\Helpers\Asset;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Models\Order;

/**
 * PACKING SLIPS AND INVOICES, TO PRINT.
 *
 * A page of its own, without the admin around it, one order to a sheet. The
 * slip is what to pack and where it goes, with no prices on it: it goes in
 * the parcel. The invoice is the same with every figure the order charged,
 * each tax on its own line, anything refunded and what the order nets, under
 * the business's name, address and GST/HST number.
 *
 * EVERY FIGURE OFF THE ORDER, as the order screen and the receipt take them:
 * `Totals::ofOrder()` and the order's own lines, in its own currency.
 *
 * @var list<array{order: array<string, mixed>, items: list<array<string, mixed>>}> $docs
 * @var string $doc 'slip' or 'invoice'
 */

// PAPER IS LIGHT: the site's own default theme, whatever the person printing
// has chosen for their screen.
$user    = null;
$hasRail = false;
require ROOT_PATH . '/admin/templates/partials/head.php';

/** An address as lines, or none. */
$lines = static function (array $order, string $who): array {
    return array_values(array_filter([
        (string) $order[$who . '_name'],
        (string) $order[$who . '_line1'],
        (string) $order[$who . '_line2'],
        trim((string) $order[$who . '_city'] . ' ' . (string) $order[$who . '_region'] . '  ' . (string) $order[$who . '_post']),
        Countries::name((string) $order[$who . '_country']),
    ], static fn (string $s): bool => trim($s) !== ''));
};
$business = Business::lines();
$back     = count($docs) === 1 ? 'orders/' . (int) $docs[0]['order']['id'] : 'orders';
?>
<body class="print-page">
    <div class="print-page__bar">
        <a class="btn" href="<?= h(Url::admin($back)) ?>"><?= icon('angle-left', 'icon icon--sm') ?>Back</a>
        <button type="button" class="btn btn--primary" data-print><?= icon('print', 'icon icon--sm') ?>Print</button>
    </div>

    <?php foreach ($docs as ['order' => $order, 'items' => $items]): ?>
        <?php $currency = (string) $order['currency']; ?>
        <article class="print-doc" data-print-doc="<?= h($doc) ?>">
            <header class="print-doc__head">
                <div class="print-doc__from">
                    <?php foreach ($business !== [] ? $business : [\Cms\SEO\Head::globalTokens()['site_name']] as $i => $line): ?>
                        <?= $i === 0 ? '<strong>' . h($line) . '</strong>' : h($line) ?><br>
                    <?php endforeach; ?>
                </div>
                <div class="print-doc__what">
                    <h1 class="print-doc__title"><?= $doc === 'invoice' ? 'Invoice' : 'Packing slip' ?></h1>
                    <p>Order <?= h(Order::number($order)) ?><br><?= h(Dates::format((string) $order['created_at'], 'j F Y')) ?></p>
                    <?php if ($doc === 'invoice'): ?>
                        <p>
                            <?= h((string) $order['payment_method_title']) ?><br>
                            <?= in_array((string) $order['status'], Order::PAID, true) || (string) $order['status'] === 'refunded'
                                ? 'Paid' . ($order['paid_at'] !== null ? ' ' . h(Dates::format((string) $order['paid_at'], 'j F Y')) : '')
                                : 'Not paid yet' ?>
                        </p>
                    <?php endif; ?>
                </div>
            </header>

            <div class="print-doc__to">
                <div>
                    <h2 class="print-doc__label"><?= $doc === 'invoice' ? 'Bill to' : 'Customer' ?></h2>
                    <p>
                        <?= implode('<br>', array_map('h', $lines($order, 'billing'))) ?>
                        <?php if ((string) $order['email'] !== ''): ?><br><?= h((string) $order['email']) ?><?php endif; ?>
                        <?php if ((string) $order['phone'] !== ''): ?><br><?= h((string) $order['phone']) ?><?php endif; ?>
                    </p>
                </div>
                <div>
                    <?php if ((string) ($order['shipping_method'] ?? '') === \Cms\Commerce\Shipping::PICKUP): ?>
                        <h2 class="print-doc__label">Collected</h2>
                        <p><?= h((string) $order['shipping_method_title']) ?></p>
                    <?php elseif ((string) ($order['shipping_method'] ?? '') !== ''): ?>
                        <h2 class="print-doc__label">Deliver to</h2>
                        <p><?= implode('<br>', array_map('h', $lines($order, (string) $order['shipping_line1'] !== '' ? 'shipping' : 'billing'))) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <table class="print-doc__items">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Code</th>
                        <th class="print-doc__num">Qty</th>
                        <?php if ($doc === 'invoice'): ?>
                            <th class="print-doc__num">Each</th>
                            <th class="print-doc__num">Amount</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= h(Refund::name($item)) ?></td>
                            <td><?= h((string) $item['sku']) ?></td>
                            <td class="print-doc__num"><?= (int) $item['quantity'] ?></td>
                            <?php if ($doc === 'invoice'): ?>
                                <td class="print-doc__num"><?= h(Money::format((int) $item['unit_price'], $currency)) ?></td>
                                <td class="print-doc__num"><?= h(Money::format((int) $item['line_total'], $currency)) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($doc === 'invoice'): ?>
                <table class="print-doc__totals">
                    <?php foreach (Totals::rows(Totals::ofOrder($order)) as [$label, $amount, $isNote]): ?>
                        <tr<?= $isNote ? ' class="print-doc__note"' : '' ?>><th><?= h($label) ?></th><td><?= h(Money::format($amount, $currency)) ?></td></tr>
                    <?php endforeach; ?>
                    <tr class="print-doc__grand"><th>Total</th><td><?= h(Money::format((int) $order['total'], $currency)) ?></td></tr>
                    <?php foreach (Refund::rows($order) as [$label, $amount]): ?>
                        <tr><th><?= h($label) ?></th><td><?= h(Money::format($amount, $currency)) ?></td></tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>

            <?php if (trim((string) ($order['customer_note'] ?? '')) !== ''): ?>
                <h2 class="print-doc__label">Customer's note</h2>
                <p><?= nl2br(h((string) $order['customer_note'])) ?></p>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>

    <script src="<?= h(Asset::admin('js/print-page.js')) ?>"></script>
</body>
</html>
