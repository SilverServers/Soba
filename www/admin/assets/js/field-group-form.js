/**
 * field-group-form.js — the behaviour the field group editor needs.
 *
 * Emitted by the SCREEN, inside $pageContent, which the layout prints above its
 * own scripts — so `CMS` (admin.js, in <head>) is already there.
 *
 * Four components, ALL DELEGATED FROM THE DOCUMENT, so a row cloned after load
 * behaves identically to one the server rendered. That is not a style
 * preference: a field editor's whole job is producing rows that did not exist at
 * load, and per-element listeners bound at startup make the newest row the one
 * that quietly does not work.
 *
 *   1. [data-field-type]    reveal the settings panel for the chosen type, and
 *                           DISABLE every panel it hides
 *   2. [data-field-label]   derive the key until someone edits the key
 *   3. [data-field-add]     clone a row from the <template> the SERVER rendered
 *   4. reindex              rebuild every `name` from the row's DOM position
 *
 * ── WHY THE PANELS ARE DISABLED AND NOT REMOVED ─────────────────────────────
 * A hidden input still POSTs. Leave the Choices textarea enabled after switching
 * a field from Choice to Number and every save writes the stale options list
 * back into the database, where nothing displays it and nothing can clear it.
 * The entry form's password box has the identical rule for the identical reason.
 *
 * Disabling rather than deleting is what makes the switch reversible: change the
 * type back and the options are still there, because they never left the DOM.
 *
 * ── AND WHY THE ROW MARKUP IS NOT IN THIS FILE ──────────────────────────────
 * The <template> is rendered by the same PHP function that renders the stored
 * rows. A row built here from a string literal would be a second copy of the
 * form, and the first setting added to a field type would reach existing rows
 * and not new ones — a difference nobody sees until someone adds a field.
 *
 * ES5, no build step, no dependency. Same as every other file in here.
 */
(function () {
    'use strict';

    var doc = document;

    function closest(el, selector) {
        while (el && el.nodeType === 1) {
            if (el.matches(selector)) { return el; }
            el = el.parentElement;
        }
        return null;
    }

    /* ═══ 1. TYPE → SETTINGS PANEL ════════════════════════════════════════
       `data-for` is a SPACE-SEPARATED list, because two types share the rows
       panel. Matched with padded indexOf rather than a split-and-loop so the
       check is one expression and cannot half-match `text` against `rich_text`. */
    function syncPanels(scope) {
        var select = scope.querySelector('[data-field-type]');
        if (!select) { return; }

        var type   = select.value;
        var panels = scope.querySelectorAll('[data-for]');

        for (var i = 0; i < panels.length; i++) {
            var panel = panels[i];

            /* A nested subfield row's panels are inside `scope` too when scope is
               the repeater's own row. Skip anything that belongs to a DIFFERENT
               row — without this, choosing Number on a repeater's column would
               also unhide the parent field's Number panel. */
            if (closest(panel.parentElement, '[data-field-row], [data-subfield-row]') !== scope) {
                continue;
            }

            var list = ' ' + panel.getAttribute('data-for') + ' ';
            var on   = list.indexOf(' ' + type + ' ') !== -1;

            panel.hidden = !on;

            /* THE DISABLE IS THE POINT — see the header. */
            var inputs = panel.querySelectorAll('input, select, textarea');
            for (var j = 0; j < inputs.length; j++) {
                inputs[j].disabled = !on;
            }
        }
    }

    function syncAll() {
        var rows = doc.querySelectorAll('[data-field-row], [data-subfield-row]');
        for (var i = 0; i < rows.length; i++) { syncPanels(rows[i]); }
    }

    /* ═══ 2. LABEL → KEY ══════════════════════════════════════════════════
       A PREVIEW OF WHAT THE SERVER WOULD DO, not a second rule: PHP's
       labelToKey() derives the same string when the box is left empty. It stops
       the moment the key box is touched, and it never fires on a row that
       ALREADY has a key — the key is what post_meta rows are stored under, so
       re-deriving it after someone renames a field would orphan every value
       already saved against it. Renaming "Start date" to "Starts" must not
       silently move the data to `starts`. */
    function labelToKey(label) {
        return label
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .slice(0, 50);
    }

    function bindKeyDerivation(row) {
        var label = row.querySelector('[data-field-label]');
        var key   = row.querySelector('[data-field-key]');
        if (!label || !key) { return; }

        if (key.value !== '') { key.dataset.touched = '1'; }

        key.addEventListener('input', function () { key.dataset.touched = '1'; });
        label.addEventListener('input', function () {
            if (key.dataset.touched === '1') { return; }
            key.value = labelToKey(label.value);
        });
    }

    function bindAll() {
        var rows = doc.querySelectorAll('[data-field-row], [data-subfield-row]');
        for (var i = 0; i < rows.length; i++) {
            if (rows[i].dataset.bound === '1') { continue; }
            rows[i].dataset.bound = '1';
            bindKeyDerivation(rows[i]);
        }
    }

    /* ═══ 3. REINDEX ══════════════════════════════════════════════════════
       Every `name` is rebuilt from the row's POSITION, so add, remove and
       reorder all reduce to the same operation and there is no bookkeeping to
       get out of step. `validateAndSanitize()` iterates VALUES, so a gap would
       be harmless — but two rows sharing an index is not, and that is what
       "append with a counter" produces after any removal.

       The rewrite is a substitution on the bracketed index only. A blanket
       replace of the whole prefix would also rewrite a sub-field's own
       `[settings][sub_fields][j]`, collapsing every column onto one. */
    function reindex() {
        var rows = doc.querySelectorAll('[data-fields] > [data-field-row]');

        for (var i = 0; i < rows.length; i++) {
            var subs = rows[i].querySelectorAll('[data-subfield-row]');

            for (var j = 0; j < subs.length; j++) {
                renameWithin(subs[j], i, j);
            }
            renameWithin(rows[i], i, null, true);
        }
    }

    /* Rewrite the field index — and, when `j` is given, the sub-field index —
       on every named control inside `el`. `outerOnly` skips controls that live
       in a sub-field row, because those were already handled with their own j. */
    function renameWithin(el, i, j, outerOnly) {
        var named = el.querySelectorAll('[name]');

        for (var k = 0; k < named.length; k++) {
            var input = named[k];

            if (outerOnly && closest(input.parentElement, '[data-subfield-row]')) { continue; }

            var name = input.getAttribute('name')
                .replace(/^fields\[[^\]]*\]/, 'fields[' + i + ']');

            if (j !== null && j !== undefined) {
                name = name.replace(/\[sub_fields\]\[[^\]]*\]/, '[sub_fields][' + j + ']');
            }

            input.setAttribute('name', name);
        }
    }

    /* ═══ 4. ADD AND REMOVE ═══════════════════════════════════════════════ */

    function addField() {
        var host = doc.querySelector('[data-fields]');
        var tpl  = doc.querySelector('[data-field-template]');
        if (!host || !tpl) { return; }

        host.appendChild(tpl.content.cloneNode(true));

        var empty = doc.querySelector('[data-fields-empty]');
        if (empty) { empty.remove(); }

        reindex();
        bindAll();
        syncAll();

        /* THE CARET GOES IN THE NEW ROW. A row that appears with the focus still
           on the button asks the author to aim at a box that was not there a
           moment ago. */
        var rows = host.querySelectorAll('[data-field-row]');
        var last = rows[rows.length - 1];
        if (last) {
            /* A FIELD YOU JUST ADDED IS ONE YOU ARE ABOUT TO FILL IN, so it
               opens; a group you just opened is one you want to see the shape
               of, so its rows stay shut. */
            setOpen(last, true);
            fillLogic(last);
            var label = last.querySelector('[data-field-label]');
            if (label) { label.focus(); }
        }
        paintNumbers();
    }

    function addSubField(button) {
        var panel = closest(button, '[data-for]');
        var host  = panel && panel.querySelector('[data-subfields]');
        var tpl   = doc.querySelector('[data-subfield-template]');
        if (!host || !tpl) { return; }

        host.appendChild(tpl.content.cloneNode(true));

        reindex();
        bindAll();
        syncAll();

        var rows = host.querySelectorAll('[data-subfield-row]');
        var last = rows[rows.length - 1];
        if (last) {
            var label = last.querySelector('[data-field-label]');
            if (label) { label.focus(); }
        }
    }

    /* ═══ DELEGATION ══════════════════════════════════════════════════════ */

    doc.addEventListener('click', function (e) {
        if (closest(e.target, '[data-field-add]'))    { addField(); return; }
        if (closest(e.target, '[data-subfield-add]')) { addSubField(closest(e.target, '[data-subfield-add]')); return; }

        var removeSub = closest(e.target, '[data-subfield-remove]');
        if (removeSub) {
            var subRow = closest(removeSub, '[data-subfield-row]');
            if (subRow) { subRow.remove(); reindex(); }
            return;
        }

        var remove = closest(e.target, '[data-field-remove]');
        if (remove) {
            var row = closest(remove, '[data-field-row]');
            if (row) { row.remove(); reindex(); paintNumbers(); }

            /* NO CONFIRM. Removing a row here does not delete anything — nothing
               is written until Save, and Cancel is right there. A dialog in front
               of a reversible, unsaved edit teaches people to dismiss dialogs. */
            return;
        }
    });

    doc.addEventListener('change', function (e) {
        var select = closest(e.target, '[data-field-type]');
        if (!select) { return; }
        syncPanels(closest(select, '[data-field-row], [data-subfield-row]'));
    });

    /* ═══ 5. THE SUMMARY BAR ══════════════════════════════════════════════
       A field is a ONE-LINE ROW that opens — `# | Label | Name | Type`. Every
       field drawn permanently open, as a three-input grid, would make a group of
       five fields a wall of identical boxes, with no way to see the group's
       shape and no way to find a field by name.

       THE BAR IS DERIVED, NEVER AUTHORITATIVE. It is written from the row's own
       inputs on every edit, so the collapsed view cannot disagree with what will
       be saved. There is one source for a field's label and it is the box in the
       General tab. */
    function paintBar(row) {
        var labelIn = row.querySelector('[data-field-label]');
        var keyIn   = row.querySelector('[data-field-key]');
        var typeIn  = row.querySelector('[data-field-type]');
        var reqIn   = row.querySelector('[data-field-required]');

        var labelOut = row.querySelector('[data-row-label]');
        var nameOut  = row.querySelector('[data-row-name]');
        var typeOut  = row.querySelector('[data-row-type]');
        var reqOut   = row.querySelector('[data-row-req]');
        var logicOut = row.querySelector('[data-row-logic]');

        if (labelOut && labelIn) {
            var text = labelIn.value.trim();
            labelOut.textContent = text || '(no label)';
            labelOut.classList.toggle('is-empty', text === '');
        }
        // THE KEY SHOWN IS THE ONE THAT WILL BE SAVED, derived included. Showing
        // the empty box would say "no name" about a field that is about to get
        // one from the server, which is the sort of half-truth this row exists
        // to remove.
        if (nameOut && keyIn) {
            var key = keyIn.value.trim() || (labelIn ? labelToKey(labelIn.value) : '');
            nameOut.textContent = key;
        }
        // The type LABEL comes from the selected <option>'s own text, so the
        // list of types lives in exactly one place — the server's <select> —
        // and adding one needs no change here.
        if (typeOut && typeIn) {
            var opt = typeIn.options[typeIn.selectedIndex];
            typeOut.textContent = opt ? opt.text.trim() : '';
        }
        // ITS GLYPH TOO, from the page's one set of them (`[data-type-glyphs]`):
        // a row added as Text and made a File said File beside Text's pen.
        var glyphOut = row.querySelector('[data-row-type-icon]');
        var glyphs   = doc.querySelector('[data-type-glyphs]');
        if (glyphOut && glyphs && typeIn) {
            var glyph = glyphs.content.querySelector('[data-glyph="' + typeIn.value + '"]');
            if (glyph && glyphOut.innerHTML !== glyph.innerHTML) { glyphOut.innerHTML = glyph.innerHTML; }
        }
        if (reqOut && reqIn) { reqOut.hidden = !reqIn.checked; }
        if (logicOut) {
            var json = row.querySelector('[data-logic-json]');
            logicOut.hidden = !(json && json.value !== '');
        }
    }

    /* The row NUMBERS are positional, so they are written in one pass over the
       list rather than by each row about itself — a row cannot know its own
       index, and one that guessed would be wrong the moment anything moved. */
    function paintNumbers() {
        var rows = doc.querySelectorAll('[data-fields] > [data-field-row]');
        for (var i = 0; i < rows.length; i++) {
            var num = rows[i].querySelector('[data-row-num]');
            if (num) { num.textContent = String(i + 1); }
            paintBar(rows[i]);
        }
        var head = doc.querySelector('[data-fields-head]');
        if (head) { head.hidden = rows.length === 0; }
    }

    function setOpen(row, open) {
        row.classList.toggle('is-collapsed', !open);
        var toggle = row.querySelector('[data-field-toggle]');
        if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    }

    /* ═══ 6. CONDITIONAL LOGIC ════════════════════════════════════════════
       Per row, and the whole rule set posts as ONE hidden input carrying JSON.
       A rule list is a nested array of unknown length; `settings[logic][rules]
       [0][op]` as a form field name is unreadable, undebuggable, and silently
       reindexed by PHP. `Cms\Forms\Logic::parse()` is the only thing that
       reads it, and it drops anything malformed rather than storing a rule that
       cannot evaluate.

       The OPERATOR LIST comes from `logic.js`, which mirrors the PHP constant —
       so this file never names an operator at all. */
    function logicTemplate() { return doc.querySelector('[data-logic-template]'); }

    /* Every field in this GROUP that could be tested: not this one, and it has a
       key. Testing a field against itself is a rule that can never settle. */
    function logicCandidates(row) {
        var out  = [];
        var rows = doc.querySelectorAll('[data-fields] > [data-field-row]');
        for (var i = 0; i < rows.length; i++) {
            if (rows[i] === row) { continue; }
            var keyIn   = rows[i].querySelector('[data-field-key]');
            var labelIn = rows[i].querySelector('[data-field-label]');
            var key = keyIn ? (keyIn.value.trim() ||
                      (labelIn ? labelToKey(labelIn.value) : '')) : '';
            if (key === '') { continue; }
            out.push({ key: key, label: (labelIn && labelIn.value.trim()) || key });
        }
        return out;
    }

    function addRuleRow(row, rule) {
        var tpl = logicTemplate();
        var box = row.querySelector('[data-logic-rules]');
        if (!tpl || !box) { return; }

        var frag = tpl.content.cloneNode(true);
        var el   = frag.querySelector('[data-logic-rule]');
        var sel  = el.querySelector('[data-logic-field]');

        logicCandidates(row).forEach(function (c) {
            sel.appendChild(new Option(c.label, c.key));
        });
        if (rule && rule.field) {
            // A SAVED RULE WHOSE FIELD IS GONE IS KEPT AND MARKED, never silently
            // repointed at whatever happens to be first in the list.
            var known = Array.prototype.some.call(sel.options, function (o) {
                return o.value === rule.field;
            });
            if (!known) { sel.appendChild(new Option(rule.field + ' (no such field)', rule.field)); }
            sel.value = rule.field;
        }
        el.querySelector('[data-logic-op]').value    = (rule && rule.op)    || 'is';
        el.querySelector('[data-logic-value]').value = (rule && rule.value) || '';
        paintRule(el);

        box.appendChild(frag);
    }

    /* `is empty` and `is not empty` read nothing from the value box, so it goes
       away rather than collecting a string that is discarded. */
    function paintRule(ruleEl) {
        var op    = ruleEl.querySelector('[data-logic-op]').value;
        var unary = (window.Logic && window.Logic.UNARY) || ['empty', 'not_empty'];
        ruleEl.querySelector('[data-logic-value]').hidden = unary.indexOf(op) !== -1;
    }

    function fillLogic(row) {
        var json   = row.querySelector('[data-logic-json]');
        var enable = row.querySelector('[data-logic-enable]');
        var panel  = row.querySelector('[data-logic-panel]');
        var box    = row.querySelector('[data-logic-rules]');
        if (!json || !enable || !panel || !box) { return; }

        var logic = null;
        try { logic = json.value ? JSON.parse(json.value) : null; } catch (e) { logic = null; }

        box.textContent = '';
        ((logic && logic.rules) || []).forEach(function (r) { addRuleRow(row, r); });

        var none = row.querySelector('[data-logic-nofields]');
        if (none) { none.hidden = logicCandidates(row).length !== 0; }
    }

    function readLogic(row) {
        var json   = row.querySelector('[data-logic-json]');
        var enable = row.querySelector('[data-logic-enable]');
        if (!json || !enable) { return; }

        if (!enable.checked) { json.value = ''; paintBar(row); return; }

        var rules = [];
        row.querySelectorAll('[data-logic-rule]').forEach(function (el) {
            var field = el.querySelector('[data-logic-field]').value;
            if (!field) { return; }
            rules.push({
                field: field,
                op:    el.querySelector('[data-logic-op]').value,
                value: el.querySelector('[data-logic-value]').value
            });
        });

        // MATCHES Logic::parse() — an empty rule list is stored as no logic at
        // all, so "has logic?" stays a one-step check everywhere.
        json.value = rules.length ? JSON.stringify({
            action: row.querySelector('[data-logic-action]').value,
            match:  row.querySelector('[data-logic-match]').value,
            rules:  rules
        }) : '';
        paintBar(row);
    }

    /* ═══ 7. TABS ═════════════════════════════════════════════════════════ */

    function showTab(row, which) {
        row.querySelectorAll('[data-fgtab]').forEach(function (b) {
            var on = b.getAttribute('data-fgtab') === which;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        row.querySelectorAll('[data-fgpane]').forEach(function (p) {
            p.hidden = p.getAttribute('data-fgpane') !== which;
        });
    }

    /* ═══ 8. DELEGATION FOR THE NEW CHROME ════════════════════════════════ */

    doc.addEventListener('click', function (e) {
        var toggle = closest(e.target, '[data-field-toggle]');
        if (toggle) {
            var row = closest(toggle, '[data-field-row]');
            if (row) {
                var opening = row.classList.contains('is-collapsed');
                setOpen(row, opening);
                // The candidate list and the rule rows are rebuilt ON OPEN, not
                // at load: another field's key may have changed since, and a
                // dropdown offering a name that no longer exists is how a rule
                // ends up pointing at nothing.
                if (opening) { fillLogic(row); }
            }
            return;
        }

        var tab = closest(e.target, '[data-fgtab]');
        if (tab) {
            showTab(closest(tab, '[data-field-row]'), tab.getAttribute('data-fgtab'));
            return;
        }

        /* THE GROUP'S OWN TABS. Same chrome, different scope — these switch the
           Settings card at the foot of the page rather than a field's panes, so
           they carry their own attribute. Sharing `data-fgtab` would have made
           `closest(…, '[data-field-row]')` return null and the handler silently
           do nothing, which is the kind of dead control this screen is full of
           corrections for. */
        var gtab = closest(e.target, '[data-gtab]');
        if (gtab) {
            var which = gtab.getAttribute('data-gtab');
            var card  = closest(gtab, '.form-card');
            card.querySelectorAll('[data-gtab]').forEach(function (b) {
                var on = b === gtab;
                b.classList.toggle('is-active', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            card.querySelectorAll('[data-gpane]').forEach(function (pane) {
                pane.hidden = pane.getAttribute('data-gpane') !== which;
            });
            return;
        }

        var close = closest(e.target, '[data-field-close]');
        if (close) { setOpen(closest(close, '[data-field-row]'), false); return; }

        var closeAdd = closest(e.target, '[data-field-close-add]');
        if (closeAdd) {
            setOpen(closest(closeAdd, '[data-field-row]'), false);
            addField();
            return;
        }

        var addRule = closest(e.target, '[data-logic-add]');
        if (addRule) {
            var ruleRow = closest(addRule, '[data-field-row]');
            addRuleRow(ruleRow, null);
            readLogic(ruleRow);
            return;
        }

        var rmRule = closest(e.target, '[data-logic-remove]');
        if (rmRule) {
            var owner = closest(rmRule, '[data-field-row]');
            closest(rmRule, '[data-logic-rule]').remove();
            readLogic(owner);
            return;
        }
    });

    doc.addEventListener('input', function (e) {
        var row = closest(e.target, '[data-field-row]');
        if (!row) { return; }
        // A label edit changes what OTHER rows offer in their logic dropdowns,
        // so the numbers-and-bars pass runs over the whole list rather than this
        // row alone. It is a dozen text writes; the alternative is a rule naming
        // a label nobody uses any more.
        paintNumbers();
        if (closest(e.target, '[data-logic-rule]')) { readLogic(row); }
    });

    doc.addEventListener('change', function (e) {
        var row = closest(e.target, '[data-field-row]');
        if (!row) { return; }

        var enable = closest(e.target, '[data-logic-enable]');
        if (enable) {
            var panel = row.querySelector('[data-logic-panel]');
            if (panel) { panel.hidden = !enable.checked; }
            // Turning it on with no rules yet gives you one to fill in, rather
            // than an empty panel and a button to hunt for.
            if (enable.checked && !row.querySelector('[data-logic-rule]')) { addRuleRow(row, null); }
            readLogic(row);
            return;
        }

        var opSel = closest(e.target, '[data-logic-op]');
        if (opSel) { paintRule(closest(opSel, '[data-logic-rule]')); }

        if (closest(e.target, '[data-logic-rule]') ||
            closest(e.target, '[data-logic-action]') ||
            closest(e.target, '[data-logic-match]')) {
            readLogic(row);
            return;
        }

        paintNumbers();
    });

    /* ON LOAD. The server already rendered the right panel visible for each
       row's stored type — this pass exists for the DISABLED state, which markup
       cannot express without repeating the type test on every input, and for the
       geometry failure this project keeps producing: a value derived from state
       in two places drifts the moment one of them changes. One rule, in one
       place, run over both the server's rows and the cloned ones. */
    syncAll();
    bindAll();
    reindex();
    paintNumbers();
    doc.querySelectorAll('[data-fields] > [data-field-row]').forEach(fillLogic);

    /* ═══ 9. REORDER ══════════════════════════════════════════════════════
       THE ORDER OF THE ROWS IS THE ORDER OF THE ENTRY FORM, so every row has a
       handle and drags: without one, moving a field would mean deleting it and
       adding it again at the bottom, losing every setting on it.

       reorder.js does the dragging; the form builder is its other consumer.
       Pointer events, so this works on a tablet too.

       ── WHY onEnd CALLS reindex() AND NOT JUST paintNumbers() ──────────────
       Every control in these rows is named `fields[i][...]`, and PHP rebuilds
       the group from those indices — not from the order the elements appear in
       the request. So a drag that only moved DOM nodes would look right, save,
       and come back in the old order, which is the silent no-op this project
       keeps shipping. `reindex()` rewrites every name from the row's new
       position; `paintNumbers()` repaints the visible index to match. Both, in
       that order, or the screen and the database disagree.

       A missing Reorder is a HARD failure here rather than a silent skip: the
       old CDN call sites guarded with `if (typeof Sortable === 'undefined')
       return;`, and a reorder UI that quietly stops reordering reads to the
       person using it as though they mis-dragged. */
    var fieldList = doc.querySelector('[data-fields]');
    if (fieldList && window.Reorder && typeof window.Reorder.create === 'function') {
        window.Reorder.create(fieldList, {
            handle:      '[data-field-grab]',
            ghostClass:  'sortable-ghost',
            chosenClass: 'sortable-chosen',
            animation:   150,
            onEnd:       function () { reindex(); paintNumbers(); }
        });
    } else if (fieldList) {
        console.error('field-group-form: reorder.js did not load — the field rows cannot be reordered.');
    }
}());
