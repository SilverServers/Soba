<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Security\Csrf;
use Cms\Ui\Sidebar;

/**
 * THE TRASH — one screen, every Content Type, like the list it mirrors.
 *
 * ── IT IS THE ENTRY LIST, NOT A NEW SURFACE ─────────────────────────────────
 * Same `.list` / `.list__row` vocabulary, same `.entry-list` column grid, same
 * title-and-slug cell. The trash is not a different kind of place; it is the
 * same place with a different filter and two different verbs, and building it
 * out of new classes would be ~40 lines of CSS saying so in a different accent.
 * Reuse, do not re-implement.
 *
 * The status chip is deliberately ABSENT. Every row here is `trash` — a column
 * of five identical chips is a column that says nothing, and the screen's own
 * title already carries the fact.
 *
 * ── THE TWO VERBS ───────────────────────────────────────────────────────────
 *   Restore  reversible, ordinary, a plain `.btn`
 *   Delete   NOT reversible — the one thing on this screen that is — so it
 *            goes through the confirm dialog, which IS the alert surface.
 *            The trigger itself stays an uncoloured `.link .link--xs`: a
 *            destructive trigger does not need to shout.
 *
 * Both are POSTs, because both are writes. A restore behind an `<a>` is a link
 * a crawler or a prefetch can fire.
 *
 * ── WHAT THE EMPTY STATE SAYS ───────────────────────────────────────────────
 * "Nothing in the trash" is a GOOD state, not a failure, so it gets the bin
 * glyph and no call to action — there is deliberately no button here, because
 * the only thing you could offer is "go back", and the sidebar and the back
 * link both already do that.
 *
 * ── MANY AT ONCE ────────────────────────────────────────────────────────────
 * The trash is the entry list with Restore and Delete Permanently as the bulk
 * actions: the list's tick boxes, its select-all heading and its bar, through
 * the same `/bulk` route and the same
 * `entry-bulk.js`, which asks before a bulk delete exactly as the row's own
 * Delete does.
 *
 * @var array<string, mixed>       $contentType
 * @var list<array<string, mixed>> $posts        from Post::allTrashed()
 * @var array<string, mixed>|null  $bulkReport   what the last bulk action did
 */

$label       = (string) $contentType['label'];
$labelSingle = (string) $contentType['label_single'];
$adminSlug   = (string) $contentType['admin_slug'];

$pageTitle = $label . ' trash';

ob_start();
?>
<div class="page">

    <?php // ABOVE `.page__header`, not inside `.page__header-main` — that element
          // is a flex ROW, so inside it the link renders beside the title instead
          // of over it. See core.css §1. ?>
    <a class="page__back" href="<?= h(Url::admin($adminSlug)) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?><?= h($label) ?>
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Trash</h1>
        </div>
    </div>

    <?php if ($bulkReport !== null): ?>
        <?php $n = (int) ($bulkReport['done'] ?? 0); ?>
        <?php if ($n > 0): ?>
            <div class="flashes">
                <?php // RESTORED AS PRIVATE, AND IT SAYS SO. Post::restore() brings
                      // an entry back unpublished, so "restored" alone would read
                      // as "back on the site". ?>
                <div class="flash flash--success" data-bulk-done>
                    <?= $n ?> <?= $n === 1 ? 'entry' : 'entries' ?>
                    <?= ($bulkReport['action'] ?? '') === 'delete'
                        ? 'deleted for good.'
                        : 'restored, as private.' ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if (!empty($bulkReport['skipped'])): ?>
            <div class="alert alert--danger alert--compact" data-bulk-skipped>
                <?= icon('triangle-exclamation', 'alert__icon icon', '', 15) ?>
                <div>
                    <?php $s = count((array) $bulkReport['skipped']); ?>
                    <p class="alert__title"><?= $s ?> <?= $s === 1 ? 'entry' : 'entries' ?> not changed</p>
                    <?php foreach ((array) $bulkReport['skipped'] as $skip): ?>
                        <p class="alert__text">
                            <strong><?= h((string) ($skip['title'] ?? '')) ?></strong> —
                            <?= h((string) ($skip['reason'] ?? '')) ?>
                        </p>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($posts === []): ?>
        <div class="empty">
            <?= icon('trash-can', 'empty__glyph', '', 28) ?>
            <p class="empty__title">Nothing in the trash</p>
        </div>
    <?php else: ?>
        <?php // The rows' tick boxes join this form by `form=` — each row
              // already holds two forms, and HTML has no nested ones. ?>
        <form id="entry-bulk" method="post" action="<?= h(Url::admin($adminSlug . '/bulk')) ?>"
              data-entry-bulk data-label-one="entry" data-label-many="entries"
              data-ask-delete="Permanently delete {n}?"
              data-ask-delete-detail="{Its revision history and custom fields go with it. This one cannot|Their revision histories and custom fields go with them. These cannot} be undone."
              data-ask-delete-verb="Delete for good">
            <?= Csrf::field() ?>
            <input type="hidden" name="return" value="<?= h(Url::admin($adminSlug . '/trash')) ?>">
            <div class="bulk-bar">
                <select class="select select--sm" name="bulk_action" aria-label="Bulk action" data-bulk-action>
                    <option value="">Bulk actions</option>
                    <option value="restore">Restore</option>
                    <option value="delete">Delete permanently</option>
                </select>
                <button type="submit" class="btn btn--sm" data-bulk-apply disabled>Apply</button>
            </div>
        </form>

        <div class="list entry-list entry-list--trash entry-list--select">
            <?php // The list's heading row, for its select-all — nothing here
                  // sorts: the trash is ordered by when things arrived in it. ?>
            <div class="list__row entry-list__head">
                <label class="entry-list__check">
                    <input type="checkbox" data-bulk-all aria-label="Select everything in the trash">
                </label>
                <div class="entry-list__main">Title</div>
                <div class="entry-list__when">Trashed</div>
                <div aria-hidden="true"></div>
            </div>
            <?php foreach ($posts as $post): ?>
                <div class="list__row">
                    <label class="entry-list__check">
                        <input type="checkbox" name="ids[]" value="<?= (int) $post['id'] ?>" form="entry-bulk"
                               data-bulk-row data-title="<?= h((string) $post['title']) ?>"
                               aria-label="Select &ldquo;<?= h((string) $post['title']) ?>&rdquo;">
                    </label>
                    <div class="entry-list__main">
                        <?php // NOT a link. A trashed entry has no edit route
                              // that makes sense — restoring is the step before
                              // editing — so the title is text, and the row does
                              // not pretend to navigate. ?>
                        <span class="list__row-title"><?= h((string) $post['title']) ?></span>
                        <div class="list__row-sub">/<?= h((string) $post['slug']) ?></div>
                    </div>

                    <div class="list__row-meta">
                        <span class="entry-list__author"><?= h((string) $post['author_name']) ?></span>
                        <?php // WHEN IT WAS BINNED is the fact you want here —
                              // `updated_at` is set by the trash operation, and
                              // it is what tells you whether this is yesterday's
                              // mistake or last year's clear-out. ?>
                        <time datetime="<?= h(date('c', strtotime((string) $post['updated_at']))) ?>">
                            <?= h(Dates::short((string) $post['updated_at'])) ?>
                        </time>
                    </div>

                    <div class="list__row-actions">
                        <form class="form-inline" method="post"
                              action="<?= h(Url::admin($adminSlug . '/' . (int) $post['id'] . '/restore')) ?>">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--sm">Restore</button>
                        </form>

                        <form class="form-inline" method="post"
                              action="<?= h(Url::admin($adminSlug . '/' . (int) $post['id'] . '/delete')) ?>"
                              data-confirm="Permanently delete &ldquo;<?= h((string) $post['title']) ?>&rdquo;?"
                              data-confirm-detail="Its revision history and custom fields go with it. This one cannot be undone."
                              data-confirm-verb="Delete for good">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link link--xs">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<?php if ($posts !== []): ?>
    <script src="<?= h(Asset::admin('js/entry-bulk.js')) ?>"></script>
<?php endif; ?>
<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
