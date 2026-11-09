<?php

declare(strict_types=1);

use Cms\Security\Csrf;

/**
 * /account/enter/{token} — THE LINK FROM THE EMAIL, AND ONE BUTTON.
 *
 * Opening the link spends nothing: a mail scanner that opens it first is shown
 * this page and goes no further. The button spends it (`CustomerAccount`). A
 * link used already, or run out, says so and offers a new one.
 *
 * @var string $token
 * @var bool   $good
 */
?>
<div class="shop shop-account">
    <h1 class="shop__title">Sign in</h1><?php /* audit-ok: the name of the page, which the system draws */ ?>
    <?php if ($good): ?>
        <form class="shop-panel" method="post" action="<?= htmlspecialchars(\Cms\Helpers\Url::page('account/enter/' . $token)) ?>" data-account-enter><?php /* audit-ok: a route on this site */ ?>
            <?= Csrf::field() ?>
            <p>Your link is good. Sign in to see your orders.</p><?php /* audit-ok: the system describing the link's state */ ?>
            <button type="submit" class="shop-btn">Sign in</button><?php /* audit-ok: the button names what it does */ ?>
        </form>
    <?php else: ?>
        <div class="shop-panel" data-account-spent>
            <p>That link has been used, or has run out: each one works once, for half an hour.</p><?php /* audit-ok: the system describing the link's state */ ?>
            <a class="shop-btn" href="<?= htmlspecialchars(\Cms\Helpers\Url::page('account')) ?>">Ask for a new one</a><?php /* audit-ok: a route on this site, named by what it does */ ?>
        </div>
    <?php endif; ?>
</div>
