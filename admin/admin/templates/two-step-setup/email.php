<?php

declare(strict_types=1);

use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * SET UP EMAIL CODES — one code sent now, typed back, before the method counts
 * (`TwoStepController::email()`). An address that does not receive mail would
 * otherwise be discovered at the next sign-in, from the wrong side of it.
 *
 * @var string      $address  the account's address
 * @var bool        $sent     a code is out
 * @var int         $minutes  how long it works
 * @var string|null $error
 * @var string|null $notice
 */

$site      = \Cms\SEO\Head::globalTokens()['site_name'];
$pageTitle = 'Codes by email · ' . $site;
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
        <?php elseif ($notice !== null): ?>
            <div class="flashes"><div class="flash flash--success"><?= h($notice) ?></div></div>
        <?php endif; ?>

        <form class="form-card" method="post" action="<?= h(Url::admin('two-step/email')) ?>">
          <div class="form-card__section">
            <?= Csrf::field() ?>
            <?= panel_title('A code by email', 'email-code') ?>

            <?php if ($address === ''): ?>
                <div class="field"><p class="form-help">Your account has no email address. Add one on your account screen first.</p></div>
                <div class="form-actions form-actions--bare">
                    <a class="link link--xs" href="<?= h(Url::admin('two-step')) ?>">Back</a>
                </div>
            <?php elseif (!$sent): ?>
                <div class="field"><p class="form-help">
                    First, a code to <?= h($address) ?>, to make sure mail from this site reaches you.
                </p></div>
                <div class="form-actions form-actions--bare">
                    <a class="link link--xs" href="<?= h(Url::admin('two-step')) ?>">Back</a>
                    <div class="form-actions__spacer"></div>
                    <button class="btn btn--primary" type="submit" name="send" value="1">Send the code</button>
                </div>
            <?php else: ?>
                <div class="field">
                    <label class="label" for="code">The 6-digit code we sent to <?= h($address) ?></label>
                    <input class="input" type="text" id="code" name="code" inputmode="numeric"
                           autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus>
                    <p class="form-help">It works for <?= (int) $minutes ?> minutes.</p>
                </div>
                <div class="form-actions form-actions--bare">
                    <button class="link link--xs" type="submit" name="send" value="1" formnovalidate>Send another</button>
                    <div class="form-actions__spacer"></div>
                    <button class="btn btn--primary" type="submit">Turn it on</button>
                </div>
            <?php endif; ?>
          </div>
        </form>
    </div>
</main>
</body>
</html>
