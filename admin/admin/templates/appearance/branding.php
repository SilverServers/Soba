<?php

declare(strict_types=1);

use Cms\ContentType\FieldRenderer;
use Cms\Controllers\Admin\BrandingController;
use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Security\Csrf;
use Cms\Security\SignInPage;
use Cms\Ui\Brand;
use Cms\Ui\Theme;

/**
 * APPEARANCE → BRANDING — the Admin and Sign-in page tabs (`BrandingController`
 * has the rules).
 *
 * The palette and the corners are the design language's picker: a labelled
 * <select data-appearance="…">, and `appearance.js` repaints this page as you
 * choose, because the page you are on is the only honest preview. Save keeps
 * it, for everyone.
 *
 * @var string               $tab       admin | sign-in
 * @var list<string>         $errors
 * @var bool                 $saved
 * @var array<string, mixed> $form      the tab's values, as stored or as typed
 * @var string               $siteName  the Site title — what a blank name means
 * @var string               $preview   (sign-in) the sign-in page, for an administrator to look at
 */

$tabUrl = static fn (string $key): string => Url::admin('appearance/branding') . ($key === 'admin' ? '' : '?tab=' . $key);
?>
<div class="page" data-branding>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Branding</h1>
        </div>
        <?php if ($tab === 'sign-in'): ?>
            <div class="page__header-actions">
                <a class="btn" href="<?= h($preview) ?>" target="_blank" rel="noopener">Preview</a>
            </div>
        <?php endif; ?>
    </div>

    <div class="tabs">
        <?php foreach (BrandingController::TABS as $key => $label): ?>
            <a class="tabs__tab<?= $tab === $key ? ' is-active' : '' ?>"
               <?= $tab === $key ? 'aria-current="page"' : '' ?>
               href="<?= h($tabUrl($key)) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
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

    <div class="form-main">
    <?php if ($tab === 'admin'): ?>
        <form class="form-card" method="post" action="<?= h(Url::admin('appearance/branding')) ?>">
            <?= Csrf::field() ?>

            <div class="form-card__section">
                <?= panel_title('Identity', 'brand-identity') ?>
                <div class="field">
                    <label class="label" for="f-brand-name">Name</label>
                    <input class="input" type="text" id="f-brand-name" name="name" maxlength="<?= Brand::NAME_MAX ?>"
                           value="<?= h((string) $form['name']) ?>" placeholder="<?= h($siteName) ?>">
                    <p class="form-help">What the admin bar, the admin's browser tabs and the sign-in pages call this CMS. Empty: the Site title.</p>
                </div>
                <div class="field">
                    <span class="label">Logo</span>
                    <?= FieldRenderer::renderImage('logo', $form['logo']) ?>
                    <p class="form-help">Beside the name in the admin bar, and on the sign-in pages unless they have a logo of their own.</p>
                </div>
                <div class="field">
                    <span class="label">Site icon</span>
                    <?= FieldRenderer::renderImage('icon', $form['icon']) ?>
                    <p class="form-help">The icon in the browser's tab, for the admin, the sign-in pages and the public site. Square, at least 512 pixels.</p>
                </div>
            </div>

            <div class="form-card__section">
                <?= panel_title('Look', 'brand-look') ?>
                <div class="field">
                    <label class="label" for="f-brand-palette">Palette</label>
                    <select class="select" id="f-brand-palette" name="palette" data-appearance="theme">
                        <?php foreach (['signature' => 'Signature', 'legacy' => 'Legacy'] as $family => $familyLabel): ?>
                            <optgroup label="<?= h($familyLabel) ?>">
                                <?php foreach (Theme::all() as $t): ?>
                                    <?php if ($t['family'] === $family): ?>
                                        <option value="<?= h($t['key']) ?>"<?= $t['key'] === $form['palette'] ? ' selected' : '' ?>><?= h($t['label']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="label" for="f-brand-radius">Corners</label>
                    <select class="select" id="f-brand-radius" name="radius" data-appearance="radius">
                        <option value="classic"<?= $form['radius'] === 'classic' ? ' selected' : '' ?>>Square</option>
                        <option value="modern"<?= $form['radius'] === 'modern' ? ' selected' : '' ?>>Rounded</option>
                    </select>
                    <p class="form-help">What everyone sees in the admin. This page shows a choice as you make it; Save keeps it.</p>
                </div>
            </div>

            <div class="form-card__section form-actions">
                <div class="form-actions__spacer"></div>
                <button type="submit" class="btn btn--primary">Save changes</button>
            </div>
        </form>
    <?php else: ?>
        <form class="form-card" method="post" action="<?= h(Url::admin('appearance/branding/sign-in')) ?>">
            <?= Csrf::field() ?>

            <div class="form-card__section">
                <?= panel_title('Logo', 'signin-logo') ?>
                <div class="field">
                    <span class="label">Logo</span>
                    <?= FieldRenderer::renderImage('logo', $form['logo']) ?>
                    <p class="form-help">Above the card. None: the Admin tab's logo, or else the name.</p>
                </div>
                <div class="field">
                    <label class="label" for="f-signin-width">Width</label>
                    <input class="input input--count" type="number" id="f-signin-width" name="logo_width"
                           min="<?= SignInPage::WIDTH[0] ?>" max="<?= SignInPage::WIDTH[1] ?>" value="<?= h((string) $form['logo_width']) ?>" inputmode="numeric">
                    <p class="form-help">Pixels, from <?= SignInPage::WIDTH[0] ?> to <?= SignInPage::WIDTH[1] ?>. Never wider than the card.</p>
                </div>
            </div>

            <div class="form-card__section">
                <?= panel_title('Background', 'signin-background') ?>
                <div class="field">
                    <label class="label" for="f-signin-background">Colour</label>
                    <input class="input input--mono" type="text" id="f-signin-background" name="background"
                           value="<?= h((string) $form['background']) ?>" spellcheck="false" autocapitalize="none">
                    <p class="form-help">A # and six hex digits. Empty: the admin's own.</p>
                </div>
                <div class="field">
                    <span class="label">Image</span>
                    <?= FieldRenderer::renderImage('background_image', $form['background_image']) ?>
                    <p class="form-help">Covers the page behind the card.</p>
                </div>
            </div>

            <div class="form-card__section">
                <?= panel_title('Custom CSS', 'signin-css') ?>
                <div class="field">
                    <label class="label" for="f-signin-css">CSS</label>
                    <textarea class="textarea input--mono" id="f-signin-css" name="css" rows="10"
                              spellcheck="false" autocapitalize="none"><?= h((string) $form['css']) ?></textarea>
                    <p class="form-help">On the pages at the sign-in address only. Nothing in it may load from another site:
                        no <code>@import</code>, and every address a path on this site.</p>
                </div>
            </div>

            <div class="form-card__section form-actions">
                <div class="form-actions__spacer"></div>
                <button type="submit" class="btn btn--primary">Save changes</button>
            </div>
        </form>
    <?php endif; ?>
    </div>

    <?php // The media picker's dialog and behaviour, as on any entry form. ?>
    <script src="<?= h(Asset::admin('js/entry-form.js')) ?>"></script>

</div>
