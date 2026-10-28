<?php

declare(strict_types=1);

use Cms\Assistant\Budget;
use Cms\Forms\Captcha;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * SETTINGS → GENERAL.
 *
 * A FRAGMENT, NOT A PAGE. `SettingsController::showGeneral()` wraps this in
 * `ob_start()` and requires `layout.php` itself, so this file must not do
 * either — unlike every list and edit template in the tree, which own their own
 * wrapper. The two conventions live side by side; check the call site.
 *
 * ── THE DATE AND TIME FORMATS ARE READ ──────────────────────────────────────
 * `handleGeneral()` saves them and `Helpers\Dates` reads them; the admin's
 * dates go through it, so the preview under these fields is the same code path
 * the lists use, not a description of it. A format hard-coded in a template
 * would make this a control whose only effect is the words beside it.
 *
 * ── THE PREVIEW IS THE HINT ─────────────────────────────────────────────────
 * "Use PHP date() syntax" tells a site owner nothing they can act on, so there
 * is no such hint: the SELECT ITSELF renders each choice as the date it
 * produces, and the line under it renders today through `Dates::short()` — the
 * same call the lists make. Choosing is reading.
 *
 * NO JAVASCRIPT. With the options rendered as examples nothing needs to update
 * live — the preview changes when the form is saved, which is the only moment
 * the lists change too.
 *
 * @var array<string, string|null> $options  GENERAL_KEYS, defaults applied
 * @var bool                       $saved
 * @var list<string>               $errors
 * @var array<string, mixed>       $user
 */

$opt = static fn (string $k, string $fallback = ''): string
    => (string) ($options[$k] ?? $fallback);

$timezones = timezone_identifiers_list();
$current   = $opt('timezone', 'America/Vancouver');

/** The formats offered as ready-made choices, rendered as they will appear. */
$dateChoices = ['M j, Y', 'j M Y', 'F j, Y', 'Y-m-d', 'd/m/Y', 'm/d/Y'];
$timeChoices = ['g:i A', 'g:ia', 'H:i'];
$now         = time();
?>
<div class="page">

    <a class="page__back" href="<?= h(Url::admin('settings')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Settings
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">General</h1>
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

    <form method="post" action="<?= h(Url::admin('settings/general')) ?>">
        <?= Csrf::field() ?>

        <?php // `.form-main` PUTS THIS ON THE SURFACE LADDER — without it every
              // control shares its card's tone. See users/create.php. ?>
        <div class="form-main">
            <div class="form-card">

                <div class="form-card__section">
                    <?= panel_title('Identity', 'identity') ?>

                    <div class="field">
                        <label class="label" for="f-site-name">Site title</label>
                        <?php // OPTIONAL. Blank is a real state (migration 058): the
                              // site's own host stands in. The box
                              // shows what is STORED — the host is only its
                              // placeholder — or saving would write the host as the
                              // title without anyone typing it. ?>
                        <input class="input" type="text" id="f-site-name" name="site_name"
                               value="<?= h($opt('site_name')) ?>"
                               placeholder="<?= h(\Cms\SEO\Head::globalTokens()['site_name']) ?>">
                        <p class="form-help">This site's name everywhere: the admin bar, the sign-in page, page titles, search results and the sender of its emails. Left blank, the site's web address is used.</p>
                    </div>

                    <div class="field">
                        <label class="label" for="f-site-tagline">Tagline</label>
                        <input class="input" type="text" id="f-site-tagline" name="site_tagline"
                               value="<?= h($opt('site_tagline')) ?>">
                        <?php // NO CLAIM ABOUT WHERE IT APPEARS. `seo_pattern_home`
                              // defaults to "{site_name} | {tagline}", so it shows
                              // on the homepage title ONLY while that pattern
                              // contains the token — which the SEO screen can
                              // change. Naming one place would be wrong the moment
                              // it does. ?>
                        <p class="form-help">
                            Available to title patterns as <code>{tagline}</code>.
                        </p>
                    </div>

                    <div class="field">
                        <label class="label" for="f-footer-text">Footer text</label>
                        <input class="input" type="text" id="f-footer-text" name="site_footer_text"
                               value="<?= h($opt('site_footer_text')) ?>"
                               autocomplete="off"
                               placeholder="© <?= date('Y') ?> <?= h($opt('site_name')) ?>">
                        <?php // WHAT IT HOLDS, AND WHAT BLANK DOES. A footer is for
                              // an address, a company number, a charity
                              // registration — not only a copyright line written
                              // into `base.php`, where no owner could reach it.
                              //
                              // Blank keeps the copyright line rather than
                              // emptying the footer: a site that has said nothing
                              // about it should not lose what it had. ?>
                        <p class="form-help">
                            The line at the bottom of every page. Leave it empty for
                            the copyright line above. A footer menu, if you build
                            one, appears beside it — Appearance &rarr; Menus.
                        </p>
                    </div>

                    <div class="field">
                        <label class="label" for="f-admin-email">Administrator email</label>
                        <input class="input" type="email" id="f-admin-email" name="admin_email"
                               value="<?= h($opt('admin_email')) ?>">
                    </div>
                </div>

                <div class="form-card__section">
                    <?= panel_title('Dates and times', 'dates-and-times') ?>

                    <?php // SIZED TO THE VALUE, NOT TO THE COLUMN. A 1130px box for a
                          // number of dollars, or for "Aug 27, 2026", is a control
                          // sized to its container: the box is a promise about
                          // how much you are expected to type. `.field--narrow`
                          // caps it at 320. ?>
                    <div class="field field--narrow">
                        <label class="label" for="f-timezone">Timezone</label>
                        <select class="select" id="f-timezone" name="timezone">
                            <?php foreach ($timezones as $tz): ?>
                                <option value="<?= h($tz) ?>"<?= $tz === $current ? ' selected' : '' ?>>
                                    <?= h(str_replace('_', ' ', $tz)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php // A SELECT OF RENDERED EXAMPLES, not a text box wanting
                          // PHP's date() syntax. The owner picks the shape they
                          // want by reading it. `handleGeneral()` accepts any
                          // string, so a format typed by hand still saves — this
                          // narrows the choice without narrowing the field. ?>
                    <div class="field field--narrow">
                        <label class="label" for="f-date-format">Date format</label>
                        <select class="select" id="f-date-format" name="date_format">
                            <?php
                            $currentDate = $opt('date_format', 'M j, Y');
                            $dateOptions = in_array($currentDate, $dateChoices, true)
                                ? $dateChoices
                                : array_merge([$currentDate], $dateChoices);
                            foreach ($dateOptions as $fmt): ?>
                                <option value="<?= h($fmt) ?>"<?= $fmt === $currentDate ? ' selected' : '' ?>>
                                    <?= h(date($fmt, $now)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field field--narrow">
                        <label class="label" for="f-time-format">Time format</label>
                        <select class="select" id="f-time-format" name="time_format">
                            <?php
                            $currentTime = $opt('time_format', 'g:i A');
                            $timeOptions = in_array($currentTime, $timeChoices, true)
                                ? $timeChoices
                                : array_merge([$currentTime], $timeChoices);
                            foreach ($timeOptions as $fmt): ?>
                                <option value="<?= h($fmt) ?>"<?= $fmt === $currentTime ? ' selected' : '' ?>>
                                    <?= h(date($fmt, $now)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php // SHOW THE RESULT, through the same helper the lists
                              // use — so this is the actual output, not a mock-up
                              // of it. Show, don't tell. ?>
                        <p class="form-help">
                            Lists currently stamp entries as
                            <strong><?= h(Dates::short(date('c', $now))) ?></strong>.
                        </p>
                    </div>
                </div>

                <div class="form-card__section">
                    <?= panel_title('Reading', 'reading') ?>

                    <?php // ENTRIES PER PAGE (`posts_per_page`). It is here rather
                          // than on a screen of its own because it is the one
                          // reading setting the CMS has. Read by every public list
                          // through `Helpers\Paging`: a type's archive, a term's
                          // archive and the front page's feed — which is what the
                          // help line says; those are the three controllers that
                          // call it. A count box, sized to a count. ?>
                    <div class="field field--narrow">
                        <label class="label" for="f-per-page">Entries per page</label>
                        <input class="input input--count" type="number" id="f-per-page"
                               name="posts_per_page" min="1" max="100"
                               value="<?= h($opt('posts_per_page', (string) \Cms\Helpers\Paging::DEFAULT_PER_PAGE)) ?>">
                        <p class="form-help">
                            How many each archive, each category page and the front
                            page show before going on to a second page — unless a
                            content type sets its own, under Structure → Content types.
                        </p>
                    </div>
                </div>

                <?php // No Assistant panel when the module is switched off
                      // (Helpers\Modules). The stored limit rides along
                      // hidden, so saving this form cannot reset it. ?>
                <?php if (!\Cms\Helpers\Modules::enabled('assistant')): ?>
                <input type="hidden" name="<?= h(Budget::OPTION_LIMIT) ?>" value="<?= h($opt(Budget::OPTION_LIMIT, '25')) ?>">
                <?php else: ?>
                <div class="form-card__section">
                    <?= panel_title('Assistant', 'assistant') ?>

                    <div class="field field--narrow">
                        <label class="label" for="f-assistant-limit">Monthly spend limit</label>
                        <input class="input" type="text" id="f-assistant-limit"
                               name="<?= h(Budget::OPTION_LIMIT) ?>"
                               value="<?= h($opt(Budget::OPTION_LIMIT, '25')) ?>">
                        <?php // BOTH ACCEPTED FORMS ARE NAMED because the
                              // validator accepts both: a number, or the literal
                              // word "unlimited". A hint that mentioned only the
                              // number would make the other look like a mistake. ?>
                        <p class="form-help">
                            In US dollars. <code>Budget</code> checks this before every
                            assistant call and refuses once the month's spend reaches it.
                            Enter <code>unlimited</code> to remove the ceiling.
                        </p>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-card__section">
                    <?= panel_title('Spam protection', 'shield-halved') ?>

                    <?php // WHAT THE CAPTCHA FIELD USES, site-wide. A key
                          // pasted once covers every form; the built-in
                          // challenge needs none, which is why it is the
                          // default. `Forms\Captcha` is the whole reasoning. ?>
                    <div class="field field--narrow">
                        <label class="label" for="f-captcha-provider">Captcha</label>
                        <select class="select" id="f-captcha-provider"
                                name="<?= h(Captcha::PROVIDER_KEY) ?>">
                            <?php $chosen = $opt(Captcha::PROVIDER_KEY, 'builtin'); ?>
                            <?php foreach (Captcha::providers() as $value => $label): ?>
                                <option value="<?= h($value) ?>"<?= $chosen === $value ? ' selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-help">
                            The built-in one asks a simple question and sends nothing
                            anywhere. The other two are services: they need the keys
                            below, and they load that company's script on every page a
                            form appears on.
                        </p>
                    </div>

                    <div class="form-grid form-grid--2">
                        <div class="field">
                            <label class="label" for="f-captcha-site">Site key</label>
                            <input class="input input--mono" type="text" id="f-captcha-site"
                                   name="<?= h(Captcha::SITE_KEY) ?>" autocomplete="off"
                                   value="<?= h($opt(Captcha::SITE_KEY, '')) ?>">
                        </div>
                        <div class="field">
                            <label class="label" for="f-captcha-secret">Secret key</label>
                            <input class="input input--mono" type="text" id="f-captcha-secret"
                                   name="<?= h(Captcha::SECRET_KEY) ?>" autocomplete="off"
                                   value="<?= h($opt(Captcha::SECRET_KEY, '')) ?>">
                        </div>
                    </div>
                    <?php // SAID PLAINLY, because the alternative is a form
                          // nobody can submit and no error anywhere saying so. ?>
                    <p class="form-help">
                        Only for hCaptcha and reCAPTCHA. With either chosen and these
                        blank, the built-in question is used instead — a service that
                        cannot verify would leave every form unsubmittable.
                    </p>
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
