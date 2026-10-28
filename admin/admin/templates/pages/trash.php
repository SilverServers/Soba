<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * THE PAGES TRASH — `content-type/trash.php` for pages.
 *
 * Same two verbs, same reasoning: Restore is ordinary and reversible so it is a
 * plain `.btn`; Delete is the one irreversible act on the screen, so it goes
 * through the confirm dialog, which IS the alert surface. Both are POSTs,
 * because both are writes.
 *
 * One difference from the entry trash: a trashed page may still be some other
 * page's parent. The row says so, because "restore this and the tree comes
 * back" and "restore this and it lands at the top level" are different
 * outcomes and the admin cannot tell which without being told.
 *
 * @var list<array<string, mixed>> $pages  from Page::allTrashed()
 * @var array<string, mixed>       $user
 */

$pageTitle = 'Pages trash';

ob_start();
?>
<div class="page">

    <?php // ABOVE `.page__header`, not inside `.page__header-main` — that element
          // is a flex ROW, so the link rendered beside the title instead of over
          // it. See core.css §1. ?>
    <a class="page__back" href="<?= h(Url::admin('pages')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Pages
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Trash</h1>
        </div>
    </div>

    <?php if ($pages === []): ?>
        <div class="empty">
            <?= icon('trash-can', 'empty__glyph', '', 28) ?>
            <p class="empty__title">Nothing in the trash</p>
        </div>
    <?php else: ?>
        <div class="list entry-list entry-list--trash">
            <?php foreach ($pages as $page): ?>
                <?php
                // The REAL url — flat. The public route is `/{slug}`, so a
                // child page lives at `/our-team` and never at
                // `/about/our-team`. Its parent is a menu fact, not a URL one,
                // and the confirm dialog below is where it is stated.
                ?>
                <div class="list__row">
                    <div class="entry-list__main">
                        <span class="list__row-title"><?= h((string) $page['title']) ?></span>
                        <div class="list__row-sub">/<?= h((string) $page['slug']) ?></div>
                    </div>

                    <div class="list__row-meta">
                        <span class="entry-list__author"><?= h((string) $page['author_name']) ?></span>
                        <time datetime="<?= h(date('c', strtotime((string) $page['updated_at']))) ?>">
                            <?= h(Dates::short((string) $page['updated_at'])) ?>
                        </time>
                    </div>

                    <div class="list__row-actions">
                        <form class="form-inline" method="post"
                              action="<?= h(Url::admin('pages/' . (int) $page['id'] . '/restore')) ?>">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--sm">Restore</button>
                        </form>

                        <form class="form-inline" method="post"
                              action="<?= h(Url::admin('pages/' . (int) $page['id'] . '/delete')) ?>"
                              data-confirm="Permanently delete &ldquo;<?= h((string) $page['title']) ?>&rdquo;?"
                              data-confirm-detail="Any page nested under it moves to the top level. This one cannot be undone."
                              data-confirm-verb="Delete for good">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link link--xs">Delete</button>
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
