<?php

declare(strict_types=1);

use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * SIGN IN — the only admin screen reachable without a session, and the only one
 * that renders its own document rather than going through `layout.php`.
 *
 * It has no shell on purpose: there is nothing to navigate to until you are
 * through it, and chrome around a login form advertises destinations that would
 * every one of them bounce you straight back here.
 *
 * ── WHAT IS DELIBERATELY NOT ON THIS PAGE ───────────────────────────────────
 * No "Enter your credentials to continue". Nobody arrives at a login form
 * needing to be told what a login form is; the two labelled fields and one
 * button ARE the instruction.
 *
 * "Forgot your password?" is a link (`Security\PasswordReset`), beside the
 * button, to the same address with `?action=lostpassword`: wherever this page
 * moves, it follows.
 *
 * ── AND WHY THE ERROR IS DELIBERATELY VAGUE ─────────────────────────────────
 * "Incorrect username or password" — never "no such user". Distinguishing the
 * two turns the form into a free username oracle for anyone who wants to know
 * which accounts exist. The wording comes from AuthController; this only paints
 * it.
 *
 * ── WHERE IT POSTS ─────────────────────────────────────────────────────────
 * To itself, wherever that is: `/admin/login`, or the address the owner moved
 * it to (`Security\LoginUrl`). The controller hands the address over; this
 * page never builds it, so it cannot post to the old one.
 *
 * @var string|null $error
 * @var string|null $username
 * @var int|null    $attemptsLeft  after a failure, while the attempt limit is on
 * @var string      $action        this page's own address
 * @var string|null $notice        back from choosing a new password, or from an unlock link
 * @var array|null  $challenge     [nonce, question] when this address must answer one
 */

$error        = $error        ?? null;
$username     = $username     ?? null;
$attemptsLeft = $attemptsLeft ?? null;
$action       = $action       ?? Url::admin('login');
$notice       = $notice       ?? null;
$challenge    = $challenge    ?? null;

// No sidebar here, so the first-paint skeleton must not paint a rail band.
$pageTitle = 'Sign in · ' . \Cms\Ui\Brand::name();
$hasRail   = false;
$user      = null;

// At the sign-in address: its logo, background and CSS (`SignInPage`).
$signInPage = true;
require ROOT_PATH . '/admin/templates/partials/head.php';
?>
<body>
<main class="auth">
    <div class="auth__inner">
        <?= \Cms\Security\SignInPage::brand(\Cms\Ui\Brand::name()) ?>

        <?php if ($notice !== null && $error === null): ?>
            <div class="flashes">
                <div class="flash flash--success"><?= h($notice) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error !== null): ?>
            <?php // An ALERT is a standalone surface with its own box and glyph —
                  // it is not red text, and it is not a tint on the field. ?>
            <div class="alert alert--danger" role="alert">
                <?= icon('circle-exclamation', 'alert__icon icon', '', 18) ?>
                <div class="alert__body">
                    <p class="alert__text"><?= h($error) ?></p>
                    <?php // The attempts left: the one fact that changes what
                          // a person does next — slow down and check the
                          // caps-lock key. ?>
                    <?php if ($attemptsLeft !== null): ?>
                        <p class="alert__text"><?= $attemptsLeft === 1
                            ? '1 attempt left.'
                            : (int) $attemptsLeft . ' attempts left.' ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php // The card is the FORM, and its one section carries the padding —
              // `.form-card` itself is an unpadded shell, so a card with no
              // `__section` inside it is a box with its content on the border.
              // core.css §4. ?>
        <form class="form-card" method="post" action="<?= h($action) ?>">
          <div class="form-card__section">
            <?php // CHECKED — by AuthController, not by www/index.php's global
                  // check, which exempts this page so that a form left open past
                  // its session gets "please sign in again" instead of a bare
                  // 403. A forged sign-in (someone else's form signing you into
                  // THEIR account) is what the token stops. ?>
            <?= Csrf::field() ?>

            <div class="field">
                <label class="label" for="username">Username</label>
                <?php // autofocus: this is the page's only purpose, so the caret
                      // belongs in the first field without a click. ?>
                <input class="input" type="text" id="username" name="username"
                       value="<?= h((string) $username) ?>"
                       autocomplete="username" autocapitalize="none" spellcheck="false"
                       required autofocus>
            </div>

            <div class="field">
                <label class="label" for="password">Password</label>
                <input class="input" type="password" id="password" name="password"
                       autocomplete="current-password" required>
            </div>

            <?php // WITHOUT A PASSWORD — full-width launchers straight after the
                  // password, before the question and Sign in. Only what this
                  // site offers, and only where mail is sent
                  // (`Passwordless::offered()`). Each label says what arrives,
                  // a code or a link: side by side, two that both said
                  // "continue with email" could not be told apart. Links, not
                  // buttons: each is a page of its own. ?>
            <?php $passwordless = array_filter(['code' => \Cms\Security\Passwordless::offered('code'), 'link' => \Cms\Security\Passwordless::offered('link')]); ?>
            <?php if ($passwordless !== []): ?>
                <div class="field auth__ways">
                    <?php if (isset($passwordless['code'])): ?>
                        <a class="btn" href="<?= h($action . '?action=code') ?>"><?= icon('hashtag', 'icon icon--sm') ?> Email me a code</a>
                    <?php endif; ?>
                    <?php if (isset($passwordless['link'])): ?>
                        <a class="btn" href="<?= h($action . '?action=link') ?>"><?= icon('link', 'icon icon--sm') ?> Email me a link</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php // THE QUESTION (`Security\Challenge`) — after a failed attempt
                  // from this address, by default. Built in, always: no
                  // provider's script ever runs on the page the password is
                  // typed into. ?>
            <?php if ($challenge !== null): ?>
                <div class="field">
                    <label class="label" for="captcha"><?= h($challenge[1]) ?></label>
                    <input class="input" type="text" id="captcha" name="_captcha" inputmode="numeric"
                           autocomplete="off" required>
                    <input type="hidden" name="_captcha_nonce" value="<?= h($challenge[0]) ?>">
                </div>
            <?php endif; ?>

            <?php // THE HONEYPOT: off the page, out of the tab order, hidden from
                  // assistive technology. A person never fills it; a form-filling
                  // script does, and is turned away as a wrong password. ?>
            <div class="auth__trap" aria-hidden="true">
                <label for="website">Website</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <?php // Full-size primary, never --sm: this is the page's Save. And no
                  // glyph — position and skin already say which button this is. ?>
            <div class="form-actions form-actions--bare">
                <?php // The way back in, where the cancel link sits on every
                      // other form — quiet, and always at this page's own
                      // address (`?action=lostpassword`), moved or not. ?>
                <a class="link link--xs" href="<?= h($action . '?action=lostpassword') ?>">Forgot your password?</a>
                <div class="form-actions__spacer"></div>
                <button class="btn btn--primary" type="submit">Sign in</button>
            </div>
          </div>
        </form>
    </div>
</main>
</body>
</html>
