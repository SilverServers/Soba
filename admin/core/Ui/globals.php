<?php

declare(strict_types=1);

/**
 * Template ergonomics — the three functions an admin template calls constantly.
 *
 * These are thin wrappers over `Cms\Ui\View` and `Cms\Ui\Icons`. There
 * is ONE implementation of each behind them; this file only shortens the name at
 * the call site, because `<?= \Cms\Ui\View::escape($x) ?>` fifteen times in
 * a row is markup nobody can read.
 *
 * Required once from `www/index.php`. It is not autoloaded, because PSR-4
 * autoloading loads classes and these are functions — and adding a Composer
 * `files` entry would put the admin's presentation layer into the bootstrap of
 * every public page request too.
 *
 * `function_exists()` guards so this file is safe to require twice; the public
 * theme does not use these, but a future one might define its own.
 */

use Cms\Ui\Icons;
use Cms\Ui\View;

if (!function_exists('h')) {
    /** HTML-escape for output. See `View::escape()` for why both quote kinds and U+FFFD matter. */
    function h(mixed $value): string
    {
        return View::escape($value);
    }
}

if (!function_exists('icon')) {
    /**
     * An icon, as inline SVG. See `Icons::render()`.
     *
     * `$attrs` is raw markup, never escaped — author it at the call site and
     * never build it from request data.
     */
    function icon(string $slug, string $cls = 'icon', string $attrs = '', int $size = 0): string
    {
        return Icons::render($slug, $cls, $attrs, $size);
    }
}

if (!function_exists('panel_title')) {
    /**
     * An aside panel's heading, with its domain mark.
     *
     * ── WHY ─────────────────────────────────────────────────────────────────
     * Without a mark, the entry form's right-hand column is FIVE IDENTICAL GREY
     * PANELS of empty boxes. Visibility, Placement, Featured image, the
     * taxonomy, Search engines: told apart only by reading them, on the screen
     * a site owner spends their whole day in. That is exactly what core.css §6
     * says a card glyph is for.
     *
     * ── THE MARK COMES FROM THE SIDEBAR'S MAP ───────────────────────────────
     * Four of the five already exist there — `seo`, `media`, `taxonomies`,
     * `group-structure` — so the panel and the nav row for the same idea draw
     * the same glyph and cannot drift. Only the ones with no nav equivalent are
     * named here.
     *
     * A slug is passed rather than derived from the heading text, because a
     * taxonomy panel's heading is the taxonomy's own label and no lookup table
     * can know it.
     */
    /**
     * ── TWO REGISTRIES, TRIED IN ORDER ──────────────────────────────────────
     * `Ui\Sections` holds the MAIN column's section keys — Names, Addresses,
     * Shape, Identity, Sender, Fields, Export — which have no nav row to borrow
     * a mark from. `Sidebar::iconMap()` holds everything that does, plus the
     * aside's own panel-only marks.
     *
     * Sections is tried FIRST and only falls through when the key is not one of
     * its own, so the existing aside call sites (`glyph`, `entry-form`,
     * `visibility`, `seo`, …) resolve exactly as before.
     *
     * Why it matters that this is not one map: `Sidebar::iconFor()` cannot fail
     * — it returns a chevron for anything it does not know, and logs. Routing
     * the section keys through it would have drawn a chevron on all 43 headings
     * and buried the reason in the error log.
     */
    function panel_title(string $text, string $slug): string
    {
        $sections = \Cms\Ui\Sections::glyphs();
        $icon     = array_key_exists($slug, $sections)
            ? $sections[$slug]
            : \Cms\Ui\Sidebar::iconFor($slug);

        return '<h2 class="form-card__title">'
            . \Cms\Ui\Icons::render($icon, 'icon icon--sm')
            . View::escape($text)
            . '</h2>';
    }
}

if (!function_exists('variation_row')) {
    /**
     * ONE ROW OF THE OPTIONS TABLE on a product's entry form.
     *
     * ── A FUNCTION, BECAUSE THE TEMPLATE NEEDS IT TWICE ─────────────────────
     * Once per stored variant, and once more inside a `<template>` that
     * `variations.js` clones for "Add an option". Written out twice in the
     * template they would drift the first time a column is added — the new
     * field would exist on the stored rows and not on the ones the owner adds,
     * which is a bug that only shows up on the second save.
     *
     * The same reasoning `FieldRenderer::renderRepeaterRow()` gives for being
     * one method with a `__ROW__` index.
     *
     * ── AND THE ID IS HIDDEN IN THE ROW ─────────────────────────────────────
     * `Variation::sync()` matches on it. Matching on the LABEL instead would be
     * simpler and wrong: renaming "Small" to "S" would read as a delete and an
     * insert, and the shop would lose the count of how many smalls it has —
     * silently, on a save that looked like a spelling fix.
     *
     * @param array<string, mixed>|null $v null for the blank template row
     */
    function variation_row(int|string $index, ?array $v): string
    {
        $name = 'variations[' . $index . ']';
        $val  = static fn (string $k, string $d = ''): string
            => \Cms\Ui\View::escape((string) ($v[$k] ?? $d));

        $price = $v !== null && $v['price'] !== null && $v['price'] !== ''
            ? \Cms\Commerce\Money::edit((int) $v['price'])
            : '';

        $manages = $v !== null && !empty($v['manage_stock']);
        $status  = (string) ($v['stock_status'] ?? 'in_stock');

        $statuses = [
            'in_stock'     => 'In stock',
            'out_of_stock' => 'Out of stock',
            'on_backorder' => 'On backorder',
        ];
        $options = '';
        foreach ($statuses as $key => $label) {
            $options .= '<option value="' . $key . '"'
                . ($status === $key ? ' selected' : '') . '>'
                . \Cms\Ui\View::escape($label) . '</option>';
        }

        // ── THE REPEATER'S OWN HOOKS, NOT A SECOND SET ──────────────────
        // `data-repeater-row` and `data-repeater-remove` are what
        // `entry-form.js` already listens for, on the document, for repeater
        // FIELDS. A variations panel with `data-variation-*` attributes would
        // have needed a second script doing the identical job — a second
        // component where one already exists.
        //
        // `repeaterAdd()` finds the next index with `/\[(\d+)\]/` over the
        // row's markup, which `variations[0][label]` satisfies.
        $h = '<div class="repeater__row" data-repeater-row>';
        $h .= '<input type="hidden" name="' . $name . '[id]" value="' . $val('id', '0') . '">';

        $h .= '<div class="repeater__fields form-grid form-grid--12">';
        $h .= '<div class="field field--third"><label class="label">Option</label>'
            . '<input class="input" type="text" name="' . $name . '[label]"'
            . ' value="' . $val('label') . '" placeholder="Small" autocomplete="off"></div>';

        $h .= '<div class="field field--third"><label class="label">Price</label>'
            . '<input class="input" type="text" inputmode="decimal" name="' . $name . '[price]"'
            . ' value="' . \Cms\Ui\View::escape($price) . '"'
            . ' placeholder="Same as above" autocomplete="off"></div>';

        $h .= '<div class="field field--third"><label class="label">Code</label>'
            . '<input class="input" type="text" name="' . $name . '[sku]"'
            . ' value="' . $val('sku') . '" autocomplete="off"></div>';

        $h .= '<div class="field field--third"><label class="label">Availability</label>'
            . '<select class="select" name="' . $name . '[stock_status]">' . $options . '</select></div>';

        // COUNTING IS OPT-IN PER OPTION, exactly as it is per product — a
        // ticket that never runs out and a shirt that does are both sold here.
        $h .= '<div class="field field--third"><label class="check-list__row">'
            . '<input type="hidden" name="' . $name . '[manage_stock]" value="0">'
            . '<input type="checkbox" name="' . $name . '[manage_stock]" value="1"'
            . ($manages ? ' checked' : '') . '><span>Count</span></label></div>';

        $h .= '<div class="field field--third"><label class="label">In stock</label>'
            . '<input class="input" type="number" min="0" step="1" name="' . $name . '[stock_qty]"'
            . ' value="' . $val('stock_qty', '0') . '"></div>';
        $h .= '</div>';

        // Glyph-only, so a tooltip is legal.
        $h .= '<button type="button" class="icon-btn" data-repeater-remove'
            . ' data-tip="Remove this option" aria-label="Remove this option">'
            . \Cms\Ui\Icons::render('trash-can', 'icon icon--sm') . '</button>';

        return $h . '</div>';
    }
}

if (!function_exists('count_of')) {
    /**
     * A count WITH THE WORD FOR WHAT IT COUNTS.
     *
     * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
     * `<span class="card__count">3</span>` was printed on five list screens and
     * it was a bare integer on every one, in an unheaded column, meaning
     * something different each time: entries of a Content Type, fields in a
     * field group, terms in a taxonomy, entries filed under a term, records in
     * a table: a floating, unlabelled number.
     *
     * A tooltip is not the fix: a tooltip may only carry a GLYPH-ONLY element,
     * never compete with visible text. Nor is the delete dialog: a number you
     * must attempt a destructive action to understand is not labelled. The fix
     * is to print the noun.
     *
     * ── AND WHY IT IS A FUNCTION ────────────────────────────────────────────
     * Five call sites, five nouns, and a plural rule that is wrong in one place
     * the moment someone writes `$n . ' entries'` by hand for the sixth. The
     * unit is the caller's — it is the only part that differs — and the
     * pluralisation, the escaping and the zero state live here.
     *
     * ZERO KEEPS ITS OWN CLASS. `.card__count--none` was already on the terms
     * list, on the reasoning that "0 entries use this" answers the question you
     * came to the screen with — whether deleting costs anything. That is right
     * and it now applies everywhere, because the question is the same on all
     * five.
     *
     * @param string $unit     singular noun: 'entry', 'field', 'term', 'record'
     * @param string $plural   when the plural is not $unit . 's'
     */
    function count_of(int $n, string $unit, string $plural = ''): string
    {
        $word = $n === 1 ? $unit : ($plural !== '' ? $plural : $unit . 's');

        return '<span class="card__count' . ($n === 0 ? ' card__count--none' : '') . '">'
            . '<span class="card__count-n">' . $n . '</span> '
            . View::escape($word)
            . '</span>';
    }
}

if (!function_exists('partial')) {
    /**
     * Render a fragment from `admin/templates/partials/`.
     *
     * @param array<string, mixed> $data
     */
    function partial(string $name, array $data = []): string
    {
        return View::partial($name, $data);
    }
}
