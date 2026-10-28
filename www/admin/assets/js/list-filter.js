/**
 * list-filter.js — the instant `.filterbar`.
 *
 * Authored here. There is no dependency and there will not be one: a filter over
 * rows already in the document is a `for` loop and a `hidden` attribute, and the
 * moment a third-party script is loaded into an authenticated admin it executes
 * with the privileges of whoever is signed in.
 *
 * ── THE CONTRACT core.css §12 SETS ─────────────────────────────────────────
 * There are TWO filter idioms and the difference must be VISIBLE:
 *
 *   .filterbar        instant, client-side. Unlabeled controls, NO Apply
 *                     button, and it is NEVER a <form>.
 *   .filterbar--apply server round-trip. Labeled controls, an Apply button,
 *                     and it IS a form.
 *
 * The Apply button's presence or absence IS the "does this reload the page?"
 * signal, which is why this file drives the first kind only and why the markup
 * it binds to is a plain <div>. A hybrid of the two is never right.
 *
 * ── MARKUP ─────────────────────────────────────────────────────────────────
 *   <div class="filterbar" data-list-filter="#news-list">
 *     <input data-filter-text>                        free text over row text
 *     <select data-filter-attr="data-status">         match one row attribute
 *     <a data-filter-reset>                           shown only while filtering
 *   </div>
 *   <div id="news-list" class="list">
 *     <div class="list__row" data-status="published" data-text="…">…</div>
 *   </div>
 *   <div data-filter-empty hidden>…</div>            the FILTERED-empty state
 *
 * `data-text` is supplied by the server rather than read out of the DOM: the
 * row's visible text also contains its status label and its author, so matching
 * on textContent quietly makes "published" a search term that hits every
 * published row. The server knows which fields are actually the entry's
 * identity; the DOM does not.
 *
 * ── AND WHY THE EMPTY STATE IS A SEPARATE ELEMENT ──────────────────────────
 * "Your filter found nothing" and "there is nothing here" are DIFFERENT FACTS
 * and get different glyphs — the magnifying glass and the kind-of-place. Reusing
 * one empty state for both would tell a user with 400 entries that they have
 * none.
 *
 * ES5 on purpose, to match the rest of the admin JavaScript. No build step.
 */
(function () {
    'use strict';

    /** Rows of one list, plus the controls pointed at it. */
    function bind(bar) {
        var list = document.querySelector(bar.getAttribute('data-list-filter'));
        if (!list) { return; }

        var rows  = Array.prototype.slice.call(list.querySelectorAll('.list__row'));
        var text  = bar.querySelector('[data-filter-text]');
        var attrs = Array.prototype.slice.call(bar.querySelectorAll('[data-filter-attr]'));
        var reset = bar.querySelector('[data-filter-reset]');
        var empty = document.querySelector('[data-filter-empty]');
        var count = bar.querySelector('[data-filter-count]');

        function apply() {
            var needle = text ? text.value.trim().toLowerCase() : '';
            var shown  = 0;

            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                var ok  = true;

                if (needle) {
                    var hay = (row.getAttribute('data-text') || '').toLowerCase();
                    ok = hay.indexOf(needle) !== -1;
                }

                for (var j = 0; ok && j < attrs.length; j++) {
                    var control = attrs[j];
                    var want    = control.value;
                    if (want) {
                        ok = row.getAttribute(control.getAttribute('data-filter-attr')) === want;
                    }
                }

                // `hidden` rather than a class: it is the platform's own "this is
                // not currently relevant", so it takes the row out of the
                // accessibility tree too. A row hidden with `display:none` from a
                // class is equally invisible to sighted users and equally present
                // to a screen reader, which is the worse half of the bargain.
                row.hidden = !ok;
                if (ok) { shown++; }
            }

            var filtering = !!needle || attrs.some(function (c) { return !!c.value; });

            // NO APPLY BUTTON, and no Reset either unless there is something to
            // reset — a permanently-lit Reset is a control that does nothing on
            // most page loads.
            if (reset) { reset.hidden = !filtering; }

            // The count is the honest report of what the filter did, and it only
            // appears once a filter is on: "4 of 4" on an untouched list is noise.
            if (count) {
                count.hidden = !filtering;
                count.textContent = shown + ' of ' + rows.length;
            }

            // The list's own box is hidden when nothing survives, so the empty
            // state does not sit underneath an empty bordered rectangle.
            list.hidden = filtering && shown === 0;
            if (empty) { empty.hidden = !(filtering && shown === 0); }
        }

        function clear() {
            if (text) { text.value = ''; }
            attrs.forEach(function (c) { c.selectedIndex = 0; });
            apply();
            if (text) { text.focus(); }
        }

        // INSTANT. Every control applies on the keystroke or the change, never on
        // a button — see the contract above. `input` covers typing, pasting and
        // the search field's own clear affordance, which `keyup` does not.
        if (text)  { text.addEventListener('input', apply); }
        attrs.forEach(function (c) { c.addEventListener('change', apply); });
        if (reset) {
            reset.addEventListener('click', function (ev) { ev.preventDefault(); clear(); });
        }

        // Escape clears, but only from inside the bar — a global Escape handler
        // would fight every dialog and drawer on the page.
        bar.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') { ev.preventDefault(); clear(); }
        });

        apply();
    }

    function init() {
        var bars = document.querySelectorAll('[data-list-filter]');
        for (var i = 0; i < bars.length; i++) { bind(bars[i]); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
