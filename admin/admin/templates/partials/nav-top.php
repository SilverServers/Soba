<?php

declare(strict_types=1);

use Cms\Helpers\Url;

/**
 * THE TOP NAV — brand, pillars, user chip, hamburger.
 *
 * Rendered once, inside `.app`, as the `nav` grid area's occupant.
 *
 * ── TEXT-ONLY. NO ICONS. ────────────────────────────────────────────────────
 * The bar, its dropdowns and the user chip carry NO glyphs, which is the exact
 * opposite of the sidebar's every-row-has-one rule. Both are correct: the
 * sidebar collapses to a rail where the glyph IS the row, while the bar never
 * collapses to glyphs — it DROPS items as width falls. At nav size a glyph reads
 * as noise, and two of three items wearing one is worse than none.
 *
 * The ONE exception is the group caret, which indicates STATE (it rotates)
 * rather than labelling anything — and it exists because without a caret you
 * can see, grouped titles read exactly like plain links.
 *
 * ── AND NO TOOLTIPS. ────────────────────────────────────────────────────────
 * Every control in here carries a visible label, so none may carry `data-tip`.
 *
 * ── HIDING BEATS SHRINKING ──────────────────────────────────────────────────
 * The bar never wraps and never squishes: every child holds its intrinsic size
 * and only `.nav-top__spacer` flexes. Add anything here and add it to nav.css's
 * DROP cascade in the same change, or the bar overflows at a width nobody tested.
 *
 * @var list<array<string, mixed>> $navItems
 * @var array<string, mixed>|null  $user
 * @var string                     $env  '' on production; anything else badges
 * @var string                     $navTitle  the screen's own title, no app suffix
 * @var array{new: list<array>, site: string} $tools  Ui\AdminMenu::barTools()
 */

$navItems = $navItems ?? [];
$user     = $user     ?? null;
$env      = (string) ($env ?? '');
$tools    = $tools ?? ['new' => [], 'site' => '#'];

/**
 * One nav row, recursive. A row with `items` is a dropdown group; a row with
 * `href` is a link. Nesting deeper than two levels almost always means the
 * information architecture is wrong.
 */
$navRow = function (array $it, int $depth = 0) use (&$navRow): void {
    $active = !empty($it['active']);

    if (!empty($it['items'])) {
        $sub = $depth > 0;
        ?>
        <div class="nav-top__group<?= $sub ? ' nav-top__group--sub' : '' ?>" data-nav-group>
            <button type="button"
                    class="nav-top__link nav-top__group-toggle<?= $sub ? ' nav-top__subgroup-toggle' : '' ?><?= $active ? ' is-active' : '' ?>"
                    aria-expanded="false" aria-haspopup="true">
                <?= h((string) $it['label']) ?>
                <?= icon(
                        $sub ? 'angle-right' : 'angle-down',
                        'nav-top__group-caret icon icon--sm' . ($sub ? ' nav-top__group-caret--right' : ''),
                        '',
                        12
                    ) ?>
            </button>
            <div class="nav-top__group-menu<?= $sub ? ' nav-top__group-menu--sub' : '' ?>" hidden>
                <?php foreach ($it['items'] as $child) { $navRow($child, $depth + 1); } ?>
            </div>
        </div>
        <?php
        return;
    }
    ?>
    <a class="nav-top__link<?= $active ? ' is-active' : '' ?>"
       href="<?= h((string) ($it['href'] ?? '#')) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
        <?= h((string) $it['label']) ?>
        <?php if (!empty($it['badge'])): ?>
            <span class="nav-top__badge"><?= (int) $it['badge'] ?></span>
        <?php endif; ?>
    </a>
    <?php
};
?>
<nav class="nav-top" aria-label="Primary">
    <?php // The hamburger comes FIRST in source order so it is the first tab
          // stop on a narrow viewport, where it is the only route to navigation.
          // CSS hides it on wide screens — one node, one state. ?>
    <button type="button" class="nav-top__hamburger" data-drawer-toggle
            aria-expanded="false" aria-controls="nav-drawer" aria-label="Open navigation">
        <?= icon('bars', 'icon icon--lg') ?>
    </button>

    <?php // The brand IS the dashboard link. A separate "Dashboard" item would
          // spend a slot in the drop cascade on the one destination every admin
          // already knows how to reach. ?>
    <a class="nav-top__brand" href="<?= h(Url::admin()) ?>">
        <?php // The CMS in the site's own name and mark (Appearance → Branding). ?>
        <?php if (\Cms\Ui\Brand::logoUrl() !== ''): ?>
            <?php // The design language's mark: 28px high, and the one thing
                  // left of the brand below 560px (nav.css §2 and nav.css §6). ?>
            <img class="nav-top__brand-mark" src="<?= h(\Cms\Ui\Brand::logoUrl()) ?>" alt="">
        <?php endif; ?>
        <span><?= h(\Cms\Ui\Brand::name()) ?></span>
        <?php if ($env !== ''): ?>
            <?php // NEUTRAL chip — the TEXT carries the meaning. Alert colours
                  // are a standalone surface and never sit inside themed chrome. ?>
            <span class="nav-top__env-badge"><?= h($env) ?></span>
        <?php endif; ?>
    </a>

    <?php // ── THE BAR CARRIES NO DESTINATIONS ────────────────────────────
          // `Ui\AdminMenu::navItems()` returns [] on purpose: the sidebar holds
          // the whole information architecture in one column. Pillars here
          // would be a SECOND navigation naming the same places, and two
          // navigations disagreeing about where you are is worse than one.
          //
          // The container still renders — `nav.css`'s drop cascade targets it,
          // and it is where anything genuinely bar-level (a site switcher, an
          // environment jump) would go. ?>
    <div class="nav-top__links">
        <?php foreach ($navItems as $it) { $navRow($it); } ?>
    </div>

    <?php // WHERE AM I. With no destinations the bar would have nothing in the
          // middle at all, and a chrome bar that is empty from the brand to the
          // avatar reads as a broken load. The page title goes here for exactly
          // this reason, and it answers a real question on a screen scrolled
          // halfway down a long form. ?>
    <?php if (($navTitle ?? '') !== ''): ?>
        <span class="nav-top__page"><?= h((string) $navTitle) ?></span>
    <?php endif; ?>

    <?php // The ONLY flexing child. ?>
    <div class="nav-top__spacer"></div>

    <?php // ── TOOLS: THINGS YOU DO, NOT PLACES YOU GO ─────────────────────
          // The sidebar answers "where am I / where can I go", so an action you
          // take from every screen has no honest row in it and belongs here.
          //
          // These two carry the bar's only glyphs, and the text-only rule above
          // is bent deliberately: `plus` IS the verb, and `up-right-from-square`
          // is the standard mark for a link that opens a new tab — the same one
          // the entry list uses on its own view-on-site control. Neither
          // repeats its label; both say something it does not. ?>
    <?php if ($tools['new'] !== []): ?>
        <div class="nav-top__group" data-nav-group>
            <button type="button" class="nav-top__link nav-top__group-toggle nav-top__tool"
                    aria-expanded="false" aria-haspopup="true">
                <?= icon('plus', 'icon icon--sm', '', 12) ?>
                New
            </button>
            <div class="nav-top__group-menu" hidden>
                <?php foreach ($tools['new'] as $item): ?>
                    <a class="nav-top__link" href="<?= h((string) $item['href']) ?>">
                        <?= h((string) $item['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <a class="nav-top__link nav-top__tool" href="<?= h((string) $tools['site']) ?>"
       target="_blank" rel="noopener">
        View site
        <?php // The glyph goes AFTER the label here, because it is a property of
              // the destination ("opens elsewhere"), not the name of it. ?>
        <?= icon('up-right-from-square', 'icon icon--sm', '', 12) ?>
    </a>

    <?php // The user cluster is OUTSIDE .nav-top__links on purpose: the cascade
          // hides that container wholesale at ≤lg and moves its contents into
          // the drawer. Sign-out must never be a thing you have to open a drawer
          // to find, and the drawer renders destinations, not session controls. ?>
    <?php if ($user): ?>
        <div class="nav-top__user">
            <a class="nav-top__user-link" href="<?= h(Url::admin('users/' . (int) $user['id'] . '/edit')) ?>">
                <?php // The avatar ring carries identity. The CMS has no uploaded
                      // avatars, so the initial is what we DO know — a literal "?"
                      // would be filling a slot with nothing. ?>
                <span class="avatar nav-top__avatar" aria-hidden="true"><?= h(mb_substr((string) $user['username'], 0, 1)) ?></span>
                <span class="nav-top__user-name"><?= h((string) $user['username']) ?></span>
            </a>
            <?php // The token: `AuthController::logout()` ignores a sign-out
                  // without it, so another site cannot sign you out with an
                  // image tag. ?>
            <a class="nav-top__link" href="<?= h(Url::admin('logout') . '?_token=' . rawurlencode(\Cms\Security\Csrf::token())) ?>">Log out</a>
        </div>
    <?php endif; ?>
</nav>
