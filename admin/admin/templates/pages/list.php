<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Dates;
use Cms\Security\Csrf;
use Cms\Helpers\Url;
use Cms\Models\Page;
use Cms\Ui\Status;

/**
 * PAGES — the entry list's twin, for the one content kind that is NOT a
 * Content Type.
 *
 * Pages are hierarchical and standalone: they are not in the Registry, they
 * have no archetypes and no custom fields, and they own their SEO columns
 * outright rather than through post_meta. Everything else about them is an
 * entry, so this screen is `content-type/list.php`'s vocabulary with one
 * addition and one removal.
 *
 *   ADDED    the nesting. A child page is indented under its parent, because
 *            two pages called "About" are different pages when one is a child
 *            of Services.
 *   REMOVED  nothing — the filter bar, the status chip, the trash count and
 *            the row grid are all the same.
 *
 * ── THE NESTING IS SHOWN, NOT STATED ────────────────────────────────────────
 * `Page::all()` orders by `menu_order` then title, so the tree arrives already
 * flattened. Rather than printing "Parent: Services" on the row, the child is
 * INDENTED — the same idea as `.sb__item--child` in the sidebar, where the
 * indent is what says whose it is.
 *
 * One level of indent only. `parent_id` permits any depth; a grandchild has no
 * parent label above it to sit under, so the indent would stop meaning
 * anything. A deeper page still appears, indented once, rather than vanishing.
 *
 * AND THE NESTING IS NOT IN THE URL. The public route is `/{slug}` and
 * `Page::findBySlug()` takes one slug, so every page — child or not — lives at
 * `/its-own-slug`. `parent_id` orders the menu and nothing else. The sub-line
 * therefore shows the real address, not a path built out of the parent's TITLE
 * such as `/About/our-team`, which would be both wrong-cased and a 404.
 *
 * @var list<array<string, mixed>> $pages  from Page::all(), trash excluded
 * @var array<string, mixed>       $user
 */

$pageTitle    = 'Pages';
$trashedCount = Page::countTrashed();

ob_start();
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Pages</h1>
        </div>

        <div class="page__header-actions">
            <?php if ($trashedCount > 0): ?>
                <a class="btn" href="<?= h(Url::admin('pages/trash')) ?>">
                    Trash
                    <span class="card__count"><?= (int) $trashedCount ?></span>
                </a>
            <?php endif; ?>

            <?php if ($pages !== []): ?>
                <a class="btn btn--primary" href="<?= h(Url::admin('pages/new')) ?>">Add page</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($pages === []): ?>
        <div class="empty">
            <?= icon('file', 'empty__glyph', '', 28) ?>
            <p class="empty__title">No pages yet</p>
            <div class="empty__action">
                <a class="btn btn--primary" href="<?= h(Url::admin('pages/new')) ?>">Add page</a>
            </div>
        </div>
    <?php else: ?>
        <?php // The INSTANT idiom, identical to the entry list's: unlabeled
              // controls, no Apply, and deliberately not a <form>. Honest for
              // the same reason and with the same expiry date — `Page::all()`
              // has no pagination, so every row is already in the document. ?>
        <div class="filterbar" data-list-filter="#page-list">
            <div class="filterbar__group">
                <input class="input input--search" type="search"
                       placeholder="Filter pages&hellip;"
                       aria-label="Filter pages by title or slug"
                       data-filter-text>

                <select class="select" aria-label="Filter by status" data-filter-attr="data-status">
                    <option value="">Any status</option>
                    <?php // What can be stored, and Ended: a page has no publish
                          // date, so never Scheduled, but its unpublish date can
                          // have come (Status::forEntry()). ?>
                    <?php foreach (array_diff_key(Status::filterLabels(), ['scheduled' => 1]) as $key => $statusLabel): ?>
                        <?php if ($key === 'trash') { continue; } ?>
                        <option value="<?= h($key) ?>"><?= h($statusLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filterbar__actions">
                <span class="filterbar__label" data-filter-count hidden></span>
                <a class="filterbar__reset" href="#" data-filter-reset hidden>Clear</a>
            </div>
        </div>

        <div class="list entry-list entry-list--copy page-list" id="page-list">
            <?php foreach ($pages as $page): ?>
                <?php
                $isChild = !empty($page['parent_id']);

                // THE SUB-LINE IS THE REAL URL, and every page's real URL is
                // flat: the public route is `/{slug}` (www/index.php) and
                // `Page::findBySlug()` takes ONE slug, so a child page lives at
                // `/our-team`, not `/about/our-team`.
                //
                // The parent's TITLE printed into a fake path — `/About/our-team`
                // — would be wrong twice: wrong case, and an address that 404s if
                // anyone types it. `parent_id` orders the menu; it does not nest
                // the URL. The INDENT is what carries the parent, and it is the
                // only thing that should.
                // NOT `=== 'published'`. The person reading this list is signed
                // in, and every status but `trash` opens for them — see the note
                // in pages/edit.php. Restricting it to Published would put a view
                // control on some rows and not others for no reason the owner
                // could see.
                $viewUrl = (string) $page['status'] !== 'trash'
                    ? Url::page((string) $page['slug'])
                    : null;

                $stamp = $page['status'] === 'published' && !empty($page['published_at'])
                    ? (string) $page['published_at']
                    : (string) $page['updated_at'];
                ?>
                <?php // `data-text` is the page's IDENTITY only — title and
                      // slug. Matching the row's rendered text instead would
                      // quietly make "published" a search term hitting every
                      // published row, and "admin" one hitting everything. ?>
                <div class="list__row<?= $isChild ? ' page-list__row--child' : '' ?>"
                     data-status="<?= h(Status::forEntry($page)) ?>"
                     data-text="<?= h($page['title'] . ' ' . $page['slug']) ?>">
                    <div class="entry-list__main">
                        <a class="list__row-link list__row-title"
                           href="<?= h(Url::admin('pages/' . (int) $page['id'] . '/edit')) ?>"><?= h((string) $page['title']) ?></a>
                        <div class="list__row-sub">/<?= h((string) $page['slug']) ?></div>
                    </div>

                    <?= Status::render(Status::forEntry($page)) ?>

                    <div class="list__row-meta">
                        <span class="entry-list__author"><?= h((string) $page['author_name']) ?></span>
                        <time datetime="<?= h(date('c', strtotime($stamp))) ?>"><?= h(Dates::short($stamp)) ?></time>
                    </div>

                    <?php // Same rail as the entry list, and for the same
                          // reason: editing a page and trashing one are both
                          // visible on its row, not only "View on site". Edit
                          // is the primary verb; Trash is the low-emphasis
                          // destructive with no colour on the trigger. ?>
                    <div class="list__row-actions">
                        <?php // ALWAYS RENDERED, empty when there is nothing to
                              // link to — it holds the rail's first grid cell so
                              // Edit and Trash keep the same left edge on a draft
                              // as on a published row. ?>
                        <span class="entry-list__view">
                        <?php if ($viewUrl !== null): ?>
                            <a class="icon-btn" href="<?= h($viewUrl) ?>" target="_blank" rel="noopener"
                               data-tip="View on site" aria-label="View on site">
                                <?= icon('up-right-from-square', 'icon') ?>
                            </a>
                        <?php endif; ?>
                        </span>
                        <a class="btn btn--sm" href="<?= h(Url::admin('pages/' . (int) $page['id'] . '/edit')) ?>">Edit</a>
                        <?php // A PAGE COPIED to start the next one, Private. ?>
                        <form class="form-inline" method="post" action="<?= h(Url::admin('pages/' . (int) $page['id'] . '/copy')) ?>">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link link--xs">Copy</button>
                        </form>
                        <form class="form-inline" method="post"
                              action="<?= h(Url::admin('pages/' . (int) $page['id'] . '/trash')) ?>"
                              data-confirm="Move &ldquo;<?= h((string) $page['title']) ?>&rdquo; to the trash?"
                              data-confirm-detail="It stops appearing on the site straight away. You can restore it from the trash."
                              data-confirm-verb="Move to trash">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link link--xs">Trash</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="empty" data-filter-empty hidden>
            <?= icon('magnifying-glass', 'empty__glyph', '', 28) ?>
            <p class="empty__title">No pages match that filter</p>
        </div>
    <?php endif; ?>

</div>

<?php if ($pages !== []): ?>
    <script src="<?= h(Asset::admin('js/list-filter.js')) ?>"></script>
<?php endif; ?>
<?php
?>
<?php // confirm.js owns the dialog; without it `data-confirm` is inert and
      // the trash form posts on the first click with no question asked. ?>
<script src="<?= h(\Cms\Helpers\Asset::admin('js/confirm.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
