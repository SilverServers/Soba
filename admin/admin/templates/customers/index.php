<?php

declare(strict_types=1);

use Cms\Commerce\Customers;
use Cms\Commerce\Money;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;

/**
 * SHOP → CUSTOMERS.
 *
 * Everyone who has ordered, one row each (`Commerce\Customers`): their name and
 * how to reach them, where they are, when they last ordered, how many orders
 * and what they have spent. A row opens the customer.
 *
 * ── THE FILTER IS A ROUND-TRIP ──────────────────────────────────────────────
 * `.filterbar--apply`, as the entry list's: a GET form with labelled controls
 * and a Filter button, so a search or a sort is an address that can be sent on.
 *
 * @var list<array<string, mixed>> $customers
 * @var string                     $search
 * @var string                     $sort
 */

$count  = count($customers);
$agreed = count(array_filter($customers, static fn (array $c): bool => $c['opt_in']));

ob_start();
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Customers</h1>
            <?php if ($count > 0 && $search === ''): ?>
                <?php // HOW MANY, AND HOW MANY MAY BE WRITTEN TO: the second is
                      // the figure an owner asks before sending a newsletter. ?>
                <p class="page__subtitle">
                    <?= $count ?> <?= $count === 1 ? 'customer' : 'customers' ?><?php
                    if ($agreed > 0): ?> · <?= $agreed ?> agreed to marketing email<?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (($erased ?? null) !== null): ?>
        <div class="flash flash--success" data-customer-erased>
            Their personal details are erased from <?= (int) $erased ?> <?= (int) $erased === 1 ? 'order' : 'orders' ?>; what was sold, and for how much, is kept.
        </div>
    <?php endif; ?>

    <?php if ($count === 0 && $search === ''): ?>
        <div class="empty">
            <?= icon('user', 'empty__glyph', '', 28) ?>
            <p class="empty__title">No customers yet</p>
            <p class="empty__text">
                A customer appears here with their first order: everyone who has
                ordered with the same email address is one customer.
            </p>
        </div>
    <?php else: ?>
        <form class="filterbar filterbar--apply" method="get" action="<?= h(Url::admin('customers')) ?>">
            <div class="filterbar__group">
                <label class="filterbar__field">
                    <span class="filterbar__label">Search</span>
                    <input class="input input--search" type="search" name="s" value="<?= h($search) ?>"
                           placeholder="Name, email or telephone">
                </label>
                <label class="filterbar__field">
                    <span class="filterbar__label">Sort by</span>
                    <select class="select" name="sort">
                        <?php foreach (Customers::SORTS as $key => $words): ?>
                            <option value="<?= h($key) ?>"<?= $sort === $key ? ' selected' : '' ?>><?= h($words) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="filterbar__actions">
                <?php if ($search !== ''): ?>
                    <span class="filterbar__label"><?= $count ?> found</span>
                    <a class="filterbar__reset" href="<?= h(Url::admin('customers') . ($sort !== 'last' ? '?sort=' . rawurlencode($sort) : '')) ?>">Clear</a>
                <?php endif; ?>
                <button type="submit" class="btn">Filter</button>
            </div>
        </form>

        <?php if ($customers === []): ?>
            <div class="empty" data-filter-empty>
                <?= icon('magnifying-glass', 'empty__glyph', '', 28) ?>
                <p class="empty__title">No customer matches that search</p>
            </div>
        <?php else: ?>
            <div class="list entry-list customer-list" id="customer-list">
                <?php foreach ($customers as $c): ?>
                    <?php $href = Url::admin('customers/' . $c['key']); ?>
                    <div class="list__row" data-customer="<?= h($c['email']) ?>" data-row-href="<?= h($href) ?>">
                        <div class="entry-list__main">
                            <a class="list__row-link list__row-title" href="<?= h($href) ?>">
                                <?= h($c['name'] !== '' ? $c['name'] : $c['email']) ?>
                            </a>
                            <div class="list__row-sub">
                                <?= h(implode(' · ', array_filter([$c['email'], $c['phone'], $c['place'],
                                    'last ordered ' . Dates::short($c['last_at'])]))) ?>
                            </div>
                        </div>
                        <div class="list__row-meta">
                            <strong><?= h(Money::format($c['spent'])) ?></strong>
                            <span><?= $c['orders'] ?> <?= $c['orders'] === 1 ? 'order' : 'orders' ?></span>
                        </div>
                        <div class="list__row-actions">
                            <a class="btn btn--sm" href="<?= h($href) ?>">Open</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
