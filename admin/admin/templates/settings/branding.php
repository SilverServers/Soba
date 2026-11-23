<?php

declare(strict_types=1);

use Cms\ContentType\FieldRenderer;
use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Security\Csrf;
use Cms\Ui\Theme;

/**
 * SETTINGS → BRANDING (`Ui\Branding`): one sheet. The site's logo, in a version
 * for light backgrounds and one for dark, shown on the admin's top bar and on
 * the sign-in pages; and the theme the sign-in pages wear.
 *
 * @var list<string>         $errors
 * @var bool                 $saved
 * @var array<string, mixed> $form   logo_light, logo_dark (media ids) and signin_theme, as kept or as chosen
 */
?>
<div class="page">
    <a class="page__back" href="<?= h(Url::admin('settings')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Settings
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Branding</h1>
            <p class="page__subtitle">The site's logo on the admin's top bar and its sign-in pages, and the look of those sign-in pages.</p>
        </div>
    </div>

    <?php if ($saved): ?>
        <div class="flashes">
            <div class="flash flash--success">Settings saved.</div>
        </div>
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

    <form method="post" action="<?= h(Url::admin('settings/branding')) ?>">
        <?= Csrf::field() ?>

        <div class="form-main">
            <div class="form-card">
                <div class="form-card__section">
                    <?= panel_title('Logo', 'branding-logo') ?>
                    <p class="form-help">Shown on the admin's top bar and on the sign-in pages, each in the version that reads on its background. With one version set, it is used everywhere; with none, the Site title is shown.</p>
                    <div class="field" data-branding-logo="light">
                        <span class="label">For light backgrounds</span>
                        <?= FieldRenderer::renderImage('logo_light', $form['logo_light']) ?>
                    </div>
                    <div class="field" data-branding-logo="dark">
                        <span class="label">For dark backgrounds</span>
                        <?= FieldRenderer::renderImage('logo_dark', $form['logo_dark']) ?>
                    </div>
                </div>

                <div class="form-card__section">
                    <?= panel_title('Sign-in page', 'branding-signin') ?>
                    <div class="field">
                        <label class="label" for="f-signin-theme">Theme</label>
                        <select class="select" id="f-signin-theme" name="signin_theme">
                            <?php foreach (Theme::offered() as $t): ?>
                                <option value="<?= h($t['key']) ?>"<?= $t['key'] === $form['signin_theme'] ? ' selected' : '' ?>><?= h($t['label']) ?><?= $t['key'] === Theme::defaultKey() ? ' (default)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-help">The admin theme every page at the sign-in address wears.</p>
                    </div>
                </div>

                <div class="form-card__section form-actions">
                    <div class="form-actions__spacer"></div>
                    <button type="submit" class="btn btn--primary">Save changes</button>
                </div>
            </div>
        </div>
    </form>
</div>
<?php // The media picker's dialog and behaviour, as on any entry form. ?>
<script src="<?= h(Asset::admin('js/entry-form.js')) ?>"></script>
