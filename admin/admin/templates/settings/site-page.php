<?php

declare(strict_types=1);

use Cms\ContentType\FieldRenderer;
use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Security\Csrf;
use Cms\Site\Language;
use Cms\Site\Settings;

/**
 * A SITE'S OWN SETTINGS PAGE (`Controllers\Admin\SettingsPageController`):
 * its fields in their sections, drawn by the renderer every entry's fields
 * use, and one Save, as Settings → Branding has. With a second language, a
 * tab for each language; there, a text box shows the site's own words as its
 * placeholder, one left empty reads them, and a tick box, the same in both
 * languages, is not drawn.
 *
 * @var array<string, mixed>                                          $page
 * @var list<array{label: string, fields: list<array<string, mixed>>}> $sections
 * @var array<string, mixed>                                          $values field key => value in `$lang`
 * @var list<string>                                                  $errors
 * @var bool                                                          $saved
 * @var string                                                        $lang   '' for the site's own language
 * @var string                                                        $second the second language's tag, or ''
 */
$action = Url::admin('site-settings/' . $page['key']);
?>
<div class="page">
    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title"><?= h((string) $page['label']) ?></h1>
            <?php if ($lang !== '' && $sections !== []): ?>
                <p class="page__subtitle">In <?= h(Language::nameOf($lang)) ?>, a field left empty shows what it holds in <?= h(Language::nameOf('')) ?>, and a tick box is the same in both.</p>
            <?php endif; ?>
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

    <?php if ($second !== ''): ?>
        <div class="tabs">
            <?php foreach (['' => Language::nameOf(''), $second => Language::nameOf($second)] as $tag => $name): ?>
                <a class="tabs__tab<?= $lang === $tag ? ' is-active' : '' ?>"
                   <?= $lang === $tag ? 'aria-current="page"' : '' ?>
                   href="<?= h($action . ($tag !== '' ? '?lang=' . rawurlencode((string) $tag) : '')) ?>"><?= h($name) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($sections === [] && $lang !== ''): ?>
        <div class="empty">
            <?= icon('sliders', 'empty__glyph', '', 28) ?>
            <p class="empty__title">The same in both languages</p>
            <p class="empty__text">This page holds only tick boxes, and a tick box is the same in <?= h(Language::nameOf($lang)) ?> as in <?= h(Language::nameOf('')) ?>.</p>
        </div>
    <?php elseif ($sections === []): ?>
        <div class="empty">
            <?= icon('sliders', 'empty__glyph', '', 28) ?>
            <p class="empty__title">No fields yet</p>
            <p class="empty__text">A page's fields are listed with it in settings-pages.php, or come from a field group placed on it.</p>
        </div>
    <?php else: ?>
        <form method="post" action="<?= h($action) ?>" data-site-settings="<?= h((string) $page['key']) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="lang" value="<?= h($lang) ?>">

            <div class="form-main">
                <div class="form-card">
                    <?php foreach ($sections as $section): ?>
                        <div class="form-card__section">
                            <?= panel_title($section['label'], 'fields') ?>
                            <div class="form-grid form-grid--12">
                                <?php foreach ($section['fields'] as $field): ?>
                                    <?php
                                    // In the second language nothing is required, and a text box shows
                                    // the site's own words as its placeholder.
                                    if ($lang !== '') {
                                        unset($field['required']);
                                    }
                                    if ($lang !== '' && in_array($field['type'] ?? 'text', ['text', 'textarea', 'email', 'url'], true)) {
                                        $own = Settings::stored((string) $page['key'], (string) $field['key']);
                                        if ($own !== null && $own !== '') {
                                            $field['placeholder'] = FieldRenderer::plain($own);
                                        }
                                    }
                                    ?>
                                    <?= FieldRenderer::renderField($field, $values[(string) $field['key']] ?? null) ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="form-card__section form-actions">
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn btn--primary">Save changes</button>
                    </div>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php // The field widgets, as on any entry form: pictures, repeaters, logic and the editor. ?>
<script src="<?= h(Asset::admin('js/logic.js')) ?>"></script>
<script src="<?= h(Asset::admin('js/entry-form.js')) ?>"></script>
<script src="<?= h(Asset::admin('js/reorder.js')) ?>"></script>
<?php if (FieldRenderer::drewGallery()): ?>
    <?php // A gallery field's tiles: the Galleries screen's script and the public gallery's stylesheet. ?>
    <link rel="stylesheet" href="<?= h(Asset::site('assets/css/gallery.css')) ?>">
    <script src="<?= h(Asset::admin('js/gallery-images.js')) ?>"></script>
<?php endif; ?>
<script>
(function () {
    var form = document.querySelector('[data-site-settings]');
    if (form && window.Logic) { Logic.bind(form, {prefix: 'field_'}); }
}());
</script>
<?= \Cms\Ui\Editor::iconTemplate() ?>
<script src="<?= h(Asset::admin('js/prompt.js')) ?>" defer></script>
<script src="<?= h(Asset::admin('js/editor.js')) ?>" defer></script>
