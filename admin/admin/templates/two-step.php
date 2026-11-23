<?php

declare(strict_types=1);

use Cms\Security\Csrf;
use Cms\Security\Factors;

/**
 * THE SECOND STEP — between a right password and a session, for an account
 * with two-step sign-in (`AuthController::handleTwoStep()`, `Security\TwoStep`).
 * No shell, like the sign-in page it follows, and at that page's own address.
 *
 * One code field, asked the way the account's method needs: six digits from an
 * app, six digits from the email just sent, or a backup code. The other ways
 * this account has are buttons under the form — they post `use=…` and skip the
 * field's `required` (formnovalidate), so switching never needs a code.
 *
 * @var string        $method    app | email | backup — what this page asks for
 * @var list<string>  $methods   the account's methods the site allows
 * @var string        $email     the address a code went to, masked
 * @var array         $settings  TwoStep::settings()
 * @var int           $backups   backup codes left
 * @var string        $action    this page's address
 * @var string        $signIn    the sign-in page
 * @var string|null   $error
 * @var string|null   $notice
 */

$site      = \Cms\SEO\Head::globalTokens()['site_name'];
$pageTitle = 'Two-step sign-in · ' . (string) \Cms\SEO\Head::globalTokens()['site_name'];
$hasRail   = false;
$user      = null;

$ask = match ($method) {
    Factors::EMAIL  => 'We emailed a 6-digit code to ' . $email . '. It works for ' . (int) $settings['code_minutes'] . ' minutes.',
    Factors::BACKUP => 'Type one of your backup codes. Each one works once.',
    default         => 'Type the 6-digit code your authenticator app shows for ' . $site . '.',
};

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
                <div class="alert__body"><p class="alert__text"><?= h($error) ?></p></div>
            </div>
        <?php elseif ($notice !== null): ?>
            <div class="flashes"><div class="flash flash--success"><?= h($notice) ?></div></div>
        <?php endif; ?>

        <form class="form-card" method="post" action="<?= h($action) ?>">
          <div class="form-card__section">
            <?= Csrf::field() ?>
            <input type="hidden" name="method" value="<?= h($method) ?>">
            <?= panel_title('Two-step sign-in', 'two-step') ?>

            <div class="field">
                <label class="label" for="code"><?= $method === Factors::BACKUP ? 'Backup code' : 'Code' ?></label>
                <?php if ($method === Factors::BACKUP): ?>
                    <input class="input" type="text" id="code" name="code" autocomplete="off"
                           autocapitalize="none" spellcheck="false" required autofocus>
                <?php else: ?>
                    <input class="input" type="text" id="code" name="code" inputmode="numeric"
                           autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus>
                <?php endif; ?>
                <p class="form-help"><?= h($ask) ?></p>
            </div>

            <?php if ($settings['remember']): ?>
                <div class="field">
                    <label class="check-list__row" for="remember">
                        <input type="checkbox" id="remember" name="remember" value="1">
                        <span>Don't ask again on this browser for <?= (int) $settings['remember_days'] ?> days</span>
                    </label>
                </div>
            <?php endif; ?>

            <div class="form-actions form-actions--bare">
                <a class="link link--xs" href="<?= h($signIn) ?>">Back to sign in</a>
                <div class="form-actions__spacer"></div>
                <button class="btn btn--primary" type="submit">Continue</button>
            </div>
          </div>

          <?php // THE OTHER WAYS IN this account has — never one it has not. ?>
          <div class="form-card__section form-actions form-actions--bare">
            <?php if ($method === Factors::EMAIL): ?>
                <button class="link link--xs" type="submit" name="resend" value="1" formnovalidate>Send another code</button>
            <?php endif; ?>
            <?php foreach ($methods as $other): ?>
                <?php if ($other !== $method): ?>
                    <button class="link link--xs" type="submit" name="use" value="<?= h($other) ?>" formnovalidate>
                        <?= $other === Factors::APP ? 'Use the authenticator app' : 'Email me a code instead' ?>
                    </button>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($method !== Factors::BACKUP && $backups > 0): ?>
                <button class="link link--xs" type="submit" name="use" value="<?= Factors::BACKUP ?>" formnovalidate>Use a backup code</button>
            <?php endif; ?>
          </div>
        </form>
    </div>
</main>
</body>
</html>
