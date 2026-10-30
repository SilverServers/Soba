<?php

declare(strict_types=1);

/**
 * The checkout's figures — drawn with the page, and drawn again on their own
 * by `/checkout/totals` when the customer chooses a province, so the tax line
 * says what this order will be charged before it is placed.
 *
 * THE SAME ROWS THE BASKET PRINTED, from the same `Commerce\Totals` call. A
 * checkout that recomputes its own figures is a checkout that can show a
 * different number from the page the customer just agreed to — which is the
 * one disagreement that costs money.
 *
 * @var array<string, mixed> $totals from `Totals::forCart()`
 */

use Cms\Commerce\Money;
use Cms\Commerce\Shipping;
use Cms\Commerce\Totals;
?>
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
<?php $freeNote = (string) ($totals['deliveryNote'] ?? ''); ?>
<?php if ($freeNote !== ''): ?>
    <p class="shop-totals__note u-text-muted u-text-sm"><?= htmlspecialchars($freeNote) ?></p>
<?php endif; ?>
<?php if (Shipping::noteForCart() !== ''): ?>
    <p class="shop-totals__note u-text-muted u-text-sm"><?= htmlspecialchars(Shipping::noteForCart()) ?></p>
<?php endif; ?>
