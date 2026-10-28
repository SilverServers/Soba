<?php

declare(strict_types=1);

use Cms\Controllers\Admin\SecurityController;
use Cms\Helpers\Url;
use Cms\Security\AddressRules;
use Cms\Security\Challenge;
use Cms\Security\Csrf;

/**
 * SECURITY → BLOCKING — three tabs. A FRAGMENT: `SecurityController` owns
 * `ob_start()` and `layout.php`.
 *
 *   CAPTCHA           when the sign-in and lost-password forms ask the
 *                     built-in question (`Security\Challenge`)
 *   Addresses         banned and allowed IP addresses, with ranges and IPv6
 *                     (`Security\AddressRules`)
 *   User enumeration  the places a site can give its logins away. The CMS has
 *                     none of those places, so what is left to switch is the
 *                     log of who comes looking (`Security\Enumeration`)
 *
 * @var string                $tab
 * @var list<string>          $errors
 * @var bool                  $saved
 * @var string                $captcha    Challenge mode, or as typed after a refused save
 * @var array<string, mixed>  $addresses  AddressRules::settings(), or as typed
 * @var string                $mine       the address this page was asked from
 * @var bool                  $logging    Enumeration::logging()
 */

$tabUrl = static fn (string $key): string => Url::admin('security/blocking') . '?tab=' . $key;

$captchaGlyphs = ['failure' => 'x', 'always' => 'equals', 'off' => 'toggle-off'];
$captchaNotes  = [
    'failure' => 'an owner signing in normally never sees it',
    'always'  => 'every sign-in answers it',
    'off'     => 'no question',
];
$modeGlyphs = ['off' => 'toggle-off', 'block' => 'location-crosshairs', 'allow' => 'check'];
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Blocking</h1>
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
        <?php foreach (SecurityController::BLOCKING_TABS as $key => $label): ?>
            <a class="tabs__tab<?= $tab === $key ? ' is-active' : '' ?>"
               <?= $tab === $key ? 'aria-current="page"' : '' ?>
               href="<?= h($tabUrl($key)) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="form-main">
        <form method="post" action="<?= h(Url::admin('security/blocking')) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="_tab" value="<?= h($tab) ?>">

            <?php if ($tab === 'captcha'): ?>
                <div class="form-card">
                    <div class="form-card__section">
                        <?= panel_title('CAPTCHA', 'captcha') ?>
                        <div class="field">
                            <span class="label">The sign-in and lost-password forms ask a question</span>
                            <div class="check-list">
                                <?php foreach (Challenge::MODES as $mode => $label): ?>
                                    <label class="check-list__row">
                                        <input type="radio" name="mode" value="<?= h($mode) ?>"<?= $captcha === $mode ? ' checked' : '' ?>>
                                        <span class="check-list__name">
                                            <?= icon($captchaGlyphs[$mode], 'check-list__glyph icon') ?><?= h($label) ?>
                                        </span>
                                        <span class="check-list__note"><?= h($captchaNotes[$mode]) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?php // Challenge has why only the built-in question is
                                  // used here; Forms\Captcha has the providers. ?>
                            <p class="form-help">A short sum; its answer stays on the server. Only this built-in
                                question is used here, never hCaptcha or reCAPTCHA: their script would run on the
                                page where the password is typed. Forms can use them (Settings → General → Spam
                                protection). A hidden field that only scripts fill is always on the sign-in form.<?= \Cms\Auth\Auth::enforcesPasswordChanges() ? '' : ' This is a development install: it never asks the question, because its test tools sign in by script.' ?></p>
                        </div>
                    </div>

            <?php elseif ($tab === 'enumeration'): ?>
                <?php
                // WHERE A SITE CAN GIVE A LOGIN AWAY — one row for each place —
                // and what this site has there, as a chip: shown, not a
                // paragraph. No author or feed routes, no users route in the
                // API, `Sitemap` names no account, bylines are
                // `User::bylineSql()`, the sign-in error is one sentence
                // (`login.php`). Nothing here to switch off, so no switches.
                $places = [
                    ['Author pages', '?author=1 and /author/<name>', 'None here'],
                    ['The users API', '/wp-json/wp/v2/users', 'None here'],
                    ['Feeds and oEmbed', 'an article\'s author, by name', 'None here'],
                    ['Sitemaps', 'the users sitemap', 'Page addresses only'],
                    ['Bylines', 'the name under an article', 'Display name or site name'],
                    ['The sign-in error', 'a wrong username against a wrong password', 'The same answer'],
                ];
                ?>
                <div class="form-card">
                    <div class="form-card__section">
                        <?= panel_title('User enumeration', 'enumeration') ?>
                        <span class="label">Where WordPress gives usernames away</span>
                        <?php // `--bleed`: the list is the rest of its section. Inset, it
                              // would sit on the card's `--surface` in the same
                              // `--surface`, with a band of card all round it — a tone
                              // collision, and import-export.php's reason too. ?>
                        <div class="list list--bleed entry-list">
                            <?php foreach ($places as [$place, $where, $here]): ?>
                                <div class="list__row">
                                    <div class="entry-list__main">
                                        <span class="list__row-title"><?= h($place) ?></span>
                                        <div class="list__row-sub"><?= h($where) ?></div>
                                    </div>
                                    <div><span class="status status--phrase status--active"><?= icon('check', 'status__icon') ?><?= h($here) ?></span></div>
                                    <div class="list__row-meta"></div>
                                    <div class="list__row-actions"></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-card__section">
                        <div class="field">
                            <div class="check-list">
                                <label class="check-list__row">
                                    <input type="checkbox" name="log" value="1"<?= $logging ? ' checked' : '' ?>>
                                    <span class="check-list__name">
                                        <?= icon('microscope', 'check-list__glyph icon') ?>Log attempts to list usernames
                                    </span>
                                    <span class="check-list__note">one line per address an hour</span>
                                </label>
                            </div>
                            <p class="form-help">Who came looking, and where:
                                <a class="link link--xs" href="<?= h(Url::admin('security/activity') . '?tab=enumeration') ?>">Activity log → Enumeration</a>.</p>
                        </div>
                    </div>

            <?php else: ?>
                <div class="form-card">
                    <div class="form-card__section">
                        <?= panel_title('Addresses', 'blocked-addresses') ?>
                        <div class="field">
                            <span class="label">The list</span>
                            <div class="check-list">
                                <?php foreach (AddressRules::MODES as $mode => $label): ?>
                                    <label class="check-list__row">
                                        <input type="radio" name="mode" value="<?= h($mode) ?>"<?= $addresses['mode'] === $mode ? ' checked' : '' ?>>
                                        <span class="check-list__name">
                                            <?= icon($modeGlyphs[$mode], 'check-list__glyph icon') ?><?= h($label) ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="field">
                            <label class="label" for="f-addresses">Addresses and ranges</label>
                            <textarea class="textarea input--mono" id="f-addresses" name="list" rows="8"
                                      spellcheck="false" autocapitalize="none"><?= h(implode("\n", $addresses['list'])) ?></textarea>
                            <p class="form-help">One per line: an address (203.0.113.9), or a range (198.51.100.0/24,
                                2001:db8::/48). It applies to the sign-in page and the admin, not the public site.
                                Your address is <?= h($mine) ?>.</p>
                        </div>
                    </div>
                    <div class="form-card__section">
                        <?= panel_title('Message', 'block-message') ?>
                        <div class="field">
                            <label class="label" for="f-block-message">What a turned-away address sees</label>
                            <input class="input" type="text" id="f-block-message" name="message" maxlength="255"
                                   value="<?= h($addresses['message']) ?>" placeholder="<?= h(AddressRules::DEFAULT_MESSAGE) ?>">
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
