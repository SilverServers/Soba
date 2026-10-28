<?php

declare(strict_types=1);

use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * SET UP AN AUTHENTICATOR APP — the QR code, the same key typed out for an app
 * that cannot scan, and one code from the app to prove it took. The secret
 * waits in the session until that code comes back (`TwoStepController::app()`).
 *
 * The QR code is drawn here (`Security\Qr`), not fetched: the secret never
 * leaves the page it is shown on.
 *
 * @var string      $qr       the SVG
 * @var string      $key      the secret, in groups of four
 * @var string      $uri      the otpauth:// link the QR code holds
 * @var string      $account  the name the app will list it under
 * @var string      $site
 * @var string|null $error
 */

$pageTitle = 'Authenticator app · ' . $site;
$hasRail   = false;
$user      = null;

require ROOT_PATH . '/admin/templates/partials/head.php';
?>
<body>
<main class="auth">
    <div class="auth__inner">
        <div class="auth__brand"><?= h($site) ?></div>

        <?php if ($error !== null): ?>
            <div class="alert alert--danger" role="alert">
                <?= icon('circle-exclamation', 'alert__icon icon', '', 18) ?>
                <div class="alert__body"><p class="alert__text"><?= h($error) ?></p></div>
            </div>
        <?php endif; ?>

        <form class="form-card" method="post" action="<?= h(Url::admin('two-step/app')) ?>">
          <div class="form-card__section">
            <?= Csrf::field() ?>
            <?= panel_title('Authenticator app', 'authenticator-app') ?>

            <div class="field">
                <p class="form-help">In your app, add an account and scan this code. It will be listed as
                    <?= h($site) ?> (<?= h($account) ?>).</p>
                <?= $qr ?>
            </div>

            <div class="field">
                <label class="label" for="key">Or type this key</label>
                <input class="input input--mono" type="text" id="key" value="<?= h($key) ?>" readonly>
                <?php // A PHONE CANNOT SCAN ITS OWN SCREEN. On the phone the app
                      // is on, the otpauth:// link opens the app with all of
                      // this filled in — the same link the QR code holds. ?>
                <p class="form-help">On the phone itself? <a class="link link--xs" href="<?= h($uri) ?>">Open it in your authenticator app</a>.</p>
            </div>

            <div class="field">
                <label class="label" for="code">The 6-digit code the app shows</label>
                <input class="input" type="text" id="code" name="code" inputmode="numeric"
                       autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus>
            </div>

            <div class="form-actions form-actions--bare">
                <a class="link link--xs" href="<?= h(Url::admin('two-step')) ?>">Back</a>
                <div class="form-actions__spacer"></div>
                <button class="btn btn--primary" type="submit">Turn it on</button>
            </div>
          </div>
        </form>
    </div>
</main>
</body>
</html>
