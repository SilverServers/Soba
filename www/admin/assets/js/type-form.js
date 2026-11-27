/**
 * type-form.js — the Content Type form's four behaviours.
 *
 *   1. [data-icon-select]    swap the previewed glyph when the picker changes
 *   2. [data-type-plural]    suggest the key from the plural label, on CREATE only
 *   3. [data-archetype]      show an archetype's starter fields while it is ticked
 *   4. [data-type-public]    fill the Public URL from the plural label, on CREATE
 *                            only: a type has pages unless that box is cleared
 *   5. [data-archetype-alone] a kind with no page (an announcement): while it is
 *                            ticked, the Public URL box gives way to that fact
 *
 * ── WHY THE PREVIEW SWAPS REAL MARKUP ───────────────────────────────────────
 * The server renders every candidate glyph into a `<template data-icon-set>`,
 * and this file MOVES one out of it. It does not build an `<svg>` in
 * JavaScript. `Ui\Icons` reads the geometry off disk and is the single source
 * for it; a second copy written here would be a registry that goes stale
 * silently — a missing glyph renders nothing at all, which looks exactly like
 * "this option has no icon" rather than like a bug.
 *
 * ── AND WHY THE KEY SUGGESTION STOPS THE MOMENT YOU TYPE ────────────────────
 * `posts.post_type` stores the key on every entry, so it is fixed after
 * creation. Helping with the first draft is worth it; overwriting a key the
 * admin has deliberately chosen, because they went back and fixed a typo in the
 * label, is not.
 *
 * ES5, no build step, no dependency.
 */
(function () {
    'use strict';

    var doc = document;

    /* ═══ 1. THE GLYPH PREVIEW ════════════════════════════════════════════ */
    var select  = doc.querySelector('[data-icon-select]');
    var preview = doc.querySelector('[data-icon-preview]');
    var set     = doc.querySelector('[data-icon-set]');

    if (select && preview && set) {
        select.addEventListener('change', function () {
            var source = set.content.querySelector('[data-icon-for="' + select.value + '"]');
            if (!source) { return; }

            preview.innerHTML = '';
            // A CLONE, so the template keeps its copy and the preview can be
            // changed back and forth without emptying the set.
            preview.appendChild(source.firstElementChild.cloneNode(true));
        });
    }

    /* ═══ 2. THE KEY SUGGESTION ═══════════════════════════════════════════ */
    var plural = doc.querySelector('[data-type-plural]');
    var key    = doc.querySelector('[data-type-key]');

    if (plural && key) {
        // Present only on CREATE — the edit screen renders the key as a fact,
        // not an input, so there is nothing here to suggest into.
        var touched = key.value !== '';

        key.addEventListener('input', function () { touched = true; });

        plural.addEventListener('input', function () {
            if (touched) { return; }

            // The controller's rule, matched: start with a letter, then
            // lowercase letters, digits and underscores, max 50.
            var suggestion = plural.value
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '_')
                .replace(/^[^a-z]+/, '')
                .replace(/_+$/, '');

            // ── TRIM AT A WORD BOUNDARY, NOT MID-WORD ────────────────────────
            // A bare `.slice(0, 20)` turned "Student Caucus Bodies" into
            // `student_caucus_bodie` — a key ending in a chopped word, which is
            // then IMMUTABLE (entries are stored against it, so the edit screen
            // renders it as a fact rather than a control) and appears in nothing
            // the owner reads afterwards.
            //
            // Cutting back to the last underscore gives a name rather than a
            // truncation. If the FIRST word is already over the limit there is
            // no boundary to find and the hard cut is all that is left — better
            // a chopped key than none.
            //
            // THE LIMIT IS 50, not 20: every column that holds a type key is
            // varchar(50), so a 20-character cap would be a constraint this
            // system does not have. "Student Caucus Bodies"
            // derives `student_caucus_bodies` and this branch never runs for it.
            // The branch stays because a long enough name still reaches 50.
            if (suggestion.length > 50) {
                var cut = suggestion.slice(0, 50);
                var at  = cut.lastIndexOf('_');
                suggestion = at > 0 ? cut.slice(0, at) : cut;
            }

            key.value = suggestion;
        });
    }

    /* ═══ 4. THE PUBLIC URL, FROM THE PLURAL NAME ════════════════════════ */
    /* A BLANK PUBLIC URL MEANS NO PAGES, so a new type's box is filled as the
       plural name is typed, by the rule the server uses for the admin URL
       (lower case, a dash for each run of anything else). It stops the moment
       the box itself is typed into: emptying it is how a type is made with no
       page for its entries. */
    var publicBox = doc.querySelector('[data-type-public]');

    if (plural && publicBox) {
        var publicTouched = publicBox.value !== '';

        publicBox.addEventListener('input', function () { publicTouched = true; });

        plural.addEventListener('input', function () {
            if (publicTouched) { return; }
            publicBox.value = plural.value
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        });
    }

    /* ═══ 3. AN ARCHETYPE'S STARTER FIELDS ════════════════════════════════ */
    /* The list under each archetype follows its box. Ticking Event opens the
       four fields it brings so you can decline any of them before saving;
       unticking closes them again.

       IT ONLY EVER TOGGLES `hidden`. The inputs stay in the form either way, so
       a closed list submits exactly what it shows — and with this file absent
       every list is open, which is the state that works. Hiding by removing the
       inputs would make the no-JS case silently seed nothing. */
    var boxes = doc.querySelectorAll('[data-archetype]');

    for (var i = 0; i < boxes.length; i++) {
        (function (box) {
            var seed = doc.querySelector(
                '[data-archetype-seed="' + box.value + '"]'
            );

            box.addEventListener('change', function () {
                if (seed) { seed.hidden = !box.checked; }
                if (box.checked) { bring(box); }
                pages();
            });
        }(boxes[i]));
    }

    /* ═══ 5. A KIND WITH NO PAGE ══════════════════════════════════════════ */
    /* An announcement shows on the pages it names and has none of its own, so
       its type has no Public URL and no archive: the server stores no address
       for it, and while it is ticked the box gives way to the sentence that
       says so, and the Archive panel steps aside. */
    function pages() {
        var field   = doc.querySelector('[data-type-public-field]');
        var fact    = doc.querySelector('[data-type-pageless]');
        var archive = doc.querySelector('[data-type-archive]');
        if (!field || !fact) { return; }
        var alone = doc.querySelectorAll('[data-archetype-alone]');
        var none  = false;
        for (var k = 0; k < alone.length; k++) {
            if (alone[k].checked) { none = true; }
        }
        field.hidden = none;
        fact.hidden  = !none;
        if (archive) { archive.hidden = none; }
    }

    /* AND WHAT IT BRINGS TO THE ENTRY FORM. A product has a photo, so ticking
       Product ticks Featured image under Entry form, where the owner sees it
       and can untick it. Unticking the archetype leaves those boxes alone:
       they may have been ticked for their own sake. */
    function bring(box) {
        var parts = (box.getAttribute('data-archetype-supports') || '').split(' ');
        for (var j = 0; j < parts.length; j++) {
            if (!parts[j]) { continue; }
            var part = doc.querySelector('input[name="supports[' + parts[j] + ']"]');
            if (part) { part.checked = true; }
        }
    }
}());
