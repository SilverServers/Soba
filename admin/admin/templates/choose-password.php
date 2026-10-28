<?php

declare(strict_types=1);

use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * CHOOSE A NEW PASSWORD — between a sign-in and everything else, for an account
 * that owes one (`AuthController::choosePassword()`). No shell, like the
 * sign-in page it follows: there is nowhere to go until this is done, and a
 * sidebar would advertise destinations that all lead back here.
 *
 * ── IT SAYS WHY, ONCE ───────────────────────────────────────────────────────
 * One line, from the reason on the account (migration 061). Without it the
 * screen reads as a fault — "what did I do?" — and the owner's next move is to
 * ring whoever built the site.
 *
 * @var string        $reason    seed | admin | all | weak
 * @var bool          $renaming  the current username is a blocked one
 * @var string        $newName   what was typed, after a refused save
 * @var list<string>  $errors
 * @var string        $summary   PasswordPolicy::summary()
 */

$why = match ($reason) {
    'seed'  => 'This is the password the site was installed with. Choose one only you know.',
    'admin' => 'Somebody else set this account\'s password. Choose one only you know.',
    'all'   => 'An administrator has asked everyone to choose a new password.',
    default => 'This password no longer meets the site\'s password rule.',
};

$pageTitle = 'Choose a new password · ' . \Cms\SEO\Head::globalTokens()['site_name'];
$hasRail   = false;
$user      = null;   // the head partial must not draw a signed-in chrome here

require ROOT_PATH . '/admin/templates/partials/head.php';
?>
<body>
<main class="auth">
    <div class="auth__inner">
        <div class="auth__brand"><?= h(\Cms\SEO\Head::globalTokens()['site_name']) ?></div>

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

        <form class="form-card" method="post" action="<?= h(Url::admin('choose-password')) ?>">
          <div class="form-card__section">
            <?= Csrf::field() ?>

            <?= panel_title('Choose a new password', 'password') ?>
            <div class="field"><p class="form-help"><?= h($why) ?></p></div>

            <?php if ($renaming): ?>
                <?php // The seeded `admin`, or another name every script's
                      // word list starts with: the name is half the guess. ?>
                <div class="field">
                    <label class="label" for="new-username">New username</label>
                    <input class="input" type="text" id="new-username" name="new_username"
                           value="<?= h($newName) ?>" autocomplete="username"
                           autocapitalize="none" spellcheck="false" required autofocus>
                    <p class="form-help">You sign in with this from now on.</p>
                </div>
            <?php endif; ?>

            <div class="field">
                <label class="label" for="new-password">New password</label>
                <input class="input" type="password" id="new-password" name="new_password"
                       autocomplete="new-password" required<?= $renaming ? '' : ' autofocus' ?>>
                <p class="form-help"><?= h($summary) ?></p>
            </div>

            <div class="field">
                <label class="label" for="new-password-confirm">New password, again</label>
                <input class="input" type="password" id="new-password-confirm" name="new_password_confirm"
                       autocomplete="new-password" required>
            </div>

            <div class="form-actions form-actions--bare">
                <a class="link link--xs" href="<?= h(Url::admin('logout') . '?_token=' . rawurlencode(Csrf::token())) ?>">Sign out</a>
                <div class="form-actions__spacer"></div>
                <button class="btn btn--primary" type="submit">Save and continue</button>
            </div>
          </div>
        </form>
    </div>
</main>
</body>
</html>
