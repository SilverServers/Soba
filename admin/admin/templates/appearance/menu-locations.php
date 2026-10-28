<?php

declare(strict_types=1);

use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * MANAGE LOCATIONS — the same table as the edit screen's tick boxes, read the
 * other way round.
 *
 * ── WHY BOTH, AND WHY THIS IS NOT TWO STORES ────────────────────────────────
 * When something cannot be reached, the question is which EXISTING mechanism
 * holds it, never what new store to add. This screen writes
 * `menu_locations` through `Menu::assignLocation()` and the edit screen writes
 * the same table through `Menu::setLocations()`. One table, two questions:
 *
 *   the edit screen  "where does THIS MENU go?"      — one menu, many locations
 *   this screen      "what goes in THIS LOCATION?"   — one location, one menu
 *
 * A theme with three locations and six menus is filled in far faster here, which
 * is the entire reason for both.
 *
 * ── A LOCATION HOLDS ONE MENU, AND THE CONTROL SAYS SO ──────────────────────
 * Hence a `<select>` per row rather than a checkbox: the unique index on
 * `location_slug` means picking a second menu for one location is not a state
 * that exists, and a control that offers an impossible state is a control that
 * lies.
 *
 * @var array<string, array{label: string}>  $locations    slug => [label]
 * @var array<string, int>                   $locationMap  slug => menu id
 * @var list<array<string, mixed>>           $menus
 * @var bool                                 $saved
 * @var array<string, mixed>                 $user
 */

$locations   = $locations   ?? [];
$locationMap = $locationMap ?? [];
$menus       = $menus       ?? [];
$saved       = $saved       ?? false;

$menusById = [];
foreach ($menus as $m) {
    $menusById[(int) $m['id']] = $m;
}

$pageTitle = 'Menus';

ob_start();
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Menus</h1>
        </div>

        <div class="page__header-actions">
            <a class="btn btn--primary" href="<?= h(Url::admin('appearance/menus/new')) ?>">Add menu</a>
        </div>
    </div>

    <div class="tabs">
        <a class="tabs__tab" href="<?= h(Url::admin('appearance/menus')) ?>">Edit menus</a>
        <a class="tabs__tab is-active" aria-current="page"
           href="<?= h(Url::admin('appearance/menus/locations')) ?>">Manage locations</a>
    </div>

    <?php if ($saved): ?>
        <div class="flashes">
            <div class="flash flash--success">Locations saved.</div>
        </div>
    <?php endif; ?>

    <?php if ($locations === []): ?>
        <?php // NOT AN ERROR. A build with no registered locations can still
              // hold menus; they simply have nowhere to appear yet. ?>
        <div class="alert">
            <?= icon('circle-exclamation', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__title">No menu locations are registered</p>
                <p class="alert__text">
                    A theme registers them with
                    <code>MenuLocationRegistry::register()</code> in
                    <code>config/menus.php</code>. Until one exists, a menu can be
                    built but has nowhere to appear on the site.
                </p>
            </div>
        </div>
    <?php elseif ($menus === []): ?>
        <div class="empty">
            <?= icon('bars', 'empty__glyph', '', 28) ?>
            <p class="empty__title">No menus to assign yet</p>
            <p class="empty__text">
                <?= count($locations) === 1
                    ? 'This theme has one place a menu can go. Build a menu and it can go there.'
                    : 'This theme has ' . count($locations) . ' places a menu can go. Build a menu and it can go in any of them.' ?>
            </p>
            <div class="empty__action">
                <a class="btn btn--primary" href="<?= h(Url::admin('appearance/menus/new')) ?>">Add menu</a>
            </div>
        </div>
    <?php else: ?>
        <form method="post" action="<?= h(Url::admin('appearance/menus/locations')) ?>">
            <?= Csrf::field() ?>

            <div class="form-card">
                <div class="form-card__section">
                    <?= panel_title('Where menus appear', 'location') ?>

                    <div class="list loc-list">
                        <?php foreach ($locations as $slug => $loc): ?>
                            <?php
                            $slug     = (string) $slug;
                            $assigned = $locationMap[$slug] ?? 0;
                            $fieldId  = 'loc-' . preg_replace('/[^a-z0-9]+/i', '-', $slug);
                            ?>
                            <div class="list__row">
                                <div class="loc-list__main">
                                    <label class="loc-list__name" for="<?= h($fieldId) ?>">
                                        <?= h((string) $loc['label']) ?>
                                    </label>
                                    <span class="loc-list__slug"><?= h($slug) ?></span>
                                </div>

                                <div class="loc-list__pick">
                                    <select class="select" id="<?= h($fieldId) ?>"
                                            name="locations[<?= h($slug) ?>]">
                                        <option value="0">Nothing here yet</option>
                                        <?php foreach ($menus as $m): ?>
                                            <option value="<?= (int) $m['id'] ?>"
                                                <?= $assigned === (int) $m['id'] ? ' selected' : '' ?>>
                                                <?= h((string) $m['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="list__row-actions">
                                    <?php // THE EDIT LINK IS ONLY THERE WHEN THERE IS SOMETHING
                                          // TO EDIT. A link to `/0/edit` redirects back to the
                                          // list, which reads as the screen having eaten the
                                          // click. ?>
                                    <?php if ($assigned > 0 && isset($menusById[$assigned])): ?>
                                        <a class="link link--xs"
                                           href="<?= h(Url::admin('appearance/menus/' . $assigned . '/edit')) ?>">Edit</a>
                                    <?php else: ?>
                                        <span class="loc-list__none">&mdash;</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-card__section form-actions">
                    <div class="form-actions__spacer"></div>
                    <button type="submit" class="btn btn--primary">Save locations</button>
                </div>
            </div>
        </form>
    <?php endif; ?>

</div>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
