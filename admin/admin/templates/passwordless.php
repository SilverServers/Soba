<?php

declare(strict_types=1);

use Cms\Security\Csrf;
use Cms\Security\Passwordless;

/**
 * SIGNING IN WITHOUT A PASSWORD — a code or a link, by email, at the sign-in
 * page's own address (`Security\Passwordless`). No shell, like the sign-in
 * page.
 *
 *   ask      one field: the username or the email address
 *   enter    (a code) the six digits — shown whatever was typed before it, so
 *            the page never says whether an account exists
 *   sent     (a link) the SAME answer whatever was typed
 *   button   (a link, opened) one button: opening the link spends nothing,
 *            because mail scanners open links before their readers do
 *   invalid  (a link) used, out of date, or replaced
 *
 * @var string       $method   code | link
 * @var string       $state    ask | enter | sent | button | invalid
 * @var list<string> $errors
 * @var string       $signIn   the sign-in page's address, wherever it lives
 * @var int          $minutes  how long a code or a link works
 * @var array|null   $challenge [nonce, question] when this address must answer one
 * @var string       $key      the link's key, on `button`
 */

$site      = \Cms\SEO\Head::globalTokens()['site_name'];
$pageTitle = 'Sign in · ' . (string) \Cms\SEO\Head::globalTokens()['site_name'];
$hasRail   = false;
$user      = null;
$glyph     = $method === 'code' ? 'signin-code' : 'signin-link';
// Each form posts back to its own action — rebuilt from the sign-in address,
// never the raw request URI.
$self      = $signIn . '?action=' . ($state === 'button' ? 'signin&key=' . rawurlencode($key) : $method);

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
            <form class="form-card" method="post" action="<?= h($self) ?>">
              <div class="form-card__section">
                <?= Csrf::field() ?>
                <?= panel_title($method === 'code' ? 'Sign in with a code' : 'Sign in with a link', $glyph) ?>
                <div class="field">
                    <label class="label" for="login">Username or email address</label>
                    <input class="input" type="text" id="login" name="login" autocomplete="username"
                           autocapitalize="none" spellcheck="false" required autofocus>
                    <p class="form-help"><?= $method === 'code'
                        ? 'A six-digit code is emailed to the account\'s address.'
                        : 'A link is emailed to the account\'s address.' ?></p>
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
                    <button class="btn btn--primary" type="submit"><?= $method === 'code' ? 'Email me a code' : 'Email me a link' ?></button>
                </div>
              </div>
            </form>

        <?php elseif ($state === 'enter'): ?>
            <form class="form-card" method="post" action="<?= h($self) ?>">
              <div class="form-card__section">
                <?= Csrf::field() ?>
                <?= panel_title('Enter the code', $glyph) ?>
                <?php // The two-step page's field, help and all, under the input. ?>
                <div class="field">
                    <label class="label" for="code">Code</label>
                    <input class="input" type="text" id="code" name="code" inputmode="numeric"
                           autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus>
                    <p class="form-help">If that account may sign in this way, a six-digit code is on its way
                        to its email address. It works for <?= (int) $minutes ?> minutes, once, in this browser.</p>
                </div>
                <div class="form-actions form-actions--bare">
                    <a class="link link--xs" href="<?= h($signIn . '?action=code') ?>">Ask for another code</a>
                    <div class="form-actions__spacer"></div>
                    <button class="btn btn--primary" type="submit">Sign in</button>
                </div>
              </div>
            </form>

        <?php elseif ($state === 'sent'): ?>
            <div class="form-card">
              <div class="form-card__section">
                <?= panel_title('Check your email', 'notifications') ?>
                <p class="form-help">
                    If that account may sign in this way, a link is on its way to its email address.
                    It works for <?= (int) $minutes ?> minutes, once.
                </p>
                <div class="form-actions form-actions--bare">
                    <a class="link link--xs" href="<?= h($signIn) ?>">Back to sign in</a>
                </div>
              </div>
            </div>

        <?php elseif ($state === 'button'): ?>
            <form class="form-card" method="post" action="<?= h($self) ?>">
              <div class="form-card__section">
                <?= Csrf::field() ?>
                <?= panel_title('Sign in to ' . $site, $glyph) ?>
                <p class="form-help">The link is good. Press the button to sign in — it works once.</p>
                <div class="form-actions form-actions--bare">
                    <a class="link link--xs" href="<?= h($signIn) ?>">Back to sign in</a>
                    <div class="form-actions__spacer"></div>
                    <button class="btn btn--primary" type="submit">Sign in</button>
                </div>
              </div>
            </form>

        <?php else: ?>
            <div class="form-card">
              <div class="form-card__section">
                <?= panel_title('This link no longer works', $glyph) ?>
                <p class="form-help">
                    It has been used, or it is more than <?= (int) $minutes ?> minutes old, or a newer
                    one was sent.
                </p>
                <div class="form-actions form-actions--bare">
                    <a class="link link--xs" href="<?= h($signIn) ?>">Back to sign in</a>
                    <?php if (Passwordless::offered('link')): ?>
                        <div class="form-actions__spacer"></div>
                        <a class="btn btn--primary" href="<?= h($signIn . '?action=link') ?>">Email a new link</a>
                    <?php endif; ?>
                </div>
              </div>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
