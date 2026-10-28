<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Models\FormSubmission;
use Cms\Security\Csrf;

/**
 * SUBMISSIONS FOR ONE FORM — a page at a time, with bulk actions.
 *
 * The list itself is `forms/submission-list.php`, shared with All submissions
 * and Spam: the row is who sent it (`Forms\Preview`), never "Submission #14",
 * because that is what tells you whether this is the enquiry you are looking
 * for. Tick boxes, a select-all and one bulk bar — Mark as spam, Mark as read,
 * Mark as unread, Delete — and the entry list's pager under it.
 *
 * ── UNREAD IS A WEIGHT, NOT A BADGE ─────────────────────────────────────────
 * An unread row is bolder. It is not given a coloured chip: `--danger` and
 * `--warning` are surfaces on a closed list of places (§3), and "nobody has
 * read this yet" is not a fault. Opening a submission marks it read — the
 * controller does that in `showSubmission()` — so the weight resolves itself.
 *
 * @var array<string, mixed> $form
 * @var array<string, mixed> $list  from `FormController::listPage()`
 * @var array<string, mixed> $user
 */

$formId    = (int) $form['id'];
$pageTitle = 'Submissions: ' . (string) $form['title'];
$unread    = FormSubmission::countUnread($formId);

ob_start();
?>
<div class="page">

    <a class="page__back" href="<?= h(Url::admin('forms')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Forms
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title"><?= h((string) $form['title']) ?></h1>
        </div>

        <div class="page__header-actions">
            <a class="btn" href="<?= h(Url::admin('forms/' . $formId . '/edit')) ?>">Edit form</a>
            <?php // A FILE OF EVERY SUBMISSION:
                  // the received ones, every page of them, for a spreadsheet.
                  // Spam is not in it. ?>
            <?php if ((int) $list['total'] > 0): ?>
                <a class="btn" href="<?= h(Url::admin('forms/' . $formId . '/submissions.csv')) ?>">Export CSV</a>
            <?php endif; ?>
            <?php // MARK ALL READ IS OFFERED ONLY WHEN SOMETHING IS UNREAD. A
                  // button that is always there and usually does nothing trains
                  // you to ignore it. It reaches every page; the bulk bar's
                  // "Mark as read" reaches the ticked rows. ?>
            <?php if ($unread > 0): ?>
                <?php // THE ROUTE IS `/mark-all-read`, checked in www/index.php.
                      // Guessing `/submissions/read` from the screen's own URL
                      // gave a 404 that looked like a dead button. ?>
                <form method="post" action="<?= h(Url::admin('forms/' . $formId . '/mark-all-read')) ?>">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn">Mark all read</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php
    $emptyTitle = 'No submissions yet';
    $emptyGlyph = 'envelope';
    require __DIR__ . '/submission-list.php';
    ?>

</div>

<?php if ((int) $list['total'] > 0): ?>
    <script src="<?= h(Asset::admin('js/entry-bulk.js')) ?>"></script>
<?php endif; ?>
<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
