<?php

declare(strict_types=1);

use Cms\Security\Csrf;

/**
 * UNLOCK — the page the link opens that `Security\AccountLock` mails to an
 * account whose NAME has been locked. No shell, like the sign-in page.
 *
 * ── A BUTTON, NOT THE LINK ITSELF ───────────────────────────────────────────
 * Opening the link changes nothing. Mail scanners open every link in a message
 * before its reader does; if opening it lifted the lock, the scanner would
 * lift it on the owner's behalf — for whoever is guessing at the name. The
 * button posts back to this same address, `?key=` included, and the controller
 * checks the link again.
 *
 *   good link   the name, and the one button
 *   otherwise   used, out of date, or never sent — and the way to sign in
 *
 * @var array|null  $lock    the lock the link belongs to, or null
 * @var string|null $error
 * @var string      $signIn  the sign-in page's address, wherever it lives
 */

$site      = \Cms\SEO\Head::globalTokens()['site_name'];
$pageTitle = 'Unlock · ' . (string) \Cms\SEO\Head::globalTokens()['site_name'];
$hasRail   = false;
$user      = null;
// Posts back to its own link — rebuilt from the sign-in address and the one key
// it needs, never the raw request URI.
$self      = $signIn . '?action=unlock&key=' . rawurlencode((string) ($_GET['key'] ?? ''));

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

        <?php if ($lock !== null): ?>
            <form class="form-card" method="post" action="<?= h($self) ?>">
              <div class="form-card__section">
                <?= Csrf::field() ?>
                <?= panel_title('Unlock “' . (string) $lock['username'] . '”', 'unlock-name') ?>
                <p class="form-help">
                    Too many wrong passwords were typed for this account, so it can only be signed
                    in to from places it has signed in from before. Unlock it to sign in from here.
                </p>
                <div class="form-actions form-actions--bare">
                    <a class="link link--xs" href="<?= h($signIn) ?>">Back to sign in</a>
                    <div class="form-actions__spacer"></div>
                    <button class="btn btn--primary" type="submit">Unlock</button>
                </div>
              </div>
            </form>

        <?php else: ?>
            <div class="form-card">
              <div class="form-card__section">
                <?= panel_title('This link no longer works', 'unlock-name') ?>
                <p class="form-help">
                    It has been used, or the lock it was sent for has already ended. Try signing in.
                </p>
                <div class="form-actions form-actions--bare">
                    <div class="form-actions__spacer"></div>
                    <a class="btn btn--primary" href="<?= h($signIn) ?>">Sign in</a>
                </div>
              </div>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
