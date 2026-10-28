<?php

declare(strict_types=1);

use Cms\Ui\Sidebar;

/**
 * A SECTION'S OWN LANDING PAGE — the generic one.
 *
 * `/admin/settings` and `/admin/tools` are group headings in the sidebar. Both
 * are also real URLs — reached by typing the bare URL, following a stale link,
 * or clicking the group row — and this is the page both controllers render
 * there.
 *
 * ── IT IS ONE PARTIAL, NOT ONE PER SECTION ──────────────────────────────────
 * The two callers differ only in their heading, their lede, and their list of
 * children. That is data, so it is passed as data. A second copy of this file
 * for the Tools section is how the two drift, and the sidebar already proves
 * the point: `Sidebar::iconMap()` is one map, not one per section.
 *
 * ── IT OWNS ITS WRAPPER, UNLIKE THE settings/* FRAGMENTS ────────────────────
 * `SettingsController::index()` requires this file directly, while every
 * `show*()` method wraps its template in `ob_start()` and requires `layout.php`
 * itself. So the fragments MUST NOT call `ob_start()` and this one MUST. Getting
 * that backwards renders the page inside-out or not at all — check the call site
 * before adding a template here, not the file next to it.
 *
 * ── EVERY CARD IS A LINK, AND ONLY A LINK ───────────────────────────────────
 * The whole card is an `<a>`, the same device the dashboard uses, so the target
 * is the card rather than the four words inside it. That also gets middle-click
 * and open-in-new-tab for free, which a `data-row-href` handler would not — and
 * no such handler ships in this package.
 *
 * @var string $sectionTitle
 * @var string $sectionLede   one sentence; what the section is FOR
 * @var list<array{label: string, url: string, text: string, icon: string}> $sections
 * @var string $pageTitle
 * @var array<string, mixed> $user
 */

$sectionLede = $sectionLede ?? '';
$sections    = $sections    ?? [];

ob_start();
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title"><?= h($sectionTitle) ?></h1>
            <?php // A SUBTITLE MUST BEAT WHAT THE SURFACE BELOW IT ALREADY SHOWS
                  // (core.css §1: no on-screen commentary). It earns
                  // its place here because the cards below name the four pages
                  // but not what the section as a whole is for — and it is the
                  // caller's sentence, so a section with nothing to add passes
                  // '' and gets no subtitle rather than a filler one. ?>
            <?php if ($sectionLede !== ''): ?>
                <p class="page__subtitle"><?= h($sectionLede) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-grid">
        <?php foreach ($sections as $section): ?>
            <a class="card" href="<?= h((string) $section['url']) ?>">
                <div class="card__head">
                    <?php // THE GLYPH COMES FROM THE SIDEBAR'S OWN MAP, keyed by
                          // the same row key. Both screens then draw one mark for
                          // one destination and cannot disagree — which they would
                          // the first time either was edited alone.
                          //
                          // Without it these would be five identical shells told
                          // apart only by reading them, which is what core.css §6
                          // says a card glyph is for. ?>
                    <?= icon(Sidebar::iconFor((string) ($section['icon'] ?? '')), 'card__glyph icon', '', 18) ?>
                    <strong><?= h((string) $section['label']) ?></strong>
                </div>
                <p class="card__text"><?= h((string) $section['text']) ?></p>
            </a>
        <?php endforeach; ?>
    </div>

</div>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
