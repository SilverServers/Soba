<?php

declare(strict_types=1);

use Cms\Helpers\Url;

/**
 * TOOLS → SYSTEM HEALTH.
 *
 * ── EVERY ROW IS A CHECK THE CONTROLLER ACTUALLY RAN ────────────────────────
 * `$checks` is built entirely in `ToolsController::systemHealth()` — the CMS
 * release, PHP version, required extensions, writable directories, migrations.
 * This template renders what it is given and invents nothing: no row is added
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
 * harder to find, which is the opposite of what a health screen is for.
 *
 * @var array<string, array{label: string, value: string, status: string, hint: string}> $checks
 * @var array<string, int> $counts  content label => row count
 * @var array<string, mixed> $user
 */

$pageTitle = 'System health';

// AN INFORMATIONAL ROW IS NOT A PROBLEM. `info` is a fact with nothing to
// judge — how much the uploads take up — and counting it would make a healthy
// install open on "1 check needs attention" with a Check-this badge on a
// number. Only `warning` and `error` count.
$quiet    = ['ok', 'info'];
$problems = 0;
foreach ($checks as $check) {
    if (!in_array((string) ($check['status'] ?? 'ok'), $quiet, true)) {
        $problems++;
    }
}

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

    <div class="form-main">
        <div class="form-card">

            <div class="form-card__section">
                <?= panel_title('Environment', 'environment') ?>
                <div class="kv-list">
                    <?php foreach ($checks as $check): ?>
                        <?php $status = (string) ($check['status'] ?? 'ok'); ?>
                        <div class="kv-list__row">
                            <span class="kv-list__key"><?= h((string) $check['label']) ?></span>
                            <span class="kv-list__val">
                                <?= h((string) $check['value']) ?>
                                <?php if (!in_array($status, $quiet, true)): ?>
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
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

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
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
