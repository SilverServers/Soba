<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Theme;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * APPEARANCE → THEMES — a card per theme, the active one first and named
 * "Active: …", Activate on each of the others; under them, the themes that are
 * there but cannot be used, each with what is wrong ("Broken themes").
 *
 * The card is the screenshot, the name and the actions, and the rest is short
 * enough to sit on it: what the theme says about itself, its version, who made
 * it, and how it renders — which is the one fact an owner needs before
 * switching, because a whole-page theme replaces the pages it has templates
 * for outright.
 *
 * A theme is added by putting its folder in `admin/site/themes/` (the site's
 * own directory, which no update touches); the line under the title says so.
 *
 * @var list<array<string, mixed>>  $usable     the active theme first, then the rest by name
 * @var list<array<string, mixed>>  $broken
 * @var array<string, mixed>        $active
 * @var array<string, mixed>|null   $activated  the theme just switched to
 * @var bool                        $failed
 * @var array<string, mixed>|null   $deleted    name, done, problem — the delete just asked for
 */

$modes = [
    'framed'     => 'Inside the site frame',
    'whole-page' => 'Draws whole pages',
];

// DELETE, for a theme in the themes folder that is not in use. It asks first,
// naming the folder, because nothing brings the files back.
$deleteForm = static function (array $theme): string {
    if (Theme::deleteProblem((string) $theme['key']) !== null) {
        return '';
    }
    return '<form class="form-inline" method="post" action="' . h(Url::admin('appearance/themes/delete')) . '"'
         . ' data-confirm="' . h('Delete the theme ' . $theme['name'] . '?') . '"'
         . ' data-confirm-detail="' . h('Its folder, ' . $theme['where'] . ', is removed from the server, with every file in it.') . '"'
         . ' data-confirm-verb="Delete theme">'
         . Csrf::field()
         . '<input type="hidden" name="theme" value="' . h((string) $theme['key']) . '">'
         . '<button type="submit" class="icon-btn" data-tip="Delete theme" aria-label="' . h('Delete ' . $theme['name']) . '">'
         . icon('trash-can', 'icon') . '</button></form>';
};

ob_start();
?>
<div class="page" data-themes>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Themes</h1>
            <p class="page__subtitle">A theme is a folder in <code>admin/site/themes/</code> with a <code>theme.json</code> naming it. Updates never touch that folder.</p>
        </div>
    </div>

    <?php if ($activated !== null || $failed || $deleted !== null): ?>
        <div class="flashes">
            <?php if ($deleted !== null && $deleted['done']): ?>
                <div class="flash flash--success"><?= h((string) $deleted['name']) ?> is deleted.</div>
            <?php elseif ($deleted !== null): ?>
                <div class="flash flash--error"><?= h((string) $deleted['name']) ?> was not deleted. <?= h((string) $deleted['problem']) ?></div>
            <?php elseif ($activated !== null): ?>
                <div class="flash flash--success">
                    <?= h((string) $activated['name']) ?> is now the site's theme.
                    <a href="<?= h(BASE_URL . '/') ?>" target="_blank" rel="noopener">View the site</a>
                </div>
            <?php else: ?>
                <div class="flash flash--error">That theme cannot be used. It is listed under Broken themes with the reason.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card-grid">
        <?php foreach ($usable as $theme): ?>
            <?php $isActive = $theme['key'] === $active['key']; ?>
            <article class="card theme-card<?= $isActive ? ' is-active' : '' ?>" data-theme="<?= h((string) $theme['key']) ?>">
                <div class="theme-shot">
                    <?php if ($theme['screenshot'] !== null): ?>
                        <img src="<?= h(Url::admin('appearance/themes/' . $theme['key'] . '/screenshot') . '?v=' . (int) @filemtime((string) $theme['screenshot'])) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <?= icon('window-restore', 'theme-shot__glyph', '', 28) ?>
                    <?php endif; ?>
                </div>
                <div class="card__head">
                    <strong><?= $isActive ? 'Active: ' : '' ?><?= h((string) $theme['name']) ?></strong>
                    <?php if ($theme['version'] !== ''): ?>
                        <span class="card__count"><?= h((string) $theme['version']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($theme['description'] !== ''): ?>
                    <p class="card__text"><?= h((string) $theme['description']) ?></p>
                <?php endif; ?>
                <p class="card__text">
                    <?= h($modes[$theme['mode']] ?? (string) $theme['mode']) ?><?php
                    if ($theme['author'] !== ''): ?> · By <?= h((string) $theme['author']) ?><?php endif; ?>
                    · <?= h((string) $theme['where']) ?>
                </p>
                <div class="card__tools">
                    <?php if ($isActive): ?>
                        <span class="status status--active"><?= icon('check', 'status__icon') ?>Active</span>
                    <?php else: ?>
                        <form method="post" action="<?= h(Url::admin('appearance/themes/activate')) ?>">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="theme" value="<?= h((string) $theme['key']) ?>">
                            <button type="submit" class="btn btn--primary"
                                    aria-label="Activate <?= h((string) $theme['name']) ?>">Activate</button>
                        </form>
                        <?= $deleteForm($theme) ?>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php // "BROKEN THEMES": installed, incomplete, each with why. The
          // security overview's section-and-rows, so it is spaced and ruled
          // like every other list under a card grid. ?>
    <?php if ($broken !== []): ?>
        <div class="panel__section" data-broken-themes>
            <div class="panel__heading">
                <h3>Broken themes</h3>
            </div>
            <div class="list entry-list">
                <?php foreach ($broken as $theme): ?>
                    <div class="list__row" data-theme="<?= h((string) $theme['key']) ?>">
                        <div class="entry-list__main">
                            <span class="list__row-title"><?= h((string) $theme['name']) ?></span>
                            <div class="list__row-sub"><?= h((string) $theme['problem']) ?></div>
                        </div>
                        <div>
                            <?php // One word, to fit the row's status track (104px);
                                  // the reason is the line under the name. ?>
                            <span class="status"><?= icon('triangle-exclamation', 'status__icon') ?>Broken</span>
                        </div>
                        <div class="list__row-meta"><?= h((string) $theme['where']) ?></div>
                        <div class="list__row-actions"><?= $deleteForm($theme) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

</div>
<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
