<?php

declare(strict_types=1);

use Cms\Security\Csrf;

/**
 * LOST PASSWORD — the `lostpassword` and `resetpass` actions, at the sign-in
 * page's own address (`Security\PasswordReset` has why). No shell, like the
 * sign-in page: the person here is not signed in and has nowhere else to go.
 *
 *   ask      one field: the username or the email address
 *   sent     the SAME answer whatever was typed — a different one for "no such
 *            account" would turn this form into a list of the accounts
 *   choose   a new password, from a good link
 *   invalid  a wrong, used or expired link, and the way to ask again
 *
 * The forms post to their own address, query string included, so the token in
 * `?key=` rides along to the controller that checks it again.
 *
 * @var string       $state    ask | sent | choose | invalid
 * @var list<string> $errors
 * @var string       $signIn   the sign-in page's address, wherever it lives
 * @var string       $summary  PasswordPolicy::summary()
 * @var int          $minutes  PasswordReset::LIFETIME_MINUTES
 * @var array|null   $challenge [nonce, question] when this address must answer one
 */

$site      = \Cms\SEO\Head::globalTokens()['site_name'];
$pageTitle = ($state === 'choose' ? 'Choose a new password' : 'Lost password') . ' · ' . (string) \Cms\SEO\Head::globalTokens()['site_name'];
$hasRail   = false;
$user      = null;
// The choose form posts back to its own link — rebuilt from the sign-in
// address and the one key it needs, never the raw request URI.
$self      = $signIn . '?action=resetpass&key=' . rawurlencode((string) ($_GET['key'] ?? ''));

// At the sign-in address: Settings → Branding's theme and logo (`Ui\Branding`).
$signInPage = true;
require ROOT_PATH . '/admin/templates/partials/head.php';
?>
<body>
<main class="auth">
    <div class="auth__inner">
        <?= \Cms\Ui\Branding::signInBrand() ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert--danger" role="alert">
                <?= icon('circle-exclamation', 'alert__icon icon', '', 18) ?>
                <div class="alert__body">
                    <?php foreach ($errors as $error): ?>
                        <p class="alert__text"><?= h($error) ?></p>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($state === 'ask'): ?>
            <form class="form-card" method="post" action="<?= h($signIn . '?action=lostpassword') ?>">
              <div class="form-card__section">
                <?= Csrf::field() ?>
                <?= panel_title('Lost password', 'password') ?>
                <div class="field">
                    <label class="label" for="login">Username or email address</label>
                    <input class="input" type="text" id="login" name="login" autocomplete="username"
                           autocapitalize="none" spellcheck="false" required autofocus>
                    <p class="form-help">A link to choose a new password is emailed to the account's address.</p>
                </div>
                <?php if ($challenge !== null): ?>
                    <div class="field">
                        <label class="label" for="captcha"><?= h($challenge[1]) ?></label>
                        <input class="input" type="text" id="captcha" name="_captcha" inputmode="numeric"
                               autocomplete="off" required>
                        <input type="hidden" name="_captcha_nonce" value="<?= h($challenge[0]) ?>">
                    </div>
                <?php endif; ?>
                <div class="form-actions form-actions--bare">
                    <a class="link link--xs" href="<?= h($signIn) ?>">Back to sign in</a>
                    <div class="form-actions__spacer"></div>
                    <button class="btn btn--primary" type="submit">Email the link</button>
                </div>
              </div>
            </form>

        <?php elseif ($state === 'sent'): ?>
            <div class="form-card">
              <div class="form-card__section">
                <?= panel_title('Check your email', 'notifications') ?>
                <p class="form-help">
                    If that account exists, a link to choose a new password is on its way to
                    its email address. It works for <?= (int) $minutes ?> minutes, once.
                </p>
                <div class="form-actions form-actions--bare">
                    <a class="link link--xs" href="<?= h($signIn) ?>">Back to sign in</a>
                </div>
              </div>
            </div>

        <?php elseif ($state === 'choose'): ?>
            <form class="form-card" method="post" action="<?= h($self) ?>">
              <div class="form-card__section">
                <?= Csrf::field() ?>
                <?= panel_title('Choose a new password', 'password') ?>
                <div class="field">
                    <label class="label" for="new-password">New password</label>
                    <input class="input" type="password" id="new-password" name="new_password"
                           autocomplete="new-password" required autofocus>
                    <p class="form-help"><?= h($summary) ?></p>
                </div>
                <div class="field">
                    <label class="label" for="new-password-confirm">New password, again</label>
                    <input class="input" type="password" id="new-password-confirm" name="new_password_confirm"
                           autocomplete="new-password" required>
                </div>
                <div class="form-actions form-actions--bare">
                    <div class="form-actions__spacer"></div>
                    <button class="btn btn--primary" type="submit">Save the new password</button>
                </div>
              </div>
            </form>

        <?php else: ?>
            <div class="form-card">
              <div class="form-card__section">
                <?= panel_title('This link no longer works', 'password') ?>
                <p class="form-help">
                    It has been used, or it is more than <?= (int) $minutes ?> minutes old, or a
                    newer one was sent.
                </p>
                <div class="form-actions form-actions--bare">
                    <a class="link link--xs" href="<?= h($signIn) ?>">Back to sign in</a>
                    <div class="form-actions__spacer"></div>
                    <a class="btn btn--primary" href="<?= h($signIn . '?action=lostpassword') ?>">Email a new link</a>
                </div>
              </div>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
