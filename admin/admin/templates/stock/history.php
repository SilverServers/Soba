<?php

declare(strict_types=1);

use Cms\Commerce\StockLog;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;

/**
 * ONE PRODUCT'S STOCK HISTORY: every change to its counts, newest first.
 *
 * What changed, by how much, what the count was after, why, the order it
 * belonged to, who, and the note. Named as it was at the time, so a product
 * renamed since reads as it happened.
 *
 * @var array<string, mixed>       $product from `Product::find()`
 * @var list<array<string, mixed>> $moves
 */

ob_start();
?>
<div class="page">

    <a class="page__back" href="<?= h(Url::admin('stock')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Stock
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title"><?= h((string) $product['title']) ?></h1>
            <p class="page__subtitle">Every change to its stock, newest first</p>
        </div>
    </div>

    <?php if ($moves === []): ?>
        <div class="empty">
            <?= icon('list-ol', 'empty__glyph', '', 28) ?>
            <p class="empty__title">No changes yet</p>
            <p class="empty__text">A sale, a delivery or a recount puts a line here.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table stock-history" data-stock-history>
                <thead>
                    <tr>
                        <th>When</th>
                        <th>What</th>
                        <th class="num">Change</th>
                        <th class="num">After</th>
                        <th>Why</th>
                        <th>Who</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($moves as $move): ?>
                        <tr>
                            <td><?= h(Dates::stamp((string) $move['created_at'])) ?></td>
                            <td><?= h((string) $move['label']) ?></td>
                            <td class="num"><?= (int) $move['change_qty'] > 0 ? '+' : '' ?><?= (int) $move['change_qty'] ?></td>
                            <td class="num"><?= $move['qty_after'] !== null ? (int) $move['qty_after'] : '' ?></td>
                            <td>
                                <?= h(StockLog::reasonLabel((string) $move['reason'])) ?>
                                <?php if ($move['order_id'] !== null): ?>
                                    · <a class="link" href="<?= h(Url::admin('orders/' . (int) $move['order_id'])) ?>">#<?= (int) $move['order_id'] ?></a>
                                <?php endif; ?>
                                <?php if (trim((string) $move['note']) !== ''): ?>
                                    · <?= h((string) $move['note']) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= h((string) $move['author']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
