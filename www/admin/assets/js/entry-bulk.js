/**
 * entry-bulk.js — bulk actions over a ticked list: the entry list, its trash,
 * and a taxonomy's terms.
 *
 * Authored here, no dependency.
 *
 * ── WHAT IT DOES, AND WHAT IT LEAVES TO THE SERVER ─────────────────────────
 * The tick boxes, the select-all and the bulk form are plain HTML: without this
 * file a ticked selection still posts to `/admin/{type}/bulk` and the server
 * decides everything. This adds what a form cannot do by itself:
 *
 *   select-all      the heading's box ticks the page, and shows "some" as its
 *                   own third state rather than lying with a tick or a blank
 *   Apply           enabled only with an action AND a selection — a button that
 *                   can only reload the page is a dead control with a label
 *   bulk edit       Edit + Apply opens the panel instead of posting, and fills
 *                   it from the ticked rows: their titles, and each term's three
 *                   states (below)
 *   the question    Move to trash and Delete ask first, through confirm.js — the
 *                   single row's verbs already do, and a bulk one that did not
 *                   would be the one place twenty entries go without a word
 *
 * ── THE THREE STATES ───────────────────────────────────────────────────────
 * For each term, across the ticked rows: on every one → ticked; on none →
 * clear; on some → the dash (`indeterminate`). Submitting sends the ticked ones
 * as `on[]`, and every box STILL showing the dash as `keep[]`. The server
 * keeps what each entry had among `keep`, adds `on` and drops the rest. So an
 * untouched dash leaves every entry as it was, clicking it turns it into a tick
 * for all, and clearing a tick removes it from all.
 *
 * A taxonomy that is never touched is not sent as changed, and the server does
 * not rewrite it at all.
 *
 * ES5 on purpose, to match the rest of the admin JavaScript. No build step.
 */
(function () {
    'use strict';

    function each(list, fn) { Array.prototype.forEach.call(list, fn); }

    function bind(form) {
        var id      = form.id;
        var action  = form.querySelector('[data-bulk-action]');
        var apply   = form.querySelector('[data-bulk-apply]');
        var panel   = form.querySelector('[data-bulk-edit]');
        var one     = form.getAttribute('data-label-one') || 'entry';
        var many    = form.getAttribute('data-label-many') || 'entries';
        var all     = document.querySelector('[data-bulk-all]');

        // The rows' boxes join this form by `form=`, from outside it.
        //
        // ONLY THE ROWS ON SHOW. A list filtered in the browser
        // (`list-filter.js`) hides rows rather than dropping them, and select
        // all on a view of Processing orders must not reach the Pending ones
        // it is hiding. A row the server left out is not in the page at all.
        function rowOf(el) {
            while (el && !(el.classList && el.classList.contains('list__row'))) { el = el.parentNode; }
            return el;
        }
        function shown(box) { var row = rowOf(box); return !row || !row.hidden; }
        function every() { return document.querySelectorAll('input[data-bulk-row][form="' + id + '"]'); }
        function boxes() { return Array.prototype.filter.call(every(), shown); }
        function ticked() {
            return Array.prototype.filter.call(boxes(), function (b) { return b.checked; });
        }
        function noun(n) { return n + ' ' + (n === 1 ? one : many); }

        // AN ACTION THAT NEEDS A TARGET — Merge into… on the terms screen —
        // shows its target select only when chosen, and is not ready until a
        // target is: an Apply that could only be refused is a dead control.
        var into = form.querySelector('[data-bulk-into]');
        function needsInto() {
            var opt = action && action.options[action.selectedIndex];
            return !!(opt && opt.hasAttribute('data-needs-into'));
        }
        function intoName() {
            if (!into || into.selectedIndex < 1) { return ''; }
            // The option's text carries the tree's "— " indent; the name is
            // what is left.
            return '“' + into.options[into.selectedIndex].text.replace(/^(—\s)+/, '') + '”';
        }

        function refresh() {
            var n     = ticked().length;
            var total = boxes().length;

            if (all) {
                all.checked       = total > 0 && n === total;
                all.indeterminate = n > 0 && n < total;
            }
            if (into) { into.hidden = !needsInto(); }
            if (apply) {
                apply.disabled = n === 0 || !action || action.value === ''
                    || (needsInto() && (!into || into.value === ''));
            }

            // An open panel follows the selection: its chips, and the states of
            // every taxonomy nobody has touched yet — a touched one keeps what
            // the owner chose.
            if (panel && !panel.hidden) {
                if (n === 0) { close(); return; }
                fill();
            }
        }

        // ── THE PANEL ─────────────────────────────────────────────────────
        function fill() {
            var rows  = ticked();
            var count = panel.querySelector('[data-bulk-count]');
            var list  = panel.querySelector('[data-bulk-titles]');
            var proto = panel.querySelector('template[data-bulk-chip]');

            if (count) { count.textContent = noun(rows.length); }

            if (list && proto) {
                list.innerHTML = '';
                rows.forEach(function (box) {
                    var chip  = proto.content.firstElementChild.cloneNode(true);
                    var title = box.getAttribute('data-title') || '';
                    chip.querySelector('.chip__label').textContent = title;
                    var x = chip.querySelector('.chip__remove');
                    x.setAttribute('aria-label', 'Leave “' + title + '” out');
                    x.addEventListener('click', function () {
                        box.checked = false;
                        refresh();
                    });
                    list.appendChild(chip);
                });
            }

            // Which terms each ticked row is filed under, read from the row.
            var filed = rows.map(function (box) {
                var row = box.closest('.list__row');
                try { return JSON.parse(row.getAttribute('data-bulk-terms') || '{}'); }
                catch (e) { return {}; }
            });

            each(panel.querySelectorAll('[data-bulk-tax][data-bulk-mode="many"]'), function (group) {
                if (changed(group)) { return; }
                var key = group.getAttribute('data-bulk-tax');
                each(group.querySelectorAll('input[type=checkbox]'), function (input) {
                    var term = parseInt(input.value, 10);
                    var have = filed.filter(function (f) {
                        return (f[key] || []).indexOf(term) !== -1;
                    }).length;
                    input.checked       = have > 0 && have === filed.length;
                    input.indeterminate = have > 0 && have < filed.length;
                });
            });
        }

        function changed(group) {
            var marker = group.querySelector('[data-bulk-changed]');
            return !!(marker && marker.value);
        }

        // UPDATE IS READY WHEN THERE IS SOMETHING TO UPDATE — a term touched,
        // a single choice made, an author or a status picked. Before that it
        // could only reload the page having done nothing: a dead control with
        // the screen's most important word on it.
        var update = panel ? panel.querySelector('[data-bulk-update]') : null;
        function readiness() {
            if (!update) { return; }
            var any = false;
            each(panel.querySelectorAll('[data-bulk-tax]'), function (group) {
                if (group.getAttribute('data-bulk-mode') === 'one') {
                    var r = group.querySelector('input[type=radio]:checked');
                    if (r && r.value !== '') { any = true; }
                } else if (changed(group)) {
                    any = true;
                }
            });
            each(panel.querySelectorAll('select'), function (s) { if (s.value !== '') { any = true; } });
            update.disabled = !any;
        }

        function open() {
            panel.hidden = false;
            fill();
            readiness();
            panel.scrollIntoView({ block: 'nearest' });
        }

        function close() {
            panel.hidden = true;
            // Back to "no change" everywhere, so the next opening starts from
            // the rows rather than from a half-made edit.
            each(panel.querySelectorAll('[data-bulk-changed]'), function (m) { m.value = ''; });
            each(panel.querySelectorAll('input[type=radio][value=""]'), function (r) { r.checked = true; });
            each(panel.querySelectorAll('select'), function (s) { s.value = ''; });
            each(panel.querySelectorAll('input[type=text]'), function (t) { t.value = ''; });
            if (action) { action.value = ''; }
            refresh();
        }

        // ── WIRING ────────────────────────────────────────────────────────
        if (all) {
            all.addEventListener('change', function () {
                each(boxes(), function (b) { b.checked = all.checked; });
                refresh();
            });
        }
        document.addEventListener('change', function (e) {
            if (e.target.matches && e.target.matches('input[data-bulk-row]')) { refresh(); }
        });
        if (action) { action.addEventListener('change', refresh); }
        if (into) { into.addEventListener('change', refresh); }

        if (panel) {
            // The first touch on a taxonomy marks it as one to write.
            each(panel.querySelectorAll('[data-bulk-tax]'), function (group) {
                group.addEventListener('change', function () {
                    var marker = group.querySelector('[data-bulk-changed]');
                    if (marker) { marker.value = '1'; }
                });
            });
            // After the marker above — a listener on the panel hears the
            // change once the taxonomy's own listener has marked it.
            panel.addEventListener('change', readiness);
            var cancel = panel.querySelector('[data-bulk-cancel]');
            if (cancel) { cancel.addEventListener('click', close); }
        }

        // THIS FORM'S OWN LISTENER RUNS BEFORE confirm.js's, which listens on
        // the document — so the question can be written here, per action and
        // per count, and confirm.js then asks it.
        // A TICK LEFT ON A ROW THE FILTER HAS SINCE HIDDEN is not sent: what
        // is acted on is what is ticked on the screen. Captured, so it runs
        // before the question below and before confirm.js.
        form.addEventListener('submit', function () {
            each(every(), function (b) { if (!shown(b)) { b.checked = false; } });
        }, true);

        form.addEventListener('submit', function (e) {
            var n    = ticked().length;
            var verb = action ? action.value : '';
            var fromPanel = e.submitter && e.submitter.hasAttribute('data-bulk-update');

            form.removeAttribute('data-confirm');
            form.removeAttribute('data-confirm-detail');
            form.removeAttribute('data-confirm-verb');

            if (n === 0) { e.preventDefault(); return; }

            if (fromPanel) {
                if (action) { action.value = 'edit'; }
                // Every box still showing the dash is "leave as it is".
                each(panel.querySelectorAll('[data-bulk-keep]'), function (k) { k.remove(); });
                each(panel.querySelectorAll('[data-bulk-tax][data-bulk-mode="many"]'), function (group) {
                    var key = group.getAttribute('data-bulk-tax');
                    each(group.querySelectorAll('input[type=checkbox]'), function (input) {
                        if (!input.indeterminate) { return; }
                        var keep = document.createElement('input');
                        keep.type  = 'hidden';
                        keep.name  = 'bulk_terms[' + key + '][keep][]';
                        keep.value = input.value;
                        keep.setAttribute('data-bulk-keep', '');
                        group.appendChild(keep);
                    });
                });
                return;
            }

            if (verb === 'edit') {
                e.preventDefault();
                open();
                return;
            }

            // THE QUESTION IS THE SCREEN'S, written on the form per action —
            // `data-ask-{action}`, `-detail`, `-verb` — so the entry list, the
            // trash and the terms screen each say what THEIR action does,
            // through one script. An action with no question posts at once.
            var ask = form.getAttribute('data-ask-' + verb);
            if (ask) {
                var fill = function (s) {
                    return (s || '')
                        // `{one|many}` — the words for one, or for several.
                        .replace(/\{([^|{}]*)\|([^{}]*)\}/g, function (_, a, b) { return n === 1 ? a : b; })
                        .replace(/\{n\}/g, noun(n))
                        .replace(/\{into\}/g, intoName());
                };
                form.setAttribute('data-confirm', fill(ask));
                form.setAttribute('data-confirm-detail', fill(form.getAttribute('data-ask-' + verb + '-detail')));
                form.setAttribute('data-confirm-verb', fill(form.getAttribute('data-ask-' + verb + '-verb')) || 'Delete');
            }
        });

        refresh();
    }

    function init() {
        each(document.querySelectorAll('form[data-entry-bulk]'), bind);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
