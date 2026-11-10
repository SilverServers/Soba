<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Models\Option;
use Cms\Ui\Theme;

/**
 * THE PUBLIC THEME'S LAYOUT.
 *
 * ── THE STYLESHEETS ARE LINKED, NOT INLINE ──────────────────────────────────
 * Every class the admin writes into the front end — the whole `cms-gallery*`
 * and `cms-form__*` sets, the archetype meta blocks — is styled by the sheets
 * below, not by an inline <style> block that styles the chrome and the post
 * list and nothing else.
 *
 * Order matters and is the package's: faces, then reset, then tokens, then
 * components — see the header of `www/assets/css/theme.css`.
 *
 * ── data-radius, AND WHY THERE IS NO data-theme ─────────────────────────────
 * `Theme`'s two axes are stored per USER (`users.theme_preset`,
 * `users.radius_pref`), which a public visitor does not have. A site's public
 * look is the owner's, not whichever operator last opened the admin, so the
 * palette here is the token layer's own default — `tokens-base.css`'s bare
 * `:root`, which carries the full default palette precisely so a page is
 * tokenized before any attribute is stamped.
 *
 * `data-radius` IS stamped, from `Theme::DEFAULT_RADIUS` rather than the
 * literal string, so the front end cannot drift from the admin's default the
 * day someone changes that constant. Without the attribute the bare `:root`
 * resolves to the rounded tier, which would put 6px on the public site's
 * controls while the admin ships flat.
 *
 * @var string $pageContent  the rendered inner template
 */
?>
<!DOCTYPE html>
<html lang="en" data-radius="<?= htmlspecialchars(Theme::DEFAULT_RADIUS) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= \Cms\SEO\Head::render() ?>
    <?= \Cms\Ui\Brand::iconTags() ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(Asset::site('assets/css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(Asset::site('assets/css/reset.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(Asset::site('assets/css/tokens-base.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(Asset::site('assets/css/theme.css')) ?>">
    <?php // THE ONE COMPONENT THE ADMIN RENDERS TOO. The gallery editor links
          // this same file, so the tiles it arranges are laid out by the rules
          // that will lay them out on this page — not by a second grid that
          // agreed with it on the day it was written. See gallery.css's header. ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(Asset::site('assets/css/gallery.css')) ?>">
<?php // The active theme's stylesheet (Appearance → Themes, `Helpers\Theme`):
      // after the CMS's, before the site's own, so the site can still correct
      // it. A theme of nothing but `assets/theme.css` restyles every page here.
if (($_themeCss = \Cms\Helpers\Theme::stylesheet()) !== null): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($_themeCss) ?>">
<?php endif; ?>
<?php // The site's own stylesheet, last so it wins. www/site/ is the site's:
      // no package ships it and no update touches it. At column 0 so that,
      // with no such file, the page is byte-for-byte what it would be without
      // this block.
if (is_file(PUBLIC_PATH . '/site/theme.css')): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(Asset::site('site/theme.css')) ?>">
<?php endif; ?>
</head>
<body>
<?= \Cms\Site\Google::body() ?>

<?php $_publicSiteName = \Cms\SEO\Head::globalTokens()['site_name']; ?>
<header class="site-header">
    <a href="<?= htmlspecialchars(BASE_URL . '/') ?>" class="site-header__name"><?= htmlspecialchars($_publicSiteName) ?></a>
    <?= \Cms\Helpers\MenuRenderer::render('primary', 'site-header__nav') ?>
    <?php
    /* THE BASKET LINK APPEARS WHEN THERE IS SOMETHING TO SELL, AND NOT BEFORE.
       `Registry::withArchetype('product')` is the test — the Registry, not a
       model query, because a type registered in `config/` is not in the
       `content_type_definitions` table and a shop that could not see its own
       products would look like a broken link.

       An install with no product type is not a shop, and a "Basket (0)" in the
       header of a plumber's brochure site is a control that leads nowhere. */
    if (\Cms\ContentType\Registry::withArchetype('product') !== []):
        $_cartCount = \Cms\Commerce\Cart::count();
    ?>
        <?php /* A CUSTOMER'S ORDERS, beside the basket: their way back to
                 an order without the email it came in. */ ?>
        <a href="<?= htmlspecialchars(BASE_URL . '/account') ?>" class="site-header__account">Your orders</a><?php /* audit-ok: the name of the page it opens, which the system draws */ ?>
        <a href="<?= htmlspecialchars(BASE_URL . '/cart') ?>" class="site-header__cart">
            <?= htmlspecialchars(\Cms\Commerce\Words::of('basket')) ?>
            <?php /* No zero badge: a permanent "0" is furniture that says
                     nothing. Absent at zero, present the moment it is not. */ ?>
            <?php if ($_cartCount > 0): ?>
                <span class="site-header__cart-count"><?= (int) $_cartCount ?></span>
                <span class="u-visually-hidden">items in your <?= htmlspecialchars(\Cms\Commerce\Words::basketInline()) ?></span><?php /* audit-ok: the words either side of the shop's own name for a basket */ ?>
            <?php endif; ?>
        </a>
    <?php endif; ?>
</header>

<main class="site-content">
    <?php
    /* THE SHOP'S ONE-SHOT MESSAGE, RENDERED WHEREVER THE VISITOR LANDS.
       "Add to basket" sends the customer back to the product page they were
       reading — which is right, and which would make the button appear to do
       nothing at all if only the basket template drew the notice. A control
       that gives no sign of having worked is one people press twice.

       Read and cleared HERE rather than in each controller: one mechanism,
       not one per screen. A controller that sets a notice does
       not have to know which template will show it, and a template that
       forgets to render it cannot exist. */
    $_shopNotice = $_SESSION['shop_notice'] ?? null;
    unset($_SESSION['shop_notice']);
    if (is_array($_shopNotice)):
    ?>
        <div class="shop-notice cms-alert cms-alert--<?= htmlspecialchars((string) ($_shopNotice['tone'] ?? 'success')) ?> imprint-alert imprint-alert--<?= htmlspecialchars((string) ($_shopNotice['tone'] ?? 'success')) ?>"
             role="status">
            <?= htmlspecialchars((string) ($_shopNotice['text'] ?? '')) ?>
            <?php // TO THE BASKET from a notice about it, and not from one about
                  // something else: an address left for a sold-out size. ?>
            <?php if (($_shopNotice['tone'] ?? '') === 'success' && ($_shopNotice['basket'] ?? true)): ?>
                <a class="shop__link" href="<?= htmlspecialchars(BASE_URL . '/cart') ?>">View basket</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?= $pageContent ?>
</main>

<?php
/* ── THE FOOTER IS THE OWNER'S ───────────────────────────────────────────────
   Its text is `site_footer_text` from Settings → General — an address, a
   company number, a charity registration, the things a footer is actually
   for — not a line written here where no owner can reach it.

   AND EVERY MENU LOCATION APPEARS. `config/menus.php` registers `footer` and
   `footer_secondary` alongside `primary`, and the admin offers all three on
   the menu editor's "Show this menu in" select. A location left unrendered is
   a Footer Navigation an owner can build, assign and save, and find on no page
   of their site — the admin offering something and the site ignoring it. */
$_footerText  = trim((string) (Option::get('site_footer_text', '') ?? ''));
$_footerMenus = array_filter([
    \Cms\Helpers\MenuRenderer::render('footer', 'site-footer__nav'),
    \Cms\Helpers\MenuRenderer::render('footer_secondary', 'site-footer__nav'),
]);
?>
<footer class="site-footer">
    <?php /* audit-ok: the fallback beside a real field — `site_footer_text` on
             Settings → General is what an owner types, and blank keeps this
             rather than emptying the footer of a site that never chose. */ ?>
    <div class="site-footer__text">
        <?= $_footerText !== ''
            ? htmlspecialchars($_footerText)
            : '&copy; ' . date('Y') . ' ' . htmlspecialchars($_publicSiteName) ?>
    </div>

    <?php if ($_footerMenus !== []): ?>
        <div class="site-footer__menus">
            <?php foreach ($_footerMenus as $_menu): ?>
                <?= $_menu ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</footer>

<?php // OPEN-ON-CLICK, the one menu behaviour CSS cannot express. Loaded on
      // every page because a menu is on every page; it returns immediately when
      // no menu on the page asked for the click gesture.
      //
      // AT THE FOOT AND NOT DEFERRED IN THE HEAD: it binds to markup above it,
      // so by the time it runs the menu exists, and there is no build step to
      // arrange anything cleverer. ?>
<script src="<?= htmlspecialchars(Asset::site('assets/js/theme-menu.js')) ?>"></script>

</body>
</html>
