<?php

declare(strict_types=1);

use Cms\Auth\Auth;
use Cms\Helpers\Asset;
use Cms\Ui\AdminMenu;

/**
 * THE ADMIN LAYOUT — the one chrome, wrapped around every authenticated screen.
 *
 * A controller renders its screen into `$pageContent` and requires this:
 *
 *     $pageTitle = 'News';
 *     ob_start();
 *     require ROOT_PATH . '/admin/templates/content-type/list.php';
 *     $pageContent = ob_get_clean();
 *     require ROOT_PATH . '/admin/templates/layout.php';
 *
 * ── THE NAV IS DERIVED, NOT PASSED ──────────────────────────────────────────
 * Several controllers also set `$currentPage` — a string naming the active
 * sidebar row. This file deliberately ignores it. `AdminMenu` derives both the
 * active pillar and the active row from the REQUEST PATH, which cannot drift out
 * of sync with where the user actually is. A hand-passed marker can, and a
 * sidebar that lights the wrong row is a screen quietly lying about where you
 * are standing. `$currentPage` stays harmless where it is already set; nothing
 * reads it.
 *
 * @var string      $pageTitle
 * @var string      $pageContent  markup, already escaped by whoever built it
 * @var string|null $currentPage  ignored — see above
 */

// ── TWO WRAPPING CONVENTIONS COEXIST IN THIS CODEBASE, AND MIXING THEM IS
//    INVISIBLE ────────────────────────────────────────────────────────────────
// Most controllers `require` a template that buffers ITSELF and requires this
// file at its foot. Six (ContentTypeDefinition, FieldGroup, Settings, Taxonomy,
// Term, Placeholder) buffer the template themselves and require this file
// afterwards — the pattern the docblock above documents.
//
// Hand a self-wrapping template to a wrapping controller and you do not get a
// 500. You get HTTP 200 and THE ENTIRE ADMIN SHELL RENDERED TWICE, NESTED: a
// second top bar, a second sidebar and a second page inside `.main` — which
// only looking at the page reveals. So a body that is already a whole
// document is printed as it is.
//
// No page body legitimately contains `<body`, so this cannot false-positive.
if (str_contains((string) ($pageContent ?? ''), '<body')) {
    echo $pageContent;
    return;
}

$user     = Auth::user();
// WHERE THE SIDEBAR SAYS YOU ARE. The address, unless the screen knows better:
// a spam submission lives at /admin/forms/submissions/{id} like any other, and
// highlighting Submissions while you read something kept as spam is the
// sidebar disagreeing with the back link above the title. A template sets
// `$sidebarHere` to the admin path it belongs under.
$here     = isset($sidebarHere) && is_string($sidebarHere) ? $sidebarHere : AdminMenu::here();
$navItems = AdminMenu::navItems($here);
$spec     = AdminMenu::sidebarSpec($here);
$hasRail  = $spec['nav'] !== [];

// The environment badge. Non-production installs say so in the chrome, because
// "which one am I editing" is the question behind most destructive accidents.
$__config = @include ROOT_PATH . '/config/config.php';
$env      = is_array($__config) && ($__config['app_env'] ?? 'production') !== 'production'
    ? strtoupper((string) $__config['app_env'])
    : '';
unset($__config);

// The bar shows WHERE YOU ARE, so it wants the screen's own title — not the
// document title with the app name appended. Captured before the suffix goes on,
// rather than stripped off again downstream.
$navTitle  = (string) ($pageTitle ?? '');
$pageTitle = ($pageTitle ?? 'Admin') . ' · ' . \Cms\Ui\Brand::name();

require ROOT_PATH . '/admin/templates/partials/head.php';
?>
<body>
<?php // WCAG 2.4.1 bypass-blocks: lets a keyboard user skip the bar and the rail
      // and land on the content. Invisible until focused. ?>
<a class="skip-link" href="#main">Skip to content</a>

<div class="app<?= $hasRail ? '' : ' app--no-rail' ?>">
    <?= partial('nav-top', ['navItems' => $navItems, 'user' => $user, 'env' => $env, 'navTitle' => $navTitle, 'tools' => AdminMenu::barTools()]) ?>

    <?php if ($hasRail): ?>
        <?= partial('sidebar', ['spec' => $spec]) ?>
    <?php endif; ?>

    <?php // tabindex="-1" so the skip link can actually move focus here.
          //
          // `$mainFlush` DROPS .main's 24px padding and stops it growing past the
          // viewport. Exactly one kind of screen needs it: one that owns the whole
          // area edge to edge and scrolls INSIDE itself rather than scrolling the
          // page — the assistant, whose composer has to stay pinned to the bottom.
          // A padded, page-scrolling main pushes that composer off screen the
          // moment the conversation is longer than the window.
          //
          // A boolean and not a free class string, so this cannot become a second
          // way to style `.main` from any template that fancies one. ?>
    <main class="main<?= !empty($mainFlush) ? ' main--flush' : '' ?>" id="main" tabindex="-1">
        <?= $pageContent ?? '' ?>
    </main>
</div>

<?php // A SIBLING of .app, not a child: it is position:fixed and must not
      // inherit the grid's stacking or overflow. ?>
<?= partial('nav-drawer', ['navItems' => $navItems]) ?>

<?php // Chrome behaviour only. Screen-specific scripts are emitted by the
      // screen itself, inside $pageContent — which prints ABOVE these, which is
      // why admin.js is in <head> rather than here. ?>
<script src="<?= h(Asset::admin('js/tooltip.js')) ?>"></script>
<script src="<?= h(Asset::admin('js/sidebar.js')) ?>"></script>
<script src="<?= h(Asset::admin('js/nav.js')) ?>"></script>
<script src="<?= h(Asset::admin('js/appearance.js')) ?>"></script>
</body>
</html>
