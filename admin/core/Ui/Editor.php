<?php

declare(strict_types=1);

namespace Cms\Ui;

/**
 * THE RICH-TEXT TOOLBAR'S GLYPHS.
 *
 * ── WHY A REGISTRY FOR FIVE ICONS ───────────────────────────────────────────
 * As the note on `Ui\Status` says: a glyph registry that goes stale is
 * INVISIBLE, because `Icons::render()` returns '' for a slug with no SVG. A
 * toolbar button whose icon silently became nothing is a blank button — and a
 * blank button in a row of buttons does not look broken, it looks like a
 * separator.
 *
 * ── AND WHY NOT ONE FOR EVERY CONTROL ───────────────────────────────────────
 * The icon set has no bold, italic, strikethrough, unlink or eraser. Rather
 * than draw new SVGs to a set somebody else's hand made — which would show, at
 * 14px, in a row beside the originals — the controls that have no glyph use the
 * LETTERS every editor uses for them: **B**, *I*, S̶.
 *
 * That is not a compromise. `B` in bold and `I` in italic are more legible at
 * this size than any icon of them, they are what every writing tool has used
 * for thirty years, and they cannot render as a box.
 *
 * The heading controls are not letters and not icons either — they are a
 * SELECT, the only shape that can offer Paragraph, six heading levels and
 * Preformatted without eight buttons in the row.
 *
 * Unicode characters are no substitute — `⛓` for link, `⛓̸` for unlink, `⌫`
 * for clear are tofu: the chain renders as a box in this font stack and the
 * combining slash on the second one lands anywhere it likes. A glyph that
 * renders as a box is worse than a word.
 *
 * ── THE SVGs ARE HANDED TO JAVASCRIPT, NOT DUPLICATED IN IT ─────────────────
 * `editor.js` builds the toolbar — it must, because a toolbar rendered by PHP
 * would exist with JavaScript off and every button in it would do nothing —
 * and a control that is drawn but does not work is worse than none.
 *
 * So the markup comes from `Icons::render()` into a `<template>` on the page,
 * and the script clones it. One icon source, and no path data copied into a
 * second language.
 */
final class Editor
{
    /**
     * Toolbar key => icon slug.
     *
     * The keys are what `editor.js` looks up; keep them in step with `TOOLS`
     * in that file. A key here with no button is dead weight; a button there
     * with no key falls back to its text label, which is why the file cannot
     * break by disagreeing with this — it can only be untidy.
     */
    public static function glyphs(): array
    {
        return [
            'bullets' => 'list-ul',
            'numbers' => 'list-ol',
            // `quote-left` and not `quote-right`: an opening quotation mark is
            // what a block quote starts with, and the two are mirror images
            // that would be indistinguishable in a toolbar anyway.
            'quote'   => 'quote-left',
            'link'    => 'link',
            // NOT a separate unlink glyph — there is no unlink icon in the set,
            // and inventing one would be a fifth silhouette nobody recognises.
            // The link button TOGGLES: pressed inside a link it removes it,
            // which is fewer controls and one less thing to explain.
            'code'    => 'terminal',
            'image'   => 'image',
            // Import Markdown, beside Add image: a file coming in.
            'markdown' => 'file-arrow-up',

            // ── TEXT ALIGNMENT ──────────────────────────────────────────────
            // The SAME three silhouettes the image bar uses, under different
            // keys, because they are different instructions: these set
            // `text-align` on a block, those float a picture. One key for both
            // would be one button for both, which is the bug
            // `Html\Sanitizer::TEXT_CLASSES` exists to prevent in the markup.
            //
            // There is no justify. See `TEXT_CLASSES`.
            'text-left'   => 'align-left',
            'text-center' => 'align-center',
            'text-right'  => 'align-right',

            // Nesting a list item in or out. Font Awesome's `indent`/`outdent`
            // are the arrow-and-lines pair every word processor has used for
            // this since the eighties.
            'indent'  => 'indent',
            'outdent' => 'outdent',

            // A HORIZONTAL RULE IS A LINE, and `minus` is the only line in the
            // set. It reads as a rule in a row of formatting controls, where a
            // subtract sign would need a number beside it to be misread as one.
            'rule'    => 'minus',

            // Undo and redo are the counter-clockwise and clockwise arrows.
            // NOT `arrow-left`/`arrow-right`, which mean navigation everywhere
            // else in this admin and would read as "previous entry".
            'undo'    => 'arrow-rotate-left',
            'redo'    => 'arrow-rotate-right',

            // A grid of cells, which is what a table is. `table-cells` is the
            // same silhouette with the lines drawn in and reads as a spreadsheet
            // at 14px; `table` is the outline, which is the more general idea
            // and the one the toolbar wants.
            'table'   => 'table',

            // Distraction-free writing. `expand` is the four-corner arrows and
            // `compress` its inverse; ONE key, because the button toggles and a
            // second glyph would be a second control's worth of registry for a
            // state that already announces itself through `aria-pressed`.
            'fullscreen' => 'expand',

            // ── THE SELECTED-IMAGE BAR ──────────────────────────────────────
            // Click a picture and the formatting controls are replaced by that
            // picture's own.
            //
            // Alignment has three real glyphs and no fourth: there is no
            // "no alignment" silhouette in this set, and inventing one would be
            // a shape nobody recognises next to three they do. That control is
            // the word "None", which cannot be misread.
            'align-left'   => 'align-left',
            'align-center' => 'align-center',
            'align-right'  => 'align-right',
            // ── ONE PENCIL WHERE THERE WERE TWO BUTTONS ────────────────────
            // `caption` (a speech bubble) and `alt` (a writing mark) were
            // separate controls opening separate one-field prompts. They are
            // one dialog now, behind a pencil, holding the
            // caption, the description, the width and the link, because a bar
            // that FLOATS OVER the picture cannot grow to four more buttons and
            // because alt and caption are written in the same breath anyway.
            //
            // The two slugs went with the buttons: a registry entry with no
            // control is dead weight.
            'details'      => 'pen-to-square',
            // Swap this picture for another. `repeat` is the two-arrow cycle,
            // which is the exchange; `arrow-right-arrow-left` was the other
            // candidate and reads as "move between two places".
            'replace'      => 'repeat',
            'remove'       => 'trash-can',
        ];
    }

    /**
     * The `<template>` the script clones from.
     *
     * Rendered by whichever screen links `editor.js`. A template's contents are
     * inert — not rendered, not fetched, no layout — so this costs a page with
     * no editor on it nothing, and a page with three editors renders it once.
     */
    public static function iconTemplate(): string
    {
        $out = '<template data-editor-icons>';
        foreach (self::glyphs() as $key => $slug) {
            $out .= '<span data-icon="' . View::escape($key) . '">'
                . Icons::render($slug, 'icon icon--sm')
                . '</span>';
        }

        return $out . '</template>';
    }
}
