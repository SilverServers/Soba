<?php

declare(strict_types=1);

namespace Cms\Ui;

/**
 * FIELD WIDTH — the one list of widths, and the one place they turn into spans.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 * A field's width was the string `full` or `half`, and those two strings were
 * written out by hand in SIX places that all had to agree:
 *
 *   · the form builder's Field width select          admin/templates/forms/edit.php
 *   · the field group's Presentation Width select    field-groups/edit.php
 *   · FormController::parseFieldsJson()'s whitelist
 *   · FieldGroupController's settings whitelist
 *   · Shortcode::renderForm(), turning it into a class on the public page
 *   · FieldRenderer::renderField(), turning it into a class on the entry form
 *
 * Two selects offering a value, two whitelists deciding whether to store it,
 * two renderers deciding what it means. A control that offers something the
 * renderer ignores is the failure to guard against, and six hand-written
 * copies of one vocabulary is that failure with the safety catch off. Adding
 * 1/3 and 1/4 to two of the six and forgetting the others would have produced
 * a select offering Quarter width and a page rendering it full — silently, and
 * only on the surface nobody reopened.
 *
 * So the list lives here, once. A width nothing here names cannot be offered,
 * cannot be stored, and cannot be rendered.
 *
 * ── TWELVE COLUMNS, AND WHY NOT FOUR ────────────────────────────────────────
 * Fields come in halves, thirds and quarters as well as full width. Halves,
 * thirds and quarters have no common denominator below twelve, so a
 * four-column or six-column grid cannot express them all. Twelve is the
 * smallest that can, and it is why every grid system that offers thirds and
 * quarters uses it.
 *
 * ── THE ORDER IS WIDEST FIRST ───────────────────────────────────────────────
 * Both selects render `labels()` in this order, and it reads as a scale — Full,
 * Half, Third, Quarter — rather than as an alphabetised list where Full sits
 * between Third and Half.
 */
final class FieldWidth
{
    /** The default, and the value that is never stored — see `store()`. */
    public const DEFAULT = 'full';

    /**
     * Every width there is, widest first, mapped to its span of twelve.
     *
     * ── EVERY WIDTH HAS A COMPLEMENT, AND THAT IS THE POINT ─────────────────
     * Full, half, third and quarter alone cannot fill a row. Put a field at a
     * quarter and nothing completes the line beside it: a half leaves three
     * columns of hole, a third leaves five, and full wraps to the next row —
     * the field beside it is either too short, or too wide and drops a row.
     *
     * So each of the three fractions has its complement here:
     *
     *     quarter 3 + three-quarters 9 = 12
     *     third   4 + two-thirds     8 = 12
     *     half    6 + half           6 = 12
     *
     * That is the whole reason for the two additions, and it is the rule for
     * any future one: a width earns its place by completing a row, not by
     * being another fraction that exists.
     */
    public const SPANS = [
        'full'           => 12,
        'three-quarters' => 9,
        'two-thirds'     => 8,
        'half'           => 6,
        'third'          => 4,
        'quarter'        => 3,
    ];

    /** @return array<string, string> value => label, for a <select>. */
    public static function labels(): array
    {
        return [
            'full'           => 'Full width',
            'three-quarters' => 'Three quarters',
            'two-thirds'     => 'Two thirds',
            'half'           => 'Half width',
            'third'          => 'One third',
            'quarter'        => 'One quarter',
        ];
    }

    /** Anything unrecognised — an old row, a hand-edited JSON blob — is full. */
    public static function normalize(mixed $width): string
    {
        $width = is_string($width) ? $width : '';

        return isset(self::SPANS[$width]) ? $width : self::DEFAULT;
    }

    /** Columns of twelve. */
    public static function span(mixed $width): int
    {
        return self::SPANS[self::normalize($width)];
    }

    /**
     * The value to STORE, or null when it is the default.
     *
     * `full` is what every renderer assumes when the key is absent, so writing
     * it would make "this field has a width set" true of every field ever
     * saved — which is the reason FieldGroupController only stored `half`.
     * That reasoning is right and it belongs here rather than repeated at each
     * call site.
     */
    public static function store(mixed $width): ?string
    {
        $w = self::normalize($width);

        return $w === self::DEFAULT ? null : $w;
    }

    /**
     * The modifier a renderer appends, or '' for the default.
     *
     * Both renderers name their own block — `cms-form__field` on the public
     * page, `field` on the entry form — and take the same suffix, so the two
     * stylesheets express one idea with one word.
     */
    public static function modifier(mixed $width): string
    {
        $w = self::normalize($width);

        return $w === self::DEFAULT ? '' : '--' . $w;
    }
}
