<?php

declare(strict_types=1);

use Cms\Helpers\Asset;

/**
 * A SITE'S OWN TOOL'S PAGE IN THE ADMIN'S FRAME (`Controllers\Admin\SiteToolController`):
 * its back link and title as every admin screen has them, the lines it left
 * for this page (`Tools::flash()`), then its own markup, with the scripts the
 * admin's fields use, so a media picker (`Tools::mediaPicker()`) and a
 * rich-text box (`<textarea class="textarea" data-editor>`) work in it.
 *
 * @var array{title: string, content: string, back: array{label: string, url: string}|null, subtitle: string} $page
 * @var list<array{kind: string, message: string}>                                                          $flashes
 */
?>
<div class="page" data-site-tool="<?= h((string) SITE_TOOL) ?>">
    <?php if ($page['back'] !== null): ?>
        <a class="page__back" href="<?= h($page['back']['url']) ?>">
            <?= icon('angle-left', 'icon icon--sm') ?><?= h($page['back']['label']) ?>
        </a>
    <?php endif; ?>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title"><?= h($page['title']) ?></h1>
            <?php if ($page['subtitle'] !== ''): ?>
                <p class="page__subtitle"><?= h($page['subtitle']) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flashes !== []): ?>
        <div class="flashes">
            <?php foreach ($flashes as $flash): ?>
                <div class="flash flash--<?= h($flash['kind']) ?>"><?= h($flash['message']) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?= $page['content'] ?>
</div>
<script src="<?= h(Asset::admin('js/entry-form.js')) ?>"></script>
<?= \Cms\Ui\Editor::iconTemplate() ?>
<script src="<?= h(Asset::admin('js/prompt.js')) ?>" defer></script>
<script src="<?= h(Asset::admin('js/editor.js')) ?>" defer></script>
