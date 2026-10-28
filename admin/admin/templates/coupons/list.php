<?php

declare(strict_types=1);

use Cms\Commerce\Coupon;
use Cms\Commerce\Money;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * DISCOUNT CODES.
 *
 * ── THE SAME LIST COMPONENT EVERY OTHER SCREEN USES ─────────────────────────
 * `.list .entry-list` with a four-track row: main, status, meta, actions. The
 * orders list has the note about why a fifth child cannot be added — it lands
 * in the actions track and prints over the button — and this screen obeys the
 * same shape rather than inventing one.
 *
 * ── WHAT A ROW HAS TO SAY WITHOUT BEING OPENED ──────────────────────────────
 * The code, what it takes off, and whether it still works. An owner looking at
 * this screen is answering "is SPRING20 still running?", and a list that makes
 * them open a row to find out has not answered it.
 *
 * `times_used` is on the row and nowhere editable: it is a record of what
 * happened rather than a setting, and it is the only measure an owner gets of
 * whether a campaign worked.
 *
 * @var list<array<string, mixed>> $coupons
 */

ob_start();
$today = date('Y-m-d');
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Discount codes</h1>
        </div>
        <div class="page__header-actions">
            <a class="btn btn--primary" href="<?= h(Url::admin('coupons/new')) ?>">
                <?= icon('plus', 'icon icon--sm') ?>New code
            </a>
        </div>
    </div>

    <?php if ($coupons === []): ?>
        <?php // A STATE, NOT A BROKEN SCREEN. It says what a code does and what
              // would put a row here, because "No discount codes" on its own
              // reads as something that failed to load. ?>
        <div class="empty">
            <?= icon('tag', 'empty__glyph', '', 28) ?>
            <p class="empty__title">No discount codes yet</p>
            <p class="empty__text">
                A code is something a customer types into their basket to take
                money off — a percentage or a fixed amount. You can limit how
                many times it is used, how much has to be in the basket, and
                when it stops working.
            </p>
        </div>
    <?php else: ?>
        <div class="list entry-list" id="coupon-list">
            <?php foreach ($coupons as $c): ?>
                <?php
                $cid     = (int) $c['id'];
                $limit   = (int) $c['usage_limit'];
                $used    = (int) $c['times_used'];
                $expires = (string) ($c['expires_at'] ?? '');

                // ── WHY IT IS NOT WORKING, IN ONE WORD ──────────────────────
                // The row has to answer "is this still running?" without being
                // opened. `Coupon::refusalFor()` is the same function the
                // checkout uses, asked with a basket big enough that only the
                // reasons INDEPENDENT of the basket can come back — an owner
                // does not want their code marked "too small" against a
                // hypothetical shopper.
                $state = Coupon::refusalFor($c, PHP_INT_MAX, $today);
                $live  = $state === '';
                $why   = match ($state) {
                    Coupon::INACTIVE => 'Off',
                    Coupon::EXPIRED  => 'Expired',
                    Coupon::SPENT    => 'Used up',
                    default          => 'Live',
                };
                ?>
                <div class="list__row"
                     data-row-href="<?= h(Url::admin('coupons/' . $cid . '/edit')) ?>">
                    <div class="entry-list__main">
                        <a class="list__row-link list__row-title"
                           href="<?= h(Url::admin('coupons/' . $cid . '/edit')) ?>">
                            <?= h((string) $c['code']) ?>
                        </a>
                        <div class="list__row-sub">
                            <?= h(Coupon::describe((string) $c['kind'], (int) $c['amount'])) ?>
                            <?php if ((int) $c['min_spend'] > 0): ?>
                                · over <?= h(Money::format((int) $c['min_spend'])) ?>
                            <?php endif; ?>
                            <?php if ($expires !== ''): ?>
                                · until <?= h($expires) ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php // THE `.status` CHIP, WHICH IS ONE CHIP BUILT ON A
                          // GLYPH — `Ui\Status`'s header: never a per-state hue,
                          // because amber and red are plausible accent colours
                          // and the moment a theme's accent is orange the alarm
                          // stops meaning anything. Live is the actionable
                          // state and wears the accent; the three ways of being
                          // off are all muted, and the WORD says which. ?>
                    <div>
                        <span class="status <?= $live ? 'status--active' : 'status--archived' ?>">
                            <?= icon($live ? 'check' : 'lock', 'status__icon icon', '', 12) ?>
                            <?= h($why) ?>
                        </span>
                    </div>

                    <div class="list__row-meta">
                        <strong><?= $used ?><?= $limit > 0 ? ' / ' . $limit : '' ?></strong>
                        <span><?= $limit > 0 ? 'used' : ($used === 1 ? 'use' : 'uses') ?></span>
                    </div>

                    <div class="list__row-actions">
                        <a class="btn btn--sm"
                           href="<?= h(Url::admin('coupons/' . $cid . '/edit')) ?>">Edit</a>
                        <?php // A FORM, AND A TEXT LINK INSIDE IT — the shape
                              // `pages/list.php` uses for Trash, down to the
                              // classes. A GET that destroys is a row a
                              // link-prefetcher can remove, so it posts; and
                              // the destructive control is a plain text link
                              // rather than a red button, as everywhere else
                              // in this admin. ?>
                        <form class="form-inline" method="post"
                              action="<?= h(Url::admin('coupons/' . $cid . '/delete')) ?>"
                              data-confirm="Delete <?= h((string) $c['code']) ?>?"
                              data-confirm-detail="Orders that used it keep their record of it. To stop accepting it without deleting it, untick &ldquo;Accept this code&rdquo; instead."
                              data-confirm-verb="Delete">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link link--xs">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
