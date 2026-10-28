<?php

declare(strict_types=1);

use Cms\Helpers\Dates;
use Cms\Security\FileCheck;

/**
 * SECURITY → FILES — the CMS's files against what their release shipped
 * (`Security\FileCheck`), compared when this screen opens.
 *
 * The result is a STATE, so it is a chip — never an alert: alert colour is a
 * surface, not a state. Each file that differs is a row with its kind.
 *
 * @var array<string, mixed>|null $result  FileCheck::compare() — null on a tree with no manifest
 * @var array<string, mixed>|null $last    FileCheck::last() — what the last check kept
 */

$chip = static function (string $glyph, string $label, string $mod = ''): string {
    return '<span class="status status--phrase' . ($mod !== '' ? ' ' . $mod : '') . '">'
        . icon($glyph, 'status__icon') . h($label) . '</span>';
};
// What each kind means, in the words `Update\Manifest::diffTree()` uses.
$kinds = [
    'modified' => ['pen-to-square', 'Modified'],
    'missing'  => ['x', 'Missing'],
    'unknown'  => ['plus', 'Added'],
];
$rows = [];
if ($result !== null) {
    foreach ($kinds as $kind => $_) {
        foreach ($result[$kind] as $path) {
            $rows[] = [$kind, (string) $path];
        }
    }
}
$changed = count($rows);
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Files</h1>
        </div>
    </div>

    <div class="form-main">
        <div class="form-card">
            <div class="form-card__section">
                <?= panel_title('Core files', 'file-check') ?>
                <?php if ($result === null): ?>
                    <p class="form-help">This copy has no <code>admin/manifest.json</code> — the record of what a release
                        shipped, which every installed package carries. It is a development tree, so there is nothing
                        to compare its files with.</p>
                    <p class="form-help">On an install, this screen compares each core file with that record when
                        it opens, and the site checks once a day by itself. A change no earlier check has seen goes into
                        the log, and to the notifications that ask for it.</p>
                <?php else: ?>
                    <div class="kv-list">
                        <div class="kv-list__row">
                            <span class="kv-list__key">Release</span>
                            <span class="kv-list__val"><?= h((string) $result['version']) ?></span>
                        </div>
                        <div class="kv-list__row">
                            <span class="kv-list__key">Compared</span>
                            <span class="kv-list__val">Just now, <?= (int) ($result['same'] + count($result['modified']) + count($result['missing'])) ?> files</span>
                        </div>
                        <div class="kv-list__row">
                            <span class="kv-list__key">Result</span>
                            <span class="kv-list__val"><?= $changed === 0
                                ? $chip('check', 'All ' . (int) $result['same'] . ' as shipped', 'status--active')
                                : $chip('file', $changed === 1 ? '1 differs' : $changed . ' differ') ?></span>
                        </div>
                    </div>
                    <?php // What Updater::apply() does with the same list, unless it
                          // is run with --discard-local-changes. ?>
                    <p class="form-help">Checked once a day by itself, and whenever this screen opens. While any of them
                        differ, an update stops and names them.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($rows !== []): ?>
            <?php // `--bleed`: the list is the whole of its section, so it reaches
                  // the card's edges instead of sitting on it in the same tone. ?>
            <div class="form-card">
                <div class="form-card__section">
                    <?= panel_title('What differs', 'file-check') ?>
                    <div class="list list--bleed entry-list">
                        <?php foreach (array_slice($rows, 0, FileCheck::LISTED) as [$kind, $path]): ?>
                            <div class="list__row">
                                <div class="entry-list__main">
                                    <span class="list__row-title"><?= h(basename($path)) ?></span>
                                    <div class="list__row-sub" title="<?= h($path) ?>"><?= h($path) ?></div>
                                </div>
                                <div><?= $chip($kinds[$kind][0], $kinds[$kind][1]) ?></div>
                                <div class="list__row-meta"></div>
                                <div class="list__row-actions"></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if ($changed > FileCheck::LISTED): ?>
                    <div class="form-card__section">
                        <p class="form-help">… and <?= $changed - FileCheck::LISTED ?> more. Every one:
                            <code>php admin/bin/security.php files</code></p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

</div>
