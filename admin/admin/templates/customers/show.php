<?php

declare(strict_types=1);

use Cms\Commerce\Money;
use Cms\Helpers\Asset;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Security\Csrf;
use Cms\Models\Order;
use Cms\Ui\OrderStatus;

/**
 * ONE CUSTOMER: every order they have placed, the addresses they used, and how
 * to reach them (`Commerce\Customers::find()`).
 *
 * The order page's layout, document and aside (`.panel__grid`): what they
 * bought on the left, who they are on the right. Each order opens its own
 * screen, where it is changed.
 *
 * @var array<string, mixed> $customer
 */

$c = $customer;

ob_start();
?>
<div class="page">
    <a class="page__back" href="<?= h(Url::admin('customers')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Customers
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title"><?= h($c['name'] !== '' ? $c['name'] : $c['email']) ?></h1>
            <p class="page__subtitle">
                Customer since <?= h(Dates::short($c['first_at'])) ?> ·
                <?= $c['orders'] ?> <?= $c['orders'] === 1 ? 'order' : 'orders' ?> ·
                <?= h(Money::format($c['spent'])) ?> spent
            </p>
        </div>
    </div>

    <div class="panel__grid">
        <div class="form-main">
            <div class="form-card">
                <div class="form-card__section">
                    <?= panel_title('Orders', 'orders') ?>
                    <?php // `--bleed`: the list is the whole of its section, so
                          // it reaches the card's edges rather than drawing a
                          // second border a few pixels inside the first. ?>
                    <div class="list list--bleed entry-list customer-orders" data-customer-orders>
                        <?php foreach ($c['list'] as $o): ?>
                            <?php $href = Url::admin('orders/' . (int) $o['id']); ?>
                            <div class="list__row" data-row-href="<?= h($href) ?>">
                                <div class="entry-list__main">
                                    <a class="list__row-link list__row-title" href="<?= h($href) ?>"><?= h(Order::number($o)) ?></a>
                                    <div class="list__row-sub">
                                        <?= h(Dates::stamp((string) $o['created_at'])) ?> ·
                                        <?= (int) $o['item_count'] ?> <?= (int) $o['item_count'] === 1 ? 'item' : 'items' ?>
                                    </div>
                                </div>
                                <div><?= OrderStatus::render((string) $o['status']) ?></div>
                                <div class="list__row-meta">
                                    <strong><?= h(Money::format((int) $o['total'], (string) $o['currency'])) ?></strong>
                                    <?php if ((int) $o['refunded'] > 0): ?>
                                        <span><?= h(Money::format((int) $o['refunded'], (string) $o['currency'])) ?> refunded</span>
                                    <?php endif; ?>
                                </div>
                                <div class="list__row-actions">
                                    <a class="btn btn--sm" href="<?= h($href) ?>">Open</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($c['places'] !== []): ?>
                    <div class="form-card__section">
                        <?= panel_title('Addresses they have used', 'customer') ?>
                        <div class="order-addresses customer-places" data-customer-places>
                            <?php foreach ($c['places'] as $lines): ?>
                                <p><?= implode('<br>', array_map('h', $lines)) ?></p>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-aside">
            <div class="form-card">
                <div class="form-card__section">
                    <?= panel_title('Contact', 'customer-mail') ?>
                    <div class="kv-list" data-customer-contact>
                        <div class="kv-list__row">
                            <span class="kv-list__key">Email</span>
                            <span class="kv-list__val"><a class="link" href="mailto:<?= h($c['email']) ?>"><?= h($c['email']) ?></a></span>
                        </div>
                        <div class="kv-list__row">
                            <span class="kv-list__key">Telephone</span>
                            <span class="kv-list__val<?= $c['phone'] === '' ? ' kv-list__val--muted' : '' ?>">
                                <?php if ($c['phone'] !== ''): ?>
                                    <a class="link" href="tel:<?= h($c['phone']) ?>"><?= h($c['phone']) ?></a>
                                <?php else: ?>
                                    Not given
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="kv-list__row">
                            <span class="kv-list__key">Where</span>
                            <span class="kv-list__val<?= $c['place'] === '' ? ' kv-list__val--muted' : '' ?>"><?= h($c['place'] !== '' ? $c['place'] : 'Not given') ?></span>
                        </div>
                        <div class="kv-list__row">
                            <?php // AS THEY LAST ANSWERED at a checkout: the
                                  // newest answer is the one that stands. ?>
                            <span class="kv-list__key">Marketing email</span>
                            <span class="kv-list__val"><?= $c['opt_in'] ? 'Agreed' : 'Not agreed' ?></span>
                        </div>
                    </div>
                </div>
                <div class="form-card__section">
                    <?= panel_title('Spending', 'payment') ?>
                    <div class="kv-list" data-customer-spending>
                        <div class="kv-list__row">
                            <span class="kv-list__key">Orders</span>
                            <span class="kv-list__val"><?= $c['orders'] ?></span>
                        </div>
                        <div class="kv-list__row">
                            <span class="kv-list__key">Spent</span>
                            <span class="kv-list__val"><?= h(Money::format($c['spent'])) ?></span>
                        </div>
                        <div class="kv-list__row">
                            <span class="kv-list__key">First order</span>
                            <span class="kv-list__val"><?= h(Dates::short($c['first_at'])) ?></span>
                        </div>
                        <div class="kv-list__row">
                            <span class="kv-list__key">Last order</span>
                            <span class="kv-list__val"><?= h(Dates::short($c['last_at'])) ?></span>
                        </div>
                    </div>
                    <p class="form-help">Paid orders, less what was refunded: an order waiting for its money, cancelled or failed is not spending.</p>
                </div>
                <div class="form-card__section" data-customer-privacy>
                    <?= panel_title('Their data', 'customer') ?>
                    <p class="form-help">What a customer may ask to see, or to have erased.</p>
                    <a class="link" href="<?= h(Url::admin('customers/' . $c['key'] . '/data')) ?>" download data-customer-download>Download everything kept about them</a>
                </div>
                <?php // THE ERASE IS A QUIET LINK in the card's last section, as
                      // Delete order is: the confirm dialog it opens is the
                      // alert surface, and says what goes and what stays. ?>
                <div class="form-card__section form-aside__danger">
                    <button type="submit" class="link link--xs" form="erase-customer">Erase their personal details</button>
                </div>
            </div>
            <form method="post" id="erase-customer" action="<?= h(Url::admin('customers/' . $c['key'] . '/erase')) ?>"
                  data-confirm="Erase <?= h($c['name'] !== '' ? $c['name'] : $c['email']) ?>'s personal details?"
                  data-confirm-detail="Their name, email, telephone, addresses and notes come off all <?= $c['orders'] ?> of their orders, and they are taken off any waiting list. What was sold, and for how much, is kept for the books. This cannot be undone."
                  data-confirm-verb="Erase them">
                <?= Csrf::field() ?>
            </form>
        </div>
    </div>
</div>
<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
