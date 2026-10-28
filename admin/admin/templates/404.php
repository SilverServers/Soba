<?php

declare(strict_types=1);

use Cms\Auth\Auth;
use Cms\Helpers\Url;

/**
 * ADMIN 404 — a mistyped admin URL.
 *
 * ── WHY THE ADMIN HAS ITS OWN ──────────────────────────────────────────────
 * Without it, a mistyped admin URL would render the PUBLIC site's 404 —
 * masthead, marketing footer, "Back to home" — inside the admin, which reads as
 * the CMS having thrown you out. `www/index.php` routes admin paths here instead.
 *
 * ── AND WHY THIS FILE EXISTING AT ALL IS THE POINT ─────────────────────────
 * Without it, `/admin/anything-wrong` returns **HTTP 404 with a PHP fatal behind
 * it**: index.php sets the status code and then requires this template, so the
 * response header is already correct and already sent when the require dies.
 * Zero bytes of body, a perfectly ordinary-looking 404 in any access log, and a
 * fatal in the error log. The status code is not the evidence; the error log
 * is.
 *
 * ── SIGNED OUT, THIS RENDERS NO CHROME ─────────────────────────────────────
 * The shell's nav and sidebar name every destination in the install. There is no
 * reason to hand that inventory to someone who is not signed in over a URL they
 * guessed, so an unauthenticated 404 gets the bare page.
 */

$user = Auth::check() ? Auth::user() : null;

$pageTitle = 'Not found';

ob_start();
?>
<div class="page">
    <div class="empty">
        <?php // The glyph names the kind of place you are standing in. A
              // magnifying glass would say "your search found nothing", which is
              // a different fact — this is "there is nothing at this address". ?>
        <?= icon('binoculars', 'empty__glyph', '', 28) ?>
        <p class="empty__title">Nothing at this address</p>
        <div class="empty__action">
            <a class="btn" href="<?= h(Url::admin()) ?>">Back to the admin</a>
        </div>
    </div>
</div>
<?php
$pageContent = ob_get_clean();

if ($user === null) {
    // No shell: see the header note. head.php needs these two.
    $hasRail = false;
    require ROOT_PATH . '/admin/templates/partials/head.php';
    echo '<body><main class="auth"><div class="auth__inner">';
    echo '<div class="auth__brand">' . h(\Cms\SEO\Head::globalTokens()['site_name']) . '</div>';
    echo $pageContent;
    echo '</div></main></body></html>';
    return;
}

require ROOT_PATH . '/admin/templates/layout.php';
