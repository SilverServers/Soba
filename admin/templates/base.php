<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Models\Option;

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
 * ── THE OWNER'S LOOK, STAMPED ───────────────────────────────────────────────
 * The admin's palette and corners are each user's (`users.theme_preset`,
 * `users.radius_pref`); the public site's are the owner's, chosen once on
 * Appearance → Customizer (`Site\Look`) and the same for every visitor. They
 * are attributes on `<html>`: `data-theme`, `data-radius`, `data-font-body`,
 * `data-font-head`. The palette's family sheet follows the base tokens when the
 * palette is not the default, whose tokens `tokens-base.css`'s bare `:root`
 * already carries, so a page is tokenized before any attribute is read.
 * Without `data-radius` the bare `:root` resolves to the rounded tier, which
 * is why it is always stamped, square unless the owner chose rounded.
 *
 * @var string $pageContent  the rendered inner template
 */
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\Cms\Site\Language::current()) ?>"<?= \Cms\Site\Look::htmlAttributes() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= \Cms\SEO\Head::render() ?>
    <?= \Cms\Site\Look::iconTags() ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(Asset::site('assets/css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(Asset::site('assets/css/reset.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(Asset::site('assets/css/tokens-base.css')) ?>">
<?php if (($_paletteSheet = \Cms\Site\Look::paletteSheet()) !== ''): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($_paletteSheet) ?>">
<?php endif; ?>
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
<?php // THE WAY PAST THE HEADER, first in the page and seen only when a key
      // reaches it: a keyboard or a switch goes straight to what the page is
      // for, not through every link of the menu again (WCAG 2.4.1). ?>
<?php // audit-ok: the keyboard's way past the header, the admin's own words for it. ?>
<a class="skip-link" href="#main">Skip to content</a>

<?php $_publicSiteName = \Cms\SEO\Head::globalTokens()['site_name']; ?>
<header class="site-header">
    <?= \Cms\Site\Look::brand($_publicSiteName) ?>
    <?= \Cms\Helpers\MenuRenderer::render('primary', 'site-header__nav') ?>
    <?php
    /* THE BASKET LINK APPEARS WHEN THERE IS SOMETHING TO SELL, AND NOT BEFORE.
       `Registry::withArchetype('product')` is the test — the Registry, not a
       model query, because a type registered in `config/` is not in the
       `content_type_definitions` table and a shop that could not see its own
       products would look like a broken link.

       An install with no product type is not a shop, and a "Basket (0)" in the
       header of a plumber's brochure site is a control that leads nowhere.
       NOR IN A STATIC COPY (`Export\StaticCopy`): its products are a
       catalogue, with no basket or orders to lead to. */
    if (\Cms\ContentType\Registry::withArchetype('product') !== [] && !defined('CMS_EXPORT')):
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

<?php // tabindex="-1" so the skip link moves focus here, not only the view. ?>
<main class="site-content" id="main" tabindex="-1">
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
        <?php // Escaped first, so the owner's text stays text; then the
              // business's [phone], [email], [hours] in it become what they say. ?>
        <?= $_footerText !== ''
            ? \Cms\Site\Business::shortcodes(htmlspecialchars($_footerText))
            : '&copy; ' . date('Y') . ' ' . htmlspecialchars($_publicSiteName) ?>
    </div>

    <?php // THE WAY BACK TO THE COOKIE QUESTION, where the site asks it
          // (`Site\Google::consentLink()`); nothing where it does not. ?>
    <?= \Cms\Site\Google::consentLink() ?>

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
