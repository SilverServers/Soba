<?php

declare(strict_types=1);

use Cms\Auth\Auth;
use Cms\Forms\Guard;
use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * SPAM — what the forms turned away and KEPT, and what they discarded.
 *
 * Keeping everything a form turns away, and reading all of it at once, does
 * not survive a vulnerability scanner, which can send hundreds of thousands of
 * posts in a day: the view never loads. So:
 *
 *   KEPT       the suspicious — a stale tab, a double click, a busy address,
 *              too many links (`Forms\Guard`). A page at a time, with bulk Not
 *              spam and Delete permanently, and Empty spam for the lot. "Not
 *              spam" puts a submission back among the received, unread.
 *   DISCARDED  the certain — a decoy filled, a post that never loaded the
 *              form, a post from another site, a replay, a flood, a post sent
 *              within 3 seconds, an address that cannot receive mail, a known
 *              bot's signature. Counted by reason, here, and stored nowhere.
 *              "What is kept" can keep them too, for review with the rest.
 *
 * Nothing here was emailed, and none of it counts as unread. Spam older than
 * "Days to keep spam" is deleted a batch at a time, when a form is sent and
 * each time this screen opens.
 *
 * @var array<string, mixed> $list       from `FormController::listPage()`
 * @var array<string, int>   $discarded  reason code => posts, the last 30 days
 * @var int                  $spamDays
 * @var bool                 $keepAll
 * @var bool                 $saved
 * @var array<string, mixed> $user
 */

$pageTitle = 'Spam';
$total     = (int) $list['total'];

ob_start();
?>
<div class="page">

    <a class="page__back" href="<?= h(Url::admin('forms')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Forms
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Spam</h1>
        </div>

        <?php if ($total > 0): ?>
            <div class="page__header-actions">
                <?php // EMPTY SPAM: every row in the list, not just the page —
                      // the bulk bar is for the page. It asks, with
                      // the number, because there is no undo. ?>
                <form method="post" action="<?= h(Url::admin('forms/spam/empty')) ?>"
                      data-confirm="Delete all <?= h(number_format($total)) ?> spam <?= $total === 1 ? 'submission' : 'submissions' ?>?"
                      data-confirm-detail="What the senders typed is removed permanently."
                      data-confirm-verb="Empty spam">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn">Empty spam</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($saved): ?>
        <div class="flashes">
            <div class="flash flash--success">Settings saved.</div>
        </div>
    <?php endif; ?>

    <?php
    $emptyTitle = 'Nothing kept as spam';
    $emptyGlyph = 'shield-halved';
    require __DIR__ . '/submission-list.php';
    ?>

    <?php // ONE WRAPPER FOR BOTH CARDS, so `.form-card + .form-card` spaces
          // them as on every settings screen: the settings form for an admin,
          // a plain column for anyone else, who reads the counts but cannot
          // change what is kept. ?>
    <?php $canSet = Auth::hasRole('admin'); ?>
    <?php if ($canSet): ?>
        <form class="form-main" method="post" action="<?= h(Url::admin('forms/spam/settings')) ?>">
            <?= Csrf::field() ?>
    <?php else: ?>
        <div class="form-main">
    <?php endif; ?>
        <div class="form-card">
            <div class="form-card__section">
                <?= panel_title('Discarded in the last 30 days', 'discarded') ?>
                <div class="kv-list" data-discarded>
                    <?php if ($discarded === []): ?>
                        <div class="kv-list__row">
                            <span class="kv-list__key">Nothing</span>
                            <span class="kv-list__val">0</span>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($discarded as $code => $hits): ?>
                        <div class="kv-list__row">
                            <span class="kv-list__key"><?= h(ucfirst(Guard::DISCARDED[$code] ?? str_replace('_', ' ', (string) $code))) ?></span>
                            <span class="kv-list__val"><?= h(number_format((int) $hits)) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php if ($canSet): ?>
        <div class="form-card">
            <div class="form-card__section">
                <?= panel_title('What is kept', 'settings') ?>
                <?php // HOW STRICT, as two choices. Certain spam is discarded
                      // unless the owner says otherwise; the suspicious is
                      // always kept. ?>
                <div class="field">
                    <span class="label">Certain spam</span>
                    <div class="check-list">
                        <label class="check-list__row">
                            <input type="radio" name="spam_keep" value="0"<?= $keepAll ? '' : ' checked' ?>>
                            <span class="check-list__name">
                                <?= icon('trash-can', 'check-list__glyph icon') ?>Discard it and count it, so I never see it
                            </span>
                        </label>
                        <label class="check-list__row">
                            <input type="radio" name="spam_keep" value="1"<?= $keepAll ? ' checked' : '' ?>>
                            <span class="check-list__name">
                                <?= icon('box-open', 'check-list__glyph icon') ?>Keep it here for review, like the rest
                            </span>
                        </label>
                    </div>
                </div>
                <div class="field">
                    <label class="label" for="f-spam-days">Days to keep spam</label>
                    <input class="input input--count" type="number" min="1" max="3650" id="f-spam-days" name="spam_days"
                           value="<?= (int) $spamDays ?>">
                </div>
            </div>
            <div class="form-card__section form-actions">
                <div class="form-actions__spacer"></div>
                <button type="submit" class="btn btn--primary">Save</button>
            </div>
        </div>
        <?php endif; ?>
    <?php if ($canSet): ?>
        </form>
    <?php else: ?>
        </div>
    <?php endif; ?>

</div>

<?php if ($total > 0): ?>
    <script src="<?= h(Asset::admin('js/entry-bulk.js')) ?>"></script>
<?php endif; ?>
<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
