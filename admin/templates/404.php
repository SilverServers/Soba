<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Ui\Theme;

/**
 * 404 — a standalone document, shared by BOTH sides.
 *
 * The admin controllers require this file too (PageController, TermController,
 * FieldGroupController, ContentTypeDefinitionController), so it is not a public
 * template that happens to be reused — it is the one not-found page. It carries
 * its own <html> rather than routing through base.php because a 404 has no site
 * chrome to render into and no $pageContent to be handed.
 *
 * It links the public theme's sheets, for the same reason base.php does: one
 * vocabulary, no parallel definition of what a page looks like.
 */
?>
<!DOCTYPE html>
<html lang="en" data-radius="<?= htmlspecialchars(Theme::DEFAULT_RADIUS) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php // Google's measurement tags, first, as base.php's head prints them.
          // The admin requires this page too, and there they print nothing. ?>
    <?= \Cms\Site\Google::head() ?>
    <?php // audit-ok: the browser tab on a page that has no content to name. ?>
    <title>404 Not Found</title>
    <?= \Cms\Ui\Brand::iconTags() ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(Asset::site('assets/css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(Asset::site('assets/css/reset.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(Asset::site('assets/css/tokens-base.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(Asset::site('assets/css/theme.css')) ?>">
<?php // The active theme's stylesheet, then the site's own, as base.php links them.
if (($_themeCss = \Cms\Helpers\Theme::stylesheet()) !== null): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($_themeCss) ?>">
<?php endif; ?>
<?php // The site's own stylesheet, as base.php links it.
if (is_file(PUBLIC_PATH . '/site/theme.css')): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(Asset::site('site/theme.css')) ?>">
<?php endif; ?>
</head>
<body>
<?= \Cms\Site\Google::body() ?>
    <main class="site-content">
        <div class="notfound">
            <p class="notfound__code">404</p>
            <?php /* The words shown when the requested content does not exist.
                     There is nothing for an owner to edit them on. audit-ok */ ?>
            <p class="notfound__text">Page not found.</p>
            <a class="notfound__home" href="<?= htmlspecialchars(BASE_URL . '/') ?>">Go home</a>
        </div>
    </main>
</body>
</html>
