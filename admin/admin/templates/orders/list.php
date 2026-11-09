<?php

declare(strict_types=1);

use Cms\Commerce\Money;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Models\Order;
use Cms\Security\Csrf;
use Cms\Ui\OrderStatus;

/**
 * ORDERS.
 *
 * ── THE FILTER IS THE ADMIN'S OWN FILTERBAR, NOT A NEW ONE ──────────────────
 * `data-list-filter` with a search box and a status select, identical to
 * `pages/list.php` and the entry list. The first version of this screen was a
 * row of `?status=` links with counts beside them — arguably better, and wrong
 * anyway: it invented a `.filterbar__link` component that does not exist in
 * `core.css`, so it would have rendered as unstyled anchors while every other
 * list in the admin kept the real control. A screen that is the odd one out is
 * a screen the owner has to learn twice.
 *
 * The INSTANT idiom, with the same expiry date the pages list states: no
 * pagination yet, so every row is already in the document and filtering it in
 * the browser is honest. `OrderController` still reads `?status=` — a filtered
 * view stays linkable, and it is what the list will filter on when there are
 * more orders than one page.
 *
 * ── THE COUNTS GO IN THE HEADER, WHERE THEY DO NOT NEED A CONTROL ───────────
 * "3 pending" is the whole state of a small shop's day and should be readable
 * without touching anything. It is a sentence above the list rather than a
 * badge on a filter, so it says what it means and needs no component.
 *
 * @var list<array<string, mixed>> $orders
 * @var array<string, int>         $counts
 * @var int                        $total
 * @var string                     $status  the active filter, '' for all
 * @var string                     $month   the month shown, 'YYYY-MM' or '' for all
 * @var list<string>               $months  the months there are orders in, newest first
 * @var array<string, mixed>|null  $bulkReport what the last bulk action did
 */

ob_start();
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Orders</h1>
            <?php
            // WHAT NEEDS ATTENTION, IN WORDS. Pending is waiting on the
            // customer's money; processing is waiting on the owner. Those two
            // are the shop's whole to-do list, and a figure of zero is left out
            // rather than printed — "0 pending" is furniture.
            $lines = [];
            if (($counts['pending'] ?? 0) > 0)    { $lines[] = (int) $counts['pending'] . ' awaiting payment'; }
            if (($counts['processing'] ?? 0) > 0) { $lines[] = (int) $counts['processing'] . ' to fulfil'; }
            ?>
            <?php if ($lines !== []): ?>
                <p class="page__subtitle"><?= h(implode(' · ', $lines)) ?></p>
            <?php endif; ?>
        </div>
        <?php // AN ORDER THE SHOP TAKES ITSELF: on the telephone, at a stall.
              // EXPORT sends the status and month chosen below with it (their
              // selects belong to the export form by `form=`), so last month's
              // orders are a spreadsheet of last month's orders. ?>
        <div class="page__header-actions">
            <?php if ($total > 0): ?>
                <button type="submit" class="btn" form="order-export"><?= icon('cloud-arrow-down', 'icon icon--sm') ?>Export</button>
            <?php endif; ?>
            <a class="btn btn--primary" href="<?= h(Url::admin('orders/new')) ?>">
                <?= icon('plus', 'icon icon--sm') ?>New order
            </a>
        </div>
    </div>
    <form id="order-export" method="get" action="<?= h(Url::admin('orders/export.csv')) ?>"></form>

    <?php // WHAT THE LAST BULK ACTION DID, and what it passed over, by number. ?>
    <?php if ($bulkReport !== null): ?>
        <?php $doneCount = (int) ($bulkReport['done'] ?? 0); ?>
        <?php if ($doneCount > 0): ?>
            <div class="flash flash--success" data-bulk-done>
                <?= $doneCount ?> <?= $doneCount === 1 ? 'order' : 'orders' ?> moved to <?= h(Order::statusLabel((string) $bulkReport['action'])) ?>.
            </div>
        <?php endif; ?>
        <?php if (!empty($bulkReport['skipped'])): ?>
            <div class="alert alert--danger alert--compact" data-bulk-skipped>
                <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
                <div class="alert__body">
                    <p class="alert__title"><?= count((array) $bulkReport['skipped']) ?> not changed</p>
                    <ul class="alert__text">
                        <?php foreach ((array) $bulkReport['skipped'] as $skip): ?>
                            <li><?= h((string) $skip) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($total === 0): ?>
        <?php // NOTHING HAS BEEN ORDERED YET — a state, and one a new shop is
              // in for a while. It says what would put a row here rather than
              // just "No orders", which reads as a screen that is broken. ?>
        <div class="empty">
            <?= icon('box-open', 'empty__glyph', '', 28) ?>
            <p class="empty__title">No orders yet</p>
            <p class="empty__text">
                An order appears here the moment somebody checks out. Products
                are the entries of any content type that carries the
                <strong>Product</strong> kind.
            </p>
        </div>
    <?php else: ?>

        <div class="filterbar" data-list-filter="#order-list">
            <div class="filterbar__group">
                <input class="input input--search" type="search"
                       placeholder="Filter orders&hellip;"
                       aria-label="Filter orders by number, name or email"
                       data-filter-text>

                <select class="select" aria-label="Filter by status" data-filter-attr="data-status"
                        name="status" form="order-export">
                    <option value="">Any status</option>
                    <?php foreach (Order::STATUSES as $key => $statusLabel): ?>
                        <option value="<?= h($key) ?>"
                            <?= $status === $key ? 'selected' : '' ?>><?= h($statusLabel) ?></option>
                    <?php endforeach; ?>
                </select>

                <?php // THE MONTH IT WAS PLACED IN, in the site's own days. ?>
                <select class="select" aria-label="Filter by month" data-filter-attr="data-month"
                        name="month" form="order-export">
                    <option value="">Any month</option>
                    <?php foreach ($months as $ym): ?>
                        <option value="<?= h($ym) ?>"<?= $month === $ym ? ' selected' : '' ?>>
                            <?= h((new \DateTimeImmutable($ym . '-01'))->format('F Y')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filterbar__actions">
                <span class="filterbar__label" data-filter-count hidden></span>
                <a class="filterbar__reset" href="#" data-filter-reset hidden>Clear</a>
            </div>
        </div>

        <?php if ($orders === []): ?>
            <?php // A FILTER THAT MATCHES NOTHING IS NOT AN EMPTY SHOP, and
                  // saying "No orders yet" here would be a lie — there are
                  // orders, just none in this status. The way out is named. ?>
            <div class="empty">
                <?= icon('box-open', 'empty__glyph', '', 28) ?>
                <p class="empty__title">No <?= h(strtolower(Order::statusLabel($status))) ?> orders</p>
                <p class="empty__text">
                    <a class="link" href="<?= h(Url::admin('orders')) ?>">Show every order</a>
                </p>
            </div>
        <?php else: ?>
            <?php // ── SEVERAL AT ONCE ───────────────────────────────────────
                  // The rows' boxes join this form by `form=`; entry-bulk.js
                  // enables Apply with an action and a tick, and select all
                  // reaches only the rows the filter is showing. ?>
            <form id="order-bulk" method="post" action="<?= h(Url::admin('orders/bulk')) ?>"
                  data-entry-bulk data-label-one="order" data-label-many="orders">
                <?= Csrf::field() ?>
                <div class="bulk-bar">
                    <label class="entry-list__check order-list__all">
                        <input type="checkbox" data-bulk-all aria-label="Select every order shown">
                    </label>
                    <select class="select select--sm" name="bulk_action" aria-label="Bulk action" data-bulk-action>
                        <option value="">Bulk actions</option>
                        <?php foreach (['processing', 'on-hold', 'completed', 'cancelled'] as $to): ?>
                            <option value="<?= h($to) ?>">Move to <?= h(Order::statusLabel($to)) ?></option>
                        <?php endforeach; ?>
                        <option value="slip">Print packing slips</option>
                        <option value="invoice">Print invoices</option>
                    </select>
                    <button type="submit" class="btn btn--sm" data-bulk-apply disabled>Apply</button>
                </div>
            </form>

            <div class="list entry-list entry-list--select order-list" id="order-list">
                <?php foreach ($orders as $o): ?>
                    <?php $oid = (int) $o['id']; ?>
                    <?php // `data-text` is the order's IDENTITY — number, name,
                          // email. Matching the row's rendered text instead
                          // would make "pending" a search term hitting every
                          // pending row, which the status select already does
                          // properly. Same reasoning as the pages list. ?>
                    <div class="list__row"
                         data-status="<?= h((string) $o['status']) ?>"
                         data-month="<?= h(Order::monthOf($o)) ?>"
                         data-text="<?= h(Order::number($o) . ' ' . (string) $o['billing_name'] . ' ' . (string) $o['email']) ?>"
                         data-row-href="<?= h(Url::admin('orders/' . $oid)) ?>">
                        <label class="entry-list__check">
                            <input type="checkbox" name="ids[]" value="<?= $oid ?>" form="order-bulk" data-bulk-row
                                   data-title="<?= h(Order::number($o)) ?>" aria-label="Select order <?= h(Order::number($o)) ?>">
                        </label>
                        <div class="entry-list__main">
                            <a class="list__row-link list__row-title"
                               href="<?= h(Url::admin('orders/' . $oid)) ?>">
                                <?= h(Order::number($o)) ?>
                                <?php // THE NAME IS THE USEFUL HALF. An order
                                      // number identifies it; a name is what
                                      // the owner is looking for when the
                                      // customer telephones. ?>
                                <?php if ((string) $o['billing_name'] !== ''): ?>
                                    — <?= h((string) $o['billing_name']) ?>
                                <?php endif; ?>
                            </a>
                            <div class="list__row-sub">
                                <?= h(Dates::stamp((string) $o['created_at'])) ?>
                                <?php if ((string) $o['email'] !== ''): ?>
                                    · <?= h((string) $o['email']) ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div><?= OrderStatus::render((string) $o['status']) ?></div>

                        <?php // FOUR SLOTS, BECAUSE `.entry-list .list__row` IS
                              // A FOUR-TRACK GRID — main, status, meta, actions.
                              // A fifth child would have been laid into the
                              // actions track and printed over the button. The
                              // row's shape is the list component's, not this
                              // screen's to extend.
                              //
                              // The TOTAL is in the currency the order was
                              // PLACED in, never the current setting: a shop
                              // that switches currency must not relabel every
                              // order it ever took. See the migration header. ?>
                        <div class="list__row-meta">
                            <strong><?= h(Money::format((int) $o['total'], (string) $o['currency'])) ?></strong>
                            <span><?= (int) $o['item_count'] ?>
                                  <?= (int) $o['item_count'] === 1 ? 'item' : 'items' ?></span>
                        </div>

                        <div class="list__row-actions">
                            <a class="btn btn--sm" href="<?= h(Url::admin('orders/' . $oid)) ?>">Open</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php // WITHOUT list-filter.js THE FILTERBAR IS INERT — a search box and a
          // select that do nothing, which is worse than not drawing them. It is
          // linked inside the `$orders !== []` branch for the same reason the
          // bar is: there is nothing to filter otherwise. ?>
    <?php if ($total > 0 && $orders !== []): ?>
        <script src="<?= h(\Cms\Helpers\Asset::admin('js/list-filter.js')) ?>"></script>
        <script src="<?= h(\Cms\Helpers\Asset::admin('js/entry-bulk.js')) ?>"></script>
    <?php endif; ?>
</div>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
