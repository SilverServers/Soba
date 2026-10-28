<?php

declare(strict_types=1);

use Cms\Commerce\Money;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Models\Order;
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
    </div>

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

                <select class="select" aria-label="Filter by status" data-filter-attr="data-status">
                    <option value="">Any status</option>
                    <?php foreach (Order::STATUSES as $key => $statusLabel): ?>
                        <option value="<?= h($key) ?>"
                            <?= $status === $key ? 'selected' : '' ?>><?= h($statusLabel) ?></option>
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
            <div class="list entry-list order-list" id="order-list">
                <?php foreach ($orders as $o): ?>
                    <?php $oid = (int) $o['id']; ?>
                    <?php // `data-text` is the order's IDENTITY — number, name,
                          // email. Matching the row's rendered text instead
                          // would make "pending" a search term hitting every
                          // pending row, which the status select already does
                          // properly. Same reasoning as the pages list. ?>
                    <div class="list__row"
                         data-status="<?= h((string) $o['status']) ?>"
                         data-text="<?= h(Order::number($o) . ' ' . (string) $o['billing_name'] . ' ' . (string) $o['email']) ?>"
                         data-row-href="<?= h(Url::admin('orders/' . $oid)) ?>">
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
    <?php endif; ?>
</div>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
