<?php

declare(strict_types=1);

use Cms\Commerce\Countries;
use Cms\Commerce\Regions;
use Cms\Helpers\Url;
use Cms\Security\Csrf;
use Cms\Site\Business;

/**
 * SETTINGS → BUSINESS DETAILS.
 *
 * A FRAGMENT, as Settings → General is: `BusinessController` wraps it in the
 * layout. The boxes hold what is kept (`Business::stored()`); what is made from
 * it is shown beside what it is made from, as it will be used: each saved
 * number as it is dialled and with its shortcodes, the week as the sentence
 * [hours] prints, each profile under its network's name.
 *
 * The lists are `entry-form.js`'s repeater, as Settings → Shop's rates are.
 *
 * @var array<string, mixed>  $kept     what the boxes show
 * @var array<string, mixed>  $details  `Business::get()`, as saved
 * @var list<string>          $errors
 * @var bool                  $saved
 * @var bool                  $local    the structured data's node is a local business
 * @var array<int, string>    $pages    published pages, id => title
 */

$place   = (array) $kept['place'];
$country = (string) ($place['country'] ?? '');
$madePhones  = $details['phones'];

$phoneRow = static function (int|string $i, ?array $row) use ($madePhones): string {
    $row  = $row ?? ['key' => '', 'label' => '', 'number' => '', 'texts' => false];
    $name = 'phones[' . $i . ']';
    $made = $madePhones[(string) $row['key']] ?? null;
    $out  = '<div class="repeater__row" data-repeater-row>'
          . '<div class="repeater__fields form-grid form-grid--12">'
          . '<div class="field field--third"><label class="label">Label</label>'
          . '<input class="input" type="text" name="' . $name . '[label]" value="' . h((string) $row['label']) . '" placeholder="Office" autocomplete="off"></div>'
          . '<div class="field field--third"><label class="label">Number</label>'
          . '<input class="input" type="tel" name="' . $name . '[number]" value="' . h((string) $row['number']) . '" placeholder="(250) 555-0134" autocomplete="off"></div>'
          . '<div class="field field--third"><label class="label">Shortcode name</label>'
          . '<input class="input input--mono" type="text" name="' . $name . '[key]" value="' . h((string) $row['key']) . '" placeholder="From the label" autocomplete="off" spellcheck="false"></div>'
          . '<div class="field"><label class="check-list__row">'
          . '<input type="checkbox" name="' . $name . '[texts]" value="1"' . (!empty($row['texts']) ? ' checked' : '') . '>'
          . '<span>Takes text messages</span></label></div>';
    // AS SAVED: how it is dialled, and the shortcodes that print it.
    if ($made !== null && $made['number'] === (string) $row['number']) {
        $out .= '<div class="field"><p class="form-help" data-phone-made="' . h($made['key']) . '">'
              . ($made['tel'] !== '' ? 'Dialled as <code>' . h($made['tel']) . '</code> · ' : '')
              . '<code>[phone ' . h($made['key']) . ']</code>'
              . ($made['texts'] ? ' · <code>[sms ' . h($made['key']) . ']</code>' : '') . '</p></div>';
    }

    return $out . '</div>'
        . '<button type="button" class="icon-btn" data-repeater-remove data-tip="Remove this number" aria-label="Remove this number">'
        . \Cms\Ui\Icons::render('trash-can', 'icon icon--sm') . '</button></div>';
};

$areaRow = static function (int|string $i, ?array $row) use ($madePhones, $pages): string {
    $row  = $row ?? ['name' => '', 'towns' => [], 'phone' => '', 'page' => 0];
    $name = 'areas[' . $i . ']';
    $out  = '<div class="repeater__row" data-repeater-row>'
          . '<div class="repeater__fields form-grid form-grid--12">'
          . '<div class="field field--third"><label class="label">Name</label>'
          . '<input class="input" type="text" name="' . $name . '[name]" value="' . h((string) $row['name']) . '" placeholder="North Shore" autocomplete="off"></div>'
          . '<div class="field field--two-thirds"><label class="label">Towns</label>'
          . '<input class="input" type="text" name="' . $name . '[towns]" value="' . h(implode(', ', (array) $row['towns'])) . '" placeholder="Separated with commas" autocomplete="off"></div>'
          . '<div class="field field--half"><label class="label">Phone number</label>'
          . '<select class="select" name="' . $name . '[phone]"><option value="">None of its own</option>';
    foreach ($madePhones as $key => $p) {
        $out .= '<option value="' . h($key) . '"' . ((string) $row['phone'] === $key ? ' selected' : '') . '>'
              . h(($p['label'] !== '' ? $p['label'] . ', ' : '') . $p['number']) . '</option>';
    }
    $out .= '</select></div>';
    if ($pages !== []) {
        $out .= '<div class="field field--half"><label class="label">Its page</label>'
              . '<select class="select" name="' . $name . '[page]"><option value="0">None</option>';
        foreach ($pages as $id => $title) {
            $out .= '<option value="' . (int) $id . '"' . ((int) $row['page'] === (int) $id ? ' selected' : '') . '>' . h((string) $title) . '</option>';
        }
        $out .= '</select></div>';
    }

    return $out . '</div>'
        . '<button type="button" class="icon-btn" data-repeater-remove data-tip="Remove this area" aria-label="Remove this area">'
        . \Cms\Ui\Icons::render('trash-can', 'icon icon--sm') . '</button></div>';
};

$socialRow = static function (int|string $i, ?array $row): string {
    $url  = (string) ($row['url'] ?? '');
    $net  = $url !== '' ? Business::network($url) : '';
    $glyph = $net !== '' && \Cms\Ui\Icons::exists($net === 'x' ? 'x-twitter' : $net) ? ($net === 'x' ? 'x-twitter' : $net) : 'link';

    return '<div class="repeater__row" data-repeater-row>'
        . '<div class="repeater__fields form-grid form-grid--12">'
        . '<div class="field"><label class="label">' . ($url !== '' ? \Cms\Ui\Icons::render($glyph, 'icon icon--sm') . ' ' : '') . 'Address</label>'
        . '<input class="input" type="url" name="social[' . $i . '][url]" value="' . h($url) . '" placeholder="https://www.facebook.com/…" autocomplete="off" spellcheck="false"></div>'
        . '</div>'
        . '<button type="button" class="icon-btn" data-repeater-remove data-tip="Remove this profile" aria-label="Remove this profile">'
        . \Cms\Ui\Icons::render('trash-can', 'icon icon--sm') . '</button></div>';
};
?>
<div class="page">

    <?php // BACK TO WHERE THE ACCOUNT CAN GO: Settings is administrators',
          // and a role granted this screen (`Auth::can()`) goes back to the dashboard. ?>
    <?php $backToSettings = \Cms\Auth\Auth::hasRole('admin'); ?>
    <a class="page__back" href="<?= h($backToSettings ? Url::admin('settings') : Url::admin()) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?><?= $backToSettings ? 'Settings' : 'Dashboard' ?>
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Business details</h1>
        </div>
    </div>

    <?php if ($saved && $errors === []): ?>
        <div class="flashes">
            <div class="flash flash--success">Business details saved.</div>
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

    <form method="post" action="<?= h(Url::admin('settings/business')) ?>">
        <?= Csrf::field() ?>

        <div class="form-main">
            <div class="form-card">

                <div class="form-card__section">
                    <?= panel_title('Name', 'identity') ?>
                    <div class="form-grid form-grid--12">
                        <div class="field field--two-thirds">
                            <label class="label" for="f-biz-name">Business name</label>
                            <input class="input" type="text" id="f-biz-name" name="name"
                                   value="<?= h((string) $kept['name']) ?>">
                        </div>
                        <div class="field field--third">
                            <label class="label" for="f-biz-short">Short name</label>
                            <input class="input" type="text" id="f-biz-short" name="short"
                                   value="<?= h((string) $kept['short']) ?>"
                                   placeholder="<?= h((string) $kept['name']) ?>">
                        </div>
                    </div>
                </div>

                <div class="form-card__section">
                    <?= panel_title('Contact', 'contact') ?>
                    <div class="field">
                        <label class="label" for="f-biz-email">Email</label>
                        <input class="input" type="email" id="f-biz-email" name="email"
                               value="<?= h((string) $kept['email']) ?>" autocomplete="off" spellcheck="false">
                        <p class="form-help"><code>[email]</code></p>
                    </div>

                    <div class="field">
                    <p class="label">Phone numbers</p>
                    <div class="repeater" data-repeater data-business-phones>
                        <div class="repeater__rows" data-repeater-rows>
                            <?php foreach (array_values((array) $kept['phones']) as $i => $row): ?>
                                <?= $phoneRow($i, $row) ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-actions form-actions--bare">
                            <button type="button" class="btn" data-repeater-add>Add a number</button>
                        </div>
                        <template data-repeater-template><?= $phoneRow('__ROW__', null) ?></template>
                    </div>
                    </div>
                    <p class="form-help">The first is the main number, <code>[phone]</code>; the first that takes texts is <code>[sms]</code>. A number outside Canada and the United States is written with + and its country's code.</p>
                </div>

                <div class="form-card__section">
                    <?= panel_title('Opening hours', 'opening-hours') ?>
                    <?php // A ROW A DAY on the card itself, its two times as fields:
                          // a day with both is open, a day with neither is closed. ?>
                    <div class="kv-list kv-list--fields field" data-business-week>
                        <div class="kv-list__row">
                            <span class="kv-list__key"></span>
                            <div class="form-grid form-grid--2">
                                <span class="label">Opens</span>
                                <span class="label">Closes</span>
                            </div>
                        </div>
                        <?php foreach (Business::DAYS as $day => $dayName):
                            $span = (array) ($kept['hours']['week'][$day] ?? []); ?>
                            <div class="kv-list__row">
                                <span class="kv-list__key"><?= h($dayName) ?></span>
                                <div class="form-grid form-grid--2">
                                    <input class="input" type="time" name="hours[week][<?= h($day) ?>][0]"
                                           value="<?= h((string) ($span[0] ?? '')) ?>" aria-label="<?= h($dayName) ?> opens">
                                    <input class="input" type="time" name="hours[week][<?= h($day) ?>][1]"
                                           value="<?= h((string) ($span[1] ?? '')) ?>" aria-label="<?= h($dayName) ?> closes">
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="field">
                        <label class="label" for="f-biz-hours">Hours as written</label>
                        <?php // The sentence made from the week is the placeholder:
                              // it is what [hours] prints while the box is empty. ?>
                        <input class="input" type="text" id="f-biz-hours" name="hours[text]"
                               value="<?= h((string) $kept['hours']['text']) ?>"
                               placeholder="<?= h(Business::hoursLine($details['hours']['week'])) ?>">
                        <p class="form-help"><code>[hours]</code></p>
                    </div>
                    <?php if (!$local && $details['hours']['groups'] !== []): ?>
                        <p class="form-help">Search engines read opening hours from a local business: <a class="link" href="<?= h(Url::admin('settings/seo?tab=schema')) ?>">Settings → SEO → Schema</a> says Organisation.</p>
                    <?php endif; ?>
                </div>

                <div class="form-card__section">
                    <?= panel_title('Place', 'place') ?>
                    <div class="form-grid form-grid--12">
                        <div class="field field--half">
                            <label class="label" for="f-biz-street">Street address</label>
                            <input class="input" type="text" id="f-biz-street" name="place[street]"
                                   value="<?= h((string) ($place['street'] ?? '')) ?>" placeholder="Not published">
                        </div>
                        <div class="field field--half">
                            <label class="label" for="f-biz-city">Town or city</label>
                            <input class="input" type="text" id="f-biz-city" name="place[city]"
                                   value="<?= h((string) ($place['city'] ?? '')) ?>">
                        </div>
                        <div class="field field--third">
                            <label class="label" for="f-biz-region">Province or state</label>
                            <select class="select" id="f-biz-region" name="place[region]">
                                <option value="">None</option>
                                <?php foreach (Regions::ALL as $regionCountry => $regionList): ?>
                                    <optgroup label="<?= h(Countries::name($regionCountry)) ?>">
                                        <?php foreach ($regionList as $code => $regionName): ?>
                                            <option value="<?= h($code) ?>"<?= $country === $regionCountry && ($place['region'] ?? '') === $code ? ' selected' : '' ?>><?= h($regionName) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field field--quarter">
                            <label class="label" for="f-biz-postcode">Postal code</label>
                            <input class="input" type="text" id="f-biz-postcode" name="place[postcode]"
                                   value="<?= h((string) ($place['postcode'] ?? '')) ?>">
                        </div>
                        <div class="field field--third">
                            <label class="label" for="f-biz-country">Country</label>
                            <select class="select" id="f-biz-country" name="place[country]">
                                <?php foreach (Countries::ALL as $code => $countryName): ?>
                                    <option value="<?= h($code) ?>"<?= $country === $code ? ' selected' : '' ?>><?= h($countryName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <p class="form-help">A street is published when one is given. Left empty, the place is the town, province and country alone.</p>
                </div>

                <div class="form-card__section">
                    <?= panel_title('Service areas', 'service-areas') ?>
                    <div class="repeater" data-repeater data-business-areas>
                        <div class="repeater__rows" data-repeater-rows>
                            <?php foreach (array_values((array) $kept['areas']) as $i => $row): ?>
                                <?= $areaRow($i, $row) ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-actions form-actions--bare">
                            <button type="button" class="btn" data-repeater-add>Add an area</button>
                        </div>
                        <template data-repeater-template><?= $areaRow('__ROW__', null) ?></template>
                    </div>
                </div>

                <div class="form-card__section">
                    <?= panel_title('Social profiles', 'social-profiles') ?>
                    <div class="repeater" data-repeater data-business-social>
                        <div class="repeater__rows" data-repeater-rows>
                            <?php foreach (array_values((array) $kept['social']) as $i => $row): ?>
                                <?= $socialRow($i, is_array($row) ? $row : ['url' => (string) $row]) ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-actions form-actions--bare">
                            <button type="button" class="btn" data-repeater-add>Add a profile</button>
                        </div>
                        <template data-repeater-template><?= $socialRow('__ROW__', null) ?></template>
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

<script src="<?= h(\Cms\Helpers\Asset::admin('js/entry-form.js')) ?>"></script>
