<?php

declare(strict_types=1);

use Cms\Commerce\Money;
use Cms\Controllers\Admin\ReportController;
use Cms\Helpers\Url;

/**
 * SHOP → REPORTS.
 *
 * The stretch first, as a GET form (`.filterbar--apply`): a period by name or
 * two days, and whether the sales go by day or by month. Then four figures,
 * each a link to the table it comes from; then the tables, each with the
 * spreadsheet of itself for the same stretch: sales by day or month, what
 * sold by product and by option, and the tax collected by where it was
 * charged, which is what a GST/HST return is filled in from.
 *
 * @var string                     $period
 * @var string                     $from
 * @var string                     $to
 * @var string                     $by
 * @var array<string, int>         $summary
 * @var list<array<string, mixed>> $byDate
 * @var list<array<string, mixed>> $products
 * @var list<array<string, mixed>> $options
 * @var list<array<string, mixed>> $taxes
 */

$money    = static fn (int $n): string => Money::format($n);
// A DAY AS THE SHOP COUNTS IT, written as it is: these are the site's days
// already, and `Dates::format()` would read one as a UTC moment and move it
// into the site's zone, a day back.
$day      = static fn (string $ymd, string $format): string => (new \DateTimeImmutable($ymd, new \DateTimeZone('UTC')))->format($format);
$query    = static fn (string $report): string => Url::admin('reports/export') . '?' . http_build_query(
    ['report' => $report, 'period' => $period, 'from' => $from, 'to' => $to, 'by' => $by]);
$average  = $summary['orders'] > 0 ? intdiv($summary['net'], $summary['orders']) : 0;
$download = static fn (string $report, string $what): string => '<a class="link" href="' . h($query($report)) . '" download data-report-download="'
    . h($report) . '">' . icon('cloud-arrow-down', 'icon icon--sm') . h($what) . '</a>';

ob_start();
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Reports</h1>
            <p class="page__subtitle" data-report-span>
                <?= h($day($from, 'j F Y')) ?> to <?= h($day($to, 'j F Y')) ?>
            </p>
        </div>
    </div>

    <form class="filterbar filterbar--apply" method="get" action="<?= h(Url::admin('reports')) ?>">
        <div class="filterbar__group">
            <label class="filterbar__field">
                <span class="filterbar__label">Period</span>
                <select class="select" name="period">
                    <?php foreach (ReportController::PERIODS as $key => $words): ?>
                        <option value="<?= h($key) ?>"<?= $period === $key ? ' selected' : '' ?>><?= h($words) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php // THE TWO DAYS, read when the period is "Between two days":
                  // shown always, holding the stretch shown, so changing one is
                  // where a custom stretch starts. ?>
            <label class="filterbar__field">
                <span class="filterbar__label">From</span>
                <input class="input" type="date" name="from" value="<?= h($from) ?>">
            </label>
            <label class="filterbar__field">
                <span class="filterbar__label">To</span>
                <input class="input" type="date" name="to" value="<?= h($to) ?>">
            </label>
            <label class="filterbar__field">
                <span class="filterbar__label">Sales by</span>
                <select class="select" name="by">
                    <option value="day"<?= $by === 'day' ? ' selected' : '' ?>>Day</option>
                    <option value="month"<?= $by === 'month' ? ' selected' : '' ?>>Month</option>
                </select>
            </label>
        </div>
        <div class="filterbar__actions">
            <button type="submit" class="btn">Show</button>
        </div>
    </form>

    <?php // FOUR FIGURES, each a link to the table it is read from. ?>
    <div class="card-grid report-figures" data-report-figures>
        <a class="card stat" href="#report-sales">
            <div class="card__head"><?= icon('arrow-trend-up', 'card__glyph icon icon--lg') ?><strong>Net sales</strong></div>
            <p class="stat__n"><?= h($money($summary['net'])) ?></p>
        </a>
        <a class="card stat" href="<?= h(Url::admin('orders')) ?>">
            <div class="card__head"><?= icon('box-open', 'card__glyph icon icon--lg') ?><strong>Orders</strong></div>
            <p class="stat__n"><?= (int) $summary['orders'] ?></p>
        </a>
        <a class="card stat" href="#report-products">
            <div class="card__head"><?= icon('tag', 'card__glyph icon icon--lg') ?><strong>Items sold</strong></div>
            <p class="stat__n"><?= (int) $summary['items'] ?></p>
        </a>
        <a class="card stat" href="#report-taxes">
            <div class="card__head"><?= icon('scroll', 'card__glyph icon icon--lg') ?><strong>Tax collected</strong></div>
            <p class="stat__n"><?= h($money($summary['tax'])) ?></p>
        </a>
    </div>

    <div class="form-main">
        <div class="form-card">
            <div class="form-card__section" id="report-sales">
                <?= panel_title('Sales by ' . ($by === 'month' ? 'month' : 'day'), 'history') ?>
                <?php if ($byDate === []): ?>
                    <p class="form-help">Nothing was sold between these days.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table" data-report="sales">
                            <thead>
                                <tr><th><?= $by === 'month' ? 'Month' : 'Day' ?></th><th class="num">Orders</th><th class="num">Items</th>
                                    <th class="num">Total</th><th class="num">Refunded</th><th class="num">Net</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($byDate as $r): ?>
                                    <tr>
                                        <td><?= h($by === 'month' ? $day($r['when'] . '-01', 'F Y') : $day($r['when'], 'D j M Y')) ?></td>
                                        <td class="num"><?= (int) $r['orders'] ?></td>
                                        <td class="num"><?= (int) $r['items'] ?></td>
                                        <td class="num"><?= h($money((int) $r['total'])) ?></td>
                                        <td class="num"><?= h($money((int) $r['refunded'])) ?></td>
                                        <td class="num"><?= h($money((int) $r['net'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php // THE WHOLE STRETCH, under its days. ?>
                    <div class="kv-list report-totals" data-report-totals>
                        <?php foreach ([
                            'Goods' => $summary['goods'], 'Discounts' => -$summary['discount'], 'Delivery' => $summary['delivery'],
                            'Tax' => $summary['tax'], 'Total' => $summary['total'], 'Refunded' => -$summary['refunded'], 'Net' => $summary['net'],
                        ] as $label => $amount): ?>
                            <div class="kv-list__row">
                                <span class="kv-list__key"><?= h($label) ?></span>
                                <span class="kv-list__val"><?= h($money($amount)) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <div class="kv-list__row">
                            <span class="kv-list__key">Average order</span>
                            <span class="kv-list__val"><?= h($money($average)) ?></span>
                        </div>
                    </div>
                    <p class="form-help"><?= $download('sales', 'Sales by ' . ($by === 'month' ? 'month' : 'day') . ' as a spreadsheet') ?></p>
                <?php endif; ?>
            </div>

            <div class="form-card__section" id="report-products">
                <?= panel_title('What sold', 'order-items') ?>
                <?php if ($products === []): ?>
                    <p class="form-help">Nothing was sold between these days.</p>
                <?php else: ?>
                    <div class="report-pair">
                        <?php foreach (['products' => [$products, 'Product'], 'options' => [$options, 'By size and colour']] as $key => [$rows, $head]): ?>
                            <div class="table-wrap">
                                <table class="table" data-report="<?= h($key) ?>">
                                    <thead><tr><th><?= h($head) ?></th><th class="num">Units</th><th class="num">Sales</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($rows as $r): ?>
                                            <tr><td><?= h((string) $r['name']) ?></td><td class="num"><?= (int) $r['units'] ?></td>
                                                <td class="num"><?= h($money((int) $r['sales'])) ?></td></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="form-help"><?= $download('products', 'Products as a spreadsheet') ?> · <?= $download('options', 'Sizes and colours as a spreadsheet') ?></p>
                <?php endif; ?>
            </div>

            <div class="form-card__section" id="report-taxes">
                <?= panel_title('Tax collected', 'tax') ?>
                <?php if ($taxes === []): ?>
                    <p class="form-help">No tax was charged between these days.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table" data-report="taxes">
                            <thead>
                                <tr><th>Where</th><th>Tax</th><th class="num">Orders</th><th class="num">Collected</th>
                                    <th class="num">Refunded</th><th class="num">Net</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($taxes as $r): ?>
                                    <tr>
                                        <td><?= h((string) $r['place']) ?></td>
                                        <td><?= h((string) $r['tax']) ?></td>
                                        <td class="num"><?= (int) $r['orders'] ?></td>
                                        <td class="num"><?= h($money((int) $r['collected'])) ?></td>
                                        <td class="num"><?= h($money((int) $r['refunded'])) ?></td>
                                        <td class="num"><?= h($money((int) $r['net'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="form-help">By the province or state each order was charged for, as its tax was worked out at the checkout. <?= $download('taxes', 'Tax as a spreadsheet') ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
