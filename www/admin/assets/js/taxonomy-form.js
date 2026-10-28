/**
 * taxonomy-form.js — derive the singular label and the key from the plural,
 * on the CREATE screen only.
 *
 * Emitted by the screen, inside $pageContent, which the layout prints above its
 * own scripts — so `CMS` (admin.js, in <head>) is already there.
 *
 * ── WHY THIS IS NOT LOADED ON EDIT ──────────────────────────────────────────
 * The template only emits the <script> when $isNew. On an existing taxonomy the
 * key box does not exist at all — the key is rendered as a fact, because every
 * `term_relationships` row and every taxonomy field's `taxonomy_key` setting is
 * filed under it. And the singular must never be re-derived from the plural
 * there: someone renaming "Categories" to "Topics" is renaming a label, and
 * having their hand-written singular silently overwritten is the kind of edit
 * that gets noticed a week later, in public.
 *
 * Loading it and letting it no-op would work today and stop working the first
 * time the edit screen grows a key box for some other reason. The condition
 * belongs where the fact is — the template knows whether it rendered one.
 *
 * ── AND WHY DERIVATION STOPS AT THE FIRST KEYSTROKE ─────────────────────────
 * Both derived boxes are real inputs the author can type into. The moment one is
 * touched it is theirs: "People" is not the plural of "Person" by any rule worth
 * writing, and a helper that keeps stamping "persons" over a corrected value is
 * worse than no helper. `data-touched` is set on the first `input` event and
 * never cleared.
 *
 * A pre-filled box counts as touched at load, which is what makes a failed
 * submit safe — the form comes back wearing what the author typed, and nothing
 * here overwrites it.
 *
 * ES5, no build step, no dependency. Same as every other file in here.
 */
(function () {
    'use strict';

    var doc = document;

    var plural   = doc.querySelector('[data-tax-plural]');
    var singular = doc.querySelector('[data-tax-singular]');
    var key      = doc.querySelector('[data-tax-key]');

    if (!plural) { return; }

    /* THE SAME TRANSFORM PHP APPLIES, and it is a PREVIEW of the server's rule
       rather than a second rule: TaxonomyController derives the admin slug from
       the key the same way when the box is left empty. If these two ever
       disagree the visible one is wrong, because the server's is the one that
       gets stored. */
    function toKey(text) {
        return text
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .slice(0, 50);
    }

    /* NAIVE ON PURPOSE, and only for the common case. English plurals are not a
       function, and a table of irregulars in a CMS admin is a table that is
       wrong for somebody's content in a language nobody tested. Strip a trailing
       "s", handle "-ies", and stop — the author corrects it in the box, and the
       box then belongs to them. */
    function toSingular(text) {
        var t = text.trim();
        if (/ies$/i.test(t))            { return t.slice(0, -3) + 'y'; }
        if (/(ch|sh|ss|x|z)es$/i.test(t)) { return t.slice(0, -2); }
        if (/s$/i.test(t) && !/ss$/i.test(t)) { return t.slice(0, -1); }
        return t;
    }

    function guard(input) {
        if (!input) { return; }
        if (input.value !== '') { input.dataset.touched = '1'; }
        input.addEventListener('input', function () { input.dataset.touched = '1'; });
    }

    guard(singular);
    guard(key);

    plural.addEventListener('input', function () {
        if (singular && singular.dataset.touched !== '1') {
            singular.value = toSingular(plural.value);
        }
        /* The key is derived from the SINGULAR, not the plural: `category`,
           not `categories`. It names one thing, the way `event` and `news` do,
           and FieldRenderer stores it on a field as `taxonomy_key`. */
        if (key && key.dataset.touched !== '1') {
            key.value = toKey(toSingular(plural.value));
        }
    });
}());
