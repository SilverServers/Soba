<?php

declare(strict_types=1);

use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Security\Alerts;
use Cms\Security\Csrf;

/**
 * SECURITY → ACTIVITY LOG → NOTIFICATIONS — the Activity Log's last tab
 * (`Security\Alerts`). A PARTIAL of `activity.php`.
 *
 * A card per channel — Email, then Slack, then the Webhook — each with its
 * switch, its address and its own event switches; and last, the mail an
 * account gets when it signs in from somewhere new. One Save for all of it.
 * Each channel's "Send a test" is its own small form below the main one (a
 * form may not hold another) and uses what is SAVED.
 *
 * The email card says where mail actually goes — `Mailer`'s rule, read, not
 * assumed — and on a development install, that none goes at all.
 *
 * @var array<string, array<string, mixed>> $alerts      Alerts::settings()
 * @var bool                                $newDevice
 * @var list<string>                        $errors
 * @var bool                                $saved
 * @var array<string, mixed>                $posted      what was typed, when a save was refused
 * @var string                              $tested      the channel just tested, or ''
 * @var list<string>                        $admins      every administrator's address
 * @var string                              $smtpHost    Settings → Email's sending server, or ''
 * @var bool                                $emailSends  false on a development install
 */

$typed  = $posted !== [];
$isOn   = static fn (string $ch): bool => $typed ? (($posted[$ch . '_on'] ?? '') === '1') : (bool) $alerts[$ch]['on'];
$picked = static fn (string $ch): array => $typed
    ? Alerts::eventList(implode(',', array_map('strval', (array) ($posted[$ch . '_events'] ?? []))))
    : $alerts[$ch]['events'];
$field  = static fn (string $ch, string $name): string => $typed ? (string) ($posted[$ch . '_' . $name] ?? '') : (string) $alerts[$ch][$name];

// The same glyph an event wears in the log, where it has one.
$eventGlyphs = [
    'lockout'       => 'lock',
    'name_locked'   => 'user',
    'failed'        => 'x',
    'enumeration'   => 'microscope',
    'admin_sign_in' => 'arrow-right-to-bracket',
    'files'         => 'file',
];
$channelSwitch = ['email' => 'Send by email', 'slack' => 'Send to Slack', 'webhook' => 'Send to a webhook'];
$channelNotes  = [
    'email'   => $admins !== [] && trim($field('email', 'to')) === ''
        ? 'to every administrator'
        : 'to the addresses below',
    'slack'   => 'to a Slack channel',
    'webhook' => 'to any address that takes JSON',
];

/** The last delivery, as a chip: the glyph says whether it arrived. */
$delivery = static function (array $s): string {
    $result = trim((string) $s['result']);
    if ($result === '') {
        return '<span class="status status--phrase">' . icon('clock', 'status__icon') . 'Nothing sent yet</span>';
    }
    $ok = preg_match('/(^|: )(2\d\d OK|Handed to)/', $result) === 1;

    return '<span class="status status--phrase' . ($ok ? ' status--active' : '') . '">'
        . icon($ok ? 'check' : 'triangle-exclamation', 'status__icon')
        . h($ok ? 'Delivered' : 'Not delivered') . '</span>';
};
?>
<?php if ($saved): ?>
    <div class="flashes"><div class="flash flash--success">Settings saved.</div></div>
<?php elseif ($tested !== ''): ?>
    <div class="flashes"><div class="flash flash--success">Test on <?= h(Alerts::CHANNELS[$tested]) ?>: <?= h(preg_replace('/^A test: /', '', (string) $alerts[$tested]['result']) ?? '') ?></div></div>
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

<div class="form-main">
    <form method="post" action="<?= h(Url::admin('security/notifications')) ?>">
        <?= Csrf::field() ?>

        <?php foreach (Alerts::CHANNELS as $ch => $label): ?>
            <div class="form-card">
                <div class="form-card__section">
                    <?= panel_title($label, 'alerts-' . $ch) ?>
                    <div class="field">
                        <div class="check-list">
                            <label class="check-list__row">
                                <input type="checkbox" name="<?= h($ch) ?>_on" value="1"<?= $isOn($ch) ? ' checked' : '' ?>>
                                <span class="check-list__name"><?= h($channelSwitch[$ch]) ?></span>
                                <span class="check-list__note"><?= h($channelNotes[$ch]) ?></span>
                            </label>
                        </div>
                    </div>

                    <?php if ($ch === 'email'): ?>
                        <div class="field">
                            <label class="label" for="f-email-to">To</label>
                            <input class="input" type="text" id="f-email-to" name="email_to"
                                   value="<?= h($field('email', 'to')) ?>" placeholder="every administrator"
                                   autocapitalize="none" spellcheck="false">
                            <p class="form-help"><?= $admins !== []
                                ? 'Blank sends to every administrator: ' . h(implode(', ', $admins)) . '.'
                                : 'No administrator has an email address yet. Add one in Users, or type an address here.' ?></p>
                        </div>
                    <?php else: ?>
                        <div class="field">
                            <label class="label" for="f-<?= h($ch) ?>-url"><?= $ch === 'slack' ? 'Incoming webhook address' : 'Address' ?></label>
                            <input class="input input--mono" type="url" id="f-<?= h($ch) ?>-url" name="<?= h($ch) ?>_url"
                                   value="<?= h($field($ch, 'url')) ?>"
                                   placeholder="<?= $ch === 'slack' ? 'https://hooks.slack.com/services/…' : 'https://' ?>"
                                   autocapitalize="none" spellcheck="false">
                        </div>
                    <?php endif; ?>

                    <?php if ($ch === 'webhook'): ?>
                        <div class="field">
                            <label class="label" for="f-webhook-secret">Signing secret</label>
                            <input class="input input--mono" type="password" id="f-webhook-secret" name="webhook_secret"
                                   value="" autocomplete="new-password"
                                   placeholder="<?= $alerts['webhook']['secret'] !== '' ? 'set — leave blank to keep it' : 'none' ?>">
                            <?php if ($alerts['webhook']['secret'] !== ''): ?>
                                <div class="check-list">
                                    <label class="check-list__row">
                                        <input type="checkbox" name="webhook_secret_clear" value="1">
                                        <span class="check-list__name">Remove the secret</span>
                                    </label>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="field">
                        <span class="label">Send when</span>
                        <div class="check-list">
                            <?php foreach (Alerts::EVENTS as $event => $what): ?>
                                <label class="check-list__row">
                                    <input type="checkbox" name="<?= h($ch) ?>_events[]" value="<?= h($event) ?>"<?= in_array($event, $picked($ch), true) ? ' checked' : '' ?>>
                                    <span class="check-list__name"><?= icon($eventGlyphs[$event], 'check-list__glyph icon') ?><?= h($what) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="form-help"><?php if ($ch === 'email'): ?><?php if (!$emailSends): ?>This is a development install: it sends no email.<?php elseif ($smtpHost !== ''): ?>Sent through <?= h($smtpHost) ?> (<a class="link link--xs" href="<?= h(Url::admin('settings/email')) ?>">Settings → Email</a>).<?php else: ?><a class="link link--xs" href="<?= h(Url::admin('settings/email')) ?>">Settings → Email</a> has no sending server, so mail goes through this host's own mail program, which may not deliver it.<?php endif; ?><?php elseif ($ch === 'slack'): ?>The address Slack gives an incoming webhook. The message is plain text.<?php else: ?>A JSON POST: source, version, event, time, site and context. With a secret, it carries <code>X-Webhook-Signature: sha256=</code> and the HMAC of the body.<?php endif; ?></p>
                    </div>
                </div>

                <div class="form-card__section form-actions">
                    <?= $delivery($alerts[$ch]) ?>
                    <?php if ($alerts[$ch]['result'] !== ''): ?>
                        <span class="form-help"><?= h((string) $alerts[$ch]['result']) ?><?= $alerts[$ch]['tried_at'] !== '' ? ' · ' . h(Dates::stamp((string) $alerts[$ch]['tried_at'])) : '' ?></span>
                    <?php endif; ?>
                    <div class="form-actions__spacer"></div>
                    <button type="submit" class="btn btn--sm" form="alerts-test-<?= h($ch) ?>">Send a test</button>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="form-card">
            <div class="form-card__section">
                <?= panel_title('A new sign-in', 'new-device') ?>
                <div class="field">
                    <div class="check-list">
                        <label class="check-list__row">
                            <input type="checkbox" name="new_device" value="1"<?= ($typed ? (($posted['new_device'] ?? '') === '1') : $newDevice) ? ' checked' : '' ?>>
                            <span class="check-list__name"><?= icon('bell', 'check-list__glyph icon') ?>Email an account when it signs in from somewhere new</span>
                            <span class="check-list__note">a browser and address it has not signed in from</span>
                        </label>
                    </div>
                    <p class="form-help">The mail says which browser, from where and when, and how to choose a new password if it was not them. Not on an account's first sign-in.<?= $emailSends ? '' : ' This is a development install: it sends no email.' ?></p>
                </div>
            </div>
            <div class="form-card__section form-actions">
                <span class="form-help">Each channel sends at most once every <?= (int) Alerts::QUIET_MINUTES ?> minutes; what happens in between goes into its next message.</span>
                <div class="form-actions__spacer"></div>
                <button type="submit" class="btn btn--primary">Save changes</button>
            </div>
        </div>
    </form>

    <?php foreach (array_keys(Alerts::CHANNELS) as $ch): ?>
        <form id="alerts-test-<?= h($ch) ?>" method="post" action="<?= h(Url::admin('security/notifications/test')) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="channel" value="<?= h($ch) ?>">
        </form>
    <?php endforeach; ?>
</div>
