<?php

declare(strict_types=1);

use Cms\Helpers\Url;
use Cms\Security\Csrf;
use Cms\Security\Factors;

/**
 * SET UP TWO-STEP SIGN-IN — the ways the site offers, as buttons, and for an
 * account the policy requires it of, why it is here and how long is left.
 * `TwoStepController::start()`; what the account already has is on its own
 * account screen.
 *
 * @var array<string, array<string, mixed>> $factors   what it has set up
 * @var string|null $owed      grace | strict | null (`TwoStep::owed()`)
 * @var int|null    $graceEnd  when the grace period ends
 * @var array       $settings  TwoStep::settings()
 * @var bool        $ready     config.php has an app_secret of its own
 * @var bool        $hasEmail  the account has an address to send codes to
 * @var array       $me        the signed-in account
 */

$site      = \Cms\SEO\Head::globalTokens()['site_name'];
$pageTitle = 'Two-step sign-in · ' . $site;
$hasRail   = false;
$user      = null;

$daysLeft = $graceEnd !== null ? max(1, (int) ceil(($graceEnd - time()) / 86400)) : null;

require ROOT_PATH . '/admin/templates/partials/head.php';
?>
<body>
<main class="auth">
    <div class="auth__inner">
        <div class="auth__brand"><?= h($site) ?></div>

        <div class="form-card">
          <div class="form-card__section">
            <?= panel_title('Two-step sign-in', 'two-step') ?>
            <div class="field">
                <p class="form-help">
                    <?php if ($owed === 'strict'): ?>
                        This site asks your account to use two-step sign-in. Set it up to carry on.
                    <?php elseif ($owed === 'grace'): ?>
                        This site asks your account to use two-step sign-in —
                        <?= $daysLeft === 1 ? '1 day' : $daysLeft . ' days' ?> left to set it up.
                    <?php else: ?>
                        After your password, a code only you can get — so a password on its own is not enough to sign in as you.
                    <?php endif; ?>
                </p>
            </div>

            <?php if (!$ready): ?>
                <div class="field"><p class="form-help">
                    It cannot be set up on this site yet: config.php has no app_secret of its own. Whoever looks after the server can add one.
                </p></div>
            <?php else: ?>
                <?php // One field per method: the button, and a line under it.
                      // A row with the button beside its text does not fit the
                      // sign-in column — the text ran underneath the button. ?>
                <?php if ($settings['app']): ?>
                    <div class="field">
                        <a class="btn" href="<?= h(Url::admin('two-step/app')) ?>"><?= icon('mobile', 'icon icon--sm') ?> <?= isset($factors[Factors::APP]) ? 'Replace the authenticator app' : 'Use an authenticator app' ?></a>
                        <p class="form-help">Google Authenticator, Microsoft Authenticator, 1Password, Authy — any of them.</p>
                    </div>
                <?php endif; ?>
                <?php if ($settings['email']): ?>
                    <div class="field">
                        <?php if (isset($factors[Factors::EMAIL])): ?>
                            <span class="status status--phrase status--active"><?= icon('check', 'status__icon') ?>Codes by email are on</span>
                        <?php elseif ($hasEmail): ?>
                            <a class="btn" href="<?= h(Url::admin('two-step/email')) ?>"><?= icon('envelope', 'icon icon--sm') ?> Use codes by email</a>
                            <p class="form-help">Sent to your address each time you sign in.</p>
                        <?php else: ?>
                            <p class="form-help">Codes by email need an email address on your account first.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="form-actions form-actions--bare">
                <?php if ($owed === 'strict'): ?>
                    <a class="link link--xs" href="<?= h(Url::admin('logout') . '?_token=' . rawurlencode(Csrf::token())) ?>">Sign out</a>
                <?php else: ?>
                    <a class="link link--xs" href="<?= h(Url::admin('users/' . (int) $me['id'] . '/edit') . '#two-step') ?>">Back to your account</a>
                    <div class="form-actions__spacer"></div>
                    <?php if ($owed === 'grace'): ?>
                        <a class="btn" href="<?= h(ADMIN_URL) ?>">Not now</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
          </div>
        </div>
    </div>
</main>
</body>
</html>
