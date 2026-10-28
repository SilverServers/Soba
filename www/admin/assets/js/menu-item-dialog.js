/**
 * THE MENU ITEM DIALOG — the "Mega Menu" button on each row, driven.
 *
 * Mega menus reach Appearance → Menus in two places, and this is the first: a
 * button on a row opens one shared dialog, filled from that row, and Done
 * writes the answers back to it.
 *
 * ── ITS OWN FILE, AND IT TALKS TO ROWS THROUGH THE DOM ─────────────────────
 * No shared global with `menu-builder.js`, no import order to get wrong. The
 * contract is two attributes and one event:
 *
 *   READS   `[data-menu-row]`'s `data-settings` (a JSON bag) and its own
 *           `[data-m="submenu"]` / `[data-m="cols"]` controls
 *   WRITES  the same two places
 *   TELLS   the builder by dispatching `change` on the row, which is the event
 *           it already listens for — so `sync()` runs and the hidden field is
 *           written without either file knowing about the other's internals.
 *
 * The alternative — exporting a function from the builder and calling it — is a
 * load-order dependency that fails SILENTLY when the tags are reordered, and
 * this admin has been bitten by exactly that (`reorder.js` before the builder,
 * guarded with a `typeof` check that returned quietly and made a drag UI stop
 * dragging with nothing in the console).
 *
 * ── A NATIVE <dialog> ──────────────────────────────────────────────────────
 * `showModal()` gives the focus trap, the inert background and Escape for free.
 * core.css says so in as many words, and hand-rolling any of the three is how a
 * dialog becomes unreachable by keyboard.
 */
(function () {
    'use strict';

    var doc    = document;
    var dialog = doc.querySelector('[data-mi-dialog]');
    var root   = doc.querySelector('[data-menu-builder]');
    if (!dialog || !root || typeof dialog.showModal !== 'function') { return; }

    var rowOpen = null;          // the row being edited
    var layout  = null;          // the panel layout draft: [{row, span}], or null

    /**
     * THE DEFAULTS, FROM THE SERVER'S ONE DEFINITION.
     *
     * The dialog fills its blanks from these and `commit()` compares against
     * them, so this file carries no second copy to drift from
     * `MenuItemSettings::defaults()`.
     *
     * WITHOUT IT, "is this set?" has to be guessed from falsiness — and that
     * guess was wrong for every select whose default is a non-empty word.
     * `align` defaults to `left`, so every Done wrote `align: "left"` as though
     * somebody had chosen it, and an item with nothing set reported five
     * settings.
     */
    var DEFAULTS = (function () {
        var tag = doc.querySelector('[data-mi-defaults]');
        try { return JSON.parse(tag ? tag.textContent : '{}') || {}; }
        catch (e) { return {}; }
    }());

    function rows(sel) { return Array.prototype.slice.call(dialog.querySelectorAll(sel)); }

    var iconInput  = dialog.querySelector('[data-mi="icon"] [data-mi-input]');
    var iconGrid   = dialog.querySelector('[data-mi-icon-grid]');
    var iconSearch = dialog.querySelector('[data-mi-icon-search]');

    /* ── WHAT THE ROW CURRENTLY SAYS ──────────────────────────────────────── */
    function bagOf(row) {
        try {
            var parsed = JSON.parse(row.getAttribute('data-settings') || '{}');
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) { return {}; }
    }

    function depthOf(row) {
        return parseInt(row.getAttribute('data-depth') || '0', 10);
    }

    function allRows() {
        return Array.prototype.slice.call(root.querySelectorAll('[data-menu-row]'));
    }

    /** The nearest row above at one level shallower — this row's parent. */
    function parentOf(row) {
        var list = allRows();
        var i = list.indexOf(row), d = depthOf(row);
        for (var k = i - 1; k >= 0; k--) {
            if (depthOf(list[k]) < d) { return list[k]; }
        }
        return null;
    }

    /** The rows directly under this one, each with its own sub-tree. */
    function childBlocks(row) {
        var list = allRows();
        var i = list.indexOf(row), d = depthOf(row);
        var blocks = [];
        for (var k = i + 1; k < list.length && depthOf(list[k]) > d; k++) {
            if (depthOf(list[k]) === d + 1) { blocks.push([list[k]]); }
            else { blocks[blocks.length - 1].push(list[k]); }
        }
        return blocks;
    }

    function subMenuOf(row) {
        var sel = row ? row.querySelector('[data-m="submenu"]') : null;
        return sel ? sel.value : 'standard';
    }

    /* A control's value, whatever kind it is. */
    function readInput(input) {
        return input.type === 'checkbox' ? input.checked : input.value;
    }

    function writeInput(input, value) {
        if (input.type === 'checkbox') {
            input.checked = !!value && value !== 'false' && value !== '0';
        } else {
            input.value = value === undefined || value === null ? '' : String(value);
            // A STORED VALUE THE SELECT DOES NOT OFFER leaves it showing its
            // FIRST option — and a `<select>` posts whatever it displays, so the
            // next save silently rewrites the setting to something nobody chose.
            // That exact defect cost this admin a Content Type's glyph. Falling
            // back to the first option is the same bug; refusing to show a lie
            // is the fix, so an unknown value is left selected by adding it.
            if (input.tagName === 'SELECT' && input.selectedIndex === -1 && input.value !== '') {
                var opt = doc.createElement('option');
                opt.value = input.value;
                opt.textContent = input.value + ' (not offered any more)';
                input.appendChild(opt);
                input.value = opt.value;
            }
        }
    }

    /* ── FILL THE DIALOG FROM A ROW ───────────────────────────────────────── */
    function fill(row) {
        var bag   = bagOf(row);
        var depth = depthOf(row);

        rows('[data-mi]').forEach(function (field) {
            var key   = field.getAttribute('data-mi');
            var input = field.querySelector('[data-mi-input]');
            if (!input) { return; }

            if (field.getAttribute('data-mi-store') === 'column') {
                // THE THREE REAL COLUMNS FROM MIGRATION 022. They live on the
                // row's own controls, which the builder already serialises —
                // mirroring them into the bag would be a second copy of one
                // fact, and the two can disagree.
                var owner = row.querySelector('[data-m="' + columnKey(key) + '"]');
                writeInput(input, owner ? owner.value : '');
            } else {
                // The stored value if there is one, the default if not — the row
                // only ever carries what DIFFERS.
                writeInput(input, Object.prototype.hasOwnProperty.call(bag, key)
                    ? bag[key]
                    : DEFAULTS[key]);
            }
        });

        // THE SEARCH STARTS EMPTY on every item. A filter left over from the
        // last one hides the glyph this item already has.
        if (iconSearch) { iconSearch.value = ''; filterIcons(); }
        fillStyles();
        rows('[data-mi-image-preview]').forEach(drawImageField);
        rows('[data-mi-colour]').forEach(drawColourField);
        syncIcon();
        // Open the icon tab on the sub-tab that holds the item's icon.
        var iconPanel = dialog.querySelector('[data-mi-panel="icon"]');
        if (iconPanel) { showSubtab(iconPanel, iconInput && iconInput.value === 'custom' ? 1 : 0); }

        applyVisibility(depth);
        buildLayout(row);
        showTab(dialog.querySelector('[data-mi-tab].is-active')
            ? dialog.querySelector('[data-mi-tab].is-active').getAttribute('data-mi-tab')
            : 'submenu');

        var title = dialog.querySelector('[data-mi-title]');
        var what  = dialog.querySelector('[data-mi-what]');
        var label = row.querySelector('[data-m="label"]');
        if (title) { title.textContent = (label && label.value) || 'Menu item'; }
        // WHAT IT POINTS AT, only when that says something the title does not.
        // For a page item the two are the same word, and the header read
        // "About About" — which looks like a rendering bug, because it is one.
        var orig = row.getAttribute('data-orig') || '';
        var name = (label && label.value) || '';
        if (what) {
            what.textContent = orig !== '' && orig !== name ? 'Links to ' + orig : '';
            what.hidden = what.textContent === '';
        }
    }

    /* The dialog names `sub_menu_type`; the row's control is `submenu`. */
    function columnKey(key) {
        if (key === 'sub_menu_type') { return 'submenu'; }
        if (key === 'mega_columns')  { return 'cols'; }
        return key;
    }

    /* ── WHICH CONTROLS MEAN ANYTHING HERE ────────────────────────────────── */
    function applyVisibility(depth) {
        var hiddenByDepth = 0;

        rows('[data-mi]').forEach(function (field) {
            var want = field.getAttribute('data-mi-depth');
            var ok   = true;

            // A CONTROL THAT DOES NOTHING WHERE IT IS DRAWN IS NOT DRAWN. Item
            // alignment and sub-menu alignment are top-level only; collapsing
            // children only applies to an item inside a panel. Those that cannot
            // apply are removed, not greyed out, and the tab says why: a dead
            // control is worse than none.
            if (want === 'top')    { ok = depth === 0; }
            if (want === 'nested') { ok = depth > 0; }

            // A WIDTH IN THE PARENT'S PANEL only exists when the parent HAS a
            // panel. Under a dropdown list it is a control that does nothing.
            if (ok && field.getAttribute('data-mi-needs') === 'mega-parent') {
                ok = subMenuOf(rowOpen ? parentOf(rowOpen) : null) === 'mega';
            }
            // A PICTURE FOR THE PANEL: on a panel's own item — as this dialog
            // has it set now, before Done — or on an item inside one.
            if (ok && field.getAttribute('data-mi-needs') === 'panel') {
                var own = dialog.querySelector('[data-mi="sub_menu_type"] [data-mi-input]');
                ok = (depth === 0 && own && own.value === 'mega')
                    || subMenuOf(rowOpen ? parentOf(rowOpen) : null) === 'mega';
            }

            if (!ok) { hiddenByDepth++; }
            field.hidden = !ok;
        });

        // …and then the ones that depend on another control's value.
        rows('[data-mi-when]').forEach(function (field) {
            if (field.hidden) { return; }
            var when;
            try { when = JSON.parse(field.getAttribute('data-mi-when')); }
            catch (e) { return; }

            var shown = true;
            Object.keys(when).forEach(function (other) {
                var src = dialog.querySelector('[data-mi="' + other + '"] [data-mi-input]');
                if (!src) { shown = false; return; }
                var value = readInput(src);
                var want  = String(when[other]);
                // `*` is "anything set"; `!x` is "anything but x" — the badge's
                // text means nothing once its style is "No badge".
                shown = shown && (want === '*'
                    ? (value !== '' && value !== false)
                    : (want.charAt(0) === '!'
                        ? String(value) !== want.slice(1)
                        : String(value) === want));
            });
            field.hidden = !shown;
        });

        // A TAB WHOSE FIELDS ALL NEED A PANEL, and this item has none: its note.
        rows('[data-mi-needs-note]').forEach(function (note) {
            var panel = note.closest('[data-mi-panel]');
            var needy = panel ? panel.querySelectorAll('[data-mi-needs]') : [];
            var gone  = panel ? panel.querySelectorAll('[data-mi-needs][hidden]') : [];
            note.hidden = !(needy.length > 0 && gone.length === needy.length);
        });

        // SAY WHAT WAS REMOVED, on the tab it was removed from. Silence would
        // leave the owner hunting for a control that is not there.
        rows('[data-mi-note]').forEach(function (note) {
            var panel = note.closest('[data-mi-panel]');
            var gone  = panel
                ? panel.querySelectorAll('[data-mi-depth][hidden]').length
                : 0;
            note.hidden = gone === 0;
            if (gone > 0) {
                note.textContent = depth === 0
                    ? (gone === 1
                        ? 'One setting here only applies to an item nested under another, so it is not shown.'
                        : gone + ' settings here only apply to an item nested under another, so they are not shown.')
                    : (gone === 1
                        ? 'One setting here only applies to a top-level item, so it is not shown.'
                        : gone + ' settings here only apply to a top-level item, so they are not shown.');
            }
        });

        return hiddenByDepth;
    }

    /* ── THE PANEL LAYOUT EDITOR ──────────────────────────────────────────── */

    function panelColumns() {
        var sel = dialog.querySelector('[data-mi="mega_columns"] [data-mi-input]');
        return Math.max(1, parseInt(sel ? sel.value : '2', 10) || 2);
    }

    function spanOf(row) {
        var s = parseInt((bagOf(row).span || '1'), 10);
        return isNaN(s) || s < 1 ? 1 : s;
    }

    /** Take a snapshot of the children as a draft the dialog can edit freely. */
    function buildLayout(row) {
        layout = null;
        if (depthOf(row) !== 0) { drawLayout(); return; }
        layout = childBlocks(row).map(function (block) {
            return { block: block, span: spanOf(block[0]) };
        });
        drawLayout();
    }

    function drawLayout() {
        var box   = dialog.querySelector('[data-mi-layout]');
        var grid  = dialog.querySelector('[data-mi-layout-grid]');
        var empty = dialog.querySelector('[data-mi-layout-empty]');
        var help  = dialog.querySelector('[data-mi-layout-help]');
        if (!box || !grid) { return; }

        var modeSel = dialog.querySelector('[data-mi="sub_menu_type"] [data-mi-input]');
        var isMega  = !!(modeSel && modeSel.value === 'mega');
        box.hidden = !(layout && isMega && rowOpen && depthOf(rowOpen) === 0);
        if (box.hidden) { return; }

        var cols = panelColumns();
        grid.style.setProperty('--mi-cols', String(cols));
        grid.innerHTML = '';
        empty.hidden = layout.length !== 0;
        help.hidden  = layout.length === 0;

        layout.forEach(function (entry, i) {
            var span  = Math.min(entry.span, cols);
            var title = (entry.block[0].querySelector('[data-m="label"]') || {}).value || 'Untitled';

            var tile = doc.createElement('div');
            tile.className = 'mi-tile';
            tile.setAttribute('data-mi-tile', String(i));
            tile.style.setProperty('--mi-span', String(span));

            // THE TILE: a name, then narrower · N / total · wider.
            tile.innerHTML =
                '<span class="mi-tile__name"></span>' +
                '<span class="mi-tile__size">' +
                  '<button type="button" class="icon-btn" data-mi-narrow aria-label="Narrower" data-tip="Narrower"></button>' +
                  '<span class="mi-tile__count"></span>' +
                  '<button type="button" class="icon-btn" data-mi-wider aria-label="Wider" data-tip="Wider"></button>' +
                '</span>';
            // THE HOUSE'S OWN MARKS, cloned from the set the server rendered —
            // not a text minus and plus that would sit beside SVG glyphs in every
            // other button on the screen at a different weight and baseline.
            var gl = doc.querySelector('[data-mi-size-glyphs]');
            if (gl) {
                ['narrow', 'wider'].forEach(function (k) {
                    var src = gl.content.querySelector('[data-for="' + k + '"]');
                    var btn = tile.querySelector('[data-mi-' + k + ']');
                    if (src && btn) { btn.appendChild(src.firstElementChild.cloneNode(true)); }
                });
            }
            tile.querySelector('.mi-tile__name').textContent = title;
            tile.querySelector('.mi-tile__count').textContent = span + ' / ' + cols;
            tile.querySelector('[data-mi-narrow]').disabled = span <= 1;
            tile.querySelector('[data-mi-wider]').disabled  = span >= cols;
            grid.appendChild(tile);
        });

        // THE TILES DRAG, with the same reorder the rest of this admin uses —
        // it handles grids as well as lists, so a tile lands where the line says.
        if (window.Reorder && !grid.getAttribute('data-reorder-bound')) {
            grid.setAttribute('data-reorder-bound', '1');
            window.Reorder.create(grid, {
                animation: 150,
                onEnd: function () {
                    var order = Array.prototype.map.call(
                        grid.querySelectorAll('[data-mi-tile]'),
                        function (t) { return layout[parseInt(t.getAttribute('data-mi-tile'), 10)]; }
                    );
                    layout = order;
                    drawLayout();
                }
            });
        }
    }

    dialog.addEventListener('click', function (e) {
        var narrow = e.target.closest('[data-mi-narrow]');
        var wider  = e.target.closest('[data-mi-wider]');
        if (!narrow && !wider) { return; }
        e.preventDefault();
        var tile = e.target.closest('[data-mi-tile]');
        var entry = layout[parseInt(tile.getAttribute('data-mi-tile'), 10)];
        var cols  = panelColumns();
        entry.span = Math.max(1, Math.min(cols, Math.min(entry.span, cols) + (wider ? 1 : -1)));
        drawLayout();
    });

    /** Done: each item's width onto the item, and the items into the new order. */
    function commitLayout() {
        if (!layout || !rowOpen) { return; }
        var modeSel = dialog.querySelector('[data-mi="sub_menu_type"] [data-mi-input]');
        if (!modeSel || modeSel.value !== 'mega') { layout = null; return; }

        layout.forEach(function (entry) {
            var head = entry.block[0];
            var bag  = bagOf(head);
            // ONLY WHAT DIFFERS FROM THE DEFAULT, like every other setting.
            if (entry.span > 1) { bag.span = String(entry.span); } else { delete bag.span; }
            head.setAttribute('data-settings', JSON.stringify(bag));
            markRow(head);
        });

        // THE ORDER IS THE ROWS' ORDER. Re-insert each child block, in the new
        // order, straight after the parent's own row — the sub-trees travel with
        // their heads, so nothing is re-parented.
        var anchor = rowOpen;
        layout.forEach(function (entry) {
            entry.block.forEach(function (r) {
                anchor.insertAdjacentElement('afterend', r);
                anchor = r;
            });
        });
        layout = null;
    }

    function showTab(key) {
        rows('[data-mi-tab]').forEach(function (t) {
            t.classList.toggle('is-active', t.getAttribute('data-mi-tab') === key);
        });
        rows('[data-mi-panel]').forEach(function (p) {
            p.hidden = p.getAttribute('data-mi-panel') !== key;
        });
        // THE CHOSEN GLYPH IS IN VIEW when the tab opens — it can be the
        // two-hundredth of the set, below the grid's fold.
        if (key === 'icon' && iconGrid) {
            var on = iconGrid.querySelector('[data-mi-icon-choice]:checked');
            if (on) { on.parentNode.scrollIntoView({ block: 'nearest' }); }
        }
    }

    /* ── WRITE IT BACK ────────────────────────────────────────────────────── */
    function commit(row) {
        var bag = {};
        var was = bagOf(row);

        rows('[data-mi]').forEach(function (field) {
            var key   = field.getAttribute('data-mi');
            var input = field.querySelector('[data-mi-input]');
            if (!input) { return; }

            if (field.getAttribute('data-mi-store') === 'column') {
                // A CONTROL HIDDEN BY DEPTH IS NOT WRITTEN. Committing what a
                // hidden select happens to show is how an item acquires a
                // setting nobody chose — the whole reason a greyed-out control
                // is a trap rather than a courtesy.
                if (field.hidden) { return; }
                var owner = row.querySelector('[data-m="' + columnKey(key) + '"]');
                if (owner && owner.value !== input.value) {
                    owner.value = input.value;
                    owner.dispatchEvent(new Event('change', { bubbles: true }));
                }
                return;
            }

            // A CONTROL HIDDEN BY DEPTH KEEPS WHATEVER WAS ALREADY STORED
            // rather than being dropped. Nesting an item under another must not
            // silently discard the alignment it had at the top level — undo it
            // and the setting should still be there.
            if (field.hidden) {
                if (Object.prototype.hasOwnProperty.call(was, key)) { bag[key] = was[key]; }
                return;
            }

            // ONLY WHAT DIFFERS FROM THE DEFAULT. `MenuItemSettings::sanitise()`
            // does the same on the server; storing the full bag on every item
            // would make a diff of a menu unreadable and say nothing at all.
            var value = readInput(input);
            if (value !== DEFAULTS[key]) { bag[key] = value; }
        });

        row.setAttribute('data-settings', JSON.stringify(bag));

        commitLayout();

        // AND TELL THE BUILDER, through the event it already listens for. No
        // shared function, no load order to get wrong.
        row.dispatchEvent(new Event('change', { bubbles: true }));
        markRow(row, bag);
    }

    /**
     * THE ROW SAYS WHEN IT CARRIES SETTINGS.
     *
     * A dialog that hides everything behind a button, on a row that looks
     * identical whether or not anything is set, is the same as a control nobody
     * can find — which is the objection this admin raised about hiding the
     * per-row selects in the first place. So the button counts what is on.
     */
    function markRow(row, bag) {
        var btn = row.querySelector('[data-mi-open]');
        if (!btn) { return; }
        var n = Object.keys(bag || bagOf(row)).length;
        btn.classList.toggle('is-set', n > 0);
        btn.setAttribute('data-count', n > 0 ? String(n) : '');
        // THE COUNT IS DRAWN, not just recorded. This set a class and an
        // attribute that NOTHING styled, so "the button says when the item
        // carries settings" was a claim with no pixels behind it.
        var badge = btn.querySelector('[data-mi-count]');
        if (badge) {
            badge.textContent = n > 0 ? String(n) : '';
            badge.hidden = n === 0;
        }
        btn.setAttribute('aria-label', n > 0
            ? 'Item settings — ' + n + ' set'
            : 'Item settings');
    }

    /* ── EVENTS ───────────────────────────────────────────────────────────── */
    root.addEventListener('click', function (e) {
        var open = e.target.closest('[data-mi-open]');
        if (!open) { return; }
        e.preventDefault();
        rowOpen = open.closest('[data-menu-row]');
        if (!rowOpen) { return; }
        fill(rowOpen);
        dialog.showModal();
    });

    dialog.addEventListener('click', function (e) {
        var tab = e.target.closest('[data-mi-tab]');
        if (tab) {
            e.preventDefault();
            showTab(tab.getAttribute('data-mi-tab'));
            return;
        }
        if (e.target.closest('[data-mi-cancel]')) {
            e.preventDefault();
            // CANCEL DISCARDS. Nothing has been written to the row yet — the
            // dialog only ever commits on Done — so there is nothing to undo.
            dialog.close();
            return;
        }
        if (e.target.closest('[data-mi-save]')) {
            e.preventDefault();
            if (rowOpen) { commit(rowOpen); }
            dialog.close();
        }
    });

    // A dependent control appears the moment the one it depends on changes.
    dialog.addEventListener('change', function (e) {
        // A GLYPH CHOSEN IN THE GRID IS WRITTEN TO THE ONE INPUT first, so the
        // controls that depend on it see the new value below.
        if (iconInput && e.target.matches('[data-mi-icon-choice]')) {
            iconInput.value = e.target.value;
        }
        if (rowOpen) { applyVisibility(depthOf(rowOpen)); }
        // THE GRID FOLLOWS THE COLUMN COUNT AND THE MODE LIVE, before Done — a
        // layout editor that only redraws after saving is one you edit blind.
        if (rowOpen && e.target.closest('[data-mi="mega_columns"], [data-mi="sub_menu_type"]')) {
            drawLayout();
        }
        syncIcon();
    });

    /* ── THE ICON GRID ────────────────────────────────────────────────────
       The radios, the preview and the name all follow the one hidden input —
       the preview is cloned from the grid's own glyph, so there is one source
       for the markup rather than a second copy written here. It used to be
       synced only on `change`, so an item opened with an icon showed whatever
       the last item had. */
    function syncIcon() {
        if (!iconInput || !iconGrid) { return; }
        var value  = iconInput.value;
        var chosen = null;
        Array.prototype.forEach.call(iconGrid.querySelectorAll('[data-mi-icon-choice]'), function (r) {
            r.checked = r.value === value;
            if (r.checked) { chosen = r; }
        });

        var box   = dialog.querySelector('[data-mi-icon-preview]');
        var name  = dialog.querySelector('[data-mi-icon-chosen]');
        var glyph = chosen && value !== '' ? chosen.parentNode.querySelector('svg') : null;
        if (box) {
            box.innerHTML = '';
            if (glyph) { box.appendChild(glyph.cloneNode(true)); }
            // THE CUSTOM ICON, previewed where the glyph would be.
            var own = customInput ? customInput.value : '';
            if (value === 'custom' && own && mediaMap[own]) {
                var img = document.createElement('img');
                img.src = mediaMap[own];
                img.alt = '';
                box.appendChild(img);
            }
        }
        if (name) {
            // A STORED SLUG THAT IS NOT IN THE SET is said out loud, not shown
            // as "No icon" — the save keeps it until somebody chooses otherwise.
            name.textContent = value === ''
                ? 'No icon'
                : value === 'custom'
                    ? 'Custom icon'
                    : (chosen ? chosen.getAttribute('aria-label') : value + ' — not in the icon set any more');
        }
    }

    /* ── THE ICON TAB'S SUB-TABS, and one icon at a time ─────────────────
       A glyph and a picture are the same slot: the Custom Icon tab saves
       `icon = custom`. Choosing either replaces the other, so the item never
       carries two icons and a hidden winner. */
    var customInput = dialog.querySelector('[data-mi="custom_icon"] [data-mi-input]');

    function showSubtab(tabPanel, index) {
        Array.prototype.forEach.call(tabPanel.querySelectorAll('[data-mi-subtab]'), function (t) {
            t.classList.toggle('is-active', t.getAttribute('data-mi-subtab') === String(index));
        });
        Array.prototype.forEach.call(tabPanel.querySelectorAll('[data-mi-subpanel]'), function (p) {
            p.hidden = p.getAttribute('data-mi-subpanel') !== String(index);
        });
    }

    dialog.addEventListener('click', function (e) {
        var t = e.target.closest('[data-mi-subtab]');
        if (!t) { return; }
        e.preventDefault();
        showSubtab(t.closest('[data-mi-panel]'), t.getAttribute('data-mi-subtab'));
    });

    dialog.addEventListener('change', function (e) {
        if (!iconInput || !customInput) { return; }
        if (e.target === customInput) {
            if (customInput.value !== '') { iconInput.value = 'custom'; }
            else if (iconInput.value === 'custom') { iconInput.value = ''; }
            syncIcon();
            applyVisibility(rowOpen ? depthOf(rowOpen) : 0);
            return;
        }
        if (e.target.matches('[data-mi-icon-choice]') && e.target.value !== '') {
            // A GLYPH REPLACES THE PICTURE.
            ['custom_icon', 'custom_icon_hover'].forEach(function (k) {
                var input = dialog.querySelector('[data-mi="' + k + '"] [data-mi-input]');
                if (input && input.value !== '') {
                    input.value = '';
                    drawImageField(dialog.querySelector('[data-mi="' + k + '"] [data-mi-image-preview]'));
                }
            });
            applyVisibility(rowOpen ? depthOf(rowOpen) : 0);
        }
    });

    function filterIcons() {
        if (!iconGrid || !iconSearch) { return; }
        var words = iconSearch.value.toLowerCase().split(/\s+/).filter(Boolean);
        var shown = 0;
        Array.prototype.forEach.call(iconGrid.querySelectorAll('[data-mi-icon-name]'), function (cell) {
            var hay = cell.getAttribute('data-mi-icon-name');
            var ok  = words.every(function (w) { return hay.indexOf(w) !== -1; });
            cell.hidden = !ok;
            if (ok) { shown++; }
        });
        var empty = dialog.querySelector('[data-mi-icon-empty]');
        if (empty) { empty.hidden = shown > 0; }
    }

    if (iconSearch) { iconSearch.addEventListener('input', filterIcons); }

    /* ── STYLING — Pro's per-item overrides ──────────────────────────────
       Each row has its own tick box and only ticked rows are stored, as one
       JSON object in the tab's hidden input — the bag's `styles` key — so
       `fill()` and `commit()` carry it like any other control. The server
       (`MenuItemStyles`) cleans every row again on save. */
    var stylesInput = dialog.querySelector('[data-mi="styles"] [data-mi-input]');
    var mediaMap = (function () {
        var el = document.querySelector('[data-mi-media]');
        try { return (el && JSON.parse(el.textContent || '{}')) || {}; } catch (e) { return {}; }
    }());

    function fillStyles() {
        if (!stylesInput) { return; }
        var s = {};
        try { s = JSON.parse(stylesInput.value || '{}') || {}; } catch (e) { s = {}; }
        rows('[data-mi-style]').forEach(function (row) {
            var key = row.getAttribute('data-mi-style');
            var on  = Object.prototype.hasOwnProperty.call(s, key);
            var val = row.querySelector('[data-mi-style-value]');
            var to  = row.querySelector('[data-mi-style-to]');
            row.querySelector('[data-mi-style-on]').checked = on;
            if (val) {
                if (on) { val.value = String(s[key]); }
                else if (val.type === 'color') { val.value = '#000000'; }
                else if (val.type === 'hidden') { val.value = ''; }
                else { val.value = row.getAttribute('data-mi-style-default') || ''; }
            }
            if (to) {
                to.value = on ? String(s[key + '_to'] || s[key]) : '#000000';
                // THE SECOND COLOUR FOLLOWS THE FIRST until it is changed on
                // purpose — or ticking a row left it at the placeholder, and a
                // plain background was stored as a gradient into black.
                to.setAttribute('data-linked', on && !s[key + '_to'] ? '1' : (on ? '0' : '1'));
            }
            drawStyleImage(row);
            syncStyleRow(row);
        });
    }

    /** An unticked row is greyed and cannot be changed by accident. */
    function syncStyleRow(row) {
        var on = row.querySelector('[data-mi-style-on]').checked;
        row.classList.toggle('is-on', on);
        Array.prototype.forEach.call(
            row.querySelectorAll('[data-mi-style-value], [data-mi-style-to], [data-mi-style-pick]'),
            function (el) { el.disabled = !on; });
    }

    function collectStyles() {
        if (!stylesInput) { return; }
        var s = {};
        rows('[data-mi-style]').forEach(function (row) {
            if (!row.querySelector('[data-mi-style-on]').checked) { return; }
            var key = row.getAttribute('data-mi-style');
            var val = row.querySelector('[data-mi-style-value]');
            if (!val || (val.type === 'hidden' && !val.value)) { return; }   // an image row with no picture yet
            s[key] = val.type === 'number' ? Number(val.value) : val.value;
            var to = row.querySelector('[data-mi-style-to]');
            if (to && to.value !== s[key]) { s[key + '_to'] = to.value; }
        });
        stylesInput.value = Object.keys(s).length ? JSON.stringify(s) : '';
    }

    function drawStyleImage(row) {
        var box = row.querySelector('[data-mi-style-preview]');
        if (!box) { return; }
        var id   = row.querySelector('[data-mi-style-value]').value;
        var pick = row.querySelector('[data-mi-style-pick]');
        box.innerHTML = '';
        if (id && mediaMap[id]) {
            var img = document.createElement('img');
            img.src = mediaMap[id];
            img.alt = '';
            box.appendChild(img);
        } else {
            var g = emptyGlyph();
            if (g) { box.appendChild(g); }
        }
        if (pick) { pick.textContent = id ? 'Replace' : 'Choose'; }
    }

    function onStyle(e) {
        var row = e.target.closest('[data-mi-style]');
        if (!row) { return; }
        var val = row.querySelector('[data-mi-style-value]');
        var to  = row.querySelector('[data-mi-style-to]');
        if (to) {
            if (e.target === to) { to.setAttribute('data-linked', to.value === val.value ? '1' : '0'); }
            else if (to.getAttribute('data-linked') !== '0') { to.value = val.value; }
        }
        syncStyleRow(row);
        collectStyles();
    }
    dialog.addEventListener('change', onStyle);
    dialog.addEventListener('input', onStyle);

    /* ── A COLOUR FIELD — hex, or the theme's own ─────────────────────── */
    function drawColourField(pick) {
        var field = pick.closest('[data-mi]');
        var input = field.querySelector('[data-mi-input]');
        var auto  = field.querySelector('[data-mi-colour-auto]');
        auto.checked  = input.value === '';
        pick.value    = input.value || '#000000';
        pick.disabled = auto.checked;
    }

    function onColour(e) {
        var field = e.target.closest('[data-mi]');
        if (!field || !field.querySelector('[data-mi-colour]')) { return; }
        if (!e.target.matches('[data-mi-colour], [data-mi-colour-auto]')) { return; }
        var input = field.querySelector('[data-mi-input]');
        var pick  = field.querySelector('[data-mi-colour]');
        var auto  = field.querySelector('[data-mi-colour-auto]');
        input.value   = auto.checked ? '' : pick.value;
        pick.disabled = auto.checked;
    }
    dialog.addEventListener('input', onColour);
    dialog.addEventListener('change', onColour);

    /* ── A PICTURE FIELD — image swap, and the custom icon ─────────────── */

    /** AN EMPTY SLOT SHOWS THE KIND OF THING THAT GOES IN IT, as the house
        picker does: the image glyph, cloned from the icon grid, where every
        glyph is already drawn once on this page. */
    function emptyGlyph() {
        var cell = dialog.querySelector('[data-mi-icon-choice][value="image"]');
        var svg  = cell && cell.parentNode.querySelector('svg');
        if (!svg) { return null; }
        var g = svg.cloneNode(true);
        g.setAttribute('class', 'media-picker__glyph');
        g.setAttribute('width', '22');
        g.setAttribute('height', '22');
        return g;
    }

    function drawImageField(box) {
        var field = box.closest('[data-mi]');
        var input = field.querySelector('[data-mi-input]');
        var id    = input.value;
        box.innerHTML = '';
        if (id && mediaMap[id]) {
            var img = document.createElement('img');
            img.src = mediaMap[id];
            img.alt = '';
            box.appendChild(img);
        } else {
            var g = emptyGlyph();
            if (g) { box.appendChild(g); }
        }
        field.querySelector('[data-mi-image-pick]').textContent = id ? 'Replace' : 'Choose';
        field.querySelector('[data-mi-image-clear]').hidden = !id;
    }

    dialog.addEventListener('click', function (e) {
        var pick  = e.target.closest('[data-mi-image-pick]');
        var clear = e.target.closest('[data-mi-image-clear]');
        if (!pick && !clear) { return; }
        var field = (pick || clear).closest('[data-mi]');
        var input = field.querySelector('[data-mi-input]');
        function set(id) {
            input.value = id;
            drawImageField(field.querySelector('[data-mi-image-preview]'));
            // Through `change`, so the controls that depend on a picture —
            // its size — appear the moment there is one.
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (clear) { set(''); return; }
        if (!window.CmsMedia) { return; }
        window.CmsMedia.pick(function (chosen) {
            mediaMap[chosen.id] = chosen.url;
            set(String(chosen.id));
        });
    });

    // THE ONE MEDIA DIALOG, lent by entry-form.js, for a background image.
    dialog.addEventListener('click', function (e) {
        var pick = e.target.closest('[data-mi-style-pick]');
        if (!pick || !window.CmsMedia) { return; }
        var row = pick.closest('[data-mi-style]');
        window.CmsMedia.pick(function (chosen) {
            row.querySelector('[data-mi-style-value]').value = String(chosen.id);
            mediaMap[chosen.id] = chosen.url;
            drawStyleImage(row);
            collectStyles();
        });
    });

    dialog.addEventListener('close', function () { rowOpen = null; layout = null; });

    // Mark every row that already carries settings, on load.
    Array.prototype.forEach.call(
        root.querySelectorAll('[data-menu-row]'),
        function (row) { markRow(row); }
    );
}());
