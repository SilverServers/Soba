<?php

declare(strict_types=1);

use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * TOOLS → SYSTEM HEALTH.
 *
 * ── EVERY ROW IS A CHECK THE CODE ACTUALLY RAN ──────────────────────────────
 * The rows are built in `Site\Health` and `Site\GoingLive`, which
 * `php admin/bin/health.php` prints too: the release, PHP, the extensions,
 * the folders, the migrations, and what going to production changes. This
 * template renders what it is given and invents nothing: no row is added
 * here, and no row is hidden, so what the screen shows is exactly what was
 * measured.
 *
 * ── STATUS IS A WORD PLUS A SHAPE, NOT A COLOUR ALONE ───────────────────────
 * Alert colour is a SURFACE on a closed list of places, and a row in a table is
 * not on it. A failing check therefore says "Problem" in
 * words, in a badge that carries its own background — `.alert-flag`, the
 * sanctioned inline form the field-group rows already use. A green tick and a
 * red tick that differ only in hue are indistinguishable to a good share of
 * readers, and this is a screen people come to when something is wrong.
 *
 * ── THE OK ROWS ARE QUIET ───────────────────────────────────────────────────
 * A passing check gets no badge at all. Twenty green ticks make the one red one
 * harder to find, which is the opposite of what a health screen is for. An
 * informational row is not a problem either: it is a fact with nothing to
 * judge, such as how much the uploads take up (`Health::ATTENTION`).
 *
 * ── A ROW THAT NAMES THINGS LINKS EACH ──────────────────────────────────────
 * An account, a page, an entry: each of a row's `items` is a link to its
 * screen, in place of how many, before the hint that says why. Old
 * addresses carry the button that replaces them
 * (`ToolsController::replaceAddresses()`).
 *
 * ── SAMPLE ENTRIES ARE LISTED, ONE BY ONE ───────────────────────────────────
 * Their row says how many; the section under it names each, by its type,
 * with a link to its editor, and one button moves them all to the Trash
 * (`ToolsController::trashSamples()`). Drawn only while there are some.
 *
 * @var array<string, array{label: string, value: string, status: string, hint: string}> $checks
 * @var array<string, array{label: string, value: string, status: string, hint: string, items?: list<array{text: string, url: string}>}> $goingLive
 * @var string $liveTitle  the going-live section's heading, for this copy
 * @var int $problems  how many rows need attention
 * @var array<string, int> $counts  content label => row count
 * @var list<array{id: int, post_type: string, title: string}> $samples
 * @var int|null $trashed  how many the samples' button moved, on the way back
 * @var int|null $replaced  how many places the old addresses' button changed
 * @var array<string, array{label: string, value: string, status: string, hint: string}> $tasks  the site's scheduled tasks
 * @var list<array<string, mixed>> $taskRuns  their newest runs
 * @var array<string, mixed> $user
 */

$pageTitle = 'System health';
$asks      = $samples !== [] || ($goingLive['old_addresses']['status'] ?? 'ok') === 'warning';

/** One row: its value, its flag when it needs attention, its hint, and what it names. */
$drawRow = static function (string $key, array $check): string {
    $status = (string) ($check['status'] ?? 'ok');
    ob_start();
    ?>
    <?php // The value is a div, not a span: it can hold a form. A grid's cell either way. ?>
    <div class="kv-list__row" data-health-row="<?= h($key) ?>">
        <span class="kv-list__key"><?= h((string) $check['label']) ?></span>
        <div class="kv-list__val">
            <?php // WHAT IT NAMES, IN PLACE OF HOW MANY: each a link to its
                  // screen, before the hint that says why it is listed. ?>
            <?php if (($check['items'] ?? []) !== []): ?>
                <?php foreach (array_values($check['items']) as $i => $item): ?><?= $i > 0 ? ', ' : '' ?><a class="link" href="<?= h((string) $item['url']) ?>"><?= h((string) $item['text']) ?></a><?php endforeach; ?>
            <?php else: ?>
                <?= h((string) $check['value']) ?>
            <?php endif; ?>
            <?php if (in_array($status, \Cms\Site\Health::ATTENTION, true)): ?>
                <?php // `.alert-flag` — the alert surface's inline
                      // badge form, for a dense row. It is a
                      // SURFACE with its own background, not
                      // coloured text on ordinary chrome. ?>
                <span class="alert-flag">
                    <?= icon('triangle-exclamation', 'icon', '', 11) ?>
                    <?= $status === 'error' ? 'Problem' : 'Check this' ?>
                </span>
            <?php endif; ?>
            <?php if (($check['hint'] ?? '') !== ''): ?>
                <span class="kv-list__val--muted"><?= h((string) $check['hint']) ?></span>
            <?php endif; ?>
            <?php if ($key === 'old_addresses' && $status === 'warning'): ?>
                <form class="form-inline" method="post" action="<?= h(Url::admin('tools/system-health/addresses')) ?>"
                      data-confirm="Replace every old address with <?= h(\Cms\Site\OldAddresses::current()) ?>?"
                      data-confirm-detail="Each page and entry changed keeps a revision from before, in its history."
                      data-confirm-verb="Replace">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn--sm">Replace</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
};

ob_start();
?>
<div class="page">

    <a class="page__back" href="<?= h(Url::admin('tools')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Tools
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">System health</h1>
            <?php // THE HEADLINE ANSWER, FIRST. Someone opening this screen wants
                  // "is anything wrong" before they want thirty rows of detail. ?>
            <p class="page__subtitle">
                <?php if ($problems === 0): ?>
                    Every check passed.
                <?php else: ?>
                    <?= $problems ?> check<?= $problems === 1 ? '' : 's' ?> need<?= $problems === 1 ? 's' : '' ?> attention.
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php if ($trashed !== null): ?>
        <div class="flashes"><div class="flash flash--success">
            <?php if ($trashed === 0): ?>
                No sample entries were left to move.
            <?php else: ?>
                <?= $trashed === 1 ? '1 sample entry' : $trashed . ' sample entries' ?> moved to the Trash.
                Restored from its type's Trash, each comes back Private.
            <?php endif; ?>
        </div></div>
    <?php endif; ?>
    <?php if ($replaced !== null): ?>
        <div class="flashes"><div class="flash flash--success">
            <?php if ($replaced === 0): ?>
                Nothing named an old address.
            <?php else: ?>
                The site's own address is in <?= $replaced === 1 ? '1 place' : $replaced . ' places' ?> that named an old one.
                Each page and entry changed has a revision from before.
            <?php endif; ?>
        </div></div>
    <?php endif; ?>

    <div class="form-main">
        <div class="form-card">

            <div class="form-card__section">
                <?= panel_title('Environment', 'environment') ?>
                <div class="kv-list">
                    <?php foreach ($checks as $key => $check): ?>
                        <?= $drawRow((string) $key, $check) ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-card__section" data-going-live>
                <?= panel_title($liveTitle, 'going-live') ?>
                <div class="kv-list">
                    <?php foreach ($goingLive as $key => $check): ?>
                        <?= $drawRow((string) $key, $check) ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php // THE SITE'S OWN SCHEDULED TASKS (`Site\Tasks`): each with its
                  // time, its next run and how its last went, then a line for
                  // each run, newest first. Drawn only for a site that has some. ?>
            <?php if ($tasks !== []): ?>
                <div class="form-card__section" data-tasks>
                    <?= panel_title('Scheduled tasks', 'tasks') ?>
                    <div class="kv-list">
                        <?php foreach ($tasks as $key => $check): ?>
                            <?= $drawRow((string) $key, $check) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-card__section" data-task-runs>
                    <?= panel_title('Their runs', 'task-runs') ?>
                    <?php if ($taskRuns === []): ?>
                        <p class="form-help">None has run yet.</p>
                    <?php else: ?>
                        <div class="kv-list">
                            <?php foreach ($taskRuns as $taskRun): ?>
                                <div class="kv-list__row" data-task-run="<?= h((string) $taskRun['task']) ?>">
                                    <span class="kv-list__key"><?= h((string) $taskRun['task']) ?></span>
                                    <div class="kv-list__val">
                                        <?= h(\Cms\Site\Tasks::sentence($taskRun)) ?>
                                        <?php if (in_array($taskRun['outcome'], ['failed', 'timed_out'], true)): ?>
                                            <span class="alert-flag"><?= icon('triangle-exclamation', 'icon', '', 11) ?>Check this</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($samples !== []): ?>
                <?php $sampleCount = count($samples); ?>
                <div class="form-card__section" data-samples>
                    <div class="panel-head">
                        <?= panel_title('Sample entries', 'samples') ?>
                        <form class="form-inline" method="post" action="<?= h(Url::admin('tools/system-health/samples')) ?>"
                              data-confirm="Move <?= $sampleCount === 1 ? 'the sample entry' : 'all ' . $sampleCount . ' sample entries' ?> to the Trash?"
                              data-confirm-detail="Each can be restored from its type's Trash, and comes back Private."
                              data-confirm-verb="Move to the Trash">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--sm">Move <?= $sampleCount === 1 ? 'it' : 'them' ?> to the Trash</button>
                        </form>
                    </div>
                    <div class="kv-list">
                        <?php foreach ($samples as $sample): ?>
                            <?php $sampleType = \Cms\ContentType\Registry::get($sample['post_type']); ?>
                            <div class="kv-list__row">
                                <span class="kv-list__key"><?= h($sampleType !== null ? \Cms\Site\Words::typeLabel($sampleType) : $sample['post_type']) ?></span>
                                <span class="kv-list__val">
                                    <?php if ($sampleType !== null): ?>
                                        <a class="link" href="<?= h(Url::admin($sampleType['admin_slug'] . '/' . $sample['id'] . '/edit')) ?>"><?= h($sample['title']) ?></a>
                                    <?php else: ?>
                                        <?= h($sample['title']) ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="form-card__section">
                <?= panel_title('Content', 'content') ?>
                <div class="kv-list">
                    <?php foreach ($counts as $label => $n): ?>
                        <div class="kv-list__row">
                            <span class="kv-list__key"><?= h((string) $label) ?></span>
                            <span class="kv-list__val kv-list__val--num"><?= (int) $n ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
    </div>

</div>
<?php if ($asks): ?>
    <script src="<?= h(\Cms\Helpers\Asset::admin('js/confirm.js')) ?>"></script>
<?php endif; ?>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
