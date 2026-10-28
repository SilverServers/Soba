<?php

declare(strict_types=1);

use Cms\Controllers\Admin\SecurityController;
use Cms\Helpers\Url;
use Cms\Security\Csrf;
use Cms\Security\TwoStep;

/**
 * SECURITY → TWO-STEP SIGN-IN — three tabs. A FRAGMENT: `SecurityController`
 * owns `ob_start()` and `layout.php`. The accounts' own methods are on their
 * account screens.
 *
 *   Authentication methods  the email code, authenticator app and remembered-
 *                           browser switches. Both methods can be on at once,
 *                           and each account chooses its own.
 *   Policies                role rules: who must use it, and for how long an
 *                           account may say "not now" (the grace period)
 *   Advanced settings       how long a remembered browser is trusted, and how
 *                           long an emailed code lasts
 *
 * @var string               $tab
 * @var list<string>         $errors
 * @var bool                 $saved
 * @var array<string, mixed> $settings  TwoStep::settings(), or as typed after a refused save
 * @var array<string, int>   $adoption  TwoStep::adoption()
 * @var bool                 $ready     config.php has an app_secret of its own
 * @var bool                 $enforced  a production install: the policy stops people
 */

$tabUrl = static fn (string $key): string => Url::admin('security/two-step') . '?tab=' . $key;
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Two-step sign-in</h1>
        </div>
    </div>

    <?php if ($saved): ?>
        <div class="flashes"><div class="flash flash--success">Settings saved.</div></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert--danger">
            <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__title"><?= count($errors) === 1 ? 'One thing needs fixing' : count($errors) . ' things need fixing' ?></p>
                <ul class="alert__text">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="tabs">
        <?php foreach (SecurityController::TWO_STEP_TABS as $key => $label): ?>
            <a class="tabs__tab<?= $tab === $key ? ' is-active' : '' ?>"
               <?= $tab === $key ? 'aria-current="page"' : '' ?>
               href="<?= h($tabUrl($key)) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="form-main">
        <form method="post" action="<?= h(Url::admin('security/two-step')) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="_tab" value="<?= h($tab) ?>">

            <?php if ($tab === 'methods'): ?>
                <div class="form-card">
                    <div class="form-card__section">
                        <?= panel_title('Authentication methods', 'two-step-methods') ?>
                        <?php if (!$ready): ?>
                            <p class="form-help">Nothing can be set up until config.php has an app_secret of its own:
                                an app's secret is sealed with it, and a backup code checked against it.</p>
                        <?php endif; ?>
                        <div class="field">
                            <?php // Each method wears the glyph it wears everywhere
                                  // else: the phone on the second step and its
                                  // setup, the envelope on the email code, the
                                  // clock on remembered browsers (Sections). ?>
                            <div class="check-list">
                                <label class="check-list__row"><input type="checkbox" name="app" value="1" <?= $settings['app'] ? 'checked' : '' ?>>
                                    <span class="check-list__name"><?= icon('mobile', 'check-list__glyph icon') ?>Authenticator app</span>
                                    <span class="check-list__note">Google Authenticator, Microsoft Authenticator, 1Password, Authy</span></label>
                                <label class="check-list__row"><input type="checkbox" name="email" value="1" <?= $settings['email'] ? 'checked' : '' ?>>
                                    <span class="check-list__name"><?= icon('envelope', 'check-list__glyph icon') ?>A code by email</span>
                                    <span class="check-list__note">sent at each sign-in</span></label>
                                <label class="check-list__row"><input type="checkbox" name="remember" value="1" <?= $settings['remember'] ? 'checked' : '' ?>>
                                    <span class="check-list__name"><?= icon('clock', 'check-list__glyph icon') ?>Remembered browsers</span>
                                    <span class="check-list__note">skip the second step for <?= (int) $settings['remember_days'] ?> days, when their owner asks</span></label>
                            </div>
                            <p class="form-help">Each account chooses its own from the methods switched on here. A method
                                switched off stops working at sign-in for everyone who used it.</p>
                        </div>
                    </div>

            <?php elseif ($tab === 'policies'): ?>
                <div class="form-card">
                    <div class="form-card__section">
                        <?= panel_title('Who must use it', 'two-step-policy') ?>
                        <div class="field">
                            <span class="label">Required for</span>
                            <div class="check-list">
                                <?php foreach (TwoStep::ROLE_NAMES as $role => $name): ?>
                                    <label class="check-list__row">
                                        <input type="checkbox" name="roles[]" value="<?= h($role) ?>" <?= in_array($role, $settings['roles'], true) ? 'checked' : '' ?>>
                                        <span><?= h($name) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="form-help">
                                <?= (int) $adoption['on'] ?> of <?= (int) $adoption['total'] ?> accounts use it<?php
                                if ($adoption['required'] > 0): ?>; <?= (int) $adoption['requiredOn'] ?> of the <?= (int) $adoption['required'] ?> it is required of<?php endif; ?>.
                            </p>
                        </div>
                        <div class="field">
                            <label class="label" for="f-grace">Grace period, in days</label>
                            <input class="input" type="number" id="f-grace" name="grace_days" min="<?= TwoStep::GRACE_RANGE[0] ?>"
                                   max="<?= TwoStep::GRACE_RANGE[1] ?>" value="<?= h((string) $settings['grace_days']) ?>" required>
                            <p class="form-help">From the first sign-in at which an account is asked, it may say "not now" for this
                                many days; then it must set it up before anything else. 0: at that first sign-in.
                                <?= $enforced ? '' : 'This is a development install: it asks, but never stops anyone.' ?></p>
                        </div>
                    </div>

            <?php else: ?>
                <div class="form-card">
                    <div class="form-card__section">
                        <?= panel_title('Remembered browsers', 'remembered-browsers') ?>
                        <div class="field">
                            <label class="label" for="f-remember">A browser skips the second step for</label>
                            <select class="select" id="f-remember" name="remember_days">
                                <?php foreach (TwoStep::REMEMBER_CHOICES as $days): ?>
                                    <option value="<?= $days ?>"<?= (int) $settings['remember_days'] === $days ? ' selected' : '' ?>><?= $days ?> days</option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-help">Only when its owner ticks the box after a right code. The password is asked for every time.</p>
                        </div>
                    </div>
                    <div class="form-card__section">
                        <?= panel_title('Codes by email', 'email-code') ?>
                        <div class="field">
                            <label class="label" for="f-minutes">An emailed code works for, in minutes</label>
                            <input class="input" type="number" id="f-minutes" name="code_minutes" min="<?= TwoStep::CODE_MINUTES_RANGE[0] ?>"
                                   max="<?= TwoStep::CODE_MINUTES_RANGE[1] ?>" value="<?= h((string) $settings['code_minutes']) ?>" required>
                        </div>
                    </div>
            <?php endif; ?>

                    <div class="form-card__section form-actions">
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn btn--primary">Save changes</button>
                    </div>
                </div>
        </form>
    </div>
</div>
