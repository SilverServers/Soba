<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Url;

/**
 * ALL SUBMISSIONS, ACROSS EVERY FORM — a page at a time, with bulk actions.
 *
 * The same list as one form's (`forms/submission-list.php`), with the form each
 * one came from in the slot the entry list gives its status. One query a page:
 * `FormController::allSubmissions()` used to call `allForForm()` once per form
 * and merge in PHP, which this template's own comment said would not hold at
 * ten thousand rows.
 *
 * ── NO "MARK ALL READ" HERE ─────────────────────────────────────────────────
 * `handleMarkAllRead()` takes a form id and marks one form's submissions. On a
 * list of every form, the bulk bar's "Mark as read" on the ticked rows is the
 * same act at the right scope.
 *
 * @var array<string, mixed> $list  from `FormController::listPage()`
 * @var array<string, mixed> $user
 */

$pageTitle = 'All submissions';

ob_start();
?>
<div class="page">

    <a class="page__back" href="<?= h(Url::admin('forms')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Forms
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">All submissions</h1>
        </div>
        <?php if ((int) $list['total'] > 0): ?>
            <div class="page__header-actions">
                <?php // Every form's received submissions in one file. ?>
                <a class="btn" href="<?= h(Url::admin('forms/submissions.csv')) ?>">Export CSV</a>
            </div>
        <?php endif; ?>
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
