<?php

declare(strict_types=1);

use Cms\ContentType\FieldRenderer;
use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Security\Csrf;
use Cms\Site\Look;
use Cms\Ui\Theme;

/**
 * APPEARANCE → CUSTOMIZER (`CustomizerController`, `Site\Look`).
 *
 * The controls on the left, the home page on the right as a visitor will see
 * it with them: `customizer.js` repaints the page in the frame as each choice
 * is made, and Save keeps them for everyone. The frame is the site itself,
 * so what it shows is what the theme draws.
 *
 * @var list<string>         $errors
 * @var bool                 $saved
 * @var array<string, mixed> $form     the look, as kept or as chosen before a refusal
 * @var string               $siteName the Site title, which the header shows
 */
?>
<div class="page page--customizer">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Customizer</h1>
        </div>
    </div>

    <?php if ($saved): ?>
        <div class="flashes"><div class="flash flash--success">The site's look is saved.</div></div>
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

    <div class="customizer">
        <?php // `.form-main`, as Branding's: the column whose controls sit in
              // their wells on the card. ?>
        <div class="form-main customizer__controls">
        <form class="form-card" method="post" action="<?= h(Url::admin('appearance/customizer')) ?>" data-customizer>
            <?= Csrf::field() ?>

            <div class="form-card__section">
                <?= panel_title('Colours', 'look-colours') ?>
                <div class="field">
                    <label class="label" for="f-look-palette">Palette</label>
                    <select class="select" id="f-look-palette" name="palette">
                        <?php foreach (['signature' => 'Signature', 'legacy' => 'Legacy'] as $family => $familyLabel): ?>
                            <optgroup label="<?= h($familyLabel) ?>">
                                <?php foreach (Theme::all() as $t): ?>
                                    <?php if ($t['family'] === $family): ?>
                                        <option value="<?= h($t['key']) ?>" data-family="<?= h($t['family']) ?>"<?= $t['key'] === $form['palette'] ? ' selected' : '' ?>><?= h($t['label']) ?><?= $t['kind'] === 'dark' ? ' (dark)' : '' ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-help">Each palette is a whole set of colours, made to be read in.</p>
                </div>
                <div class="field">
                    <label class="label" for="f-look-radius">Corners</label>
                    <select class="select" id="f-look-radius" name="radius">
                        <?php foreach (Look::CORNERS as $value => $label): ?>
                            <option value="<?= h($value) ?>"<?= $form['radius'] === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-card__section">
                <?= panel_title('Fonts', 'look-fonts') ?>
                <?php foreach (['body' => ['font_body', 'Text'], 'head' => ['font_head', 'Headings']] as $key => [$name, $label]): ?>
                    <div class="field">
                        <label class="label" for="f-look-<?= $key ?>"><?= $label ?></label>
                        <select class="select" id="f-look-<?= $key ?>" name="<?= $name ?>">
                            <?php foreach (Look::FONTS as $value => $fontLabel): ?>
                                <option value="<?= h($value) ?>"<?= $form[$key] === $value ? ' selected' : '' ?>><?= h($fontLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
                <p class="form-help">Inter is served by this site. The visitor's own fonts load nothing, and differ from one computer to the next.</p>
            </div>

            <div class="form-card__section">
                <?= panel_title('Logo', 'look-logo') ?>
                <div class="field" data-look-logo>
                    <span class="label">Logo</span>
                    <?= FieldRenderer::renderImage('logo', $form['logo']) ?>
                    <p class="form-help">At the top of every page, linking home. None: the site's name.</p>
                </div>
                <div class="field">
                    <label class="check-list__row" for="f-look-logo-name">
                        <input type="checkbox" id="f-look-logo-name" name="logo_name" value="1"<?= !empty($form['logo_name']) ? ' checked' : '' ?>>
                        <span>Show <?= h($siteName) ?> beside the logo</span>
                    </label>
                </div>
            </div>

            <div class="form-card__section">
                <?= panel_title('Site icon', 'look-icon') ?>
                <div class="field">
                    <span class="label">Site icon</span>
                    <?= FieldRenderer::renderImage('icon', $form['icon']) ?>
                    <p class="form-help">The icon in a visitor's browser tab. Square, at least 512 pixels. None: Branding's, the admin's own.</p>
                </div>
            </div>

            <div class="form-card__section form-actions">
                <div class="form-actions__spacer"></div>
                <button type="submit" class="btn btn--primary">Save changes</button>
            </div>
        </form>
        </div>

        <div class="customizer__preview">
            <iframe class="customizer__frame" src="<?= h(BASE_URL . '/') ?>" title="The home page with these choices"
                    data-customizer-preview
                    data-sheet-signature="<?= h(Look::familySheet('signature')) ?>"
                    data-sheet-legacy="<?= h(Look::familySheet('legacy')) ?>"></iframe>
        </div>
    </div>

</div>
<script src="<?= h(Asset::admin('js/entry-form.js')) ?>"></script>
<script src="<?= h(Asset::admin('js/customizer.js')) ?>"></script>
