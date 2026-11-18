<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * THE MENU EDITOR.
 *
 * ── THE ARRANGEMENT ─────────────────────────────────────────────────────────
 * Add menu items on the LEFT in the narrow column, Menu structure on the RIGHT
 * in the wide one. Menu Name at the top of the structure card, Menu Settings at
 * the bottom of it, Save Menu and Delete Menu on one row beneath.
 *
 * ── THE ITEMS POST AS ONE JSON ARRAY, FLAT, IN ORDER ────────────────────────
 * `handleUpdate()` decodes `menu_items_json` and hands it to
 * `MenuItem::replaceAll()`, which deletes every row and re-inserts. Two
 * consequences the builder has to honour exactly:
 *
 *   POSITION IS ARRAY INDEX. The order of the array is the order on the site.
 *   NESTING IS `parent_index`, an index INTO THAT SAME ARRAY — not an id,
 *   because the ids do not exist yet at insert time. `replaceAll()` resolves it
 *   through `$newIds[$parentIndex]`, so A PARENT MUST APPEAR BEFORE ITS CHILD or
 *   the lookup misses and the child silently becomes top-level.
 *
 * ── WHAT AN ITEM CAN POINT AT ───────────────────────────────────────────────
 * `replaceAll()` stores `type`, `target_id` and `url`. A page, an entry or a
 * term carries a target id and resolves its address at render time; a custom
 * item carries a URL. An ARCHIVE is a custom link with the type's public slug
 * filled in — the table stores no fourth kind, so nothing here claims one.
 *
 * @var array<string, mixed>|null           $menu       null on create
 * @var list<array<string, mixed>>          $menuItems  flat, ordered
 * @var list<array<string, mixed>>          $tree       nested; unused here — the
 *                                                      builder needs the flat list
 * @var array<string, array{label: string}> $locations
 * @var array<string, int>                  $locationMap   slug => menu id
 * @var list<string>                        $menuLocations this menu's slugs
 * @var list<array<string, mixed>>          $allMenus
 * @var bool                                $autoAddPages
 * @var list<array<string, mixed>>          $pages
 * @var list<array<string, mixed>>          $entryGroups
 * @var list<array<string, mixed>>          $termGroups
 * @var array<string, string|null>          $locationSettings
 * @var array<string, mixed>                $formData
 * @var list<string>                        $errors
 * @var bool                                $saved
 * @var array<string, mixed>                $user
 */

$isNew         = ($menu ?? null) === null;
$menuItems     = $menuItems     ?? [];
$formData      = $formData      ?? [];
$errors        = $errors        ?? [];
$saved         = $saved         ?? false;
$locations     = $locations     ?? [];
$locationMap   = $locationMap   ?? [];
$menuLocations = $menuLocations ?? [];
$allMenus      = $allMenus      ?? [];
$autoAddPages  = $autoAddPages  ?? false;
$pages         = $pages         ?? [];
$entryGroups   = $entryGroups   ?? [];
$termGroups    = $termGroups    ?? [];
$locationSettings = $locationSettings ?? [];

$id     = $isNew ? 0 : (int) $menu['id'];
$action = $isNew
    ? Url::admin('appearance/menus/new')
    : Url::admin('appearance/menus/' . $id . '/edit');

$name = isset($formData['name']) && !is_array($formData['name'])
    ? (string) $formData['name']
    : (string) ($menu['name'] ?? '');

// THE TICKED LOCATIONS COME BACK ON A FAILED SAVE. Without this a menu name
// left blank would silently drop every location the owner had just ticked, and
// the second attempt would save the menu out of the header.
$checkedLocations = isset($formData['locations']) && is_array($formData['locations'])
    ? array_map('strval', $formData['locations'])
    : $menuLocations;

$autoAdd = array_key_exists('name', $formData)
    ? !empty($formData['auto_add_pages'])
    : $autoAddPages;

/**
 * THE FLAT LIST, WITH DEPTH RESOLVED, as JSON for the builder.
 *
 * The rows arrive with `parent_id` (a real database id). The builder works in
 * `parent_index`, so the depth is computed here once rather than in JS: walk the
 * ordered list, and each item's depth is its parent's depth plus one.
 */
/**
 * WHAT EACH ITEM POINTS AT, BY NAME — resolved HERE because only the server can.
 *
 * A footer reading "Page #3" would show a row id: a fact about the database,
 * useless to the person reading it, on the one thing on screen that has to tell
 * two items sharing a label apart. The builder cannot resolve the name —
 * a reload hands JS a `target_id` and nothing else — so the name travels with
 * the item in the JSON.
 */
$origOf = ['page' => [], 'entry' => [], 'taxonomy' => []];

// WHICH CONTENT TYPE EACH ENTRY BELONGS TO, so its row can carry that type's
// glyph — an Event row wearing the calendar the sidebar gives Events, rather than
// a generic mark that says only "entry".
$entryTypeOf = [];
foreach ($entryGroups as $g) {
    foreach ($g['entries'] as $e) {
        $entryTypeOf[(int) $e['id']] = (string) $g['key'];
    }
}
foreach ($pages as $p) {
    $origOf['page'][(int) $p['id']] = (string) $p['title'];
}
foreach ($entryGroups as $g) {
    foreach ($g['entries'] as $e) {
        $origOf['entry'][(int) $e['id']] = (string) $e['title'];
    }
}
foreach ($termGroups as $g) {
    foreach ($g['terms'] as $t) {
        $origOf['taxonomy'][(int) $t['id']] = (string) $t['title'];
    }
}

$depth = [];
$flat  = [];
foreach ($menuItems as $row) {
    $rid = (int) $row['id'];
    $pid = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
    $depth[$rid] = ($pid !== null && isset($depth[$pid])) ? $depth[$pid] + 1 : 0;
    $flat[] = [
        'label'        => (string) $row['label'],
        'type'         => (string) ($row['type'] ?? 'custom'),
        'target_id'    => $row['target_id'] !== null ? (int) $row['target_id'] : null,
        'url'          => (string) ($row['url'] ?? ''),
        'target_blank' => !empty($row['target_blank']),
        'css_class'    => (string) ($row['css_class'] ?? ''),
        'description'  => (string) ($row['description'] ?? ''),
        // THE TWO COLUMNS MIGRATION 022 DECLARED. They have to come back out
        // as well as go in: a builder that renders its selects at their
        // defaults writes those defaults on the next save, so an edit that
        // changed a label would quietly take an item's mega panel away.
        'sub_menu_type' => (string) ($row['sub_menu_type'] ?? 'standard'),
        'mega_columns'  => (static function ($raw): int {
            $cols = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

            return is_array($cols)
                ? max(1, min(\Cms\Models\MenuItem::MAX_PANEL_COLUMNS, (int) ($cols['count'] ?? 2)))
                : 2;
        })($row['mega_columns'] ?? null),
        'visibility'    => (static function ($raw): string {
            $rule = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $who  = is_array($rule) ? (string) ($rule['who'] ?? '') : '';

            // `MenuItem::VISIBILITY` minus the empty string, which is what an
            // unrecognised rule collapses to anyway. The words are not written
            // out here as well: that would be a third copy.
            return in_array($who, \Cms\Models\MenuItem::VISIBILITY, true) ? $who : '';
        })($row['visibility_rules'] ?? null),
        'depth'         => $depth[$rid],
        'glyph'         => (static function (array $row) use ($entryTypeOf): string {
            $type = (string) ($row['type'] ?? 'custom');
            if ($type === 'entry') {
                $key = $entryTypeOf[(int) ($row['target_id'] ?? 0)] ?? '';
                return $key !== '' ? 'type:' . $key : 'entry';
            }

            return in_array($type, ['page', 'taxonomy'], true) ? $type : 'custom';
        })($row),
        // MIGRATION 050'S BAG, read through the one definition the dialog and
        // the renderer also read. Handed to the builder as an OBJECT rather than
        // a string so a round trip through `JSON.stringify` cannot double-encode
        // it — which is how a settings bag becomes a quoted blob nothing parses.
        // SPARSE, not merged over the defaults. A row handed a complete bag
        // records every default as though somebody had chosen it — so an item
        // with no settings at all reports thirteen, and "cancel changed nothing"
        // cannot be told from "cancel changed everything back".
        'settings'      => (object) \Cms\Ui\MenuItemSettings::stored($row['settings'] ?? null),
        // AND A NAME FOR WHAT IT POINTS AT. A target that no longer exists says
        // so rather than printing a bare id — a page deleted out from under a
        // menu item is a real state, and "Page that no longer exists" is the
        // honest answer where a bare "#3" is not.
        'orig'          => (static function (array $row) use ($origOf): string {
            $type = (string) ($row['type'] ?? 'custom');
            $tid  = $row['target_id'] !== null ? (int) $row['target_id'] : 0;

            if ($type === 'custom' || !isset($origOf[$type])) {
                return (string) ($row['url'] ?? '');
            }

            return $origOf[$type][$tid]
                ?? 'This ' . ($type === 'taxonomy' ? 'term' : $type) . ' no longer exists';
        })($row),
    ];
}
$itemsJson = isset($formData['menu_items_json']) && is_string($formData['menu_items_json'])
    ? $formData['menu_items_json']
    : json_encode($flat);

$pageTitle = 'Menus';

/**
 * ONE "ADD MENU ITEMS" PANEL.
 *
 * A fold, then MOST RECENT / VIEW ALL / SEARCH, then a scrolling checkbox list,
 * then a `Select All` CHECKBOX on the left and an `Add to Menu` BUTTON on the
 * right. Every row is also a drag handle: an item can be carried straight from
 * here into the structure.
 *
 * A CLOSURE BECAUSE THERE ARE UP TO A DOZEN OF THEM: pages, one per Content
 * Type, one per taxonomy. Written out per source they would drift, and the one
 * that drifted would be the one nobody opened.
 *
 * @param list<array{title: string, kind: string, value: string}> $rows
 */
$addPanel = static function (
    string $key,
    string $label,
    array $rows,
    bool $open = false,
    string $note = '',
    string $glyph = 'link',
    string $rowGlyph = ''
) use ($isNew): void {
    if ($rows === []) {
        return;
    }
    // HOW MANY "MOST RECENT" SHOWS, and the reason there are three tabs at
    // all: the panel opens on a short list you can read, and
    // View All is there when the short list is not enough.
    $recent = 15;
    ?>
    <details class="fold add-panel" data-fold="add-<?= h($key) ?>"<?= $open ? ' open' : '' ?>>
        <summary class="fold__head">
            <?php // THE SAME MARK THE SIDEBAR DRAWS for this kind of thing,
                  // rather than a column of bare words ("Pages", "Events",
                  // "Team") beside a sidebar that has a glyph for every one
                  // of them. ?>
            <?= icon(\Cms\Ui\Sidebar::iconFor($glyph), 'icon icon--sm add-panel__glyph') ?>
            <?= h($label) ?>
            <?= icon('angle-down', 'fold__caret icon icon--sm') ?>
        </summary>
        <div class="fold__body">
            <div class="tabs tabs--inline add-panel__tabs" data-add-tabs>
                <button type="button" class="tabs__tab is-active" data-add-tab="recent">Most recent</button>
                <button type="button" class="tabs__tab" data-add-tab="all">View all</button>
                <button type="button" class="tabs__tab" data-add-tab="search">Search</button>
            </div>

            <div class="field add-panel__search" data-add-search hidden>
                <?php // `aria-label` AND NOT A HIDDEN <label>. This admin has no
                      // visually-hidden class, and a single control is no
                      // reason to invent one; the placeholder carries the same
                      // words for the eye. ?>
                <input class="input input--sm" type="search" data-add-filter
                       aria-label="Search <?= h(strtolower($label)) ?>"
                       placeholder="Search <?= h(strtolower($label)) ?>"
                       autocomplete="off">
            </div>

            <div class="check-list add-panel__list" data-add-list
                 data-recent="<?= (int) $recent ?>">
                <?php foreach ($rows as $i => $row): ?>
                    <label class="check-list__row add-panel__row" data-add-row
                           data-index="<?= (int) $i ?>">
                        <input type="checkbox"
                               data-add-item
                               data-kind="<?= h($row['kind']) ?>"
                               data-value="<?= h((string) $row['value']) ?>"
                               data-label="<?= h($row['title']) ?>"
                               data-glyph="<?= h((string) ($row['glyph'] ?? $row['kind'])) ?>">
                        <span data-add-text><?= h($row['title']) ?><?= ($row['lang'] ?? '') !== '' ? ' — ' . h(\Cms\Site\Language::nameOf((string) $row['lang'])) : '' ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <?php if ($note !== ''): ?>
                <?php // NO SILENT CAPS. When the list is trimmed the panel says
                      // so — a search that quietly misses older entries is worse
                      // than no search. ?>
                <p class="form-help"><?= h($note) ?></p>
            <?php endif; ?>

            <div class="add-panel__foot">
                <label class="check-list__row check-list__row--inline">
                    <input type="checkbox" data-add-all>
                    <span>Select all</span>
                </label>
                <button type="button" class="btn btn--sm" data-add-submit
                    <?= $isNew ? ' disabled' : '' ?>>Add to menu</button>
            </div>
        </div>
    </details>
    <?php
};

/**
 * THE OPTIONS FOR A `store: column` CONTROL, from the one definition.
 *
 * `MenuItemSettings::tabs()` already declares them for the item dialog; the row's
 * own selects read the same list rather than carrying a second copy that drifts.
 *
 * @return array<string, string>
 */
$rowOptions = static function (string $key): array {
    foreach (\Cms\Ui\MenuItemSettings::tabs() as $tab) {
        foreach ($tab['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                if ((string) ($field['key'] ?? '') === $key) {
                    return (array) ($field['options'] ?? []);
                }
            }
        }
    }

    return [];
};

// `visibility_rules` is a column the DIALOG does not offer — the row owns it —
// so its words live here and its vocabulary lives on the model, where
// `visibilityJson()` enforces it.
$visibilityLabels = [
    ''            => 'Everyone',
    'signed_in'   => 'Only people signed in',
    'signed_out'  => 'Only people signed out',
];

ob_start();
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Menus</h1>
        </div>
    </div>

    <div class="tabs">
        <a class="tabs__tab is-active" aria-current="page"
           href="<?= h(Url::admin('appearance/menus')) ?>">Edit menus</a>
        <a class="tabs__tab" href="<?= h(Url::admin('appearance/menus/locations')) ?>">Manage locations</a>
    </div>

    <?php if ($saved): ?>
        <div class="flashes">
            <div class="flash flash--success">Menu saved.</div>
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

    <?php // ── THE SWITCHER, FULL WIDTH, ABOVE BOTH COLUMNS ─────────────────
          // "Select a menu to edit" without a round trip through the list.
          //
          // ITS OWN <form>, AND IT COMES BEFORE THE EDITOR'S. Nesting one form
          // inside another is invalid HTML and the inner one silently does not
          // submit — so it sits outside, and it is a GET so nothing can be saved
          // by accident from it.
          //
          // NO SCRIPT. A `<select>` with an onchange handler would leave anyone
          // with scripting off stuck on one menu, and this admin's navigation
          // works without JS by rule. ?>
    <?php if ($allMenus !== []): ?>
        <div class="form-card">
            <div class="form-card__section">
                <form class="menu-switch" method="get"
                      action="<?= h(Url::admin('appearance/menus/select')) ?>">
                    <label class="label" for="menu-switch">Select a menu to edit</label>
                    <select class="select" id="menu-switch" name="menu_id">
                        <?php foreach ($allMenus as $m): ?>
                            <option value="<?= (int) $m['id'] ?>"
                                <?= (int) $m['id'] === $id ? ' selected' : '' ?>>
                                <?= h((string) $m['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn--sm">Select</button>
                    <?php // ONE PHRASE, ONE FLEX ITEM: as two, a narrow bar wraps
                          // between them and leaves "or" hanging at the line's end. ?>
                    <span class="menu-switch__or">or
                        <a class="link link--xs" href="<?= h(Url::admin('appearance/menus/new')) ?>">create a new menu</a></span>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= h($action) ?>" data-menu-builder>
        <?= Csrf::field() ?>

        <input type="hidden" name="menu_items_json" value="<?= h((string) $itemsJson) ?>"
               data-menu-json>

        <?php // ── ADD PANELS LEFT, STRUCTURE RIGHT ─────────────────────────
              // `--aside-first` is the only reason this screen needs a grid
              // modifier: every other screen in this admin reads
              // main-then-aside, and this one reads the other way. ?>
        <div class="panel__grid panel__grid--aside-first">

            <div class="form-aside">
                <?php if (!$isNew): ?>
                <div class="form-card">
                    <div class="form-card__section">
                        <?= panel_title('Add menu items', 'add-items') ?>

                        <?php
                        // ── PAGES ───────────────────────────────────────────
                        $pageRows = [];
                        foreach ($pages as $p) {
                            $pageRows[] = [
                                'title' => (string) $p['title'],
                                'kind'  => 'page',
                                'value' => (string) (int) $p['id'],
                                // A TRANSLATION SAYS ITS LANGUAGE beside its title.
                                'lang'  => (string) $p['lang'],
                            ];
                        }
                        $addPanel('pages', 'Pages', $pageRows, true, '', 'pages');

                        // ── ONE PANEL PER CONTENT TYPE: its archive, then its
                        //    entries. The archive sits with the entries it
                        //    lists, which is where anybody looks for it.
                        foreach ($entryGroups as $group) {
                            $rows = [[
                                'title' => 'All ' . $group['label'],
                                'kind'  => 'custom',
                                'value' => (string) $group['archive_url'],
                            ]];
                            // …AND IN THE SECOND LANGUAGE, for its menus
                            // (`Site\Language`): the archive under its prefix.
                            $menuSecond = \Cms\Site\Language::prefix();
                            if ($menuSecond !== '' && !empty($group['translates'])) {
                                $rows[] = [
                                    'title' => 'All ' . $group['label'],
                                    'kind'  => 'custom',
                                    'value' => '/' . $menuSecond . (string) $group['archive_url'],
                                    'lang'  => $menuSecond,
                                ];
                            }

                            foreach ($group['entries'] as $entry) {
                                $rows[] = [
                                    'title' => $entry['title'],
                                    'kind'  => 'entry',
                                    'value' => (string) $entry['id'],
                                    'glyph' => 'type:' . $group['key'],
                                    'lang'  => (string) ($entry['lang'] ?? ''),
                                ];
                            }

                            $note = $group['shown'] < $group['total']
                                ? 'Showing the ' . $group['shown'] . ' most recent of '
                                  . $group['total'] . '. Search covers these.'
                                : '';

                            $addPanel('type-' . $group['key'], (string) $group['label'], $rows, false, $note,
                                      (string) ($group['icon'] ?? 'file-text'));
                        }
                        ?>

                        <?php // ── YOUR OWN LINK ───────────────────────────
                              // Not a checkbox list — there is nothing to tick.
                              // Two boxes and a button. ?>
                        <details class="fold add-panel" data-fold="add-custom">
                            <summary class="fold__head">
                                <?= icon('link', 'icon icon--sm add-panel__glyph') ?>
                                Custom links
                                <?= icon('angle-down', 'fold__caret icon icon--sm') ?>
                            </summary>
                            <div class="fold__body">
                                <div class="field">
                                    <label class="label" for="f-add-custom-url">Address</label>
                                    <input class="input input--sm input--mono" type="text"
                                           id="f-add-custom-url" placeholder="https://example.com"
                                           data-add-custom-url spellcheck="false" autocomplete="off">
                                </div>
                                <div class="field">
                                    <label class="label" for="f-add-custom-label">Link text</label>
                                    <input class="input input--sm" type="text" id="f-add-custom-label"
                                           placeholder="Menu Item" data-add-custom-label autocomplete="off">
                                </div>
                                <div class="add-panel__foot add-panel__foot--end">
                                    <button type="button" class="btn btn--sm" data-add="custom"
                                        <?= $isNew ? ' disabled' : '' ?>>Add to menu</button>
                                </div>
                            </div>
                        </details>

                        <?php
                        // ── ONE PANEL PER TAXONOMY WITH A PUBLIC ADDRESS ────
                        foreach ($termGroups as $group) {
                            $rows = [];
                            foreach ($group['terms'] as $term) {
                                $rows[] = [
                                    'title' => $term['title'],
                                    'kind'  => 'taxonomy',
                                    'value' => (string) $term['id'],
                                ];
                            }
                            $addPanel('tax-' . $group['key'], (string) $group['label'], $rows, false, '', 'tags');
                        }
                        ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="form-card">
                    <div class="form-card__section">
                        <?= panel_title('Add menu items', 'add-items') ?>
                        <p class="form-help">
                            Give the menu a name and create it, then add pages,
                            entries, terms and your own links here.
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="form-main">
                <div class="form-card">

                    <div class="form-card__section">
                        <?= panel_title('Menu structure', 'items') ?>

                        <div class="field menu-name">
                            <label class="label" for="f-name">
                                Menu name<span class="label__req">required</span>
                            </label>
                            <input class="input" type="text" id="f-name" name="name" required
                                   value="<?= h($name) ?>" placeholder="Main navigation"
                                   autocomplete="off">
                        </div>
                    </div>

                    <?php // ITEMS ONLY EXIST ONCE THE MENU DOES. `handleCreate()`
                          // saves the NAME and the locations and redirects to this
                          // same screen in edit mode — it never reads
                          // `menu_items_json`. A builder on the create screen would
                          // therefore accept items, look saved, and drop every one
                          // of them: a control that lies, in the one shape that is
                          // hardest to notice. ?>
                    <?php if (!$isNew): ?>
                    <div class="form-card__section">
                        <p class="form-help">
                            Drag the items into the order you prefer. Drag an item
                            to the right to make it a sub-item. Click the arrow on
                            the right of an item to reveal its settings.
                        </p>

                        <?php // ── BULK SELECT, TOP AND BOTTOM ──────────────
                              // Both ends: a long menu
                              // puts the control that turns the mode off a
                              // screen and a half from the last row ticked. ?>
                        <div class="bulk-bar" data-bulk-bar>
                            <label class="check-list__row check-list__row--boxed">
                                <input type="checkbox" data-bulk-toggle>
                                <span>Bulk select</span>
                            </label>
                            <div class="bulk-bar__acts" data-bulk-acts hidden>
                                <button type="button" class="link link--xs" data-bulk-all>Select all</button>
                                <button type="button" class="link link--xs" data-bulk-none>Select none</button>
                                <button type="button" class="btn btn--sm btn--danger"
                                        data-bulk-remove disabled>Remove selected items</button>
                            </div>
                        </div>

                        <div class="menu-rows" data-menu-rows></div>

                        <div class="empty" data-menu-empty hidden>
                            <?= icon('bars', 'empty__glyph', '', 24) ?>
                            <p class="empty__title">No items yet</p>
                            <p class="empty__text">
                                Tick things in <strong>Add menu items</strong> and press
                                Add to menu — or drag one straight across.
                            </p>
                        </div>

                        <div class="bulk-bar" data-bulk-bar>
                            <label class="check-list__row check-list__row--boxed">
                                <input type="checkbox" data-bulk-toggle>
                                <span>Bulk select</span>
                            </label>
                            <div class="bulk-bar__acts" data-bulk-acts hidden>
                                <button type="button" class="link link--xs" data-bulk-all>Select all</button>
                                <button type="button" class="link link--xs" data-bulk-none>Select none</button>
                                <button type="button" class="btn btn--sm btn--danger"
                                        data-bulk-remove disabled>Remove selected items</button>
                            </div>
                        </div>

                        <?php // ── ONE ROW ───────────────────────────────────
                              // The label, what kind of thing it points at in
                              // the quiet register, and a caret. Nothing else on
                              // the closed row — the
                              // move controls live inside, because the row is
                              // DRAGGED and a row carrying six buttons is a row
                              // whose grip is hard to find. ?>
                        <template data-menu-template>
                            <div class="menu-item" data-menu-row>
                                <div class="menu-item__bar" data-menu-grip>
                                    <label class="menu-item__pick" data-menu-pick hidden>
                                        <input type="checkbox" data-menu-check
                                               aria-label="Select this item">
                                    </label>

                                    <span class="menu-item__title" data-menu-title></span>
                                    <?php // THE KIND WITH ITS GLYPH, not a bare word where
                                          // the registry has a mark for it: the sidebar
                                          // already draws one for every kind an item can
                                          // point at. ?>
                                    <span class="menu-item__kind">
                                        <span class="menu-item__glyph" data-menu-glyph aria-hidden="true"></span>
                                        <span data-menu-kind></span>
                                    </span>

                                    <?php // ── THE DISPLAY-SETTINGS BUTTON ──
                                          // One on every row, opening a tabbed
                                          // dialog of everything the item can be
                                          // told. The caret beside it stays: the
                                          // caret holds the item's own fields
                                          // and this holds the display settings.
                                          //
                                          // IT SAYS WHEN THE ITEM CARRIES ANY.
                                          // A button that looks identical whether
                                          // or not something is set is the same
                                          // as a control nobody can find. ?>
                                    <?php // A WORD, NOT A GLYPH. An unlabelled sliders mark
                                          // beside the caret would make two mystery icons
                                          // per row. ?>
                                    <button type="button" class="btn btn--sm menu-item__mega"
                                            data-mi-open aria-label="Mega Menu settings for this item">
                                        Mega Menu<span class="menu-item__mega-count" data-mi-count hidden></span>
                                    </button>
                                    <?php // DELETE LIVES ON THE THING IT DELETES, not only
                                          // inside the expanded row, where it would have to be
                                          // hunted for. The same place the form builder puts
                                          // it on a field. ?>
                                    <button type="button" class="icon-btn menu-item__remove"
                                            data-menu-remove data-tip="Remove item"
                                            aria-label="Remove item">
                                        <?= icon('x', 'icon icon--sm') ?>
                                    </button>
                                    <button type="button" class="icon-btn menu-item__caret"
                                            data-menu-caret aria-expanded="false"
                                            data-tip="Show this item's fields"
                                            aria-label="Show this item's fields">
                                        <?= icon('angle-down', 'icon icon--sm') ?>
                                    </button>
                                </div>

                                <?php // ── THE OPEN PANEL IS A LABEL/FIELD GRID ──
                                      // Label on the left, control on the right,
                                      // one tight row each — not a stack of
                                      // full-width boxes with a heading over
                                      // every one of them.
                                      //
                                      // Six stacked `.field` blocks run to
                                      // 350px of panel for one menu item, which
                                      // on a ten-item menu is a screen and a
                                      // half to open one row. ?>
                                <div class="menu-item__body" data-menu-body hidden>
                                    <div class="menu-fields">
                                        <label class="menu-fields__label" data-m-for="label">Navigation label</label>
                                        <div class="menu-fields__control">
                                            <input class="input input--sm" type="text" data-m="label"
                                                   autocomplete="off">
                                        </div>

                                        <div class="menu-fields__label" data-m-wrap="url" hidden>Address</div>
                                        <div class="menu-fields__control" data-m-wrap="url" hidden>
                                            <input class="input input--sm input--mono" type="text" data-m="url"
                                                   placeholder="https://example.com" spellcheck="false">
                                        </div>

                                        <div class="menu-fields__label">CSS classes</div>
                                        <div class="menu-fields__control">
                                            <input class="input input--sm input--mono" type="text"
                                                   data-m="css" spellcheck="false" autocomplete="off"
                                                   placeholder="optional">
                                        </div>

                                        <?php // THE DESCRIPTION, after CSS classes. Drawn
                                              // under the item only where a
                                              // location's card says so, and the help line says
                                              // that rather than leaving the owner to wonder why
                                              // nothing appeared. ?>
                                        <div class="menu-fields__label">Description</div>
                                        <div class="menu-fields__control">
                                            <textarea class="textarea input--sm" rows="2" maxlength="500"
                                                      data-m="description"
                                                      placeholder="A line under the label"></textarea>
                                            <p class="form-help">Shown only where a location's "Descriptions" is set to show them.</p>
                                        </div>

                                        <?php // ── THE TWO COLUMNS FROM MIGRATION 022 ──
                                              // `sub_menu_type` and `visibility_rules`,
                                              // which came with the menus table.
                                              //
                                              // THE OPTIONS COME FROM
                                              // `MenuItemSettings`, NOT FROM HERE.
                                              // Written out by hand they would be a
                                              // THIRD copy, alongside the settings
                                              // class and `MenuItem::SUB_MENU_TYPES`,
                                              // and a mode added to both of those
                                              // would store and render but could not
                                              // be chosen here. Rendering from the
                                              // shared source removes the question
                                              // rather than checking it. ?>
                                        <div class="menu-fields__label">Sub-menu</div>
                                        <div class="menu-fields__control menu-item__opts">
                                            <select class="select select--sm" data-m="submenu">
                                                <?php foreach ($rowOptions('sub_menu_type') as $v => $t): ?>
                                                    <option value="<?= h((string) $v) ?>"><?= h((string) $t) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php // THE COUNT APPEARS WITH THE PANEL
                                                  // and not before it. Under "A
                                                  // dropdown list" it is a control
                                                  // that does nothing. ?>
                                            <select class="select select--sm" data-m="cols"
                                                    data-m-wrap="cols" hidden
                                                    aria-label="Columns in the panel">
                                                <?php foreach ($rowOptions('mega_columns') as $v => $t): ?>
                                                    <option value="<?= h((string) $v) ?>"><?= h((string) $t) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="menu-fields__label">Who sees it</div>
                                        <div class="menu-fields__control">
                                            <select class="select select--sm" data-m="visibility">
                                                <?php foreach (\Cms\Models\MenuItem::VISIBILITY as $v): ?>
                                                    <?php // `?? $v` SO A NEW VALUE CANNOT FATAL THIS SCREEN.
                                                          // The labels are keyed by the vocabulary, so adding
                                                          // one to the constant without a word for it would
                                                          // be an undefined-key warning on every menu row. ?>
                                                    <option value="<?= h($v) ?>"><?= h($visibilityLabels[$v] ?? $v) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="menu-fields__label"></div>
                                        <div class="menu-fields__control">
                                            <label class="check-list__row">
                                                <input type="checkbox" data-m="blank">
                                                <span>Open this link in a new tab</span>
                                            </label>
                                        </div>
                                    </div>

                                    <?php // ── MOVE, IN WORDS ────────────────
                                          // Text links inside the open item,
                                          // and they are the keyboard route to
                                          // everything the drag does. A screen whose only
                                          // reordering gesture is a drag cannot
                                          // be operated without a pointer. ?>
                                    <div class="menu-item__move">
                                        <span class="menu-item__move-label">Move</span>
                                        <button type="button" class="link link--xs" data-menu-up>Up one</button>
                                        <button type="button" class="link link--xs" data-menu-down>Down one</button>
                                        <button type="button" class="link link--xs" data-menu-in>Under the item above</button>
                                        <button type="button" class="link link--xs" data-menu-out>Out from under</button>
                                        <button type="button" class="link link--xs" data-menu-top>To the top</button>
                                    </div>

                                    <div class="menu-item__foot">
                                        <?php // WHAT THIS ITEM ACTUALLY POINTS AT,
                                              // by name. A row id such as "Page #3"
                                              // is a fact about the database,
                                              // useless to the person reading it,
                                              // and this is the only thing on screen
                                              // distinguishing two items that share
                                              // a label. ?>
                                        <span class="menu-item__orig" data-menu-orig></span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                    <?php endif; ?>

                    <?php // ── MENU SETTINGS, AT THE BOTTOM OF THE STRUCTURE ──
                          // Two columns, the question on the left and the
                          // controls on the right. ?>
                    <div class="form-card__section">
                        <?= panel_title('Menu settings', 'location') ?>

                        <div class="menu-settings">
                            <div class="menu-settings__label" id="set-auto">Auto add pages</div>
                            <div class="menu-settings__field" role="group" aria-labelledby="set-auto">
                                <label class="check-list__row">
                                    <input type="checkbox" name="auto_add_pages" value="1"
                                           <?= $autoAdd ? ' checked' : '' ?>>
                                    <span>Automatically add new top-level pages to this menu</span>
                                </label>
                            </div>

                            <div class="menu-settings__label" id="set-loc">Menu location</div>
                            <div class="menu-settings__field" role="group" aria-labelledby="set-loc">
                                <?php if ($locations === []): ?>
                                    <p class="form-help">
                                        No locations are registered, so this menu has
                                        nowhere to appear yet. A theme registers them
                                        in <code>config/menus.php</code>.
                                    </p>
                                <?php else: ?>
                                    <div class="check-list">
                                        <?php foreach ($locations as $slug => $loc): ?>
                                            <?php
                                            $slug = (string) $slug;
                                            $held = $locationMap[$slug] ?? 0;
                                            $mine = in_array($slug, $checkedLocations, true);

                                            // WHO HOLDS IT NOW, named. The owner is told
                                            // what a tick will displace BEFORE they tick
                                            // it — the alternative is finding out when
                                            // the other menu goes dark on the site.
                                            $heldBy = '';
                                            if ($held > 0 && $held !== $id) {
                                                foreach ($allMenus as $m) {
                                                    if ((int) $m['id'] === $held) {
                                                        $heldBy = (string) $m['name'];
                                                        break;
                                                    }
                                                }
                                            }
                                            ?>
                                            <label class="check-list__row">
                                                <input type="checkbox" name="locations[]"
                                                       value="<?= h($slug) ?>"
                                                       <?= $mine ? ' checked' : '' ?>>
                                                <span><?= h((string) $loc['label']) ?></span>
                                                <?php if ($heldBy !== ''): ?>
                                                    <span class="check-list__note">Currently set to: <?= h($heldBy) ?></span>
                                                <?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php // ── HOW IT BEHAVES WHERE IT APPEARS ───────────────
                          // A card per location this menu occupies.
                          // Drawn from what is SAVED, not
                          // from the ticks above — see the partial. ?>
                    <?php if (!$isNew) { require __DIR__ . '/_menu-location-cards.php'; } ?>

                    <?php // ── THE FOOTER EVERY FORM HAS ──────────────────────
                          // Cancel at the left, the primary Save at the right.
                          // Delete is a LINK beside Cancel, and not a button,
                          // because it is not the action this screen is for —
                          // and it posts to its own form below, since a second
                          // submit inside this one would be one Enter keypress
                          // in the name box from firing. ?>
                    <div class="form-card__section form-actions">
                        <a class="link link--xs" href="<?= h(Url::admin('appearance/menus')) ?>">Cancel</a>
                        <?php if (!$isNew): ?>
                            <button type="submit" class="link link--xs"
                                    form="menu-delete-form">Delete menu</button>
                        <?php endif; ?>
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn btn--primary">
                            <?= $isNew ? 'Create menu' : 'Save menu' ?>
                        </button>
                    </div>

                </div>
            </div>

        </div>
    </form>

    <?php // THE DELETE'S FORM. Empty, and targeted by `form=` from the button in
          // the row above — which is how one row can hold two submits that post
          // to two different places without nesting forms. ?>
    <?php if (!$isNew): ?>
        <form id="menu-delete-form" method="post"
              action="<?= h(Url::admin('appearance/menus/' . $id . '/delete')) ?>"
              data-confirm="Delete the <?= h((string) $menu['name']) ?> menu?"
              data-confirm-detail="Its items go with it. The pages and entries it linked to are untouched."
              data-confirm-verb="Delete menu">
            <?= Csrf::field() ?>
        </form>
    <?php endif; ?>

</div>

<?php // ONE DIALOG FOR THE WHOLE SCREEN, filled from whichever row was
      // clicked. A menu of forty items would otherwise carry forty copies of
      // nine tabs in the document. ?>
<?php if (!$isNew) { require __DIR__ . '/_menu-item-dialog.php'; } ?>

<?php // EVERY KIND'S GLYPH, rendered by `Icons` once, so the builder clones real
      // markup from the one resolver the sidebar uses rather than carrying a
      // second copy of the mapping in JavaScript. ?>
<template data-menu-glyphs>
    <span data-glyph-for="page"><?= icon(\Cms\Ui\Sidebar::iconFor('pages'), 'icon icon--sm') ?></span>
    <span data-glyph-for="taxonomy"><?= icon('tags', 'icon icon--sm') ?></span>
    <span data-glyph-for="custom"><?= icon('link', 'icon icon--sm') ?></span>
    <span data-glyph-for="entry"><?= icon('file', 'icon icon--sm') ?></span>
    <?php foreach ($entryGroups as $g): ?>
        <span data-glyph-for="type:<?= h((string) $g['key']) ?>"><?= icon(\Cms\Ui\Sidebar::iconFor((string) ($g['icon'] ?? 'file-text')), 'icon icon--sm') ?></span>
    <?php endforeach; ?>
</template>

<?php // THE DEFAULTS, ONCE, FROM THE ONE DEFINITION. The dialog fills its blanks
      // from these and `commit()` compares against them, so neither has to carry
      // a second copy that can drift from `MenuItemSettings::defaults()`. Same
      // shape as the form builder's `data-field-widths`. ?>
<?php // THE PICTURES ITEMS ALREADY NAME, id => address, for the dialog. ?>
<script type="application/json" data-mi-media><?=
    json_encode((object) ($itemMedia ?? []), JSON_HEX_TAG | JSON_HEX_AMP)
?></script>
<script type="application/json" data-mi-defaults><?=
    json_encode(\Cms\Ui\MenuItemSettings::defaults())
?></script>

<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
<?php // reorder.js BEFORE the builder — the builder uses `Reorder.mirror`,
      // `Reorder.indicator` and `Reorder.dropPlan` at load. Real <script src>
      // tags, not a bundle: there is no build step. ?>
<script src="<?= h(Asset::admin('js/reorder.js')) ?>"></script>
<script src="<?= h(Asset::admin('js/menu-builder.js')) ?>"></script>
<?php // AFTER the builder: the dialog reads rows the builder has already
      // hydrated, and marks the ones carrying settings. It holds no reference to
      // the builder's internals — it talks to rows through the DOM and tells the
      // builder by dispatching `change`, which is the event it already listens
      // for — so this order is a convenience, not a dependency that fails
      // silently when somebody reorders the tags. ?>
<script src="<?= h(Asset::admin('js/menu-item-dialog.js')) ?>"></script>
<?php // THE ONE MEDIA DIALOG, lent out as `CmsMedia.pick()` — the toggle
      // bar's logo and image toggle choose pictures with it, as the page editor
      // does, rather than this screen growing a second picker. ?>
<script src="<?= h(Asset::admin('js/entry-form.js')) ?>"></script>
<script src="<?= h(Asset::admin('js/menu-toggle-bar.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
