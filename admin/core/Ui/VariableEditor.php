<?php

declare(strict_types=1);

namespace Cms\Ui;

use Cms\SEO\Head;

/**
 * THE PILL EDITOR'S TWO PIECES OF MARKUP — for `variable-editor.js`.
 *
 * SEO titles and descriptions are edited as pills: each variable is one pill
 * wearing its label, inserted from "Insert variable" or by typing `%`, dragged
 * to move it. The script carries the reasoning; this is only what the server
 * has to hand it.
 *
 * ── THE FIELD STAYS A FIELD ─────────────────────────────────────────────────
 * A template marks an ordinary `.field` — its label, its `<input>` or
 * `<textarea>` holding the stored text, its hint — with `attrs()`. The script
 * hides the control and draws the pills beside it, and the control is still
 * what posts. With JavaScript off the owner edits `{title} {sep} {site_name}`
 * as text, and it saves the same.
 *
 * ── THE VARIABLES ARE THE CONTEXT'S ─────────────────────────────────────────
 * `Head::variablesFor()` — only what that context's controller supplies, so
 * the list never offers a pill that would print nothing.
 *
 * ── ONE TEMPLATE PER PAGE ───────────────────────────────────────────────────
 * The pill is a `.chip` (the house's picked-value-you-can-remove) with the
 * house × — the chip-picker's markup, so a pill and a tag chip are the same
 * object. Its SVG comes from `Icons::render()` into a `<template>` the script
 * clones, as `Editor::iconTemplate()` hands the toolbar its glyphs: one icon
 * source, no path data copied into JavaScript.
 */
final class VariableEditor
{
    /**
     * The attributes that make a `.field` a pill editor for one context.
     *
     * EVERY VARIABLE, with the ones this context does not supply marked `off`.
     * The list offers only the rest; the labels of all of them are here so a
     * pill for one that does nothing in this box — `{page_num}` pasted into a
     * page's title — still reads "Page", struck through, rather than its
     * stored name.
     *
     * @param string $context a key of `Head::titlePatterns()`
     */
    public static function attrs(string $context): string
    {
        $on   = array_column(Head::variablesFor($context), 'name');
        $vars = [];
        foreach (Head::variables() as $name => $variable) {
            $vars[] = ['name' => $name, 'label' => $variable['label']]
                + (in_array($name, $on, true) ? [] : ['off' => true]);
        }
        $json = json_encode($vars, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return 'data-var-editor data-vars="' . View::escape((string) $json) . '"';
    }

    /**
     * The pill and the "Insert variable" glyph, once per page, for the script
     * to clone. Written without whitespace between the tags: a text node
     * inside a pill would be a character the caret could reach.
     */
    public static function template(): string
    {
        return '<template data-var-template>'
            . '<span class="chip var-editor__chip" contenteditable="false" spellcheck="false" data-var="">'
            . '<span class="chip__label"></span>'
            . '<button type="button" class="chip__remove" tabindex="-1" data-var-remove aria-label="Remove">'
            . Icons::render('x', 'icon icon--xs')
            . '</button>'
            . '</span>'
            . '<span data-var-icon="plus">' . Icons::render('plus', 'icon icon--sm') . '</span>'
            . '</template>';
    }
}
