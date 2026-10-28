<?php

declare(strict_types=1);

/**
 * TURNED AWAY — an address the owner has shut out of the sign-in page and the
 * admin (`Security\AddressRules`). 403, the site's name and the owner's
 * message, and nothing else: no form, no link, no hint of what is behind it.
 *
 * @var string $message  the owner's words, or the default
 */

$site      = \Cms\SEO\Head::globalTokens()['site_name'];
$pageTitle = $site;
$hasRail   = false;
$user      = null;

require ROOT_PATH . '/admin/templates/partials/head.php';
?>
<body>
<main class="auth">
    <div class="auth__inner">
        <div class="auth__brand"><?= h($site) ?></div>
        <div class="form-card">
            <div class="form-card__section">
                <p class="form-help"><?= h($message) ?></p>
            </div>
        </div>
    </div>
</main>
</body>
</html>
