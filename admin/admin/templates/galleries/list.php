<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * GALLERIES — the list.
 *
 * `Gallery::all()` carries `item_count` as a sub-select, so the row can say how
 * many images a gallery holds without a query per row. That count is the only
 * thing worth scanning for here: an empty gallery embedded in a page renders as
 * nothing, and this list is where you would notice.
 *
 * @var list<array<string, mixed>> $galleries  id, title, item_count, updated_at
 * @var array<string, mixed>       $user
 */

$pageTitle = 'Galleries';

ob_start();
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Galleries</h1>
        </div>

        <div class="page__header-actions">
            <?php if ($galleries !== []): ?>
                <a class="btn btn--primary" href="<?= h(Url::admin('galleries/new')) ?>">Add gallery</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($galleries === []): ?>
        <div class="empty">
            <?= icon('image', 'empty__glyph', '', 28) ?>
            <p class="empty__title">No galleries yet</p>
            <div class="empty__action">
                <a class="btn btn--primary" href="<?= h(Url::admin('galleries/new')) ?>">Add gallery</a>
            </div>
        </div>
    <?php else: ?>
        <div class="list entry-list user-list">
            <?php foreach ($galleries as $gallery): ?>
                <?php
                $gid   = (int) $gallery['id'];
                $count = (int) $gallery['item_count'];
                ?>
                <div class="list__row">
                    <div class="entry-list__main">
                        <a class="list__row-link list__row-title"
                           href="<?= h(Url::admin('galleries/' . $gid . '/edit')) ?>"><?= h((string) $gallery['title']) ?></a>
                        <div class="list__row-sub">
                            Updated <?= h(Dates::short((string) $gallery['updated_at'])) ?>
                        </div>
                        <?php // WHERE IT IS SHOWN: the
                              // page that draws it — the Forms list's line, for the
                              // same relationship. ?>
                        <?php if (($placesList ?? null) !== null && ($placeKey = $gallery['place_key'] ?? null) !== null): ?>
                            <?php $place = $placesList[$placeKey] ?? null; ?>
                            <div class="list__row-sub entry-list__parent">
                                <?php if ($place === null): ?>
                                    <?= icon('triangle-exclamation', 'icon icon--sm') ?>Shown on: “<?= h((string) $placeKey) ?>”, no longer on the list
                                <?php else: ?>
                                    <?= icon('up-right-from-square', 'icon icon--sm') ?>Shown on:
                                    <?php if (!empty($place['url'])): ?>
                                        <a href="<?= h((string) $place['url']) ?>" target="_blank" rel="noopener"><?= h((string) $place['name']) ?></a>
                                    <?php else: ?>
                                        <?= h((string) $place['name']) ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="list__row-meta">
                        <?php // AN EMPTY GALLERY IS WORTH SAYING OUT LOUD. It
                              // embeds as nothing at all on the public page, and
                              // "0 images" in the same grey as every other count
                              // is easy to read past. ?>
                        <?php if ($count === 0): ?>
                            <span>No images</span>
                        <?php else: ?>
                            <span><?= $count ?> image<?= $count === 1 ? '' : 's' ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="list__row-actions">
                        <form method="post"
                              action="<?= h(Url::admin('galleries/' . $gid . '/delete')) ?>"
                              data-confirm="Delete the <?= h((string) $gallery['title']) ?> gallery?"
                              data-confirm-detail="The gallery is removed. The images themselves stay in Files and media."
                              data-confirm-verb="Delete gallery">
                            <?= Csrf::field() ?>
                            <button type="submit" class="icon-btn"
                                    data-tip="Delete gallery" aria-label="Delete gallery">
                                <?= icon('trash-can', 'icon') ?>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
