<?php

declare(strict_types=1);

use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * SETTINGS → EMAIL.
 *
 * A FRAGMENT — the controller owns `ob_start()` and `layout.php`.
 *
 * ── WHAT ACTUALLY READS THESE, CHECKED ──────────────────────────────────────
 * One consumer: `Controllers\Public\FormController`, when a form submission is
 * emailed to its recipients. It reads host, username, password, port and
 * encryption, and it does so INSIDE `if ($smtpHost !== '')` — so a blank host is
 * not a broken configuration, it is the switch that leaves PHPMailer on PHP's
 * own `mail()`. The hints below say exactly that and nothing more; there is no
 * other mail in this system to describe.
 *
 * ── THE PASSWORD FIELD IS BLANK ON PURPOSE, AND SAYS SO ─────────────────────
 * `handleEmail()` only writes `smtp_password` when the posted value is
 * non-empty, precisely so a blank means "keep it". Rendering the stored password
 * into a `value=""` would put a live credential in the page source of every
 * settings visit — and this file is served over the dev gate to anyone with the
 * preview login. Blank, with the behaviour stated, is the only safe pairing:
 * without the sentence, a blank box looks like the password was lost.
 *
 * @var array<string, string|null> $options  EMAIL_KEYS, defaults applied
 * @var bool                       $saved
 * @var list<string>               $errors
 * @var array<string, mixed>       $user
 */

$opt = static fn (string $k, string $fallback = ''): string
    => (string) ($options[$k] ?? $fallback);

$hasPassword = $opt('smtp_password') !== '';
$encryption  = $opt('smtp_encryption', 'tls');
?>
<div class="page">

    <a class="page__back" href="<?= h(Url::admin('settings')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Settings
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Email</h1>
        </div>
    </div>

    <?php if ($saved && $errors === []): ?>
        <div class="flashes">
            <div class="flash flash--success">Settings saved.</div>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert--danger">
            <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__title">
                    <?= count($errors) === 1 ? 'One thing needs fixing' : count($errors) . ' things need fixing' ?>
                </p>
                <ul class="alert__text">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= h(Url::admin('settings/email')) ?>">
        <?= Csrf::field() ?>

        <div class="form-main">
            <div class="form-card">

                <div class="form-card__section">
                    <?= panel_title('Sender', 'sender') ?>

                    <div class="field">
                        <label class="label" for="f-from-email">From address</label>
                        <input class="input" type="email" id="f-from-email"
                               name="smtp_from_email" value="<?= h($opt('smtp_from_email')) ?>">
                        <?php // WHAT A BLANK MEANS, AS `Mailer::send()` DOES IT. This
                              // said "the server's default sender" while the mail
                              // went out with an empty From header. ?>
                        <?php $defaultFrom = \Cms\Mail\Mailer::defaultFrom(); ?>
                        <p class="form-help">
                            Who form notifications appear to come from.
                            <?php if ($defaultFrom !== ''): ?>
                                Left blank, mail comes from <code><?= h($defaultFrom) ?></code>, at the site's own domain.
                            <?php else: ?>
                                Left blank, mail goes out with no sender address, which many mail servers refuse.
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="field">
                        <label class="label" for="f-from-name">From name</label>
                        <input class="input" type="text" id="f-from-name"
                               name="smtp_from_name" value="<?= h($opt('smtp_from_name')) ?>">
                    </div>
                </div>

                <div class="form-card__section">
                    <?= panel_title('Sending server', 'sending-server') ?>

                    <div class="field">
                        <label class="label" for="f-smtp-host">SMTP host</label>
                        <input class="input" type="text" id="f-smtp-host" name="smtp_host"
                               value="<?= h($opt('smtp_host')) ?>" spellcheck="false"
                               autocomplete="off">
                        <?php // THE SWITCH, STATED AS ONE. This is the field that
                              // decides whether SMTP is used at all — FormController
                              // wraps the whole SMTP block in `if ($smtpHost !== '')`
                              // — and nothing about an empty text box says so. ?>
                        <p class="form-help">
                            Leave blank to send through the server's own mail
                            program. Fill it in to send through a mail provider
                            instead, and complete the three fields below.
                        </p>
                    </div>

                    <div class="form-grid form-grid--2">
                        <div class="field">
                            <label class="label" for="f-smtp-port">Port</label>
                            <input class="input" type="text" id="f-smtp-port" name="smtp_port"
                                   value="<?= h($opt('smtp_port', '587')) ?>"
                                   inputmode="numeric" autocomplete="off">
                        </div>

                        <div class="field">
                            <label class="label" for="f-smtp-encryption">Encryption</label>
                            <select class="select" id="f-smtp-encryption" name="smtp_encryption">
                                <?php // EXACTLY THE THREE VALUES handleEmail()
                                      // ACCEPTS — '', 'tls', 'ssl'. A fourth option
                                      // here would silently become 'tls' on save. ?>
                                <option value="tls"<?= $encryption === 'tls' ? ' selected' : '' ?>>STARTTLS (usually port 587)</option>
                                <option value="ssl"<?= $encryption === 'ssl' ? ' selected' : '' ?>>SSL/TLS (usually port 465)</option>
                                <option value=""<?= $encryption === '' ? ' selected' : '' ?>>None</option>
                            </select>
                        </div>
                    </div>

                    <div class="field">
                        <label class="label" for="f-smtp-username">Username</label>
                        <input class="input" type="text" id="f-smtp-username"
                               name="smtp_username" value="<?= h($opt('smtp_username')) ?>"
                               spellcheck="false" autocomplete="off">
                    </div>

                    <div class="field">
                        <label class="label" for="f-smtp-password">Password</label>
                        <input class="input" type="password" id="f-smtp-password"
                               name="smtp_password" value="" autocomplete="new-password">
                        <p class="form-help">
                            <?php // THE TWO STATES ARE DIFFERENT FACTS. "Leave blank
                                  // to keep it" is meaningless when there is nothing
                                  // to keep, and an empty box with no explanation
                                  // reads as a lost password when there is. ?>
                            <?php if ($hasPassword): ?>
                                A password is saved. Leave this blank to keep it, or
                                type a new one to replace it.
                            <?php else: ?>
                                No password is saved yet.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="form-card__section form-actions">
                    <a class="link link--xs" href="<?= h(Url::admin('settings')) ?>">Cancel</a>
                    <div class="form-actions__spacer"></div>
                    <button type="submit" class="btn btn--primary">Save changes</button>
                </div>

            </div>
        </div>
    </form>

</div>
