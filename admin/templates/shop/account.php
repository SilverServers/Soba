<?php

declare(strict_types=1);

use Cms\Commerce\Countries;
use Cms\Commerce\Money;
use Cms\Models\Order;
use Cms\Security\Csrf;

/**
 * /account — A CUSTOMER'S ORDERS, OR THE WAY IN (`Commerce\CustomerAccount`).
 *
 * Signed out: one field, their email address, and a button that sends a link
 * to it. Signed in: every order they have placed, each opening the page its
 * confirmation email links to, and the addresses their newest order went to,
 * which the next checkout starts from.
 *
 * Every sentence here is the system describing its own state or labelling its
 * own parts, and is marked so at its line.
 *
 * @var string|null                $email
 * @var list<array<string, mixed>> $orders
 * @var array<string, string>      $last
 */

$address = static function (array $a, string $prefix): array {
    return array_values(array_filter([
        $a[$prefix . '_name'] ?? '',
        $a[$prefix . '_line1'] ?? '',
        $a[$prefix . '_line2'] ?? '',
        trim(($a[$prefix . '_city'] ?? '') . ' ' . ($a[$prefix . '_region'] ?? '')),
        $a[$prefix . '_post'] ?? '',
        ($a[$prefix . '_country'] ?? '') !== '' ? Countries::name((string) $a[$prefix . '_country']) : '',
    ], static fn (string $s): bool => trim($s) !== ''));
};
?>
<div class="shop shop-account">
    <h1 class="shop__title">Your orders</h1><?php /* audit-ok: the name of the page, which the system draws */ ?>

    <?php if ($email === null): ?>
        <p class="shop__lede">
            Type the email address you ordered with, and a link to sign in is sent to it. There is no password to remember.<?php /* audit-ok: the system saying how its own sign-in works */ ?>
        </p>
        <form class="shop-panel shop-account__ask" method="post" action="<?= htmlspecialchars(\Cms\Helpers\Url::page('account')) ?>" data-account-ask><?php /* audit-ok: a route on this site */ ?>
            <?= Csrf::field() ?>
            <div class="shop-field">
                <label class="shop-field__label" for="account-email">Email address</label><?php /* audit-ok: a field's label */ ?>
                <input class="shop-field__input" type="email" id="account-email" name="email" required autocomplete="email">
            </div>
            <button type="submit" class="shop-btn">Email me a link</button><?php /* audit-ok: the button names what it does */ ?>
        </form>
    <?php else: ?>
        <p class="shop__lede">
            Signed in as <strong><?= htmlspecialchars($email) ?></strong>.<?php /* audit-ok: half a sentence around the customer's address */ ?>
        </p>

        <section class="shop-panel" data-account-orders>
            <h2 class="shop-panel__title">Orders</h2><?php /* audit-ok: a section heading */ ?>
            <?php if ($orders === []): ?>
                <p>No orders with this address.</p><?php /* audit-ok: the system describing its own state */ ?>
            <?php else: ?>
                <table class="shop-table">
                    <thead>
                        <tr>
                            <th scope="col">Order</th><?php /* audit-ok: a column header */ ?>
                            <th scope="col">Date</th><?php /* audit-ok: a column header */ ?>
                            <th scope="col">Status</th><?php /* audit-ok: a column header */ ?>
                            <th scope="col" class="shop-table__num">Total</th><?php /* audit-ok: a column header */ ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o): ?>
                            <tr>
                                <th scope="row" class="shop-table__name">
                                    <a href="<?= htmlspecialchars(\Cms\Helpers\Url::page('order/' . (string) $o['order_key'])) ?>"><?= htmlspecialchars(Order::number($o)) ?></a><?php /* audit-ok: a route on this site, named by its number */ ?>
                                </th>
                                <td><?= htmlspecialchars(\Cms\Helpers\Dates::short((string) $o['created_at'])) ?></td>
                                <td><?= htmlspecialchars(Order::statusLabel((string) $o['status'])) ?></td>
                                <td class="shop-table__num"><?= htmlspecialchars(Money::format((int) $o['total'], (string) $o['currency'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <?php $billing = $address($last, 'billing'); $shipping = $address($last, 'shipping'); ?>
        <?php if ($billing !== [] || $shipping !== []): ?>
            <section class="shop-panel" data-account-addresses>
                <h2 class="shop-panel__title">Your addresses</h2><?php /* audit-ok: a section heading */ ?>
                <p class="shop-account__note">From your newest order: the next checkout starts with them.</p><?php /* audit-ok: the system describing what it does with them */ ?>
                <div class="shop-account__places">
                    <?php foreach (['Billing' => $billing, 'Delivery' => $shipping] as $title => $lines): ?>
                        <?php if ($lines !== []): ?>
                            <div>
                                <h3 class="shop-account__place-title"><?= htmlspecialchars($title) ?></h3><?php /* audit-ok: which address this is */ ?>
                                <address class="shop-address"><?= implode('<br>', array_map('htmlspecialchars', $lines)) ?></address>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars(\Cms\Helpers\Url::page('account/leave')) ?>" data-account-leave><?php /* audit-ok: a route on this site */ ?>
            <?= Csrf::field() ?>
            <button type="submit" class="shop-btn shop-btn--quiet">Sign out</button><?php /* audit-ok: the button names what it does */ ?>
        </form>
    <?php endif; ?>
</div>
