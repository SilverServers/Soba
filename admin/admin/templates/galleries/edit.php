<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Models\Media;
use Cms\Security\Csrf;

/**
 * THE GALLERY EDITOR — create and edit both.
 *
 * ── THE IMAGE SET POSTS AS ONE ORDERED LIST OF IDs ──────────────────────────
 * `handleUpdate()` reads `$_POST['image_ids']`, runs it through
 * `parseImageIds()`, and hands the result to `Gallery::setItems()`, which
 * DELETES every existing row and re-inserts with `position` taken from array
 * order. So the hidden input is not a diff — it is the whole gallery, in order,
 * every time. `gallery-images.js` keeps it in step with the tiles on screen.
 *
 * That also means ORDER IS THE POINT: dragging a tile changes `position`, which
 * is what `Gallery::getItems()` sorts by and what the public shortcode renders.
 *
 * ── THE SETTINGS ALL REACH THE OUTPUT, CHECKED ──────────────────────────────
 * `Shortcode::renderGallery()` reads all three: `layout` becomes
 * `cms-gallery--{layout}`, `columns` becomes an inline
 * `--gallery-columns` custom property, and `lightbox` wraps each image in an
 * `<a data-lightbox>`. None of these is a dead option — every one changes the
 * markup the theme receives.
 *
 * AND THEY ALSO REACH A STYLESHEET. `admin/templates/base.php` links
 * `www/assets/css/theme.css`, which consumes all three: Grid crops to a
 * uniform 4:3 tile so the rows line up, Masonry keeps each image's own aspect
 * ratio, and `--gallery-columns` drives the column count in both (clamped to 2
 * at 720px and 1 at 560px — the setting is the ceiling, not the override).
 *
 * The hints below say what each control CHANGES rather than promising a look,
 * because a site may replace the bundled theme.
 *
 * @var array<string, mixed>|null  $gallery   null on create
 * @var list<array<string, mixed>> $items     from Gallery::getItems(), ordered
 * @var array<string, mixed>       $formData  $_POST on a failed submit
 * @var list<string>               $errors
 * @var bool                       $saved
 * @var array<string, mixed>       $user
 */

$isNew    = ($gallery ?? null) === null;
$items    = $items    ?? [];
$formData = $formData ?? [];
$errors   = $errors   ?? [];
$saved    = $saved    ?? false;

// WHERE IT IS SHOWN: the site's gallery places,
// which gallery holds each, and the gallery a save just took the place from.
$placesList = $placesList ?? null;
$holders    = $holders    ?? [];
$movedFrom  = $movedFrom  ?? null;
$placeNow   = isset($formData['place_key']) && is_string($formData['place_key'])
    ? ($formData['place_key'] !== '' ? $formData['place_key'] : null)
    : ($gallery['place_key'] ?? null);

$id     = $isNew ? 0 : (int) $gallery['id'];
$action = $isNew ? Url::admin('galleries/new') : Url::admin('galleries/' . $id . '/edit');

// `Gallery::find()` decodes `settings` for you — it arrives as an ARRAY.
// Decoding it again and casting to string would render the literal word
// "Array" into a hidden input.
$settings = is_array($gallery['settings'] ?? null) ? $gallery['settings'] : [];

$setting = static function (string $key, string $fallback = '') use ($settings, $formData): string {
    if (isset($formData[$key]) && !is_array($formData[$key])) {
        return (string) $formData[$key];
    }
    return (string) ($settings[$key] ?? $fallback);
};

$lightboxOn = isset($formData['lightbox'])
    ? !empty($formData['lightbox'])
    : !empty($settings['lightbox']);

$title = isset($formData['title']) && !is_array($formData['title'])
    ? (string) $formData['title']
    : (string) ($gallery['title'] ?? '');

$description = isset($formData['description']) && !is_array($formData['description'])
    ? (string) $formData['description']
    : (string) ($gallery['description'] ?? '');

// The current order, as the hidden input's value.
$imageIds = isset($formData['image_ids']) && is_string($formData['image_ids'])
    ? $formData['image_ids']
    : implode(',', array_map(static fn (array $i): string => (string) $i['attachment_id'], $items));

$pageTitle = $isNew ? 'Add gallery' : 'Edit ' . (string) $gallery['title'];

ob_start();
?>
<div class="page">

    <a class="page__back" href="<?= h(Url::admin('galleries')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Galleries
    </a>

    <?php if ($saved): ?>
        <div class="flashes">
            <div class="flash flash--success">Gallery saved.</div>
            <?php if ($movedFrom !== null && ($place = \Cms\Helpers\Places::find($gallery['place_key'] ?? null)) !== null): ?>
                <div class="flash flash--info"><?= h($place['name']) ?> showed “<?= h((string) $movedFrom['title']) ?>”; it now shows this gallery.</div>
            <?php endif; ?>
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

    <form method="post" action="<?= h($action) ?>" data-gallery>
        <?= Csrf::field() ?>

        <?php // THE WHOLE GALLERY, IN ORDER, EVERY SAVE. `Gallery::setItems()`
              // truncates and re-inserts, so a missing id here is a deleted
              // image and the order here IS `position`. ?>
        <input type="hidden" name="image_ids" value="<?= h($imageIds) ?>" data-gallery-ids>

        <div class="panel__grid">

            <div class="form-main">

                <div class="form-card">
                    <div class="form-card__section">
                        <div class="field">
                            <label class="label" for="f-title">
                                Gallery title<span class="label__req">required</span>
                            </label>
                            <input class="input-title" type="text" id="f-title" name="title" required
                                   value="<?= h($title) ?>" placeholder="Spring show"
                                   autocomplete="off">
                        </div>

                        <div class="field">
                            <label class="label" for="f-description">Description</label>
                            <textarea class="textarea" id="f-description" name="description"
                                      rows="2"><?= h($description) ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="form-card">
                    <div class="form-card__section">
                        <div class="panel-head">
                            <?= panel_title('Images', 'images') ?>
                            <button type="button" class="btn btn--sm" data-gallery-add>Add images</button>
                        </div>

                        <?php // ── THE TILE AREA IS THE GALLERY ───────────────
                              // Not `.media-grid--library`, the media library's
                              // fixed 160px track: on that, the two controls in
                              // the aside, Layout and Columns, would be
                              // previewed nowhere — Columns set to 3 would
                              // render FOUR across in this editor and three on
                              // the page.
                              //
                              // Same classes as the public render, same inline
                              // `--gallery-columns`, same stylesheet — the tiles
                              // are arranged by the rules that will arrange them
                              // on the site. `gallery-images.js` repaints both
                              // the class and the property when either select
                              // changes, so the preview follows the choice
                              // rather than the last save. ?>
                        <div class="cms-gallery cms-gallery--<?= h($setting('layout', 'grid')) ?>"
                             style="--gallery-columns: <?= (int) ($setting('columns', '3') ?: 3) ?>"
                             data-gallery-tiles>
                            <?php // THE TILES ARE `Ui\GalleryTiles`'s, a gallery field's
                                  // too: the picture as the public grid draws it, its
                                  // chrome over it, the words under it as the page
                                  // prints them, and its details in hidden inputs keyed
                                  // by the attachment (GalleryController::parseItems()). ?>
                            <?php foreach ($items as $item): ?>
                                <?= \Cms\Ui\GalleryTiles::tile($item, 'item_{detail}[{id}]', ['title', 'caption', 'link', 'thumb']) ?>
                            <?php endforeach; ?>
                        </div>

                        <div class="empty" data-gallery-empty<?= $items === [] ? '' : ' hidden' ?>>
                            <?= icon('image', 'empty__glyph', '', 24) ?>
                            <p class="empty__title">No images yet</p>
                        </div>

                        <?php // FILES DROPPED ON THE TILES go one at a time; this
                              // says which and how far (gallery-images.js). The
                              // picker uploads too, many at once. ?>
                        <div data-gallery-progress hidden>
                            <p class="form-help" data-gallery-progress-text></p>
                            <div class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="Upload">
                                <div class="progress-bar__fill" data-gallery-progress-fill></div>
                            </div>
                        </div>

                        <?php // NO "DRAG A TILE TO CHANGE THE ORDER" LINE. Every
                              // tile carries a grip, and a sentence explaining a
                              // control that is visible on the control is
                              // telling where the design language shows. ?>

                        <template data-gallery-template>
                            <?= \Cms\Ui\GalleryTiles::template('item_{detail}[{id}]', ['title', 'caption', 'link', 'thumb']) ?>
                        </template>

                        <?php // ── AN ITEM'S DETAILS (migrations 091, 127) ───────
                              // Opened by a tile's pen; Done writes the tile's own
                              // inputs, so nothing is saved until the gallery is.
                              // The thumbnail is the media-picker component's own
                              // shape (FieldRenderer::renderMediaPicker()), chosen
                              // with the one picker (`CmsMedia.pick()`).
                              // Its inputs carry no name: they never post.
                              //
                              // NO maxlength ON THE TITLE OR THE CAPTION. The
                              // browser cuts a longer paste to fit without a
                              // word; Done says how long each may be instead,
                              // and keeps the dialog open to shorten it. ?>
                        <dialog class="modal" data-gallery-details aria-labelledby="gdetails-title">
                            <div class="modal__inner">
                                <h2 class="modal__title" id="gdetails-title">Title, caption, link and thumbnail</h2>
                                <div class="alert alert--danger" data-gd-error hidden></div>
                                <div class="field">
                                    <label class="label" for="gd-title">Title</label>
                                    <input class="input" type="text" id="gd-title" data-gd-title
                                           data-most="<?= \Cms\Models\Gallery::TITLE_MAX ?>">
                                </div>
                                <div class="field">
                                    <label class="label" for="gd-caption">Caption</label>
                                    <textarea class="textarea" id="gd-caption" rows="5" data-gd-caption
                                              data-most="<?= \Cms\Models\Gallery::CAPTION_MAX ?>"></textarea>
                                </div>
                                <div class="field">
                                    <label class="label" for="gd-link">Link</label>
                                    <input class="input input--mono" type="text" id="gd-link" maxlength="500"
                                           spellcheck="false" placeholder="/portfolio/a-project" data-gd-link>
                                    <p class="form-help">A page on this site, starting with /, or a whole address. With “Open images full size on click” ticked, the picture opens in full and its title and caption carry the link — so give it one or the other.</p>
                                </div>
                                <div class="field">
                                    <span class="label">Thumbnail</span>
                                    <div class="media-picker">
                                        <div class="media-picker__preview" data-gd-thumb>
                                            <?= icon('image', 'media-picker__glyph', 'data-gd-glyph', 22) ?>
                                        </div>
                                        <div class="media-picker__actions">
                                            <button type="button" class="btn" data-gd-choose>Choose</button>
                                            <button type="button" class="btn btn--ghost" data-gd-clear hidden>Remove</button>
                                        </div>
                                    </div>
                                    <p class="form-help">What the grid shows instead of the picture. The picture itself still opens in full.</p>
                                </div>
                                <div class="modal__actions">
                                    <button type="button" class="btn" data-gd-cancel>Cancel</button>
                                    <button type="button" class="btn btn--primary" data-gd-ok>Done</button>
                                </div>
                            </div>
                        </dialog>
                    </div>
                </div>

                <div class="form-card">
                    <div class="form-card__section form-actions">
                        <a class="link link--xs" href="<?= h(Url::admin('galleries')) ?>">Cancel</a>
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn btn--primary">
                            <?= $isNew ? 'Create gallery' : 'Save changes' ?>
                        </button>
                    </div>
                </div>

            </div>

            <div class="form-aside">
                <div class="form-card">

                    <div class="form-card__section">
                        <?= panel_title('Display', 'display') ?>

                        <div class="field">
                            <label class="label" for="f-layout">Layout</label>
                            <select class="select" id="f-layout" name="layout">
                                <?php
                                // THE VALUE BECOMES A CLASS on the rendered
                                // gallery — `cms-gallery--{layout}`. Two
                                // options, and the bundled theme styles both:
                                // Grid crops to a uniform tile, Masonry keeps
                                // each image's own aspect ratio. A third would
                                // be a class name with nothing behind it.
                                $layouts = ['grid' => 'Grid', 'masonry' => 'Masonry'];
                                $current = $setting('layout', 'grid');
                                foreach ($layouts as $value => $label): ?>
                                    <option value="<?= h($value) ?>"<?= $current === $value ? ' selected' : '' ?>>
                                        <?= h($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label class="label" for="f-columns">Columns</label>
                            <select class="select" id="f-columns" name="columns">
                                <?php $cols = (int) ($setting('columns', '3') ?: 3);
                                foreach ([2, 3, 4, 5, 6] as $n): ?>
                                    <option value="<?= $n ?>"<?= $cols === $n ? ' selected' : '' ?>><?= $n ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label class="check-list__row">
                                <input type="checkbox" name="lightbox" value="1"
                                       <?= $lightboxOn ? 'checked' : '' ?>>
                                <span>Open images full size on click</span>
                            </label>
                            <p class="form-help">
                                Wraps each image in a link to the full-size file.
                                Off, an image with a link of its own goes there.
                            </p>
                        </div>
                    </div>

                    <?php if ($placesList !== null): ?>
                        <?php // WHICH PAGE SHOWS IT.
                              // A page of the site's own draws the gallery placed
                              // on it, in its own design. A page shows one
                              // gallery: choosing one that shows another moves it
                              // here. The Forms screen's control, for this kind. ?>
                        <div class="form-card__section">
                            <?= panel_title('On the site', 'embedding') ?>
                            <div class="field">
                                <label class="label" for="f-place">Shown on</label>
                                <select class="select" id="f-place" name="place_key">
                                    <option value="">No page of its own</option>
                                    <?php foreach ($placesList as $key => $place): ?>
                                        <?php
                                        $holder = $holders[$key] ?? null;
                                        $other  = $holder !== null && (int) $holder['id'] !== $id;
                                        ?>
                                        <option value="<?= h((string) $key) ?>"<?= $placeNow === (string) $key ? ' selected' : '' ?>><?= h((string) $place['name']) ?><?= $other ? ' — now showing “' . h((string) $holder['title']) . '”' : '' ?></option>
                                    <?php endforeach; ?>
                                    <?php if ($placeNow !== null && !isset($placesList[$placeNow])): ?>
                                        <option value="<?= h($placeNow) ?>" selected>“<?= h($placeNow) ?>” (no longer on the list)</option>
                                    <?php endif; ?>
                                </select>
                                <p class="form-help">That page shows this gallery in the site's own design. A page shows one gallery: choosing one that shows another moves it here.</p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!$isNew): ?>
                        <div class="form-card__section">
                            <?= panel_title('Embedding', 'embedding') ?>
                            <div class="kv-list">
                                <div class="kv-list__row">
                                    <span class="kv-list__val"><code>[gallery id="<?= $id ?>"]</code></span>
                                </div>
                            </div>
                            <p class="form-help">Paste that into any page or entry's content.</p>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

        </div>
    </form>

</div>

<?php // THE PUBLIC GALLERY'S OWN STYLESHEET, in the admin, on purpose. It is
      // the one file that lays a gallery out, and this screen renders a gallery.
      // `Asset::site()` because it lives under www/assets — one file on disk,
      // no mirror to keep in step, and no way for the preview to drift from the
      // page. It is tokens-only, so nothing in it touches the admin's chrome. ?>
<link rel="stylesheet" href="<?= h(Asset::site('assets/css/gallery.css')) ?>">
<?php // reorder.js BEFORE the gallery script, which calls Reorder.create() on
      // the tiles as soon as it runs; entry-form.js for the one picker
      // (`CmsMedia`), which the gallery adds pictures and thumbnails with. ?>
<script src="<?= h(Asset::admin('js/reorder.js')) ?>"></script>
<script src="<?= h(Asset::admin('js/entry-form.js')) ?>"></script>
<script src="<?= h(Asset::admin('js/gallery-images.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
