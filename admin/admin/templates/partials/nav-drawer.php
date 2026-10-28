<?php

declare(strict_types=1);

/**
 * THE MOBILE DRAWER — the narrow-viewport home for BOTH navigations.
 *
 * Rendered once, as a SIBLING of `.app` — not inside it. It is `position: fixed`
 * and must not inherit the grid's stacking or overflow.
 *
 * ── IT RELOCATES THE SIDEBAR. IT DOES NOT RE-RENDER IT. ─────────────────────
 * Note what is NOT in this file: any sidebar markup. `[data-drawer-rail]` is an
 * EMPTY MOUNT POINT. On open, `nav.js` moves the one live `[data-sidebar]` node
 * into it; on close it moves it back. Rendering a second copy is the obvious
 * approach and it is wrong — it duplicates every element id, and a binding made
 * on one copy is dead on the other. Moving the node means every binding keeps
 * working on mobile for free, because it is the same node.
 *
 * ── TWO TABS, ONE SCROLL REGION ─────────────────────────────────────────────
 * "Navigate" holds the destinations from the top bar. "This page" holds whatever
 * the current page's sidebar declares. Only the active panel is displayed, so
 * exactly one thing scrolls; stacking both would mean two navigations fighting
 * for the same column and a double scrollbar.
 *
 * @var list<array<string, mixed>> $navItems
 */

$navItems = $navItems ?? [];

/**
 * Flatten the nav tree for the drawer: a dropdown is a group HEADING here, not
 * a menu. A hover flyout inside a 320px touch panel is unusable, and there is no
 * width pressure to justify one — the drawer scrolls.
 */
$drawerRows = function (array $items) use (&$drawerRows): void {
    foreach ($items as $it) {
        if (!empty($it['items'])) {
            // .list__group-header, not a dressed-up .sb__sec-head. That class is
            // a BUTTON's skin, and wearing it on an inert div would mean
            // disabling pointer events to stop it looking clickable — a
            // component you have to partially undo is the wrong component.
            ?><div class="list__group-header"><?= h((string) $it['label']) ?></div><?php
            $drawerRows($it['items']);
            continue;
        }
        ?>
        <a class="nav-drawer__link<?= !empty($it['active']) ? ' is-active' : '' ?>"
           href="<?= h((string) ($it['href'] ?? '#')) ?>"<?= !empty($it['active']) ? ' aria-current="page"' : '' ?>>
            <span><?= h((string) $it['label']) ?></span>
            <?php if (!empty($it['badge'])): ?>
                <span class="nav-top__badge"><?= (int) $it['badge'] ?></span>
            <?php endif; ?>
        </a>
        <?php
    }
};
?>
<div class="nav-drawer-backdrop" data-drawer-backdrop></div>

<div class="nav-drawer" id="nav-drawer" data-drawer aria-hidden="true" role="dialog" aria-label="Navigation">
    <div class="nav-drawer__head">
        <span class="nav-drawer__title">Menu</span>
        <button type="button" class="nav-drawer__close" data-drawer-close aria-label="Close navigation">
            <?= icon('x', 'icon icon--lg') ?>
        </button>
    </div>

    <?php // ── ONE PANEL OR TWO, DECIDED BY WHETHER THERE IS A SECOND ─────────
          //
          // The top bar carries NO destinations: the sidebar holds the whole
          // information architecture in one column (see `Ui\AdminMenu`). So
          // `$navItems` is empty, and a "Navigate" tab over an empty panel
          // would be a control that opens nothing — with the real navigation
          // hidden behind the OTHER tab, one tap further away.
          //
          // The tab strip therefore renders only when there is genuinely a
          // choice to make. `nav.js` binds `[data-drawer-tab]` and simply finds
          // none. ?>
    <?php $hasDestinations = $navItems !== []; ?>

    <?php if ($hasDestinations): ?>
        <div class="nav-drawer__tabs" role="tablist">
            <button type="button" class="nav-drawer__tab is-active" data-drawer-tab="nav"
                    role="tab" aria-selected="true">Navigate</button>
            <button type="button" class="nav-drawer__tab" data-drawer-tab="page"
                    role="tab" aria-selected="false">This page</button>
        </div>

        <div class="nav-drawer__panel is-active" data-drawer-panel="nav" role="tabpanel">
            <nav class="nav-drawer__nav" aria-label="Destinations">
                <?php $drawerRows($navItems); ?>
            </nav>
        </div>
    <?php endif; ?>

    <?php // An EMPTY MOUNT POINT. The live sidebar node moves in here on open
          // and back out on close. Do not render a sidebar here. ?>
    <div class="nav-drawer__panel<?= $hasDestinations ? '' : ' is-active' ?>"
         data-drawer-panel="page" role="tabpanel">
        <div class="nav-drawer__rail" data-drawer-rail></div>
    </div>
</div>
