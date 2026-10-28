<?php

declare(strict_types=1);

use Cms\Forms\Preview;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Models\FormSubmission;
use Cms\Security\Csrf;

/**
 * A SUBMISSIONS LIST — one form's, every form's, or Spam — with its tick boxes,
 * its bulk bar and its pager. Included by the three screens, so the three lists
 * cannot drift apart.
 *
 * ── IT IS THE ENTRY LIST ────────────────────────────────────────────────────
 * Same `.entry-list--select` grid, same heading row, same bulk form and the
 * same `entry-bulk.js`: the tick box's target is its whole cell, the heading's
 * box ticks the page and shows "some" as its own state, Apply waits for an
 * action and a selection, and Delete asks first through `confirm.js` with the
 * count in the question. Reuse, do not re-implement.
 *
 * The row is WHO SENT IT (`Forms\Preview`), with what the list is scanned for
 * under it — the page it came from, or why it was turned away — the form in the
 * status slot when the list holds more than one form, and when it arrived where
 * the entry list puts its date.
 *
 * ── A PAGE AT A TIME ────────────────────────────────────────────────────────
 * `FormController::listPage()` reads one page; the pager under the list is the
 * entry list's, plus a "Number of items per page" box. Nothing here ever holds
 * more than a page of rows.
 *
 * @var array<string, mixed> $list        from `FormController::listPage()`
 * @var string               $emptyTitle  what an empty list says
 * @var string               $emptyGlyph  and its mark
 */

$isSpam   = $list['status'] === FormSubmission::SPAM;
$formId   = $list['formId'];
$manyForm = $formId === null;
$listUrl  = static fn (int $page): string => Url::admin((string) $list['path']) . ($page > 1 ? '?page=' . $page : '');
$noun     = static fn (int $n): string => $n . ' ' . ($n === 1 ? 'submission' : 'submissions');
$report   = $list['report'];
?>

<?php if ($report !== null): ?>
    <?php $done = (int) ($report['done'] ?? 0); ?>
    <?php if ($done > 0): ?>
        <div class="flashes">
            <div class="flash flash--success" data-bulk-done>
                <?= h(match ((string) ($report['action'] ?? '')) {
                    'spam'     => $noun($done) . ' marked as spam.',
                    'read'     => $noun($done) . ' marked as read.',
                    'unread'   => $noun($done) . ' marked as unread.',
                    'not_spam' => $noun($done) . ' put back among the submissions, unread.',
                    'empty'    => 'Spam emptied: ' . $noun($done) . ' deleted.',
                    default    => $noun($done) . ($isSpam ? ' deleted for good.' : ' deleted.'),
                }) ?>
            </div>
        </div>
    <?php endif; ?>
    <?php if ((int) ($report['left'] ?? 0) > 0): ?>
        <?php // A PILE TOO BIG FOR ONE REQUEST says what is left, and the same
              // button takes the next batch. ?>
        <div class="alert alert--danger alert--compact" data-bulk-left>
            <?= icon('triangle-exclamation', 'alert__icon icon', '', 15) ?>
            <div>
                <p class="alert__title"><?= h($noun((int) $report['left'])) ?> still in spam</p>
                <p class="alert__text">Empty spam again to delete the rest.</p>
            </div>
        </div>
    <?php endif; ?>
    <?php if ((int) ($report['skipped'] ?? 0) > 0): ?>
        <div class="alert alert--danger alert--compact" data-bulk-skipped>
            <?= icon('triangle-exclamation', 'alert__icon icon', '', 15) ?>
            <div>
                <p class="alert__title"><?= h($noun((int) $report['skipped'])) ?> not changed</p>
                <p class="alert__text">No longer in this list.</p>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ((int) $list['total'] === 0): ?>
    <div class="empty">
        <?= icon($emptyGlyph, 'empty__glyph', '', 28) ?>
        <p class="empty__title"><?= h($emptyTitle) ?></p>
    </div>
<?php return; endif; ?>

<?php // ── THE BULK FORM ─────────────────────────────────────────────────
      // The rows sit OUTSIDE it and their tick boxes join it through
      // `form="submission-bulk"`: every row holds its own Delete form, and HTML
      // has no nested forms. `status` and `form` say which list this is; the
      // server acts only on ticked rows that are still in it. ?>
<form id="submission-bulk" method="post" action="<?= h(Url::admin('forms/submissions/bulk')) ?>"
      data-entry-bulk data-label-one="submission" data-label-many="submissions"
      data-ask-delete="<?= $isSpam ? 'Permanently delete {n}?' : 'Delete {n}?' ?>"
      data-ask-delete-detail="{What the sender typed is|What the senders typed is} removed permanently."
      data-ask-delete-verb="<?= $isSpam ? 'Delete for good' : 'Delete' ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="status" value="<?= h((string) $list['status']) ?>">
    <?php if ($formId !== null): ?>
        <input type="hidden" name="form" value="<?= (int) $formId ?>">
    <?php endif; ?>
    <?php // Back to THIS page afterwards, not page 1 of the list. ?>
    <input type="hidden" name="return" value="<?= h($listUrl((int) $list['page'])) ?>">

    <div class="bulk-bar">
        <select class="select select--sm" name="bulk_action" aria-label="Bulk action" data-bulk-action>
            <option value="">Bulk actions</option>
            <?php if ($isSpam): ?>
                <option value="not_spam">Not spam</option>
                <option value="delete">Delete permanently</option>
            <?php else: ?>
                <option value="spam">Mark as spam</option>
                <option value="read">Mark as read</option>
                <option value="unread">Mark as unread</option>
                <option value="delete">Delete</option>
            <?php endif; ?>
        </select>
        <button type="submit" class="btn btn--sm" data-bulk-apply disabled>Apply</button>
    </div>
</form>

<div class="list entry-list entry-list--select" id="submission-list">
    <?php // THE HEADING ROW — the entry list's: a `.list__row` itself, so each
          // label sits on the grid of the cells it names. ?>
    <div class="list__row entry-list__head">
        <label class="entry-list__check">
            <input type="checkbox" data-bulk-all aria-label="Select every submission on this page">
        </label>
        <div class="entry-list__main">From</div>
        <?php if ($manyForm): ?>
            <div>Form</div>
        <?php else: ?>
            <div aria-hidden="true"></div>
        <?php endif; ?>
        <div class="entry-list__when">Received</div>
        <div aria-hidden="true"></div>
    </div>

    <?php foreach ($list['items'] as $s): ?>
        <?php
        $sid   = (int) $s['id'];
        $isNew = !$isSpam && !(bool) $s['is_read'];
        $line  = Preview::forForm((int) $s['form_id'], $s['data']);
        $title = $line !== '' ? $line : 'Submission #' . $sid;
        $sub   = $isSpam ? (string) ($s['spam_reason'] ?? '') : (string) ($s['source_page'] ?? '');
        ?>
        <div class="list__row">
            <label class="entry-list__check">
                <input type="checkbox" name="ids[]" value="<?= $sid ?>" form="submission-bulk"
                       data-bulk-row data-title="<?= h($title) ?>"
                       aria-label="Select &ldquo;<?= h($title) ?>&rdquo;">
            </label>

            <div class="entry-list__main">
                <a class="list__row-link list__row-title<?= $isNew ? ' is-unread' : '' ?>"
                   href="<?= h(Url::admin('forms/submissions/' . $sid)) ?>"><?= h($title) ?></a>
                <?php if ($sub !== ''): ?>
                    <div class="list__row-sub"><?= h($sub) ?></div>
                <?php endif; ?>
            </div>

            <?php // WHICH FORM — in the slot the entry list gives the status
                  // chip, because on a list of every form that is the fact the
                  // eye sorts by. A link: "only this form's" is the next move. ?>
            <?php if ($manyForm): ?>
                <div>
                    <a href="<?= h(Url::admin('forms/' . (int) $s['form_id'] . '/submissions')) ?>"><?= h((string) $s['form_title']) ?></a>
                </div>
            <?php else: ?>
                <div aria-hidden="true"></div>
            <?php endif; ?>

            <div class="list__row-meta">
                <?php if ($isNew): ?><span>Unread</span><?php endif; ?>
                <time datetime="<?= h(date('c', strtotime((string) $s['submitted_at'] . ' UTC'))) ?>">
                    <?= h(Dates::stamp((string) $s['submitted_at'])) ?>
                </time>
            </div>

            <div class="list__row-actions">
                <span class="entry-list__view"></span>
                <?php if ($isSpam): ?>
                    <form class="form-inline" method="post"
                          action="<?= h(Url::admin('forms/submissions/' . $sid . '/not-spam')) ?>">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn--sm">Not spam</button>
                    </form>
                <?php else: ?>
                    <a class="btn btn--sm" href="<?= h(Url::admin('forms/submissions/' . $sid)) ?>">Open</a>
                <?php endif; ?>
                <?php // THE TRASH GLYPH — glyph-only, so its tooltip is
                      // allowed, and its aria-label says the same words for a
                      // screen reader and a phone. ?>
                <form class="form-inline" method="post"
                      action="<?= h(Url::admin('forms/submissions/' . $sid . '/delete')) ?>"
                      data-confirm="<?= $isSpam ? 'Permanently delete this submission?' : 'Delete this submission?' ?>"
                      data-confirm-detail="What the sender typed is removed permanently."
                      data-confirm-verb="<?= $isSpam ? 'Delete for good' : 'Delete submission' ?>">
                    <?= Csrf::field() ?>
                    <button type="submit" class="icon-btn" data-tip="Delete submission" aria-label="Delete submission">
                        <?= icon('trash-can', 'icon') ?>
                    </button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php // THE PAGER — the entry list's: rendered only when there is more than
      // one page, with a current-page box. And its "Number of items per
      // page", a number box, 1–999, kept in the session. ?>
<?php if ($list['pages'] > 1 || $list['perPage'] !== 20): ?>
    <nav class="pager" aria-label="Submission pages">
        <?php if ($list['page'] > 1): ?>
            <a class="btn btn--sm" href="<?= h($listUrl(1)) ?>">First</a>
            <a class="btn btn--sm" href="<?= h($listUrl((int) $list['page'] - 1)) ?>" rel="prev">Previous</a>
        <?php endif; ?>

        <?php if ($list['pages'] > 1): ?>
            <form class="pager__jump" method="get" action="<?= h(Url::admin((string) $list['path'])) ?>">
                <label class="pager__status">
                    Page
                    <input class="input input--sm input--count" type="number" name="page" min="1"
                           max="<?= (int) $list['pages'] ?>" value="<?= (int) $list['page'] ?>"
                           aria-label="Page number">
                    of <?= (int) $list['pages'] ?>
                </label>
            </form>
        <?php endif; ?>

        <?php if ($list['page'] < $list['pages']): ?>
            <a class="btn btn--sm" href="<?= h($listUrl((int) $list['page'] + 1)) ?>" rel="next">Next</a>
            <a class="btn btn--sm" href="<?= h($listUrl((int) $list['pages'])) ?>">Last</a>
        <?php endif; ?>

        <form class="pager__jump" method="get" action="<?= h(Url::admin((string) $list['path'])) ?>">
            <label class="pager__status">
                Per page
                <input class="input input--sm input--count" type="number" name="per_page" min="1" max="999"
                       value="<?= (int) $list['perPage'] ?>" aria-label="Submissions per page">
            </label>
        </form>
    </nav>
<?php endif; ?>
