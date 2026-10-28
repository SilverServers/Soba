<?php

declare(strict_types=1);

use Cms\Helpers\MenuRenderer;
use Cms\Ui\MenuLocationSettings;

/**
 * HOW THIS MENU BEHAVES — the menu editor's per-location panel.
 *
 * One card per location THIS MENU occupies, holding how the menu behaves there:
 * what opens a sub-menu, how it appears, what happens on a phone. Laid out in
 * tabs — `MenuLocationSettings::tabs()` has the argument.
 *
 * ── PER PAIR, NOT PER MENU ─────────────────────────────────────────────────
 * The settings live on `menu_locations` (migration 050), because the same menu
 * in the header and in the footer wants different answers — a header opens
 * sub-menus on hover, a footer has none to open. That is only expressible at all
 * because migration 049 let one menu occupy both.
 *
 * ── AND IT NEEDS A SAVED LOCATION FIRST ────────────────────────────────────
 * The cards are drawn from `$menuLocations`, which is what the DATABASE says —
 * not from the tick boxes above, which may have been changed and not saved.
 *
 * That is the honest behaviour: a card for a location the menu does not yet
 * occupy would be collecting settings against a row that does not exist, and
 * the save would drop them with nothing on screen saying so. So the panel says
 * to save first, which is a true sentence, rather than drawing controls that
 * quietly do nothing.
 *
 * ── ONLY WHAT APPLIES THERE ────────────────────────────────────────────────
 * A footer location is not the header's bar, so its card leaves out every
 * field marked `bar` — and a tab left with nothing in it is not drawn. One tab left is
 * drawn without the strip: a single tab is a heading pretending to be a choice.
 *
 * @var array<string, array{label: string}> $locations
 * @var list<string>                        $menuLocations   saved, not ticked
 * @var array<string, array<string, string>> $locationSettings  slug => bag
 */

$locationSettings = $locationSettings ?? [];

// ONLY LOCATIONS THAT ARE BOTH SAVED AND STILL REGISTERED. A row pointing at a
// registration removed from `config/menus.php` is a location no theme will ever
// ask for; the list screen reports it, and drawing a settings card for it here
// would be inviting somebody to configure something that cannot render.
$cards = [];
foreach ($menuLocations as $slug) {
    $slug = (string) $slug;
    if (isset($locations[$slug])) {
        $cards[$slug] = (string) $locations[$slug]['label'];
    }
}

/**
 * `showWhen` as the attribute the card's script reads — and whether it holds
 * for the stored bag, so the server draws the first state right. `!value`
 * means "anything but this".
 *
 * @param  mixed                 $when
 * @param  array<string, string> $bag
 * @return array{0: string, 1: bool}   the attribute, and whether it is shown
 */
$locWhen = static function (mixed $when, array $bag): array {
    if (!is_array($when) || $when === []) {
        return ['', true];
    }
    $show = true;
    foreach ($when as $other => $wanted) {
        $is   = (string) ($bag[$other] ?? '');
        $show = $show && (str_starts_with((string) $wanted, '!')
            ? $is !== substr((string) $wanted, 1)
            : $is === (string) $wanted);
    }

    return [' data-loc-when="' . h((string) json_encode($when)) . '"', $show];
};

/**
 * ONE CONTROL, without its label column: a tick box, a bounded number, or a
 * select. `$inRow` draws a number with its own small label, as "Tolerance" and
 * "Offset" sit inside the "Hide until scrolling up" row.
 *
 * @param array<string, mixed>  $field
 * @param array<string, string> $bag
 */
$locControl = static function (string $slug, array $field, array $bag, bool $inRow) use ($locWhen): void {
    $key  = (string) $field['key'];
    $type = (string) ($field['type'] ?? 'select');
    $id   = 'loc-' . preg_replace('/[^a-z0-9]+/i', '-', $slug) . '-' . $key;
    $name = 'location_settings[' . $slug . '][' . $key . ']';
    $have = (string) ($bag[$key] ?? '');

    // A CONTROL INSIDE A ROW MAY DEPEND ON ITS NEIGHBOUR — the tolerance means
    // nothing until "hide until scrolling up" is ticked.
    [$attrs, $show] = $inRow ? $locWhen($field['showWhen'] ?? null, $bag) : ['', true];
    $hidden = $show ? '' : ' hidden';

    if ($type === 'check'): ?>
        <?php // AN UNTICKED BOX POSTS NOTHING, so the `off` goes first and the
              // box, when ticked, overrides it — PHP keeps the last of two
              // same-named fields. Without it an unticked box reads as "not
              // sent", which is the default, which may be `on`. ?>
        <input type="hidden" name="<?= h($name) ?>" value="off">
        <label class="check-list__row<?= $inRow ? ' check-list__row--inline' : '' ?>"<?= $attrs . $hidden ?>>
            <input type="checkbox" id="<?= h($id) ?>" name="<?= h($name) ?>" value="on"
                   data-loc-input="<?= h($key) ?>"<?= $have === 'on' ? ' checked' : '' ?>>
            <span><?= h((string) ($field['text'] ?? $field['label'] ?? $key)) ?></span>
        </label>
    <?php elseif ($type === 'number'): ?>
        <?php // The unit is in the LABEL, as the field-group editor's number
              // inputs have it — no suffix component exists and this does not
              // invent one. ?>
        <?php if ($inRow): ?><label class="loc-row__num"<?= $attrs . $hidden ?>><span><?= h((string) ($field['text'] ?? $key)) ?></span><?php endif; ?>
        <input class="input input--sm" type="number" id="<?= h($id) ?>"
               name="<?= h($name) ?>" value="<?= h($have) ?>"
               min="<?= h((string) $field['min']) ?>" max="<?= h((string) $field['max']) ?>"
               step="<?= h((string) ($field['step'] ?? 1)) ?>"
               data-loc-input="<?= h($key) ?>">
        <?php if ($inRow): ?></label><?php endif; ?>
    <?php else: ?>
        <select class="select select--sm" id="<?= h($id) ?>"
                data-loc-input="<?= h($key) ?>" name="<?= h($name) ?>">
            <?php foreach ((array) $field['options'] as $value => $text): ?>
                <option value="<?= h((string) $value) ?>"<?= $have === (string) $value ? ' selected' : '' ?>>
                    <?= h((string) $text) ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php endif;
};

/**
 * One field: its label in the left column, its control — or a row of them —
 * in the right, both carrying the same `data-loc-when` so a dependent control
 * hides as a pair.
 *
 * @param array<string, mixed>  $field
 * @param array<string, string> $bag
 */
$locField = static function (string $slug, array $field, array $bag) use ($locWhen, $locControl): void {
    $type = (string) ($field['type'] ?? 'select');
    [$attrs, $show] = $locWhen($field['showWhen'] ?? null, $bag);
    $hidden = $show ? '' : ' hidden';
    $forId  = $type === 'row' || $type === 'check'
        ? ''
        : 'loc-' . preg_replace('/[^a-z0-9]+/i', '-', $slug) . '-' . (string) $field['key'];
    ?>
    <div class="menu-fields__label"<?= $attrs . $hidden ?>>
        <?php if ($forId !== ''): ?>
            <label for="<?= h($forId) ?>"><?= h((string) $field['label']) ?></label>
        <?php else: ?>
            <?= h((string) $field['label']) ?>
        <?php endif; ?>
    </div>
    <div class="menu-fields__control"<?= $attrs . $hidden ?>>
        <?php if ($type === 'row'): ?>
            <div class="loc-row">
                <?php foreach ($field['fields'] as $leaf) { $locControl($slug, $leaf, $bag, true); } ?>
            </div>
        <?php else: ?>
            <?php $locControl($slug, $field, $bag, false); ?>
        <?php endif; ?>
        <?php if (!empty($field['help'])): ?>
            <p class="form-help"><?= h((string) $field['help']) ?></p>
        <?php endif; ?>
    </div>
    <?php
};
?>
<?php // THE RECESS BEHIND THE CARDS: each location is a white fold, and on the
      // white card a white fold had nothing but a hairline — the section
      // takes `--surface-2`, as the add panels' column already is. ?>
<div class="form-card__section form-card__section--fill">
    <?= panel_title('How this menu behaves', 'location') ?>

    <?php if ($cards === []): ?>
        <p class="form-help">
            <?php // A TRUE SENTENCE RATHER THAN A DEAD CONTROL. There is nowhere
                  // to store these until the menu holds a location, so the panel
                  // says what to do instead of collecting answers it would
                  // throw away. ?>
            Tick a location above and save the menu, and its behaviour — what
            opens a sub-menu, how it appears, what happens on a phone — can be
            set here for each place it appears.
        </p>
    <?php else: ?>
        <?php foreach ($cards as $slug => $label): ?>
            <?php
            $bag       = MenuLocationSettings::read($locationSettings[$slug] ?? null);
            $open      = count($cards) === 1;
            $isBar     = MenuRenderer::isBar($slug);

            $tabs = [];
            foreach (MenuLocationSettings::tabs() as $tabKey => $tab) {
                $fields = array_values(array_filter(
                    $tab['fields'],
                    static fn (array $f): bool => $isBar || empty($f['bar'])
                ));
                if ($fields !== []) {
                    $tabs[(string) $tabKey] = ['title' => (string) $tab['title'], 'fields' => $fields];
                }
            }
            // THE PHONE'S TOGGLE BAR — the "Mobile menu" tab, on the header's
            // card (migration 052). Not a field: a designer.
            if ($isBar) {
                $tabs['mobile'] = ['title' => 'Mobile menu', 'fields' => []];
            }
            $first = (string) array_key_first($tabs);
            ?>
            <details class="fold loc-card"<?= $open ? ' open' : '' ?>
                     data-fold="loc-<?= h($slug) ?>">
                <summary class="fold__head">
                    <?= h($label) ?>
                    <?= icon('angle-down', 'fold__caret icon icon--sm') ?>
                </summary>
                <div class="fold__body">
                    <?php if (!$isBar): ?>
                        <p class="form-help loc-card__note">
                            This location shows its items as a row of links, so
                            nothing opens here — only the settings that apply are shown.
                        </p>
                    <?php endif; ?>

                    <?php if (count($tabs) > 1): ?>
                        <div class="tabs tabs--inline loc-card__tabs">
                            <?php foreach ($tabs as $tabKey => $tab): ?>
                                <button type="button" class="tabs__tab<?= $tabKey === $first ? ' is-active' : '' ?>"
                                        data-loc-tab="<?= h($tabKey) ?>"><?= h($tab['title']) ?></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($tabs as $tabKey => $tab): ?>
                        <?php if ($tabKey === 'mobile'): ?>
                            <div data-loc-panel="mobile" <?= $tabKey === $first ? '' : 'hidden' ?>>
                                <?php
                                $tbSlug   = $slug;
                                $tbBlocks = $toggleBlocks[$slug] ?? \Cms\Ui\MenuToggleBlocks::defaults();
                                require __DIR__ . '/_menu-toggle-bar.php';
                                ?>
                            </div>
                        <?php else: ?>
                            <div class="menu-fields" data-loc-panel="<?= h($tabKey) ?>"
                                 <?= $tabKey === $first ? '' : 'hidden' ?>>
                                <?php foreach ($tab['fields'] as $field) { $locField($slug, $field, $bag); } ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
