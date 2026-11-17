<?php

declare(strict_types=1);

use Cms\Controllers\Admin\BrokenLinksController;
use Cms\Helpers\Asset;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * TOOLS → BROKEN LINKS (`BrokenLinksController`).
 *
 * One card: the check — Check links, the bar while it runs, and when it ran
 * and how much it read. Under it, what it found: each address a link on the
 * site reaches that finds nothing, what it is, the pages that link to it,
 * each with its editor where it has one, and Add redirect, which fills
 * Tools → Redirects' form with the address. Nothing found is said as such.
 *
 * @var array<string, mixed>|null $status    the latest check's, or null
 * @var string|null               $checkId   its id
 * @var bool                      $going
 * @var list<array{to: string, kind: string, from: list<string>}> $broken
 * @var string|null               $cannot    why a check cannot run here
 * @var string|null               $refused
 */

$kindWords = [
    'page'  => 'Not found',
    'file'  => 'File not there',
    'error' => 'Failed to draw',
];

ob_start();
?>
<div class="page">

    <a class="page__back" href="<?= h(Url::admin('tools')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Tools
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <div class="page__title-row">
                <h1 class="page__title">Broken links</h1>
                <?php if ($status !== null && !$going && $broken !== []): ?>
                    <?= count_of(count($broken), 'address', 'addresses') ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($refused !== null): ?>
        <div class="alert alert--danger">
            <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__title">The check did not start</p>
                <p class="alert__text"><?= h($refused) ?></p>
            </div>
        </div>
    <?php endif; ?>

    <div class="form-main">
        <div class="form-card">
            <div class="form-card__section">
                <?= panel_title($going ? 'Checking' : 'The check', 'broken-links') ?>

                <?php if ($going): ?>
                    <?php // THE COPY'S OWN STEPPING (`static-copy.js`): it is the
                          // same job, asked for a few seconds more until done,
                          // and the screen is drawn again with what it found. ?>
                    <?php $known = max(1, (int) $status['pages'] + (int) $status['files'] + (int) $status['queued']); ?>
                    <div class="static-copy__going"
                         data-static-copy-step="<?= h(Url::admin('tools/broken-links/' . rawurlencode((string) $checkId) . '/step')) ?>">
                        <div class="progress-bar">
                            <div class="progress-bar__fill" data-static-copy-bar
                                 style="width: <?= (int) round(100 * ((int) $status['pages'] + (int) $status['files']) / $known) ?>%"></div>
                        </div>
                        <div class="kv-list">
                            <div class="kv-list__row">
                                <span class="kv-list__key">Pages</span>
                                <span class="kv-list__val kv-list__val--num" data-static-copy="pages"><?= (int) $status['pages'] ?></span>
                            </div>
                            <div class="kv-list__row">
                                <span class="kv-list__key">Files</span>
                                <span class="kv-list__val kv-list__val--num" data-static-copy="files"><?= (int) $status['files'] ?></span>
                            </div>
                            <div class="kv-list__row">
                                <span class="kv-list__key">Still to do</span>
                                <span class="kv-list__val kv-list__val--num" data-static-copy="queued"><?= (int) $status['queued'] ?></span>
                            </div>
                        </div>
                        <div class="alert alert--danger" data-static-copy-error hidden>
                            <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
                            <div class="alert__body">
                                <p class="alert__title">The check stopped</p>
                                <p class="alert__text" data-static-copy-error-text></p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <?php if ($status !== null): ?>
                        <div class="kv-list">
                            <div class="kv-list__row">
                                <span class="kv-list__key">Checked</span>
                                <span class="kv-list__val"><?= h(Dates::stamp((int) $status['finished'])) ?></span>
                            </div>
                            <div class="kv-list__row">
                                <span class="kv-list__key">Pages</span>
                                <span class="kv-list__val kv-list__val--num"><?= (int) $status['pages'] ?></span>
                            </div>
                            <div class="kv-list__row">
                                <span class="kv-list__key">Files</span>
                                <span class="kv-list__val kv-list__val--num"><?= (int) $status['files'] ?></span>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="form-help form-help--lead">
                            Every page is drawn as a visitor sees it and every link to this
                            site's own addresses followed. Nothing on the site is changed, and
                            links to other sites are not visited.
                        </p>
                    <?php endif; ?>

                    <?php if ($cannot !== null): ?>
                        <div class="alert">
                            <?= icon('circle-info', 'alert__icon icon', '', 18) ?>
                            <div class="alert__body">
                                <p class="alert__title">This server cannot run the check</p>
                                <p class="alert__text"><?= h($cannot) ?></p>
                            </div>
                        </div>
                    <?php else: ?>
                        <form method="post" action="<?= h(Url::admin('tools/broken-links')) ?>" class="form-actions form-actions--bare">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--primary">
                                <?= icon('magnifying-glass', 'icon icon--sm') ?><?= $status === null ? 'Check links' : 'Check again' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($status !== null && !$going): ?>
            <?php if ($broken === []): ?>
                <div class="empty broken-empty">
                    <?= icon('check', 'empty__glyph', '', 28) ?>
                    <p class="empty__title">Every link reaches something</p>
                </div>
            <?php else: ?>
                <div class="list broken-list">
                    <?php foreach ($broken as $miss): ?>
                        <?php
                        $to   = (string) $miss['to'];
                        $add  = Url::admin('tools/redirects') . '?from=' . rawurlencode(strtok($to, '?') ?: $to) . '#redirect-form';
                        ?>
                        <div class="list__row" data-broken="<?= h($to) ?>">
                            <div class="broken-list__main">
                                <span class="list__row-title broken-list__to"><?= h($to) ?></span>
                                <div class="list__row-sub">
                                    <span class="status status--phrase"><?= h($kindWords[$miss['kind']] ?? 'Not found') ?></span>
                                    <?php if ($miss['from'] !== []): ?>
                                        <span class="broken-list__from">Linked from
                                            <?php foreach ($miss['from'] as $i => $from): ?>
                                                <?php $editor = BrokenLinksController::editorFor($from); ?>
                                                <?= $i > 0 ? ', ' : '' ?><a href="<?= h($editor ?? Url::public(ltrim($from, '/'))) ?>"<?= $editor === null ? ' target="_blank" rel="noopener"' : '' ?>><?= h($from) ?></a>
                                            <?php endforeach; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="list__row-actions">
                                <a class="btn btn--sm" href="<?= h($add) ?>">Add redirect</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</div>
<?php if ($going): ?>
    <script src="<?= h(Asset::admin('js/static-copy.js')) ?>"></script>
<?php endif; ?>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
