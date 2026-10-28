<?php

declare(strict_types=1);

use Cms\Ui\Icons;
use Cms\Ui\MenuItemSettings;
use Cms\Ui\MenuItemStyles;

/**
 * THE MENU ITEM DIALOG — the display settings of one menu item.
 *
 * A button on every item row opens a tabbed dialog of everything that item can
 * be told.
 *
 * ── ONE DIALOG, NOT ONE PER ROW ────────────────────────────────────────────
 * A menu with forty items would otherwise put forty copies of nine tabs in the
 * document. `menu-builder.js` fills this one from whichever row was clicked and
 * writes the answers back to that row — the same shape the media library's
 * detail panel already uses.
 *
 * ── AND THE CONTROLS COME FROM `MenuItemSettings::tabs()` ──────────────────
 * Not written out here. The sanitiser, this dialog and the renderer read one
 * definition, so a control cannot exist for a key that will be thrown away on
 * save.
 *
 * ── THERE ARE NO TIERS ─────────────────────────────────────────────────────
 * No upsell panels and no greyed-out gated controls: if a control is drawn, it
 * works. So Badge sits beside Settings with nothing marking it apart.
 */

/**
 * ONE CONTROL. A closure because there are two dozen of them across the tabs and
 * a hand-written copy per field is how the fifth one drifts from the other four.
 *
 * @param array<string, mixed> $field
 */
$miField = static function (array $field): void {
    $key   = (string) $field['key'];
    $type  = (string) ($field['type'] ?? 'check');
    $label = (string) ($field['label'] ?? $key);
    $help  = (string) ($field['help'] ?? '');

    // WHERE THIS CONTROL MEANS ANYTHING. `top` is a top-level item, `nested` is
    // an item under another. A control that cannot apply is removed, not
    // greyed out with an explanation, because a control that does nothing
    // where it is drawn is a fault. The row says why in its place.
    $depth = (string) ($field['depth'] ?? '');

    // A CONTROL THAT ONLY MATTERS ONCE ANOTHER IS SET. `*` means "any non-empty
    // value" — the icon position is meaningless until there is an icon.
    $when  = $field['showWhen'] ?? null;

    $attrs = ' data-mi="' . h($key) . '"';
    if ($depth !== '') {
        $attrs .= ' data-mi-depth="' . h($depth) . '"';
    }
    if (is_array($when)) {
        $attrs .= ' data-mi-when="' . h((string) json_encode($when)) . '"';
    }
    // A CONTROL THAT ONLY MEANS SOMETHING IN CERTAIN COMPANY. `mega-parent`:
    // a width in the parent's panel is meaningless when the parent's sub-menu
    // is a dropdown list, so it is not drawn there.
    if (!empty($field['needs'])) {
        $attrs .= ' data-mi-needs="' . h((string) $field['needs']) . '"';
    }
    if (($field['store'] ?? '') === 'column') {
        // A REAL COLUMN FROM MIGRATION 022, not a settings key. The builder
        // writes these back to the row's own controls rather than into the bag.
        $attrs .= ' data-mi-store="column"';
    }
    ?>
    <div class="mi-dialog__row"<?= $attrs ?>>
        <?php if ($type === 'check'): ?>
            <label class="check-list__row check-list__row--stacked">
                <input type="checkbox" data-mi-input>
                <span class="check-list__body">
                    <span class="check-list__name"><?= h($label) ?></span>
                    <?php if ($help !== ''): ?>
                        <span class="check-list__note"><?= h($help) ?></span>
                    <?php endif; ?>
                </span>
            </label>

        <?php elseif ($type === 'select'): ?>
            <div class="menu-fields">
                <div class="menu-fields__label"><?= h($label) ?></div>
                <div class="menu-fields__control">
                    <select class="select select--sm" data-mi-input aria-label="<?= h($label) ?>">
                        <?php foreach ((array) ($field['options'] ?? []) as $value => $text): ?>
                            <option value="<?= h((string) $value) ?>"><?= h((string) $text) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($help !== ''): ?>
                        <p class="form-help"><?= h($help) ?></p>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($type === 'icon'): ?>
            <?php // ── THE ICON TAB ──────────────────────────────────────────
                  // A search box over a grid of every glyph in the library, "no
                  // icon" first, over this admin's own set. A GRID, where the
                  // Content Type picker is a select: the
                  // design language's rule against a grid of swatches is about
                  // THEME swatches, which say nothing about how a page reads. A
                  // glyph swatch is the glyph.
                  //
                  // The value lives in ONE hidden input, so fill, commit and the
                  // "only once there is an icon" rule treat it like any other
                  // control. The radios carry no form: this dialog sits outside
                  // the menu's <form>, and they are never posted. ?>
            <?php $allIcons = Icons::all(); ?>
            <input type="hidden" data-mi-input value="">
            <div class="icon-picker">
                <span class="icon-picker__preview" data-mi-icon-preview></span>
                <input class="input input--sm input--search" type="search"
                       data-mi-icon-search autocomplete="off" spellcheck="false"
                       placeholder="Search <?= count($allIcons) ?> icons" aria-label="Search icons">
            </div>
            <p class="form-help mi-icons__chosen" data-mi-icon-chosen>No icon</p>
            <div class="mi-icons" role="radiogroup" aria-label="<?= h($label) ?>" data-mi-icon-grid>
                <label class="mi-icons__cell mi-icons__cell--none" title="No icon">
                    <input type="radio" name="mi-icon" value="" data-mi-icon-choice aria-label="No icon">
                    <span>None</span>
                </label>
                <?php foreach ($allIcons as $slug): ?>
                    <?php $name = Icons::label($slug); ?>
                    <label class="mi-icons__cell" title="<?= h($name) ?>"
                           data-mi-icon-name="<?= h(strtolower($name . ' ' . $slug)) ?>">
                        <input type="radio" name="mi-icon" value="<?= h($slug) ?>"
                               data-mi-icon-choice aria-label="<?= h($name) ?>">
                        <?= Icons::render($slug, 'icon icon--lg') ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="form-help" data-mi-icon-empty hidden>No icon matches that.</p>

        <?php elseif ($type === 'image'): ?>
            <?php // A PICTURE: the house media picker's look, and the admin's one
                  // media dialog (`CmsMedia.pick()`, lent by entry-form.js).
                  // The attachment number lives in the hidden input. ?>
            <div class="menu-fields">
                <div class="menu-fields__label"><?= h($label) ?></div>
                <div class="menu-fields__control">
                    <input type="hidden" data-mi-input value="">
                    <div class="media-picker mi-image">
                        <div class="media-picker__preview" data-mi-image-preview></div>
                        <div class="media-picker__actions">
                            <button type="button" class="btn btn--sm" data-mi-image-pick>Choose</button>
                            <button type="button" class="btn btn--sm btn--ghost" data-mi-image-clear hidden>Remove</button>
                        </div>
                    </div>
                    <?php if ($help !== ''): ?>
                        <p class="form-help"><?= h($help) ?></p>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($type === 'colour'): ?>
            <?php // A HEX COLOUR, OR THE THEME'S OWN — the toggle bar's control.
                  // A colour input cannot be empty, so "the theme's own" is a
                  // tick box beside it; the hidden input holds '' or the hex. ?>
            <div class="menu-fields">
                <div class="menu-fields__label"><?= h($label) ?></div>
                <div class="menu-fields__control">
                    <input type="hidden" data-mi-input value="">
                    <span class="tb__colour">
                        <input type="color" data-mi-colour aria-label="<?= h($label) ?>">
                        <label class="check-list__row check-list__row--inline">
                            <input type="checkbox" data-mi-colour-auto>
                            <span>The theme's own</span>
                        </label>
                    </span>
                    <?php if ($help !== ''): ?>
                        <p class="form-help"><?= h($help) ?></p>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($type === 'html'): ?>
            <div class="menu-fields">
                <div class="menu-fields__label"><?= h($label) ?></div>
                <div class="menu-fields__control">
                    <textarea class="textarea input--mono" rows="4" maxlength="2000" spellcheck="false"
                              data-mi-input aria-label="<?= h($label) ?>"></textarea>
                    <?php if ($help !== ''): ?>
                        <p class="form-help"><?= h($help) ?></p>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($type === 'px'): ?>
            <div class="menu-fields">
                <div class="menu-fields__label"><?= h($label) ?> (px)</div>
                <div class="menu-fields__control">
                    <input class="input input--sm mi-px" type="number" step="1" data-mi-input
                           min="<?= (int) $field['min'] ?>" max="<?= (int) $field['max'] ?>"
                           aria-label="<?= h($label) ?> in pixels">
                    <?php if ($help !== ''): ?>
                        <p class="form-help"><?= h($help) ?></p>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($type === 'styles'): ?>
            <?php // ── THE STYLING TAB ────────────────────────────────────────
                  // Custom styling for this menu item only. One row per
                  // override, each with its own tick box; an unticked row is
                  // greyed and stores nothing. The ticked
                  // rows travel as one JSON object in the hidden input, which is
                  // the bag's `styles` key. ?>
            <input type="hidden" data-mi-input value="">
            <p class="form-help mi-styles__intro">For this item only, over the theme's own. Tick a row to use it.</p>
            <div class="mi-styles">
                <?php foreach (MenuItemStyles::rows() as $styleRow): ?>
                    <?php
                    $rk    = (string) $styleRow['key'];
                    $rt    = (string) $styleRow['type'];
                    $rName = (string) $styleRow['label'] . ($rt === 'px' ? ' (px)' : '');
                    ?>
                    <div class="mi-style" data-mi-style="<?= h($rk) ?>"
                         data-mi-style-default="<?= h((string) ($styleRow['default'] ?? '')) ?>">
                        <label class="check-list__row mi-style__on">
                            <input type="checkbox" data-mi-style-on>
                            <span><?= h($rName) ?></span>
                        </label>
                        <div class="mi-style__value">
                            <?php if ($rt === 'colour' || $rt === 'colour2'): ?>
                                <input type="color" class="mi-style__colour" data-mi-style-value aria-label="<?= h($rName) ?>">
                                <?php if ($rt === 'colour2'): ?>
                                    <span class="mi-style__to">to</span>
                                    <input type="color" class="mi-style__colour" data-mi-style-to
                                           aria-label="<?= h($rName) ?>, second colour for a gradient">
                                <?php endif; ?>
                            <?php elseif ($rt === 'px'): ?>
                                <input class="input input--sm mi-style__px" type="number" step="1"
                                       min="<?= (int) $styleRow['min'] ?>" max="<?= (int) $styleRow['max'] ?>"
                                       data-mi-style-value aria-label="<?= h($rName) ?>">
                            <?php elseif ($rt === 'select'): ?>
                                <select class="select select--sm" data-mi-style-value aria-label="<?= h($rName) ?>">
                                    <?php foreach ((array) $styleRow['options'] as $ov => $ot): ?>
                                        <option value="<?= h((string) $ov) ?>"><?= h((string) $ot) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($rt === 'image'): ?>
                                <input type="hidden" data-mi-style-value value="">
                                <div class="media-picker mi-style__image">
                                    <div class="media-picker__preview" data-mi-style-preview></div>
                                    <div class="media-picker__actions">
                                        <button type="button" class="btn btn--sm" data-mi-style-pick>Choose</button>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($styleRow['note'])): ?>
                                <p class="form-help"><?= h((string) $styleRow['note']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <div class="menu-fields">
                <div class="menu-fields__label"><?= h($label) ?></div>
                <div class="menu-fields__control">
                    <input class="input input--sm" type="text" data-mi-input
                           aria-label="<?= h($label) ?>"
                           <?= isset($field['maxlength']) ? ' maxlength="' . (int) $field['maxlength'] . '"' : '' ?>>
                    <?php if ($help !== ''): ?>
                        <p class="form-help"><?= h($help) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
};

$miTabs = MenuItemSettings::tabs();
?>
<dialog class="modal modal--menu-item" data-mi-dialog
        aria-labelledby="mi-dialog-title">
    <div class="modal__inner">

        <div class="mi-dialog__head">
            <?php // WHICH ITEM. A dialog of switches with no subject is the
                  // commonest way to change the wrong row, and there is no undo
                  // on an unsaved menu. ?>
            <h2 class="modal__title" id="mi-dialog-title" data-mi-title>Menu item</h2>
            <span class="mi-dialog__what" data-mi-what></span>
        </div>

        <?php // THE LAYOUT: the tabs are a column down the left of the dialog,
              // each with a glyph, the chosen tab's settings beside them.
              // A strip across the top runs out of width at seven tabs and cuts
              // the last one off. ?>
        <div class="mi-dialog__body">
        <div class="tabs tabs--inline mi-dialog__tabs">
            <?php foreach ($miTabs as $tabKey => $tab): ?>
                <button type="button" class="tabs__tab<?= $tabKey === 'submenu' ? ' is-active' : '' ?>"
                        data-mi-tab="<?= h((string) $tabKey) ?>"><?= icon((string) ($tab['glyph'] ?? 'sliders'), 'icon icon--sm') ?><?= h((string) $tab['title']) ?></button>
            <?php endforeach; ?>
        </div>

        <div class="mi-dialog__panels">
            <?php foreach ($miTabs as $tabKey => $tab): ?>
                <div class="mi-dialog__panel" data-mi-panel="<?= h((string) $tabKey) ?>"
                     <?= $tabKey === 'submenu' ? '' : 'hidden' ?>>
                    <?php if (!empty($tab['needsNote'])): ?>
                        <?php // SAID IN ITS PLACE when the tab's fields cannot apply
                              // to this item, rather than an empty tab. ?>
                        <p class="mi-dialog__note" data-mi-needs-note hidden><?= h((string) $tab['needsNote']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($tab['subtabs'])): ?>
                        <?php // SUB-TABS inside a tab — the Icon
                              // tab's icon sets. One group per sub-tab. ?>
                        <div class="tabs tabs--inline mi-subtabs">
                            <?php foreach ($tab['groups'] as $gi => $group): ?>
                                <button type="button" class="tabs__tab<?= $gi === 0 ? ' is-active' : '' ?>"
                                        data-mi-subtab="<?= (int) $gi ?>"><?= h((string) $group['title']) ?></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($tab['groups'] as $gi => $group): ?>
                        <div class="mi-dialog__group"<?= !empty($tab['subtabs']) ? ' data-mi-subpanel="' . (int) $gi . '"' . ($gi === 0 ? '' : ' hidden') : '' ?>>
                            <?php if ((string) $group['title'] !== '' && empty($tab['subtabs'])): ?>
                                <p class="mi-dialog__group-title"><?= h((string) $group['title']) ?></p>
                            <?php endif; ?>
                            <?php foreach ($group['fields'] as $field) { $miField($field); } ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($tabKey === 'submenu'): ?>
                        <?php // ── THE PANEL LAYOUT, AS TILES ────────────────────
                              // Every item the owner has nested here is drawn as
                              // a tile reading "N / total" between a narrower and
                              // a wider button, and the tiles drag to reorder.
                              //
                              // STAGED, like everything else in the dialog: Cancel
                              // discards it, Done writes each item's width to that
                              // item and moves the items into the new order in the
                              // structure — order is ONE fact, the order of the
                              // rows, so the panel cannot disagree with the list. ?>
                        <template data-mi-size-glyphs>
                            <span data-for="narrow"><?= icon('minus', 'icon icon--sm') ?></span>
                            <span data-for="wider"><?= icon('plus', 'icon icon--sm') ?></span>
                        </template>
                        <div class="mi-panel" data-mi-layout hidden>
                            <p class="mi-dialog__group-title">Panel layout</p>
                            <p class="form-help" data-mi-layout-empty hidden>
                                Nothing is under this item yet. Drag items under it in
                                the menu structure and they appear here as tiles.
                            </p>
                            <div class="mi-panel__grid" data-mi-layout-grid></div>
                            <p class="form-help" data-mi-layout-help hidden>
                                Drag a tile to reorder. − and + change its width.
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php // WHAT WAS REMOVED AND WHY. Shown only when this tab's
                          // depth rules actually hid something, so it is never a
                          // line of prose on a tab that is complete. ?>
                    <p class="mi-dialog__note" data-mi-note hidden></p>
                </div>
            <?php endforeach; ?>
        </div>
        </div>

        <div class="modal__actions">
            <button type="button" class="btn" data-mi-cancel>Cancel</button>
            <button type="button" class="btn btn--primary" data-mi-save>Done</button>
        </div>

    </div>
</dialog>
