<?php

declare(strict_types=1);

use Cms\Ui\Icons;
use Cms\Ui\MenuToggleBlocks;

/**
 * THE TOGGLE BAR DESIGNER, on the header's card.
 *
 * An "Add block to toggle bar" select, the bar drawn as a phone will show it in
 * three zones — left, centre, right — with every block a chip you click to
 * change and drag to move, and the chosen block's settings beneath. The bar
 * belongs to the place the menu is shown rather than to a theme, so it is this
 * card's "Mobile menu" tab (migration 052 has the argument).
 *
 * The blocks travel as one JSON list in a hidden input, the way the menu's own
 * items do; `menu-toggle-bar.js` draws them and writes it back. The settings
 * forms are server-rendered `<template>`s from `MenuToggleBlocks::types()`, so
 * the one definition decides what each block offers.
 *
 * @var string                     $tbSlug
 * @var list<array<string, mixed>> $tbBlocks
 * @var array<int, string>         $toggleMedia  attachment id => address
 */

$tbTypes = MenuToggleBlocks::types();
$tbMeta  = [];
foreach ($tbTypes as $tbType => $tbDef) {
    $tbMeta[$tbType] = [
        'title'    => $tbDef['title'],
        'glyph'    => $tbDef['glyph'],
        'toggle'   => in_array($tbType, MenuToggleBlocks::TOGGLES, true),
        'defaults' => MenuToggleBlocks::block($tbType),
    ];
}
$tbIcons = Icons::all();
?>
<div class="tb" data-tb>
    <input type="hidden" name="toggle_blocks[<?= h($tbSlug) ?>]"
           value="<?= h((string) json_encode($tbBlocks)) ?>" data-tb-value>

    <div class="tb__head">
        <select class="select select--sm" data-tb-add aria-label="Add a block to the toggle bar">
            <option value="">Add a block to the toggle bar…</option>
            <?php foreach ($tbTypes as $tbType => $tbDef): ?>
                <option value="<?= h($tbType) ?>"><?= h($tbDef['title']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php // THE BAR AS A PHONE SHOWS IT: three zones, left to right. A zone with
          // nothing in it still has its size, so there is somewhere to drop. ?>
    <div class="tb__bar" data-tb-bar>
        <div class="tb__zone tb__zone--left" data-tb-zone="left" aria-label="Left of the bar"></div>
        <div class="tb__zone tb__zone--center" data-tb-zone="center" aria-label="Centre of the bar"></div>
        <div class="tb__zone tb__zone--right" data-tb-zone="right" aria-label="Right of the bar"></div>
    </div>
    <p class="form-help tb__help">Click a block to change it; drag it to move it along the bar.</p>

    <div class="tb__settings" data-tb-settings hidden></div>

    <?php foreach ($tbTypes as $tbType => $tbDef): ?>
        <template data-tb-form="<?= h($tbType) ?>">
            <p class="mi-dialog__group-title"><?= h($tbDef['title']) ?></p>
            <div class="menu-fields">
                <?php // WHERE IT SITS, for a keyboard — the drag's other half. ?>
                <div class="menu-fields__label">Where it sits</div>
                <div class="menu-fields__control">
                    <select class="select select--sm" data-tb-align aria-label="Where it sits">
                        <option value="left">Left of the bar</option>
                        <option value="center">Centre of the bar</option>
                        <option value="right">Right of the bar</option>
                    </select>
                </div>
                <?php foreach ($tbDef['fields'] as $tbField): ?>
                    <?php
                    $tbKey  = (string) $tbField['key'];
                    $tbKind = (string) $tbField['type'];
                    ?>
                    <?php if ($tbKind === 'check'): ?>
                        <div class="menu-fields__label"></div>
                        <div class="menu-fields__control">
                            <label class="check-list__row">
                                <input type="checkbox" data-tb-field="<?= h($tbKey) ?>">
                                <span><?= h((string) $tbField['label']) ?></span>
                            </label>
                        </div>
                    <?php else: ?>
                        <div class="menu-fields__label"><?= h((string) $tbField['label']) ?></div>
                        <div class="menu-fields__control">
                            <?php if ($tbKind === 'select'): ?>
                                <select class="select select--sm" data-tb-field="<?= h($tbKey) ?>"
                                        aria-label="<?= h((string) $tbField['label']) ?>">
                                    <?php foreach ((array) $tbField['options'] as $tbValue => $tbText): ?>
                                        <option value="<?= h((string) $tbValue) ?>"><?= h((string) $tbText) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($tbKind === 'number'): ?>
                                <input class="input input--sm tb__number" type="number" data-tb-field="<?= h($tbKey) ?>"
                                       min="<?= (int) $tbField['min'] ?>" max="<?= (int) $tbField['max'] ?>" step="1"
                                       aria-label="<?= h((string) $tbField['label']) ?>">
                            <?php elseif ($tbKind === 'colour'): ?>
                                <?php // A HEX COLOUR, OR THE THEME'S OWN. A colour input
                                      // cannot be empty, so "the theme's own" is a tick
                                      // box beside it rather than a value it can hold. ?>
                                <span class="tb__colour">
                                    <input type="color" data-tb-field="<?= h($tbKey) ?>"
                                           aria-label="<?= h((string) $tbField['label']) ?>">
                                    <label class="check-list__row check-list__row--inline">
                                        <input type="checkbox" data-tb-colour-auto="<?= h($tbKey) ?>">
                                        <span>The theme's own</span>
                                    </label>
                                </span>
                            <?php elseif ($tbKind === 'icon'): ?>
                                <?php // A LABELLED SELECT, with the glyph beside
                                      // it. ?>
                                <div class="icon-picker">
                                    <span class="icon-picker__preview" data-tb-icon-preview="<?= h($tbKey) ?>"></span>
                                    <select class="select select--sm" data-tb-field="<?= h($tbKey) ?>"
                                            aria-label="<?= h((string) $tbField['label']) ?>">
                                        <option value="">None</option>
                                        <?php foreach ($tbIcons as $tbSlugIcon): ?>
                                            <option value="<?= h($tbSlugIcon) ?>"><?= h(Icons::label($tbSlugIcon)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php elseif ($tbKind === 'image'): ?>
                                <?php // The house media picker's look; `CmsMedia.pick()`
                                      // is the one dialog, lent out by entry-form.js. ?>
                                <div class="media-picker">
                                    <div class="media-picker__preview" data-tb-image-preview="<?= h($tbKey) ?>"></div>
                                    <div class="media-picker__actions">
                                        <button type="button" class="btn btn--sm" data-tb-pick="<?= h($tbKey) ?>">Choose</button>
                                        <button type="button" class="btn btn--sm btn--ghost" data-tb-clear="<?= h($tbKey) ?>" hidden>Remove</button>
                                    </div>
                                </div>
                            <?php elseif ($tbKind === 'html'): ?>
                                <textarea class="textarea input--mono" rows="4" data-tb-field="<?= h($tbKey) ?>"
                                          maxlength="<?= (int) ($tbField['max'] ?? 2000) ?>"
                                          aria-label="<?= h((string) $tbField['label']) ?>" spellcheck="false"></textarea>
                                <p class="form-help">Cleaned as page content is — no scripts, no styles, no forms.</p>
                            <?php else: ?>
                                <input class="input input--sm<?= $tbKind === 'url' ? ' input--mono' : '' ?>" type="text"
                                       data-tb-field="<?= h($tbKey) ?>"
                                       maxlength="<?= (int) ($tbField['max'] ?? 500) ?>"
                                       <?= $tbKind === 'url' ? 'placeholder="/contact or https://…" spellcheck="false"' : '' ?>
                                       aria-label="<?= h((string) $tbField['label']) ?>">
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <div class="tb__settings-foot">
                <button type="button" class="btn btn--sm" data-tb-move="-1"><?= icon('angle-left', 'icon icon--sm') ?> Move left</button>
                <button type="button" class="btn btn--sm" data-tb-move="1">Move right <?= icon('angle-right', 'icon icon--sm') ?></button>
                <button type="button" class="btn btn--sm" data-tb-delete>
                    <?= icon('trash', 'icon icon--sm') ?> Remove this block
                </button>
                <p class="form-help" data-tb-last hidden>
                    The bar keeps one menu toggle — without it a phone cannot open the menu.
                </p>
            </div>
        </template>
    <?php endforeach; ?>

    <template data-tb-glyphs>
        <?php foreach (array_unique(array_column($tbTypes, 'glyph')) as $tbGlyph): ?>
            <span data-for="<?= h((string) $tbGlyph) ?>"><?= Icons::render((string) $tbGlyph, 'icon icon--sm') ?></span>
        <?php endforeach; ?>
    </template>
    <script type="application/json" data-tb-types><?= json_encode($tbMeta, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script type="application/json" data-tb-media><?= json_encode((object) ($toggleMedia ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</div>
