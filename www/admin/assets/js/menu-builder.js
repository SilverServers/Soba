/**
 * THE MENU BUILDER — the Appearance → Menus screen.
 *
 * Keeps `menu_items_json` in step with a flat, depth-ordered list of rows.
 *
 * ── THE CONTRACT, WHICH IS NOT NEGOTIABLE ───────────────────────────────────
 * `MenuItem::replaceAll()` inserts in array order and resolves nesting through
 * `$newIds[$parentIndex]` — a lookup into ids it is creating as it goes. So:
 *
 *   · position  IS the array index
 *   · nesting   IS `parent_index`, pointing at an EARLIER index
 *   · a parent MUST precede its child, or the lookup misses and the child
 *     silently becomes a top-level item
 *
 * Keeping the list flat and depth-ordered makes all three true by construction:
 * `parent_index` is always the nearest preceding row at a shallower depth, which
 * is always earlier in the array.
 *
 * ── DRAG IS THE GESTURE, AND IT DOES BOTH AXES ─────────────────────────────
 * Vertical moves the item; HORIZONTAL NESTS IT. That is the whole interaction,
 * and it is why this file drives the drag itself instead of handing
 * the list to `Reorder.create()`: that helper reports a drop index and nothing
 * about x, so a menu built on it can be reordered but never nested by dragging.
 *
 * It is NOT a second drag implementation. The mirror, the drop indicator and the
 * drop-index maths are `Reorder`'s own — `Reorder.mirror()`, `Reorder.indicator()`,
 * `Reorder.dropPlan()`, `Reorder.placeIndicator()` — the same shared parts the
 * form builder's palette drag uses, for the same reason: two drop markers of
 * different shapes on one screen is the fault those exports exist to stop.
 *
 * ── AND YOU CAN DRAG STRAIGHT OUT OF THE ADD PANELS ────────────────────────
 * A row in "Add menu items" is a drag source too, so a page can be carried into
 * the structure and dropped at a position and a depth in one gesture, rather
 * than being appended to the end and then moved. One engine serves both, which
 * is what makes the line, the mirror and the drop rules identical either way.
 *
 * ── A DRAG CARRIES THE WHOLE SUB-TREE ──────────────────────────────────────
 * Dragging "Events" past "Contact" and leaving its three children behind is not
 * a reorder, it is a menu nobody asked for — the children would re-parent onto
 * whatever now precedes them.
 */
(function () {
    'use strict';

    var doc  = document;
    var root = doc.querySelector('[data-menu-builder]');
    if (!root) { return; }

    var hidden   = root.querySelector('[data-menu-json]');
    var rows     = root.querySelector('[data-menu-rows]');
    var template = root.querySelector('[data-menu-template]');
    var emptyBox = root.querySelector('[data-menu-empty]');
    if (!hidden || !rows || !template) { return; }

    // FOUR KINDS, BECAUSE THE TABLE STORES FOUR. `type` is 'page', 'entry',
    // 'taxonomy' or 'custom'. An ARCHIVE is a custom link with the type's public
    // slug filled in — not a fifth kind, because the data cannot keep that
    // distinction across a reload, and a badge reading "Archive" on a row the
    // database calls `custom` is a made-up fact on screen.
    var KIND_LABEL = { page: 'Page', entry: 'Entry', taxonomy: 'Term', custom: 'Custom link' };

    // ONE INDENT STEP, READ FROM THE STYLESHEET. `.menu-item` indents by
    // `--menu-depth * --sp-5`, so the pixels a drag has to cross to nest are the
    // pixels the row actually moves. Hard-coding a number here would make the
    // two disagree the first time a token changed.
    function indentStep() {
        var v = parseFloat(getComputedStyle(rows).getPropertyValue('--sp-5'));
        return v > 0 ? v : 24;
    }

    function all() {
        return Array.prototype.slice.call(rows.querySelectorAll('[data-menu-row]'));
    }

    function depthOf(row) {
        return parseInt(row.getAttribute('data-depth') || '0', 10);
    }

    function setDepth(row, d) {
        row.setAttribute('data-depth', String(d));
        row.style.setProperty('--menu-depth', String(d));
    }

    /** A row and everything nested under it, in order. */
    function subtree(row) {
        var list = all();
        var i = list.indexOf(row);
        var d = depthOf(row);
        var out = [row];
        for (var k = i + 1; k < list.length && depthOf(list[k]) > d; k++) {
            out.push(list[k]);
        }
        return out;
    }

    /* ── 1. SERIALISE ─────────────────────────────────────────────────────── */
    function sync() {
        var list = all();
        var out  = [];

        for (var i = 0; i < list.length; i++) {
            var row = list[i];
            var d   = depthOf(row);

            // THE NEAREST PRECEDING ROW AT A SHALLOWER DEPTH. Walking backwards
            // is what guarantees the parent is earlier in the array, which is
            // what replaceAll() requires.
            var parentIndex = -1;
            for (var j = i - 1; j >= 0; j--) {
                if (depthOf(list[j]) < d) { parentIndex = j; break; }
            }

            var type = row.getAttribute('data-type') || 'custom';

            // A PAGE, ENTRY OR TERM ITEM STORES ITS TARGET, NOT ITS ADDRESS.
            // `MenuItem::resolveUrl()` builds the URL at render time, so renaming
            // a public slug moves every menu item with it. Saved as a custom link
            // with the address baked in, a menu would fill with links to an
            // address that had stopped existing.
            var byId = (type === 'page' || type === 'taxonomy' || type === 'entry');
            var blank = row.querySelector('[data-m="blank"]');

            out.push({
                label:        val(row, 'label'),
                type:         byId ? type : 'custom',
                target_id:    byId ? Number(row.getAttribute('data-target')) || null : null,
                url:          byId ? '' : val(row, 'url'),
                target_blank: !!(blank && blank.checked),
                css_class:    val(row, 'css'),
                description:  val(row, 'description'),
                // THE TWO CONTROLS MIGRATION 022'S COLUMNS WERE WAITING FOR.
                sub_menu_type: val(row, 'submenu') || 'standard',
                mega_columns:  Number(val(row, 'cols')) || 2,
                visibility:    val(row, 'visibility'),
                parent_index: parentIndex,
                depth:        d,
                // MIGRATION 050'S BAG, read straight off the row. The dialog
                // owns it; this only carries it. Parsed rather than passed as a
                // string so `JSON.stringify` cannot double-encode it into a
                // quoted blob that `MenuItemSettings::sanitise()` then rejects
                // as "not an array" and silently drops.
                settings:     bagOf(row),
                // CARRIED THROUGH, not recomputed. `sync()` rewrites the hidden
                // field on every keystroke; dropping this would blank the
                // "Links to…" line the moment anybody typed in the panel, and
                // `replaceAll()` ignores keys it does not know.
                orig:         row.getAttribute('data-orig') || '',
                glyph:        row.getAttribute('data-glyph') || ''
            });
        }

        hidden.value = JSON.stringify(out);
        if (emptyBox) { emptyBox.hidden = out.length !== 0; }
        refreshControls();
    }

    /** The item's settings bag, off the row. An unreadable one is an empty one —
     *  a malformed attribute must not take a row's other fields down with it. */
    function bagOf(row) {
        try {
            var parsed = JSON.parse(row.getAttribute('data-settings') || '{}');
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) { return {}; }
    }

    function val(row, key) {
        var el = row.querySelector('[data-m="' + key + '"]');
        return el ? el.value : '';
    }

    /* ── 2. WHAT THE CLOSED ROW SAYS ──────────────────────────────────────── */
    function refreshControls() {
        var list = all();
        list.forEach(function (row, i) {
            // The move links are the keyboard route to everything the drag does,
            // so each one is dead exactly when the move it names is impossible.
            var d    = depthOf(row);
            var prev = list[i - 1];
            dis(row, 'up',   i === 0);
            dis(row, 'down', i === list.length - 1);
            dis(row, 'top',  i === 0);
            dis(row, 'in',   !prev || d > depthOf(prev));
            dis(row, 'out',  d === 0);

            // THE TITLE ON THE CLOSED BAR MIRRORS THE LABEL BOX INSIDE IT.
            // Without this, renaming an item leaves the bar showing the old name
            // until the page reloads — the row would be telling the owner
            // something the form no longer says.
            var title = row.querySelector('[data-menu-title]');
            if (title) { title.textContent = val(row, 'label') || 'Untitled'; }
        });
        refreshBulk();
    }

    function dis(row, key, off) {
        var el = row.querySelector('[data-menu-' + key + ']');
        if (el) { el.disabled = !!off; }
    }

    /**
     * EVERY ROW IS AT MOST ONE LEVEL DEEPER THAN THE ONE ABOVE IT.
     *
     * The invariant `replaceAll()` depends on. A drag can break it — drop a
     * depth-2 row at the top of the list and its parent is gone — so this runs
     * after every gesture and clamps DOWNWARDS only, which means a sub-tree
     * dragged as a unit keeps its shape wherever it lands.
     */
    function normalise() {
        var list = all();
        for (var i = 0; i < list.length; i++) {
            var max = i === 0 ? 0 : depthOf(list[i - 1]) + 1;
            if (depthOf(list[i]) > max) { setDepth(list[i], max); }
        }
    }

    /* Show the column count only when this item's sub-menu is a panel. */
    function syncColsVisibility(row) {
        var sub  = row.querySelector('[data-m="submenu"]');
        var wrap = row.querySelector('[data-m-wrap="cols"]');
        if (sub && wrap) { wrap.hidden = sub.value !== 'mega'; }
    }

    /* ── 3. BUILD A ROW ───────────────────────────────────────────────────── */
    function buildRow(item) {
        var frag = template.content.cloneNode(true);
        var row  = frag.querySelector('[data-menu-row]');

        // THE KIND COMES FROM `type`, WHICH IS WHAT IS STORED — and a reload
        // arrives with no `kind` at all, so this line is what decides how a
        // saved item renders. Missing the `taxonomy` arm once made a term item
        // reopen as a custom link with an address box that resolves nothing.
        var type = item.type === 'page' ? 'page'
                 : item.type === 'taxonomy' ? 'taxonomy'
                 : item.type === 'entry' ? 'entry'
                 : 'custom';

        row.setAttribute('data-type', type);
        if (item.settings && typeof item.settings === 'object') {
            row.setAttribute('data-settings', JSON.stringify(item.settings));
        }
        if (item.orig) { row.setAttribute('data-orig', item.orig); }
        if (item.target_id) { row.setAttribute('data-target', String(item.target_id)); }
        setDepth(row, item.depth || 0);

        set(row, 'label', item.label || '');
        set(row, 'url',   item.url || '');
        set(row, 'css',   item.css_class || '');
        set(row, 'description', item.description || '');

        var blank = row.querySelector('[data-m="blank"]');
        if (blank) { blank.checked = !!item.target_blank; }

        // A PAGE, ENTRY OR TERM ITEM HAS NO ADDRESS FIELD: all three resolve
        // theirs from `target_id` at render time, so a box here would collect a
        // value nothing reads.
        // BOTH HALVES OF THE ADDRESS ROW. The panel is a label/control grid now,
        // so "the address row" is two grid children — hiding only the first left
        // an orphaned input sitting under the wrong label.
        var hideUrl = (type !== 'custom');
        Array.prototype.forEach.call(row.querySelectorAll('[data-m-wrap="url"]'),
            function (el) { el.hidden = hideUrl; });

        // THE SAVED VALUES COME BACK INTO THE CONTROLS. Without this the selects
        // render at their defaults and the next save writes those defaults over
        // whatever was chosen — the item quietly loses its mega panel on an edit
        // that touched a label.
        // THE STORED MODE, WHATEVER IT IS — not a ternary that knows two of them.
        // This read `=== 'mega' ? 'mega' : 'standard'`, which made it a FOURTH
        // copy of the sub-menu vocabulary after the template, the settings class
        // and the model's allowlist. A row saved as `tabbed` reopened showing
        // "A dropdown list", and the next save made that true — the item lost
        // its panel because somebody edited its label.
        //
        // `setSelect()` refuses to show a value the control does not offer,
        // rather than silently falling back to the first option, which is the
        // same defect that once rewrote a Content Type's glyph on save.
        setSelect(row, 'submenu', item.sub_menu_type || 'standard');
        set(row, 'cols',       String(item.mega_columns || 2));
        set(row, 'visibility', item.visibility || '');
        syncColsVisibility(row);

        var kindEl = row.querySelector('[data-menu-kind]');
        if (kindEl) { kindEl.textContent = KIND_LABEL[type] || KIND_LABEL.custom; }

        // THE KIND'S GLYPH, cloned from the set the server rendered. The key is
        // `type:<content type>` for an entry, so an Event wears the calendar the
        // sidebar gives Events rather than a generic "entry" mark.
        var glyphKey = item.glyph || type;
        row.setAttribute('data-glyph', glyphKey);
        drawGlyph(row, glyphKey);

        var title = row.querySelector('[data-menu-title]');
        if (title) { title.textContent = item.label || 'Untitled'; }

        // WHAT THIS ITEM POINTS AT, for the open panel ("Original"): the only
        // way to tell two items with the same label apart.
        // NAMED BY THE SERVER — see `$origOf` in the template. This read
        // "Page #3" before, which is a row id: a fact about the database and
        // useless to whoever is looking at two items with the same label.
        var orig = row.querySelector('[data-menu-orig]');
        if (orig) {
            var what = item.orig || (type === 'custom' ? (item.url || '') : '');
            orig.textContent = what === '' ? '' : 'Links to ' + what;
        }

        applyBulkMode(row);
        return row;
    }

    var GLYPHS = doc.querySelector('[data-menu-glyphs]');

    function drawGlyph(row, key) {
        var box = row.querySelector('[data-menu-glyph]');
        if (!box || !GLYPHS) { return; }
        var src = GLYPHS.content.querySelector('[data-glyph-for="' + key + '"]')
               || GLYPHS.content.querySelector('[data-glyph-for="' + (key.indexOf('type:') === 0 ? 'entry' : 'custom') + '"]');
        box.innerHTML = '';
        if (src && src.firstElementChild) { box.appendChild(src.firstElementChild.cloneNode(true)); }
    }

    function set(row, key, value) {
        var el = row.querySelector('[data-m="' + key + '"]');
        if (el) { el.value = value; }
    }

    /**
     * A SELECT, SET TO A STORED VALUE IT MAY NOT OFFER.
     *
     * A `<select>` handed an unknown value shows its FIRST option — and posts
     * whatever it is showing, so the next save silently rewrites the record to
     * something nobody chose. That exact defect cost this admin a Content Type's
     * glyph, and it is the one waiting for any menu whose mode was written by a
     * newer version of this screen than the one now loaded.
     *
     * So an unrecognised value is ADDED as an option and left selected. The row
     * then says something true and honest, and saving it changes nothing.
     */
    function setSelect(row, key, value) {
        var el = row.querySelector('[data-m="' + key + '"]');
        if (!el) { return; }
        el.value = value;
        if (el.selectedIndex === -1 && value !== '') {
            var opt = doc.createElement('option');
            opt.value = value;
            opt.textContent = value + ' (not offered any more)';
            el.appendChild(opt);
            el.value = value;
        }
    }

    function addRow(item, before) {
        var row = buildRow(item);
        if (before) { rows.insertBefore(row, before); }
        else        { rows.appendChild(row); }
        return row;
    }

    /* ── 4. HYDRATE ──────────────────────────────────────────────────────── */
    (function hydrate() {
        var parsed;
        try { parsed = JSON.parse(hidden.value || '[]'); }
        catch (e) { parsed = []; }
        if (!Array.isArray(parsed)) { parsed = []; }
        parsed.forEach(function (item) {
            // SPREAD, DO NOT HAND-COPY. An earlier version named six properties,
            // so `sub_menu_type`, `mega_columns` and `visibility` were dropped
            // between the server sending them and `sync()` writing the row back
            // — an item saved as a mega panel reopened as a dropdown and the next
            // save made that true. Spreading cannot lose a property added later,
            // which is exactly how that one got lost.
            addRow(Object.assign({}, item, { depth: item.depth || 0 }));
        });
        sync();
    }());

    /* ── 5. THE ADD PANELS ────────────────────────────────────────────────── */

    function itemFromBox(box) {
        var kind  = box.getAttribute('data-kind') || 'custom';
        var value = box.getAttribute('data-value') || '';
        var label = box.getAttribute('data-label') || '';
        return {
            label:     label,
            type:      kind,
            target_id: kind === 'custom' ? null : value,
            url:       kind === 'custom' ? value : '',
            // AN ITEM ADDED HERE KNOWS WHAT IT POINTS AT WITHOUT A ROUND TRIP.
            // On a reload the server names it (`$origOf` in the template); at
            // add time the panel row already holds both halves, so the footer
            // says "Links to About" straight away instead of staying blank
            // until the next save.
            orig:      kind === 'custom' ? value : label,
            glyph:     box.getAttribute('data-glyph') || kind,
            depth:     0
        };
    }

    /**
     * ONE BUTTON ADDS EVERY TICKED BOX IN ITS PANEL: tick, tick, tick, Add to
     * menu.
     */
    function addTicked(panel) {
        var boxes = panel.querySelectorAll('[data-add-item]:checked');
        if (!boxes.length) { return; }

        Array.prototype.forEach.call(boxes, function (box) {
            addRow(itemFromBox(box));
            box.checked = false;
        });

        var selectAll = panel.querySelector('[data-add-all]');
        if (selectAll) { selectAll.checked = false; }

        sync();
    }

    /**
     * MOST RECENT · VIEW ALL · SEARCH — three tabs, and the whole reason there
     * are three: the panel opens on a short list you can read,
     * View All is there when the short list is not enough, and Search is there
     * when neither is.
     *
     * ALL THREE ARE THE SAME ROWS, SHOWN OR HIDDEN. A search that went to the
     * server would reload this page and take every unsaved menu change with it.
     */
    function applyTab(panel) {
        var list = panel.querySelector('[data-add-list]');
        var tab  = panel.getAttribute('data-tab') || 'recent';
        if (!list) { return; }

        var recent = parseInt(list.getAttribute('data-recent') || '15', 10);
        var search = panel.querySelector('[data-add-filter]');
        var needle = (tab === 'search' && search ? search.value : '').trim().toLowerCase();

        Array.prototype.forEach.call(list.querySelectorAll('[data-add-row]'), function (r, i) {
            var show;
            if (tab === 'recent')      { show = i < recent; }
            else if (tab === 'all')    { show = true; }
            else {
                var text = (r.querySelector('[data-add-text]') || {}).textContent || '';
                show = needle !== '' && text.toLowerCase().indexOf(needle) !== -1;
            }
            r.hidden = !show;
            // A HIDDEN ROW IS NOT SILENTLY SUBMITTED. Leaving a tick on a row the
            // tab has hidden means "Add to menu" adds something the owner cannot
            // see — which is the exact shape of a control that lies.
            if (!show) {
                var box = r.querySelector('[data-add-item]');
                if (box) { box.checked = false; }
            }
        });

        var box = panel.querySelector('[data-add-search]');
        if (box) { box.hidden = tab !== 'search'; }

        Array.prototype.forEach.call(panel.querySelectorAll('[data-add-tab]'), function (t) {
            t.classList.toggle('is-active', t.getAttribute('data-add-tab') === tab);
        });

        var selectAll = panel.querySelector('[data-add-all]');
        if (selectAll) { selectAll.checked = false; }
    }

    Array.prototype.forEach.call(root.querySelectorAll('[data-fold^="add-"]'), function (panel) {
        if (panel.querySelector('[data-add-list]')) {
            panel.setAttribute('data-tab', 'recent');
            applyTab(panel);
        }
    });

    /* ── 6. BULK SELECT ───────────────────────────────────────────────────── */

    var bulkOn = false;

    function applyBulkMode(row) {
        var pick = row.querySelector('[data-menu-pick]');
        if (pick) { pick.hidden = !bulkOn; }
        if (!bulkOn) {
            var box = row.querySelector('[data-menu-check]');
            if (box) { box.checked = false; }
        }
    }

    function setBulk(on) {
        bulkOn = on;
        all().forEach(applyBulkMode);
        // BOTH BARS MOVE TOGETHER. There are two of them — a long menu puts the
        // control that turns the mode off a screen and a half from the last row
        // ticked — and two that disagree about whether the mode is on is worse
        // than one that is out of reach.
        Array.prototype.forEach.call(root.querySelectorAll('[data-bulk-toggle]'), function (t) {
            t.checked = on;
        });
        Array.prototype.forEach.call(root.querySelectorAll('[data-bulk-acts]'), function (a) {
            a.hidden = !on;
        });
        refreshBulk();
    }

    function refreshBulk() {
        var n = rows.querySelectorAll('[data-menu-check]:checked').length;
        Array.prototype.forEach.call(root.querySelectorAll('[data-bulk-remove]'), function (b) {
            b.disabled = n === 0;
        });
    }

    /**
     * REMOVING A ROW PROMOTES ITS CHILDREN rather than orphaning them — the rows
     * below it at a greater depth would otherwise re-parent themselves onto
     * whatever now precedes them, which is a different menu from the one on
     * screen.
     */
    function removeRow(row) {
        var kids = subtree(row).slice(1);
        kids.forEach(function (k) { setDepth(k, depthOf(k) - 1); });
        row.remove();
    }

    /* ── 7. CLICKS ───────────────────────────────────────────────────────── */
    root.addEventListener('click', function (e) {
        /* ---- add panels ---- */
        var tab = e.target.closest('[data-add-tab]');
        if (tab) {
            e.preventDefault();
            var tabPanel = tab.closest('[data-fold]');
            tabPanel.setAttribute('data-tab', tab.getAttribute('data-add-tab'));
            applyTab(tabPanel);
            var s = tabPanel.querySelector('[data-add-filter]');
            if (s && !s.closest('[data-add-search]').hidden) { s.focus(); }
            return;
        }

        var addSubmit = e.target.closest('[data-add-submit]');
        if (addSubmit) {
            e.preventDefault();
            var panel = addSubmit.closest('[data-fold]');
            if (panel) { addTicked(panel); }
            return;
        }

        var custom = e.target.closest('[data-add="custom"]');
        if (custom) {
            e.preventDefault();
            var lbl = root.querySelector('[data-add-custom-label]');
            var url = root.querySelector('[data-add-custom-url]');
            var text = lbl ? lbl.value.trim() : '';
            if (text === '') { if (lbl) { lbl.focus(); } return; }
            addRow({ label: text, type: 'custom', url: url ? url.value.trim() : '', depth: 0 });
            if (lbl) { lbl.value = ''; }
            if (url) { url.value = ''; }
            sync();
            return;
        }

        /* ---- bulk ---- */
        if (e.target.closest('[data-bulk-all]')) {
            e.preventDefault();
            ticks(true);
            return;
        }
        if (e.target.closest('[data-bulk-none]')) {
            e.preventDefault();
            ticks(false);
            return;
        }
        if (e.target.closest('[data-bulk-remove]')) {
            e.preventDefault();
            var picked = all().filter(function (r) {
                var b = r.querySelector('[data-menu-check]');
                return b && b.checked;
            });
            // BOTTOM UP, so promoting one row's children cannot then be undone by
            // removing a row above it in the same pass.
            picked.reverse().forEach(removeRow);
            normalise();
            sync();
            return;
        }

        /* ---- one row ---- */
        var row = e.target.closest('[data-menu-row]');
        if (!row) { return; }

        var caret = e.target.closest('[data-menu-caret]');
        if (caret) {
            e.preventDefault();
            var body = row.querySelector('[data-menu-body]');
            if (!body) { return; }
            var open = body.hidden;
            body.hidden = !open;
            caret.setAttribute('aria-expanded', open ? 'true' : 'false');
            row.classList.toggle('is-open', open);
            return;
        }

        if (e.target.closest('[data-menu-remove]')) {
            e.preventDefault();
            removeRow(row);
            normalise();
            sync();
            return;
        }

        // ── THE MOVE LINKS ──────────────────────────────────────────────────
        // The sub-tree travels with the row in every one of them, for the same
        // reason it does in a drag.
        var moved = true;
        if (e.target.closest('[data-menu-in]')) {
            setDepth(row, depthOf(row) + 1);
            subtree(row).slice(1).forEach(function (k) { setDepth(k, depthOf(k) + 1); });
        } else if (e.target.closest('[data-menu-out]')) {
            if (depthOf(row) > 0) {
                setDepth(row, depthOf(row) - 1);
                subtree(row).slice(1).forEach(function (k) { setDepth(k, depthOf(k) - 1); });
            }
        } else if (e.target.closest('[data-menu-up]')) {
            var list = all();
            var i = list.indexOf(row);
            // PAST THE WHOLE SUB-TREE ABOVE, not past one row: stepping over a
            // parent's first child would put this item inside a menu it was
            // being moved out of.
            var above = null;
            for (var j = i - 1; j >= 0; j--) {
                if (depthOf(list[j]) <= depthOf(row)) { above = list[j]; break; }
            }
            if (above) { moveBlock(subtree(row), above); }
        } else if (e.target.closest('[data-menu-down]')) {
            var block = subtree(row);
            var after = block[block.length - 1].nextElementSibling;
            if (after) {
                var afterBlock = subtree(after);
                moveBlock(block, afterBlock[afterBlock.length - 1].nextElementSibling);
            }
        } else if (e.target.closest('[data-menu-top]')) {
            moveBlock(subtree(row), rows.firstElementChild);
        } else {
            moved = false;
        }

        if (moved) {
            e.preventDefault();
            normalise();
            sync();
        }
    });

    function ticks(on) {
        Array.prototype.forEach.call(rows.querySelectorAll('[data-menu-check]'), function (b) {
            b.checked = on;
        });
        refreshBulk();
    }

    /** Move a block of rows so it sits immediately before `before` (or at the end). */
    function moveBlock(block, before) {
        block.forEach(function (el) { rows.insertBefore(el, before); });
    }

    /* ── 8. TYPING AND CHOOSING ──────────────────────────────────────────── */
    root.addEventListener('input', function (e) {
        if (e.target.closest('[data-add-filter]')) {
            var panel = e.target.closest('[data-fold]');
            if (panel) { applyTab(panel); }
            return;
        }
        if (e.target.closest('[data-menu-row]')) { sync(); }
    });

    root.addEventListener('change', function (e) {
        if (e.target.matches('[data-bulk-toggle]')) { setBulk(e.target.checked); return; }
        if (e.target.matches('[data-menu-check]'))  { refreshBulk(); return; }

        if (e.target.matches('[data-add-all]')) {
            // SELECT ALL MEANS ALL THAT ARE SHOWING. With a search typed in,
            // ticking the rows it just hid is the control doing something the
            // owner cannot see.
            var panel = e.target.closest('[data-fold]');
            var on = e.target.checked;
            Array.prototype.forEach.call(
                panel.querySelectorAll('[data-add-row]:not([hidden]) [data-add-item]'),
                function (b) { b.checked = on; }
            );
            return;
        }

        var row = e.target.closest('[data-menu-row]');
        if (!row) { return; }
        // `change` AS WELL AS `input`: the column count has to APPEAR the moment
        // the sub-menu becomes a panel, and that is a visibility change rather
        // than a value collection.
        if (e.target.matches('[data-m="submenu"]')) { syncColsVisibility(row); }
        sync();
    });

    /* ── THE LOCATION CARDS' DEPENDENT CONTROLS ───────────────────────────
       A speed for an animation that is not playing, or a second-click rule when
       sub-menus open on hover, is a control that does nothing, so it is not
       shown. The server renders the initial state;
       this keeps it true as the controls move.

       `!value` means "anything but this", which is what the speed control
       needs: it applies to every effect except `none`. */
    function syncLocationCards() {
        Array.prototype.forEach.call(
            doc.querySelectorAll('[data-loc-when]'),
            function (el) {
                var when;
                try { when = JSON.parse(el.getAttribute('data-loc-when')); }
                catch (e) { return; }

                var card  = el.closest('.loc-card') || doc;
                var shown = true;

                Object.keys(when).forEach(function (other) {
                    var src = card.querySelector('[data-loc-input="' + other + '"]');
                    if (!src) { shown = false; return; }
                    // A tick box's VALUE is always `on`; what it means is
                    // whether it is ticked.
                    var have = src.type === 'checkbox' ? (src.checked ? 'on' : 'off') : src.value;
                    var want = String(when[other]);
                    shown = shown && (want.charAt(0) === '!'
                        ? have !== want.slice(1)
                        : have === want);
                });

                el.hidden = !shown;
            }
        );
    }

    doc.addEventListener('change', function (e) {
        if (e.target.matches('[data-loc-input]')) { syncLocationCards(); }
    });
    syncLocationCards();

    /* The tabs on each card: one tab's fields at a time. Every
       field stays IN the form while its tab is hidden, so a save posts all of
       them — hiding a tab never drops what is on it. */
    doc.addEventListener('click', function (e) {
        var tab = e.target.closest('[data-loc-tab]');
        if (!tab) { return; }
        var card = tab.closest('.loc-card');
        var key  = tab.getAttribute('data-loc-tab');
        Array.prototype.forEach.call(card.querySelectorAll('[data-loc-tab]'), function (t) {
            t.classList.toggle('is-active', t === tab);
        });
        Array.prototype.forEach.call(card.querySelectorAll('[data-loc-panel]'), function (p) {
            p.hidden = p.getAttribute('data-loc-panel') !== key;
        });
    });

    root.addEventListener('submit', sync);

    /* ── 9. THE DRAG ─────────────────────────────────────────────────────── */

    var drag = null;

    /**
     * Where a drop at (x, y) lands, and at what depth.
     *
     * THE INDEX IS `Reorder`'s ANSWER, not one of ours — the same call the form
     * builder's palette drag makes, so a drop line means the same thing on every
     * screen in this admin.
     *
     * THE DEPTH IS THE HORIZONTAL DISTANCE the pointer has travelled, in indent
     * steps, clamped to one deeper than whatever ends up above it. Clamping is
     * what makes an illegal drop impossible rather than merely tidied up after:
     * dragging four steps right under a top-level item can only ever make it a
     * child, never a grandchild of nothing.
     */
    function planAt(x, y, exclude) {
        var items = all().filter(function (r) { return exclude.indexOf(r) === -1; });
        var plan  = window.Reorder.dropPlan(rows, items, x, y);

        var above = items[plan.index - 1] || null;
        var max   = above ? depthOf(above) + 1 : 0;
        var want  = Math.round((x - drag.originX) / indentStep());
        var depth = Math.max(0, Math.min(drag.baseDepth + want, max));

        return { plan: plan, items: items, depth: depth, before: items[plan.index] || null };
    }

    function showLine(p, y) {
        if (!drag.line) {
            drag.line = window.Reorder.indicator({});
            rows.appendChild(drag.line);
        }
        window.Reorder.placeIndicator(rows, drag.line, p.items, p.plan, y);
        // THE LINE MOVES RIGHT WITH THE NESTING. A drop marker that sits at the
        // left margin whatever depth you are aiming for cannot answer the one
        // question a two-axis drag asks — "will this become a sub-item?" — and
        // the owner finds out only after letting go.
        drag.line.style.marginLeft = (p.depth * indentStep()) + 'px';
    }

    function endDrag() {
        if (!drag) { return; }
        if (drag.mirror) { drag.mirror.remove(); }
        if (drag.line)   { drag.line.remove(); }
        if (drag.block)  { drag.block.forEach(function (el) { el.classList.remove('is-dragging'); }); }
        if (drag.hadStatic) { rows.style.position = ''; }
        rows.classList.remove('is-dropzone');
        // UNCONDITIONALLY, and not inside the `armed` branch: this also runs
        // from `pointercancel` and Escape, and a page left permanently
        // unselectable because a drag ended the wrong way is worse than the
        // selection this prevents.
        doc.documentElement.classList.remove('is-dragging-menu');
        drag = null;
    }

    doc.addEventListener('pointerdown', function (e) {
        // A CONTROL INSIDE THE BAR IS NOT A GRIP. The caret and the bulk tick sit
        // on the draggable bar, and starting a drag from them means the click
        // never arrives.
        if (e.button !== 0) { return; }
        if (e.target.closest('input, select, textarea, button, a')) { return; }

        var grip = e.target.closest('[data-menu-grip]');
        var pal  = e.target.closest('[data-add-row]');
        if (!grip && !pal) { return; }

        var block = [];
        var item  = null;
        var base  = 0;

        if (grip) {
            var row = grip.closest('[data-menu-row]');
            block = subtree(row);
            base  = depthOf(row);
        } else {
            var box = pal.querySelector('[data-add-item]');
            if (!box) { return; }
            item = itemFromBox(box);
        }

        // THE PRESS ITSELF DOES NOT START A SELECTION. `pointerdown` on a drag
        // handle is never a text gesture, and the controls that ARE text
        // gestures — the label box, the selects, the links — were already
        // returned above, so nothing that wants a caret loses one here.
        //
        // NOT `e.preventDefault()`: on an `<label>` add-panel row that would
        // swallow the click that ticks the checkbox. Suppressing the
        // `selectstart` the press would otherwise begin is the narrower act.
        var stopSel = function (ev) { ev.preventDefault(); };
        doc.addEventListener('selectstart', stopSel);
        doc.addEventListener('pointerup', function off() {
            doc.removeEventListener('selectstart', stopSel);
            doc.removeEventListener('pointerup', off);
        });

        drag = {
            block: block,
            item: item,
            baseDepth: base,
            originX: e.clientX,
            startX: e.clientX,
            startY: e.clientY,
            armed: false,
            mirror: null,
            line: null,
            hadStatic: false,
            source: grip ? grip.closest('[data-menu-row]') : pal
        };
    });

    doc.addEventListener('pointermove', function (e) {
        if (!drag) { return; }

        // A FEW PIXELS OF SLOP BEFORE IT IS A DRAG. Without it every click on a
        // row's bar is a one-pixel drag, and the caret stops opening because the
        // pointerup is consumed.
        if (!drag.armed) {
            if (Math.abs(e.clientX - drag.startX) < 4 && Math.abs(e.clientY - drag.startY) < 4) {
                return;
            }
            drag.armed = true;

            drag.mirror = drag.item
                ? window.Reorder.mirror({ label: drag.item.label })
                : window.Reorder.mirror({ clone: drag.source });
            doc.body.appendChild(drag.mirror);
            if (drag.block.length) {
                drag.block.forEach(function (el) { el.classList.add('is-dragging'); });
            }

            // THE LINE IS POSITIONED INSIDE THE LIST, so the list has to be a
            // containing block for as long as the drag lasts — and only for that
            // long, or it keeps a positioning context it did not have before.
            drag.hadStatic = getComputedStyle(rows).position === 'static';
            if (drag.hadStatic) { rows.style.position = 'relative'; }
            rows.classList.add('is-dropzone');

            // ── NOTHING ON THE PAGE SELECTS WHILE A DRAG IS RUNNING ─────
            // A drag sweeps the pointer across labels, help text and the whole
            // opposite column, and the browser's default answer to that is to
            // paint it all blue and then drag the SELECTION instead of the
            // item. `user-select: none` on the handle alone does not stop it —
            // the selection starts on whatever the pointer reaches next.
            doc.documentElement.classList.add('is-dragging-menu');
            // And clear anything already selected from the press itself.
            var sel = window.getSelection && window.getSelection();
            if (sel && sel.removeAllRanges) { sel.removeAllRanges(); }
        }

        e.preventDefault();
        drag.mirror.at(e.clientX + 12, e.clientY + 12);

        // ── THE DROP ZONE IS A BAND, NOT A BOX ──────────────────────────────
        // Vertical only, and deliberately: X IS THE NESTING AXIS, so every
        // horizontal position already means something and none of them can mean
        // "cancel". Testing x as well made dragging LEFT TO UN-NEST impossible —
        // a top-level row sits at the list's left edge, so the pull that takes
        // it out from under its parent immediately leaves the box, the line
        // disappeared, and the drop was thrown away. Measured: a 200px pull left
        // from an indented row moved nothing at all.
        //
        // The band is generous below the last row because the empty space there
        // is the obvious place to aim for "put it at the end", and a hit test
        // that stops where the content stops leaves it outside.
        var box = rows.getBoundingClientRect();
        var near = e.clientY >= box.top - 40 && e.clientY <= box.bottom + 80;

        if (!near) {
            if (drag.line) { drag.line.remove(); drag.line = null; }
            return;
        }

        showLine(planAt(e.clientX, e.clientY, drag.block), e.clientY);
    });

    doc.addEventListener('pointerup', function (e) {
        if (!drag) { return; }
        if (!drag.armed) { drag = null; return; }

        // A DROP WITH NO LINE IS A DROP OUTSIDE THE LIST, and that is a cancel
        // rather than an append — appending something the owner dragged away is
        // the screen doing the opposite of what the gesture said.
        if (!drag.line) { endDrag(); return; }

        var p = planAt(e.clientX, e.clientY, drag.block);

        if (drag.item) {
            var made = addRow(Object.assign({}, drag.item, { depth: p.depth }), p.before);
            setDepth(made, p.depth);
        } else {
            var delta = p.depth - drag.baseDepth;
            drag.block.forEach(function (el) { setDepth(el, depthOf(el) + delta); });
            moveBlock(drag.block, p.before);
        }

        endDrag();
        normalise();
        sync();
    });

    // A DRAG THAT IS CANCELLED LEAVES NOTHING BEHIND. `pointercancel` fires when
    // the browser takes the pointer over — a touch turning into a scroll, a
    // window losing focus — and without this the mirror stays on screen for ever.
    doc.addEventListener('pointercancel', endDrag);
    doc.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && drag) { endDrag(); }
    });
}());
