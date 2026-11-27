<?php

declare(strict_types=1);

use Cms\ContentType\FieldRenderer;
use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * FILES AND MEDIA → PICTURE PLACES (`Controllers\Admin\PicturePlaceController`):
 * each spot the site's `places.php` lists as a picture place, by its name, with
 * the picture it shows and the media picker to choose, change or remove it,
 * and the page it is on. One sheet and one Save, as Settings → Branding is. A
 * spot with no picture shows the site's own (`Helpers\Places::picture()`).
 *
 * @var array<string, array{name: string, url?: string, kind: string}> $places
 * @var array<string, int> $chosen      key => the picture's id, 0 for none
 * @var list<string>       $errors
 * @var bool               $saved
 * @var string|null        $placesError why places.php could not be read
 */
?>
<div class="page">
    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Picture places</h1>
            <p class="page__subtitle">The picture each spot on the site's pages shows. A spot with none shows the site's own.</p>
        </div>
    </div>

    <?php if ($saved): ?>
        <div class="flashes">
            <div class="flash flash--success">Pictures saved.</div>
        </div>
    <?php endif; ?>

    <?php if ($errors !== [] || $placesError !== null): ?>
        <div class="alert alert--danger">
            <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__title"><?= count($errors) > 1 ? count($errors) . ' things need fixing' : 'One thing needs fixing' ?></p>
                <ul class="alert__text">
                    <?php foreach ($placesError !== null ? [$placesError] : $errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($places === []): ?>
        <div class="empty">
            <?= icon('image', 'empty__glyph', '', 28) ?>
            <p class="empty__title">No picture places</p>
            <p class="empty__text">The site lists its picture places in its places.php.</p>
        </div>
    <?php else: ?>
        <form method="post" action="<?= h(Url::admin('picture-places')) ?>">
            <?= Csrf::field() ?>

            <div class="form-main">
                <div class="form-card">
                    <div class="form-card__section">
                        <?php foreach ($places as $key => $place): ?>
                            <div class="field" data-picture-place="<?= h((string) $key) ?>">
                                <span class="label"><?= h((string) $place['name']) ?></span>
                                <?= FieldRenderer::renderImage('picture[' . $key . ']', $chosen[(string) $key] ?? 0) ?>
                                <?php if (!empty($place['url'])): ?>
                                    <p class="form-help">On <a class="link" href="<?= h((string) $place['url']) ?>" target="_blank" rel="noopener"><?= h((string) $place['url']) ?></a></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-card__section form-actions">
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn btn--primary">Save changes</button>
                    </div>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php // The media picker's dialog and behaviour, as on any entry form. ?>
<script src="<?= h(Asset::admin('js/entry-form.js')) ?>"></script>
