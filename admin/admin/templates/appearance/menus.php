<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * MENUS — the list.
 *
 * ── THE LOCATION IS THE ROW'S FACT, AND THERE MAY BE SEVERAL ────────────────
 * A menu that is not assigned to a location renders nowhere:
 * `Menu::findByLocation()` is how the theme asks for one. So the row says which
 * locations a menu occupies, and says plainly when it occupies none — that is
 * the difference between "built and live" and "built and invisible", and it is
 * the single thing this screen exists to answer.
 *
 * LOCATIONS, PLURAL, since migration 049: the same menu can be the header and
 * the footer. The row lists every one of them, because a row that named only the
 * first would be the screen quietly under-reporting where a menu appears.
 *
 * ── LOCATIONS ARE REGISTERED IN CODE, NOT CREATED HERE ──────────────────────
 * `MenuLocationRegistry::register()` is called from `config/menus.php`, so the
 * set of locations is a developer's decision and this screen only assigns to
 * them. When the registry is empty there is nothing to assign to, and the screen
 * says so rather than showing an empty dropdown on every row.
 *
 * @var list<array<string, mixed>>            $menus      id, name, locations[]
 * @var array<string, array{label: string}>   $locations  slug => [label]
 * @var array<string, mixed>                  $user
 */

$pageTitle = 'Menus';

ob_start();
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Menus</h1>
        </div>

        <div class="page__header-actions">
            <?php if ($menus !== []): ?>
                <a class="btn btn--primary" href="<?= h(Url::admin('appearance/menus/new')) ?>">Add menu</a>
            <?php endif; ?>
        </div>
    </div>

    <?php // ── THE TWO WAYS ROUND THE SAME TABLE ───────────────────────────
          // "Where does this menu go?" is the edit screen; "what goes in this
          // location?" is the other tab. Both write `menu_locations`. ?>
    <div class="tabs">
        <a class="tabs__tab is-active" aria-current="page"
           href="<?= h(Url::admin('appearance/menus')) ?>">Edit menus</a>
        <a class="tabs__tab" href="<?= h(Url::admin('appearance/menus/locations')) ?>">Manage locations</a>
    </div>

    <?php if ($locations === []): ?>
        <?php // NOT AN ERROR, AND NOT HIDDEN. A build with no registered
              // locations can still create menus; they simply have nowhere to
              // appear yet. Saying so here beats a location dropdown that is
              // empty on every row with no explanation. ?>
        <div class="alert">
            <?= icon('circle-exclamation', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__title">No menu locations are registered</p>
                <p class="alert__text">
                    A theme registers them with
                    <code>MenuLocationRegistry::register()</code> in
                    <code>config/menus.php</code>. Until one exists, a menu can be
                    built here but has nowhere to appear on the site.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($menus === []): ?>
        <div class="empty">
            <?= icon('bars', 'empty__glyph', '', 28) ?>
            <p class="empty__title">No menus yet</p>
            <div class="empty__action">
                <a class="btn btn--primary" href="<?= h(Url::admin('appearance/menus/new')) ?>">Add menu</a>
            </div>
        </div>
    <?php else: ?>
        <div class="list entry-list user-list">
            <?php foreach ($menus as $menu): ?>
                <?php
                $mid   = (int) $menu['id'];
                $slugs = is_array($menu['locations'] ?? null) ? $menu['locations'] : [];

                // NAMED, NOT COUNTED. "2 locations" makes the owner open the menu
                // to find out which two; the labels are what they came here for.
                $known   = [];
                $unknown = 0;
                foreach ($slugs as $slug) {
                    if (isset($locations[(string) $slug])) {
                        $known[] = (string) $locations[(string) $slug]['label'];
                    } else {
                        // ASSIGNED TO A LOCATION THAT NO LONGER EXISTS. The row is
                        // still in `menu_locations`, so the menu is not unassigned
                        // — it points at a registration that has been removed from
                        // config, and the theme will never ask for it.
                        $unknown++;
                    }
                }
                ?>
                <div class="list__row">
                    <div class="entry-list__main">
                        <a class="list__row-link list__row-title"
                           href="<?= h(Url::admin('appearance/menus/' . $mid . '/edit')) ?>"><?= h((string) $menu['name']) ?></a>
                    </div>

                    <div class="list__row-meta">
                        <?php if ($known !== []): ?>
                            <span><?= h(implode(', ', $known)) ?></span>
                        <?php endif; ?>
                        <?php if ($unknown > 0): ?>
                            <span class="list__row-sub">
                                <?= $unknown === 1
                                    ? 'Unknown location'
                                    : $unknown . ' unknown locations' ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($known === [] && $unknown === 0): ?>
                            <span class="list__row-sub">Not assigned</span>
                        <?php endif; ?>
                    </div>

                    <div class="list__row-actions">
                        <form method="post"
                              action="<?= h(Url::admin('appearance/menus/' . $mid . '/delete')) ?>"
                              data-confirm="Delete the <?= h((string) $menu['name']) ?> menu?"
                              data-confirm-detail="Its items go with it. The pages and entries it linked to are untouched."
                              data-confirm-verb="Delete menu">
                            <?= Csrf::field() ?>
                            <button type="submit" class="icon-btn"
                                    data-tip="Delete menu" aria-label="Delete menu">
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
