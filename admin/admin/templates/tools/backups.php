<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Security\Csrf;
use Cms\Site\Backups;

/**
 * TOOLS → BACKUPS (`BackupsController`, `Site\Backups`).
 *
 * The backups first, newest at the top, each with its size, what it holds,
 * Download and Delete, and Back up now in the card's heading; the bar while
 * one is being made. Under them the timetable: how often, and how many are
 * kept. How to put one back is said once, under the list, because it is the
 * command line's. Where config.php names somewhere off the server
 * (`Site\BackupCopy`), each backup says how its copy went, and the list says
 * where copies go and how many are kept there.
 *
 * @var list<array{id: string, why: string, finished: int, bytes: int, file: string, count: int, copy: ?array<string, mixed>}> $backups
 * @var array<string, mixed>|null $running
 * @var string                    $every
 * @var string                    $keep
 * @var list<string>              $errors
 * @var bool                      $saved
 * @var string                    $copyTo       where copies go, '' when nowhere
 * @var string|null               $copyProblem  what config.php's backup_copy lacks
 * @var int                       $copyKeep     how many are kept there
 */

$total = array_sum(array_map(static fn (array $b): int => $b['bytes'], $backups));

ob_start();
?>
<div class="page">

    <a class="page__back" href="<?= h(Url::admin('tools')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Tools
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <div class="page__title-row">
                <h1 class="page__title">Backups</h1>
                <?php if ($backups !== []): ?>
                    <?= count_of(count($backups), 'backup') ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($saved): ?>
        <div class="flashes"><div class="flash flash--success">Timetable saved.</div></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert--danger">
            <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__title"><?= count($errors) === 1 ? 'One thing needs fixing' : count($errors) . ' things need fixing' ?></p>
                <ul class="alert__text">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="form-main">
        <div class="form-card">
            <div class="form-card__section">
                <div class="panel-head">
                    <?= panel_title('Backups', 'backups') ?>
                    <?php if ($running === null): ?>
                        <form class="form-inline" method="post" action="<?= h(Url::admin('tools/backups/now')) ?>">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--sm">Back up now</button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if ($running !== null): ?>
                    <?php // THE COPY'S OWN STEPPING (`static-copy.js`): a few
                          // seconds more until done, then the screen again. ?>
                    <?php $known = max(1, (int) $running['count']); ?>
                    <div class="static-copy__going backups__going"
                         data-static-copy-step="<?= h(Url::admin('tools/backups/' . rawurlencode((string) $running['id']) . '/step')) ?>">
                        <div class="progress-bar">
                            <div class="progress-bar__fill" data-static-copy-bar
                                 style="width: <?= (int) round(100 * (int) $running['offset'] / $known) ?>%"></div>
                        </div>
                        <div class="kv-list">
                            <div class="kv-list__row">
                                <span class="kv-list__key">Files</span>
                                <span class="kv-list__val kv-list__val--num" data-static-copy="files"><?= (int) $running['offset'] ?></span>
                            </div>
                            <div class="kv-list__row">
                                <span class="kv-list__key">Still to do</span>
                                <span class="kv-list__val kv-list__val--num" data-static-copy="queued"><?= max(0, (int) $running['count'] - (int) $running['offset']) ?></span>
                            </div>
                        </div>
                        <div class="alert alert--danger" data-static-copy-error hidden>
                            <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
                            <div class="alert__body">
                                <p class="alert__title">The backup stopped</p>
                                <p class="alert__text" data-static-copy-error-text></p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($backups === []): ?>
                    <?php if ($running === null): ?>
                        <p class="form-help form-help--lead">None yet. The first is made on a visit once one is due, or now.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="list list--bleed backup-list">
                        <?php foreach ($backups as $backup): ?>
                            <div class="list__row" data-backup="<?= h($backup['id']) ?>">
                                <div class="backup-list__main">
                                    <span class="list__row-title"><?= h(Dates::stamp($backup['finished'])) ?></span>
                                    <div class="list__row-sub">
                                        <?= h(ucfirst($backup['why'])) ?> · the database and <?= (int) $backup['count'] ?> file<?= $backup['count'] === 1 ? '' : 's' ?> ·
                                        <code><?= h($backup['id']) ?></code>
                                        <?php $copy = $backup['copy']; ?>
                                        <?php if ($copy !== null): ?>
                                            <?php // HOW ITS COPY WENT, in words: copied, going, or why not. ?>
                                            <span data-backup-copy="<?= h((string) ($copy['state'] ?? '')) ?>"> ·
                                                <?= h(match ((string) ($copy['state'] ?? '')) {
                                                    'copied'  => 'copied to ' . (string) ($copy['to'] ?? ''),
                                                    'failed'  => 'not copied: ' . (string) ($copy['error'] ?? ''),
                                                    default   => 'being copied to ' . (string) ($copy['to'] ?? ''),
                                                }) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="backup-list__size"><?= h(Backups::size($backup['bytes'])) ?></div>
                                <div class="list__row-actions">
                                    <a class="btn btn--sm" href="<?= h(Url::admin('tools/backups/' . rawurlencode($backup['id']) . '/download')) ?>">Download</a>
                                    <form class="form-inline" method="post"
                                          action="<?= h(Url::admin('tools/backups/' . rawurlencode($backup['id']) . '/delete')) ?>"
                                          data-confirm="Delete the backup of <?= h(Dates::stamp($backup['finished'])) ?>?"
                                          data-confirm-detail="Its file is deleted from the server. The site itself is not changed."
                                          data-confirm-verb="Delete backup">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="link link--xs">Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php // PUTTING ONE BACK IS THE COMMAND LINE'S: it replaces
                          // the accounts of whoever would press a button here. ?>
                    <p class="form-help backup-list__restore">
                        <?= h(Backups::size($total)) ?> on the server, in <code>admin/backups/</code>. To put one back, run
                        <code>php admin/bin/backup.php restore</code> and its name on the server; it backs up the site as it is first.
                    </p>
                <?php endif; ?>
                <?php // WHERE A COPY OF EACH GOES (`Site\BackupCopy`), or that
                      // none does: a backup kept only here does not survive
                      // the server. ?>
                <?php if ($copyTo !== ''): ?>
                    <p class="form-help" data-backup-copies>
                        A copy of each goes to <?= h($copyTo) ?>, where the newest <?= (int) $copyKeep ?> are kept.
                    </p>
                <?php elseif ($copyProblem !== null): ?>
                    <p class="form-help" data-backup-copies>
                        Kept only on this server: <?= h($copyProblem) ?>.
                    </p>
                <?php else: ?>
                    <p class="form-help" data-backup-copies>
                        Kept only on this server. config.php's <code>backup_copy</code> sends a copy of each to S3-compatible storage,
                        such as Amazon S3, Backblaze B2 or Wasabi.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-card">
            <div class="form-card__section">
                <?= panel_title('Timetable', 'backup-timetable') ?>
                <form method="post" action="<?= h(Url::admin('tools/backups')) ?>">
                    <?= Csrf::field() ?>
                    <div class="form-grid form-grid--2 backup-timetable">
                        <div class="field">
                            <label class="label" for="f-backup-every">Back up</label>
                            <select class="select" id="f-backup-every" name="backup_every">
                                <?php foreach (['day' => 'Every day', 'week' => 'Every week', 'never' => 'Never'] as $value => $label): ?>
                                    <option value="<?= h($value) ?>"<?= $every === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label class="label" for="f-backup-keep">Keep the newest</label>
                            <input class="input" type="number" id="f-backup-keep" name="backup_keep" min="1" max="<?= Backups::MAX_KEEP ?>"
                                   value="<?= h($keep) ?>" inputmode="numeric">
                        </div>
                    </div>
                    <p class="form-help">
                        Each backup is the database and every uploaded file, made on a visit once it is due
                        and kept on the server outside the web site. <code>config.php</code> is left out: it
                        holds the site's passwords. Keep a copy of it apart from the backups, somewhere only you can reach.
                    </p>
                    <div class="form-actions form-actions--bare">
                        <button type="submit" class="btn btn--primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>
<?php if ($running !== null): ?>
    <script src="<?= h(Asset::admin('js/static-copy.js')) ?>"></script>
<?php endif; ?>
<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
