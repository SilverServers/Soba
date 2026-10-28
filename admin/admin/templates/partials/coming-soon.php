<?php

declare(strict_types=1);

/**
 * A SECTION THAT IS DELIBERATELY NOT BUILT YET.
 *
 * `PlaceholderController` exists so the full admin scope is visible and
 * navigable from day one: a sidebar row that leads to "not built yet, here is
 * what it will do" is honest, where a missing row hides the roadmap and a dead
 * link looks like a bug.
 *
 * The irony is that this template did not exist, so both routes that use it —
 * Theme Builder and Customizer — returned a 500. The screen whose entire job was
 * to say "unbuilt, on purpose" was itself indistinguishable from a crash.
 *
 * ── IT SAYS WHAT IT WILL DO, AND NOTHING ELSE ───────────────────────────────
 * No "coming soon!", no date, no progress bar. `$description` is the caller's
 * one sentence about what the section is for, and that is the whole page. A
 * promised date would be a claim this file cannot keep, and it is exactly the
 * kind of prose the featured-image hint turned out to be.
 *
 * ── AND IT OFFERS THE NEAREST BUILT THING ───────────────────────────────────
 * Landing on a dead end with no way onward is the other half of the problem.
 * Both current callers are under Appearance, where Menus IS built, so the page
 * points there rather than leaving the reader to find the back button.
 *
 * A FRAGMENT: `PlaceholderController::render()` wraps this in `ob_start()` and
 * requires `layout.php` itself. Do not add either here.
 *
 * @var string $pageTitle    the section's name
 * @var string $description  one sentence: what it will do
 * @var string $currentPage  sidebar highlight key
 */

$description = $description ?? '';
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title"><?= h($pageTitle) ?></h1>
        </div>
    </div>

    <div class="empty">
        <?= icon('binoculars', 'empty__glyph', '', 28) ?>
        <p class="empty__title">Not built yet</p>
        <?php if ($description !== ''): ?>
            <p class="empty__text"><?= h($description) ?></p>
        <?php endif; ?>
        <div class="empty__action">
            <a class="btn" href="<?= h(\Cms\Helpers\Url::admin('appearance/menus')) ?>">Go to Menus</a>
        </div>
    </div>

</div>
