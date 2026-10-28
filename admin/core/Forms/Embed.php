<?php

declare(strict_types=1);

namespace Cms\Forms;

use Cms\Models\Form;

/**
 * ONE FORM'S FIELDS, INSIDE ANOTHER.
 *
 * A block of questions reused across five forms — a full address, a set of
 * consent questions — is maintained once and appears everywhere, instead of
 * being copied five times and then diverging.
 *
 * ── IT EXPANDS BEFORE ANYONE LOOKS AT THE LIST ──────────────────────────────
 * `Shortcode::renderForm()` and `Controllers\Public\FormController::submit()`
 * both run this first, so both see ONE flat list of fields and neither knows
 * an embed happened. That is not tidiness, it is the whole correctness
 * argument: expanding only in the renderer would draw the embedded fields on
 * the page while the validator had never heard of them — so they would not be
 * required, not validated, and not stored. A form that collects answers and
 * silently discards them is the worst outcome available here, and it is the
 * one you get by teaching only the visible half.
 *
 * ── CYCLES ARE CUT, NOT DETECTED-AND-FATALLED ───────────────────────────────
 * A form that embeds itself, or two that embed each other, would recurse until
 * PHP gives up — a 500 on a public page, caused by two admin screens that each
 * looked reasonable. The seen-list stops it and the offending embed simply
 * renders nothing, because a page that loads with one block missing is better
 * than a page that does not load.
 */
final class Embed
{
    /** How deep an embed chain may go before it is simply cut. */
    private const MAX_DEPTH = 3;

    /**
     * Replace every `embed` field with the fields of the form it names.
     *
     * @param  list<array<string, mixed>> $fields
     * @param  list<int>                  $seen    form ids already expanded
     * @return list<array<string, mixed>>
     */
    public static function expand(array $fields, array $seen = [], int $depth = 0): array
    {
        $out = [];

        foreach ($fields as $field) {
            if (($field['type'] ?? '') !== 'embed') {
                $out[] = $field;
                continue;
            }

            $id = (int) ($field['embed_form'] ?? 0);

            // A form cannot contain itself, at any remove, and the chain has a
            // floor. Both conditions drop the embed rather than raising: the
            // page still loads, one block short.
            if ($id <= 0 || in_array($id, $seen, true) || $depth >= self::MAX_DEPTH) {
                continue;
            }

            $inner = Form::find($id);
            if ($inner === null) {
                continue;
            }

            $nested = is_array($inner['fields'] ?? null) ? $inner['fields'] : [];
            foreach (self::expand($nested, array_merge($seen, [$id]), $depth + 1) as $one) {
                $out[] = $one;
            }
        }

        // ── NO TWO FIELDS MAY SHARE A NAME ──────────────────────────────────
        // The builder guarantees this WITHIN one form and cannot across two:
        // a host with an `email` field embedding a block that also has one
        // produces two, and the second silently overwrites the first in every
        // submission — "a data loss with no error", in the builder's own words
        // about the same hazard.
        //
        // A self-embed does it too. `expand()` cuts the cycle but the one
        // level it did expand duplicates every name in the form.
        //
        // THE FIRST WINS, because it is the one the author can see: it is on
        // the form they are editing, above the embed. Dropping the later
        // duplicate loses a question; keeping both loses an ANSWER, silently,
        // and only for the visitors who filled in the one that got overwritten.
        if ($depth === 0) {
            $seenNames = [];
            $unique    = [];
            foreach ($out as $one) {
                $name = (string) ($one['name'] ?? '');
                // A field that collects nothing has no name to collide.
                if ($name !== '' && FieldTypes::collects((string) ($one['type'] ?? 'text'))) {
                    if (isset($seenNames[$name])) {
                        continue;
                    }
                    $seenNames[$name] = true;
                }
                $unique[] = $one;
            }
            return $unique;
        }

        return $out;
    }

    /** @return array<int, string> id => title, for the rail's select. */
    public static function options(int $exceptId = 0): array
    {
        $out = [];
        foreach (Form::all() as $form) {
            $id = (int) $form['id'];
            // NOT ITSELF. Offering a form the chance to embed itself invites
            // the cycle `expand()` then has to cut — better to not offer it.
            if ($id === $exceptId) {
                continue;
            }
            $out[$id] = (string) $form['title'];
        }

        return $out;
    }
}
