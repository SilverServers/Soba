<?php

declare(strict_types=1);

use Cms\Commerce\Product;
use Cms\Commerce\StockLog;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * THE SHOP'S STOCK, ON ONE SCREEN.
 *
 * Every product that sells, or each of its options, with its count in a box.
 * Type the new counts, say why (a delivery received, a return, damage, a
 * recount), and save: each count changed is a line in its product's history.
 * A sold-out or running-low row wears the mark that says so, as the status
 * chips do: the glyph carries it, not a colour.
 *
 * @var list<array{key: string, post_id: int, title: string, option: string, sku: string,
 *                 counted: bool, count: ?int, status: string}> $rows
 * @var int $lowAt
 * @var array{changed: int, moved: list<string>, refused: list<string>}|null $report
 */

$names = [];
$out   = 0;
$low   = 0;
foreach ($rows as $r) {
    $names[$r['key']] = $r['title'] . ($r['option'] !== '' ? ' — ' . $r['option'] : '');
    if ($r['counted'] && (int) $r['count'] <= 0 || $r['status'] === Product::OUT_OF_STOCK) {
        $out++;
    } elseif ($r['counted'] && (int) $r['count'] <= $lowAt) {
        $low++;
    }
}
$state = static function (array $r) use ($lowAt): string {
    if (($r['counted'] && (int) $r['count'] <= 0) || $r['status'] === Product::OUT_OF_STOCK) {
        return '<span class="status status--archived status--phrase">' . icon('x', 'status__icon icon', '', 12) . 'Sold out</span>';
    }
    if ($r['counted'] && (int) $r['count'] <= $lowAt) {
        return '<span class="status status--phrase">' . icon('arrow-trend-down', 'status__icon icon', '', 12) . 'Running low</span>';
    }
    return '';
};

ob_start();
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Stock</h1>
            <?php $said = array_filter([$out > 0 ? $out . ' sold out' : '', $low > 0 ? $low . ' running low' : '']); ?>
            <?php if ($said !== []): ?>
                <p class="page__subtitle"><?= h(implode(' · ', $said)) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($report !== null): ?>
        <?php if ($report['changed'] > 0): ?>
            <div class="flash flash--success" data-stock-saved>
                <?= (int) $report['changed'] ?> <?= $report['changed'] === 1 ? 'count' : 'counts' ?> changed.
            </div>
        <?php endif; ?>
        <?php // A COUNT THAT MOVED WHILE IT WAS BEING TYPED, named with what it
              // is now, and one that was not a whole number: left as they were. ?>
        <?php if ($report['moved'] !== [] || $report['refused'] !== []): ?>
            <div class="alert alert--danger alert--compact" data-stock-skipped>
                <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
                <div class="alert__body">
                    <p class="alert__title"><?= count($report['moved']) + count($report['refused']) ?> not changed</p>
                    <ul class="alert__text">
                        <?php foreach ($report['moved'] as $key): ?>
                            <li><?= h($names[$key] ?? $key) ?> changed while you were typing; it is shown as it is now.</li>
                        <?php endforeach; ?>
                        <?php foreach ($report['refused'] as $key): ?>
                            <li><?= h($names[$key] ?? $key) ?>: a count is a whole number, 0 or more.</li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <div class="empty">
            <?= icon('box-open', 'empty__glyph', '', 28) ?>
            <p class="empty__title">Nothing for sale yet</p>
            <p class="empty__text">
                The entries of any type that carries the <strong>Product</strong>
                kind are listed here, with their counts.
            </p>
        </div>
    <?php else: ?>
        <?php // THE TABLE ON THE PAGE, as a list is, and the reason in a card
              // under it: inset in a card, a table is a second border a few
              // pixels inside the first, its surface the card's own. In the
              // document column, so the count boxes are wells. ?>
        <form method="post" action="<?= h(Url::admin('stock')) ?>" class="form-main stock-form">
            <?= Csrf::field() ?>
                <div class="table-wrap">
                    <table class="table stock-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Option</th>
                                <th>SKU</th>
                                <th class="num">In stock</th>
                                <th></th>
                                <?php // NO HEADING: every row's link says History. ?>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr data-stock-row="<?= h($r['key']) ?>">
                                    <td><?= h($r['title']) ?></td>
                                    <td><?= h($r['option']) ?></td>
                                    <td><?= h($r['sku']) ?></td>
                                    <td class="num">
                                        <?php if ($r['counted']): ?>
                                            <input class="input stock-table__count" type="number" min="0" step="1"
                                                   name="count[<?= h($r['key']) ?>]" value="<?= (int) $r['count'] ?>"
                                                   aria-label="In stock: <?= h($names[$r['key']]) ?>">
                                            <input type="hidden" name="was[<?= h($r['key']) ?>]" value="<?= (int) $r['count'] ?>">
                                        <?php else: ?>
                                            <span class="u-text-muted">Not counted</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $state($r) ?></td>
                                    <td><a class="link" href="<?= h(Url::admin('stock/' . $r['post_id'])) ?>">History</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php // ONE REASON FOR THE SAVE: a delivery is many sizes at once. ?>
            <div class="form-card">
            <div class="form-card__section">
                <div class="form-grid form-grid--2">
                    <div class="field">
                        <label class="label" for="stock-reason">Why the counts changed</label>
                        <select class="select" id="stock-reason" name="reason">
                            <?php foreach (StockLog::SCREEN_REASONS as $reason): ?>
                                <option value="<?= h($reason) ?>"><?= h(StockLog::reasonLabel($reason)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="label" for="stock-note">Note</label>
                        <input class="input" type="text" id="stock-note" name="note" maxlength="255"
                               placeholder="Delivery from the mill, invoice 2207">
                    </div>
                </div>
            </div>

            <div class="form-card__section form-actions">
                <button type="submit" class="btn btn--primary">Save counts</button>
            </div>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
