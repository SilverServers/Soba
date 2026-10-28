<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * FORMS — the list.
 *
 * ── THE UNREAD COUNT IS THE ROW'S POINT ─────────────────────────────────────
 * `Form::all()` already carries `submission_count` and `unread_count` as
 * sub-selects, so the counts cost nothing extra and no template needs to query
 * per row. A site owner opens this screen to answer one question — has anyone
 * filled anything in — and the answer belongs on the row, not one click deeper.
 *
 * Unread is shown ONLY when it is non-zero. A column of "0 new" reads as a
 * status the owner has to scan and dismiss; absence is the quieter true thing.
 *
 * ── THE SUBMISSIONS LINK IS ON THE COUNT ────────────────────────────────────
 * The row title opens the form's DEFINITION (its fields and notification
 * settings). The count opens its SUBMISSIONS. Those are two different places
 * and the row says which is which by what you click, rather than by a pair of
 * identically-weighted buttons in an actions column.
 *
 * @var list<array<string, mixed>> $forms  id, title, submission_count, unread_count
 * @var array<string, mixed>       $user
 */

$pageTitle = 'Forms';

// THIS TEMPLATE OWNS ITS WRAPPER. `FormController::index()` requires it
// directly — no `ob_start()`, no `layout.php` — unlike the settings/* fragments,
// where the controller does both. The two conventions coexist in this tree and
// the only way to know which applies is to read the call site.
ob_start();
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Forms</h1>
        </div>

        <div class="page__header-actions">
            <?php if ($forms !== []): ?>
                <a class="btn" href="<?= h(Url::admin('forms/submissions')) ?>">All submissions</a>
                <a class="btn btn--primary" href="<?= h(Url::admin('forms/new')) ?>">Add form</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($forms === []): ?>
        <div class="empty">
            <?= icon('list-check', 'empty__glyph', '', 28) ?>
            <p class="empty__title">No forms yet</p>
            <div class="empty__action">
                <a class="btn btn--primary" href="<?= h(Url::admin('forms/new')) ?>">Add form</a>
            </div>
        </div>
    <?php else: ?>
        <div class="list entry-list user-list">
            <?php foreach ($forms as $form): ?>
                <?php
                $formId  = (int) $form['id'];
                $total   = (int) $form['submission_count'];
                $unread  = (int) $form['unread_count'];
                ?>
                <div class="list__row">
                    <div class="entry-list__main">
                        <a class="list__row-link list__row-title"
                           href="<?= h(Url::admin('forms/' . $formId . '/edit')) ?>"><?= h((string) $form['title']) ?></a>
                        <div class="list__row-sub">
                            Updated <?= h(Dates::short((string) $form['updated_at'])) ?>
                        </div>
                        <?php // WHERE IT IS SHOWN: the
                              // page that draws it. The entry list's relationship
                              // line — an icon, then what it is tied to — because
                              // that is what this is. ?>
                        <?php if (($placesList ?? null) !== null && ($placeKey = $form['place_key'] ?? null) !== null): ?>
                            <?php $place = $placesList[$placeKey] ?? null; ?>
                            <div class="list__row-sub entry-list__parent">
                                <?php if ($place === null): ?>
                                    <?= icon('triangle-exclamation', 'icon icon--sm') ?>Shown on: “<?= h((string) $placeKey) ?>”, no longer on the list
                                <?php else: ?>
                                    <?= icon('up-right-from-square', 'icon icon--sm') ?>Shown on:
                                    <?php if (!empty($place['url'])): ?>
                                        <a href="<?= h((string) $place['url']) ?>" target="_blank" rel="noopener"><?= h((string) $place['name']) ?></a>
                                    <?php else: ?>
                                        <?= h((string) $place['name']) ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php // AN ENVELOPE, AND WHETHER IT IS SEALED. The one fact
                          // you scan a forms list for — has anything come in
                          // that nobody has read — is a mark on the row, not
                          // three words of grey text.
                          //
                          // Solid for waiting, outline for read, nothing at all
                          // for a form nobody has ever submitted. Three states,
                          // three shapes, no colour: admin.css §4 already rules
                          // that unread is weight and not colour, because
                          // `--danger` is a surface and an unopened message is
                          // not a fault. The same reasoning forbids a red dot
                          // here, and the sealed envelope says it without one.
                          //
                          // `envelope` is the sidebar's own mark for Submissions,
                          // so the row and the nav agree. ?>
                    <div class="list__row-meta">
                        <?php if ($total === 0): ?>
                            <span class="list__row-sub">No submissions</span>
                        <?php else: ?>
                            <a href="<?= h(Url::admin('forms/' . $formId . '/submissions')) ?>">
                                <?= icon($unread > 0 ? 'envelope-solid' : 'envelope',
                                         'icon icon--sm' . ($unread > 0 ? ' is-unread' : '')) ?>
                                <?= $total ?> submission<?= $total === 1 ? '' : 's' ?>
                            </a>
                            <?php if ($unread > 0): ?>
                                <span class="list__row-sub"><?= $unread ?> unread</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <div class="list__row-actions">
                        <form method="post"
                              action="<?= h(Url::admin('forms/' . $formId . '/delete')) ?>"
                              data-confirm="Delete the <?= h((string) $form['title']) ?> form?"
                              data-confirm-detail="<?= $total === 0
                                  ? 'It has no submissions.'
                                  : 'Its ' . $total . ' submission' . ($total === 1 ? '' : 's') . ' will be deleted with it.' ?>"
                              data-confirm-verb="Delete form">
                            <?= Csrf::field() ?>
                            <button type="submit" class="icon-btn"
                                    data-tip="Delete form" aria-label="Delete form">
                                <?= icon('trash-can', 'icon') ?>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
