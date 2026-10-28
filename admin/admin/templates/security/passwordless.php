<?php

declare(strict_types=1);

use Cms\Controllers\Admin\SecurityController;
use Cms\Helpers\Url;
use Cms\Security\Csrf;
use Cms\Security\Passwordless;

/**
 * SECURITY → PASSWORDLESS — two tabs: Code by email, then Sign-in link.
 * A FRAGMENT: `SecurityController` owns `ob_start()` and `layout.php`.
 * `Security\Passwordless` has how each works, why a link is spent by a button,
 * and why the second step is still asked.
 *
 * Each tab is its own form: one way is saved at a time.
 *
 * @var string                                                    $tab        code | link
 * @var list<string>                                              $errors
 * @var bool                                                      $saved
 * @var array{enabled: bool, minutes: string, roles: list<string>} $form       as stored, or as typed after a refused save
 * @var string                                                    $smtpHost   Settings → Email's sending server, if any
 * @var bool                                                      $emailSends a production install: mail is sent
 */

$tabUrl     = static fn (string $key): string => Url::admin('security/passwordless') . '?tab=' . $key;
// The same marks a role wears on the Users list.
$roleGlyphs = ['admin' => 'shield-halved', 'editor' => 'pen-to-square', 'author' => 'pen'];
$isCode     = $tab === 'code';
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Passwordless</h1>
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
        <?php foreach (SecurityController::PASSWORDLESS_TABS as $key => $label): ?>
            <a class="tabs__tab<?= $tab === $key ? ' is-active' : '' ?>"
               <?= $tab === $key ? 'aria-current="page"' : '' ?>
               href="<?= h($tabUrl($key)) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="form-main">
        <form class="form-card" method="post" action="<?= h(Url::admin('security/passwordless')) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="_tab" value="<?= h($tab) ?>">

            <div class="form-card__section">
                <?= panel_title($isCode ? 'A code by email' : 'A sign-in link', $isCode ? 'signin-code' : 'signin-link') ?>

                <div class="field">
                    <label class="check-list__row" for="f-passwordless-on">
                        <input type="checkbox" id="f-passwordless-on" name="enabled" value="1" <?= $form['enabled'] ? 'checked' : '' ?>>
                        <span><?= $isCode
                            ? 'Let people sign in with a code sent by email'
                            : 'Let people sign in with a link sent by email' ?></span>
                    </label>
                    <?php // Alerts::emailSends() and Settings → Email's server: the
                          // rule Mailer itself follows, so this says what happens. ?>
                    <p class="form-help"><?php if (!$emailSends): ?>This is a development install: it sends no email,
                        so its sign-in page does not offer this.<?php elseif ($smtpHost !== ''): ?>Sent through <?= h($smtpHost) ?>
                        (<a class="link link--xs" href="<?= h(Url::admin('settings/email')) ?>">Settings → Email</a>).<?php else: ?><a class="link link--xs" href="<?= h(Url::admin('settings/email')) ?>">Settings → Email</a>
                        has no sending server, so mail goes through this host's own mail program, which may not
                        deliver it.<?php endif; ?></p>
                </div>

                <div class="field">
                    <label class="label" for="f-passwordless-minutes"><?= $isCode ? 'A code works for' : 'A link works for' ?></label>
                    <input class="input input--count" type="number" id="f-passwordless-minutes" name="minutes"
                           min="<?= Passwordless::LIMITS[$tab][0] ?>" max="<?= Passwordless::LIMITS[$tab][1] ?>"
                           value="<?= h((string) $form['minutes']) ?>" inputmode="numeric">
                    <p class="form-help">Minutes, once.<?= $isCode ? ' Five wrong codes and it stops working.' : ' It opens a page with one button, so a mail scanner that opens it first spends nothing.' ?></p>
                </div>

                <div class="field">
                    <span class="label">Who may</span>
                    <div class="check-list">
                        <?php foreach (Passwordless::ROLES as $role => $label): ?>
                            <label class="check-list__row">
                                <input type="checkbox" name="roles[]" value="<?= h($role) ?>"
                                       <?= in_array($role, (array) $form['roles'], true) ? 'checked' : '' ?>>
                                <span class="check-list__name"><?= icon($roleGlyphs[$role], 'check-list__glyph icon') ?><?= h($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="form-help">The second step is still asked afterwards for an authenticator app — never a
                        second code by email: the inbox is already proven. A suspended account is sent nothing.</p>
                </div>
            </div>

            <div class="form-card__section form-actions">
                <div class="form-actions__spacer"></div>
                <button type="submit" class="btn btn--primary">Save changes</button>
            </div>
        </form>
    </div>

</div>
