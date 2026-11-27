<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Security\Csrf;
use Cms\Ui\Theme;

/**
 * THE DOCUMENT HEAD — asset order, the appearance attributes, and the
 * FIRST-PAINT SKELETON. All three are load-bearing.
 *
 * ── WHY THE ORDER MATTERS ───────────────────────────────────────────────────
 *   fonts   @font-face must be defined before any rule uses the family.
 *   reset   before tokens, so a token cannot be reset away.
 *   tokens  base FIRST (shared tokens + the default-paint alias on a bare
 *           `:root`, so first paint is never untokenized even if the resolver
 *           fails outright), then the two per-family theme files.
 *   core    the component vocabulary.
 *   shell   the app grid + sidebar, which depends on core.
 *   nav     the top bar + drawer, which depends on both.
 *
 * ── WHY THE SKELETON EXISTS ─────────────────────────────────────────────────
 * Stylesheets are render-blocking, so there is a blank frame before they parse.
 * By default that frame is FLAT WHITE — it flashes on every navigation and looks
 * worst on exactly the dark themes people choose to avoid glare.
 *
 * So the theme's page and chrome colours are emitted as inline custom properties
 * on `<html>`, and the shell is painted with them in an inline `<style>`. The
 * blank pre-stylesheet frame then already shows the structure, in-theme. The
 * instant the real sheet lands, its own `body { background: var(--bg) }`
 * overrides this and the grid paints opaque chrome on top — so these rules can
 * only ever show BEFORE the sheet, and can never fight it.
 *
 * The colours come from `Ui\Theme`, the same registry the CSS blocks mirror. A
 * stale value can only mis-tint a sub-second skeleton.
 *
 * ── AND WHY THE ATTRIBUTES ARE SERVER-RENDERED ──────────────────────────────
 * `data-theme` and `data-radius` must be in the HTML the server sends. If JS
 * stamps them after load, every navigation paints the default theme for a frame
 * and then swaps. `appearance.js` only ever writes the user's CHOICE.
 *
 * @var array<string, mixed>|null $user
 * @var string                    $pageTitle
 * @var bool                      $hasRail   does this page render a sidebar?
 */

$user      = $user      ?? null;
$pageTitle = $pageTitle ?? (string) \Cms\SEO\Head::globalTokens()['site_name'];
$hasRail   = $hasRail   ?? true;

// THE PAGES AT THE SIGN-IN ADDRESS wear Settings → Branding's theme
// (`Ui\Branding`); every other page its viewer's own (`Ui\Theme`).
$theme  = !empty($signInPage) ? \Cms\Ui\Branding::signInTheme() : Theme::resolve($user);
$radius = Theme::resolveRadius($user);
$scheme = Theme::scheme($theme);
$paint  = Theme::paint($theme);

// The preload href must resolve to the SAME URL as the @font-face src, or the
// browser treats the preload as unused and fetches the file twice. fonts.css
// references `../fonts/x.woff2` with no version query, so these carry none
// either — deliberately not Asset::admin(), which would append one.
$fontUrl = static fn (string $file): string => Url::admin('assets/fonts/' . $file);
?>
<!doctype html>
<html lang="en"
      data-theme="<?= h($theme) ?>"
      data-radius="<?= h($radius) ?>"
      style="color-scheme:<?= h($scheme) ?>;--paint-page:<?= h($paint['page']) ?>;--paint-nav:<?= h($paint['nav']) ?>;--paint-rail:<?= h($paint['rail']) ?>"<?= $hasRail ? ' class="has-rail-paint"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="<?= h($scheme) ?>">
    <?php // THE token, in ONE place. admin.js reads it from here and puts it on
          // the X-CSRF-Token header of every non-GET; www/index.php rejects any
          // /admin/ or /api/ POST without it. Without this tag every
          // fetch-based write in the admin would 403, and a 403 on a background
          // write is not a visible failure: it looks exactly like "nothing
          // happened". ?>
    <?= Csrf::metaTag() ?>
    <?php // The same for CMS.url(): the base address it builds on. Without it
          // only an install served from a SUBDIRECTORY breaks, which makes its
          // absence easy to miss. ?>
    <meta name="base-url" content="<?= h(BASE_URL) ?>">
    <?php // WHAT AN UPLOAD MAY BE, for CMS.upload(): the site's largest file
          // (`Media::maxFileSize()`) and the size of each piece a larger one is
          // sent in (`Media\Pieces`), in bytes. ?>
    <?php // THE THEME'S CLASSES FOR CONTENT, for the editor's Style menu (`Helpers\Theme::contentClasses()`). ?>
<?php if (($_contentClasses = \Cms\Helpers\Theme::contentClasses()) !== []): ?>
<meta name="content-classes" content="<?= h((string) json_encode($_contentClasses, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>">
<?php endif; ?>
<meta name="upload-limits" content="<?= (int) \Cms\Models\Media::maxFileSize() ?> <?= (int) \Cms\Media\Pieces::size() ?>">
    <?php // The ACTIVE theme's nav colour, not a constant. A <meta> cannot take
          // var(), so pinning this to one hex makes mobile browser chrome clash
          // with every theme but one. Same registry, one source. ?>
    <meta name="theme-color" content="<?= h($paint['nav']) ?>">
    <?php // The admin is never a search result. ?>
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($pageTitle) ?></title>

    <style>
      /* FIRST-PAINT SKELETON — see the header note. The literal fallbacks are
         what apply during the blank frame, because the token sheet has not
         parsed yet. The rail band is gated to desktop, where a rail exists. */
      html {
        background-color: var(--paint-page);
        background-image: linear-gradient(var(--paint-nav), var(--paint-nav));
        background-size: 100% var(--nav-h, 56px);
        background-position: 0 0;
        background-repeat: no-repeat;
      }
      @media (min-width: 721px) {
        html.has-rail-paint {
          background-image:
            linear-gradient(var(--paint-nav), var(--paint-nav)),
            linear-gradient(var(--paint-rail), var(--paint-rail));
          background-size: 100% var(--nav-h, 56px), var(--rail-w, 240px) 100%;
          background-position: 0 0, 0 0;
        }
      }
    </style>

    <link rel="preload" as="font" type="font/woff2" crossorigin href="<?= h($fontUrl('inter-latin-400.woff2')) ?>">
    <link rel="preload" as="font" type="font/woff2" crossorigin href="<?= h($fontUrl('inter-latin-600.woff2')) ?>">

    <link rel="stylesheet" href="<?= h(Asset::admin('css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= h(Asset::admin('css/reset.css')) ?>">
    <link rel="stylesheet" href="<?= h(Asset::admin('css/tokens-base.css')) ?>">
    <link rel="stylesheet" href="<?= h(Asset::admin('css/tokens/signature-styles.css')) ?>">
    <link rel="stylesheet" href="<?= h(Asset::admin('css/core.css')) ?>">
    <link rel="stylesheet" href="<?= h(Asset::admin('css/shell.css')) ?>">
    <link rel="stylesheet" href="<?= h(Asset::admin('css/nav.css')) ?>">
    <?php // LAST: the CMS's own thin layer, for the surfaces the package does
          // not cover. See its header for what is allowed to live there. ?>
    <link rel="stylesheet" href="<?= h(Asset::admin('css/admin.css')) ?>">
<?php // A SITE'S OWN ADMIN THEME (`Ui\Theme`): its stylesheet, last, so its
      // tokens win. www/site/ is the site's; no package ships it, no update
      // touches it.
if (($_themeCss = Theme::stylesheet($theme)) !== null): ?>
    <link rel="stylesheet" href="<?= h($_themeCss) ?>">
<?php endif; ?>

    <?php // admin.js in <head>: page templates emit their own <script> tags
          // inside $pageContent, which the layout prints ABOVE its own. Anything
          // they call has to already exist. ?>
    <script src="<?= h(Asset::admin('js/admin.js')) ?>"></script>
    <?php // CMS.upload(), for every screen that puts a file in the library. ?>
    <script src="<?= h(Asset::admin('js/upload.js')) ?>"></script>
</head>
