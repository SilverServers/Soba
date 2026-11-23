<?php

declare(strict_types=1);

use Cms\Helpers\Dates;
use Cms\Security\Csrf;

/**
 * TEMPORARY ACCESS — the page a temporary access link opens
 * (`Security\TemporaryAccess`). No shell, like the sign-in page.
 *
 * ── A BUTTON, NOT THE LINK ITSELF ───────────────────────────────────────────
 * Opening the link spends nothing; each press of the button spends one use.
 * The link is shared by hand — pasted into a chat or a mail — and whatever
 * carries it may open it first (`unlock.php` has why).
 *
 *   good link   whose account, what is left of it, and the one button
 *   otherwise   used up, out of time, revoked, or replaced
 *
 * @var array|null  $access  TemporaryAccess::find(), or null
 * @var string|null $error
 * @var string      $signIn  the sign-in page's address, wherever it lives
 */

$site      = \Cms\SEO\Head::globalTokens()['site_name'];
$pageTitle = 'Sign in · ' . (string) \Cms\SEO\Head::globalTokens()['site_name'];
$hasRail   = false;
$user      = null;
// Posts back to its own link — rebuilt from the sign-in address and the one key
// it needs, never the raw request URI.
$self      = $signIn . '?action=access&key=' . rawurlencode((string) ($_GET['key'] ?? ''));

// At the sign-in address: Settings → Branding's theme and logo (`Ui\Branding`).
$signInPage = true;
require ROOT_PATH . '/admin/templates/partials/head.php';
?>
<body>
<main class="auth">
    <div class="auth__inner">
        <?= \Cms\Ui\Branding::signInBrand() ?>

        <?php if ($error !== null): ?>
            <div class="alert alert--danger" role="alert">
                <?= icon('circle-exclamation', 'alert__icon icon', '', 18) ?>
                <div class="alert__body">
                    <p class="alert__text"><?= h($error) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($access !== null): ?>
            <?php $left = (int) $access['max_uses'] - (int) $access['uses']; ?>
            <form class="form-card" method="post" action="<?= h($self) ?>">
              <div class="form-card__section">
                <?= Csrf::field() ?>
                <?= panel_title('Sign in to ' . $site, 'temporary-access') ?>
                <p class="form-help">
                    Temporary access as “<?= h((string) $access['username']) ?>”. This link signs in
                    <?= $left === 1 ? 'once more' : h((string) $left) . ' more times' ?>, until
                    <?= h(Dates::stamp((string) $access['expires_at'])) ?>.
                </p>
                <div class="form-actions form-actions--bare">
                    <div class="form-actions__spacer"></div>
                    <button class="btn btn--primary" type="submit">Sign in</button>
                </div>
              </div>
            </form>

        <?php else: ?>
            <div class="form-card">
              <div class="form-card__section">
                <?= panel_title('This link no longer works', 'temporary-access') ?>
                <p class="form-help">
                    Its time or its uses have run out, or it was replaced or revoked. Ask whoever
                    gave it to you for a new one.
                </p>
              </div>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
