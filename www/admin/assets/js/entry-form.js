/**
 * entry-form.js — the behaviour the generic entry/page edit form needs.
 *
 * Emitted by the SCREEN, inside $pageContent, which the layout prints above its
 * own scripts — so `CMS` (admin.js, in <head>) is already there.
 *
 * Four components, all delegated from the document so a repeater row cloned
 * after load behaves exactly like one the server rendered:
 *
 *   1. [data-status-select]  reveal the password box only for the one status
 *                            that uses it
 *   2. [data-media-picker]   choose an attachment from a <dialog> over
 *                            /api/v1/media, or upload one
 *   3. [data-repeater]       add and remove rows from a <template>
 *   4. [data-chip-picker]    multi-select, because <select multiple> is not one
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

    /* ═══ 1. STATUS → PASSWORD ════════════════════════════════════════════
       A password box that is always on screen asks every author to think about
       a setting that applies to one status in five. It appears when it is the
       thing you are configuring and is gone otherwise.

       The `disabled` matters as much as the `hidden`: a hidden-but-enabled
       input still POSTs, so switching away from Password and saving would keep
       re-hashing a password nobody can see. */
    function bindStatus(select) {
        var wrap = doc.querySelector('[data-password-field]');
        if (!wrap) { return; }

        function sync() {
            var on    = select.value === 'password';
            var input = wrap.querySelector('input');

            wrap.hidden = !on;
            if (input) { input.disabled = !on; }
        }

        select.addEventListener('change', sync);
        sync();
    }

    /* ═══ 2. THE MEDIA PICKER ═════════════════════════════════════════════ */

    var mediaDialog = null;
    var mediaTarget = null;   // the [data-media-picker] awaiting a choice
    var mediaChosen = null;   // { id, url }

    /** Build the dialog once and reuse it — one node, one listener set. */
    function mediaDialogEl() {
        if (mediaDialog) { return mediaDialog; }

        mediaDialog = doc.createElement('dialog');
        mediaDialog.className = 'modal modal--wide';
        mediaDialog.setAttribute('data-media-dialog', '');
        mediaDialog.innerHTML =
            '<form method="dialog" class="modal__inner">' +
              '<h2 class="modal__title">Media</h2>' +
              '<div data-media-results></div>' +
              '<div class="modal__actions">' +
                '<label class="btn" data-media-upload-label>Upload' +
                  '<input type="file" accept="image/*" hidden data-media-upload>' +
                '</label>' +
                '<div class="form-actions__spacer"></div>' +
                '<button type="button" class="btn" data-media-cancel>Cancel</button>' +
                '<button type="button" class="btn btn--primary" data-media-confirm disabled>Choose</button>' +
              '</div>' +
            '</form>';

        doc.body.appendChild(mediaDialog);

        mediaDialog.addEventListener('click', function (e) {
            var tile = closest(e.target, '.media-grid__item');
            if (tile) {
                mediaSelect(tile);
                return;
            }
            if (closest(e.target, '[data-media-cancel]')) { mediaDialog.close(); }
            if (closest(e.target, '[data-media-confirm]')) { mediaApply(); }
        });

        mediaDialog.querySelector('[data-media-upload]')
            .addEventListener('change', mediaUpload);

        return mediaDialog;
    }

    function mediaSelect(tile) {
        var grid = tile.parentElement;
        var all  = grid.querySelectorAll('.media-grid__item');

        for (var i = 0; i < all.length; i++) {
            all[i].setAttribute('aria-pressed', all[i] === tile ? 'true' : 'false');
        }

        mediaChosen = { id: tile.getAttribute('data-id'), url: tile.getAttribute('data-url') };
        mediaDialog.querySelector('[data-media-confirm]').disabled = false;
    }

    function mediaApply() {
        if (!mediaTarget || !mediaChosen) { return; }

        // ── A CALLBACK TARGET, FOR CALLERS THAT ARE NOT A PICKER ELEMENT ──
        // `editor.js` needs to choose an image and insert it into a
        // contenteditable surface — there is no `[data-media-picker]` for it to
        // write into. Rather than build a SECOND dialog (two markup sources,
        // two upload paths, two things to keep in step), it borrows this one
        // through `CmsMedia.pick()` below.
        if (typeof mediaTarget === 'function') {
            var fn = mediaTarget;
            mediaTarget = null;
            mediaDialog.close();
            fn(mediaChosen);
            return;
        }

        mediaSet(mediaTarget, mediaChosen.id, mediaChosen.url);
        mediaDialog.close();
    }

    /** Write a chosen attachment into a picker: the id, the preview, the verbs. */
    function mediaSet(picker, id, url) {
        var value   = picker.querySelector('[data-media-value]');
        var preview = picker.querySelector('[data-media-preview]');
        var choose  = picker.querySelector('[data-media-choose]');
        var clear   = picker.querySelector('[data-media-clear]');

        value.value = id || '0';

        if (url) {
            preview.innerHTML = '';
            var img = doc.createElement('img');
            img.src = url;
            img.alt = '';
            preview.appendChild(img);
            choose.textContent = 'Replace';
            clear.hidden = false;
        } else {
            // Back to the KIND of thing that goes here, not an empty box. The
            // glyph markup is the server's, so it is recovered rather than
            // re-authored in JS — one source for the shape.
            preview.innerHTML = picker.getAttribute('data-empty-glyph') || '';
            choose.textContent = 'Choose';
            clear.hidden = true;
        }
    }

    function mediaOpen(picker) {
        mediaTarget = picker;     // a [data-media-picker] element, or a function
        mediaChosen = null;

        var dialog  = mediaDialogEl();
        var results = dialog.querySelector('[data-media-results]');

        dialog.querySelector('[data-media-confirm]').disabled = true;
        results.innerHTML = '';
        dialog.showModal();

        CMS.api('/api/v1/media', { query: { type: 'image' } })
            .then(function (data) { mediaRender(results, (data && data.items) || []); })
            .catch(function (err) { CMS.flash(err.message, 'error'); dialog.close(); });
    }

    function mediaRender(results, items) {
        results.innerHTML = '';

        if (!items.length) {
            // An empty state is the purest case of telling, so it gets the glyph
            // for the kind of place you are standing in — and the act that ends
            // the emptiness is already in the footer.
            var empty = doc.createElement('div');
            empty.className = 'empty';
            empty.appendChild(CMS.el('p', 'empty__title', 'No images yet'));
            results.appendChild(empty);
            return;
        }

        var grid = CMS.el('div', 'media-grid');

        items.forEach(function (item) {
            var tile = doc.createElement('button');
            tile.type = 'button';
            tile.className = 'media-grid__item';
            tile.setAttribute('data-id', item.id);
            tile.setAttribute('data-url', item.url);
            tile.setAttribute('aria-pressed', 'false');
            // The filename is the only name an attachment has; without it the
            // grid is a wall of thumbnails with nothing to aim at by keyboard.
            tile.setAttribute('aria-label', item.filename || ('Image ' + item.id));

            var img = doc.createElement('img');
            img.src = item.url;
            img.alt = item.alt_text || '';
            img.loading = 'lazy';
            tile.appendChild(img);

            grid.appendChild(tile);
        });

        results.appendChild(grid);
    }

    function mediaUpload(e) {
        var file = e.target.files && e.target.files[0];
        if (!file) { return; }

        var body = new FormData();
        body.append('file', file);

        CMS.post('/api/v1/media/upload', body)
            .then(function (data) {
                e.target.value = '';
                if (mediaTarget) { mediaSet(mediaTarget, data.id, data.url); }
                mediaDialog.close();
            })
            .catch(function (err) { e.target.value = ''; CMS.flash(err.message, 'error'); });
    }

    /* ═══ 3. THE REPEATER ═════════════════════════════════════════════════
       Rows are cloned from the server's own <template>, with `__ROW__` replaced
       by the next index. Building the row in JS instead would be a second
       renderer for a shape FieldRenderer already owns — and the two would
       diverge the first time a field type gained an attribute. */
    function repeaterAdd(repeater) {
        var rows = repeater.querySelector('[data-repeater-rows]');
        var tpl  = repeater.querySelector('[data-repeater-template]');
        if (!rows || !tpl) { return; }

        // The next index is one past the HIGHEST in use, not the row count:
        // removing row 0 of two and then adding would otherwise reuse index 1
        // and overwrite the row already there.
        var next = 0;
        var used = rows.querySelectorAll('[data-repeater-row]');
        for (var i = 0; i < used.length; i++) {
            var m = /\[(\d+)\]/.exec(used[i].innerHTML);
            if (m && (parseInt(m[1], 10) + 1) > next) { next = parseInt(m[1], 10) + 1; }
        }

        var wrap = doc.createElement('div');
        wrap.innerHTML = tpl.innerHTML.split('__ROW__').join(String(next));

        var row = wrap.firstElementChild;
        rows.appendChild(row);

        var first = row.querySelector('input, select, textarea');
        if (first) { first.focus(); }
    }

    /* ═══ 4. THE CHIP-PICKER ══════════════════════════════════════════════
       Typeahead input + a filterable menu; click an option to chip it; ↑/↓
       navigate, Enter adds, Esc closes, Backspace on an empty input removes the
       last chip. That contract is written into core.css §9 beside the CSS. */
    /* THE CREATE ROW IS NOT AN OPTION. It shares the option class so it gets the
       same geometry, but it carries no value and no label until the filter puts
       one there — so every loop that reads `data-label` would throw on it, and
       arrow-key navigation would land on a row with nothing to pick. It is
       excluded here, once, rather than special-cased in five places. */
    function chipOptions(picker) {
        return picker.querySelectorAll('.chip-picker__option:not([data-chip-new])');
    }

    function chipHas(picker, value) {
        return !!picker.querySelector('.chip[data-value="' + value + '"]');
    }

    function chipAdd(picker, value, label) {
        if (chipHas(picker, value)) { return; }

        var tpl  = picker.querySelector('[data-chip-template]');
        var list = picker.querySelector('[data-chip-list]');
        if (!tpl || !list) { return; }

        var wrap = doc.createElement('div');
        wrap.innerHTML = tpl.innerHTML;

        var chip = wrap.firstElementChild;
        chip.setAttribute('data-value', value);
        chip.querySelector('.chip__label').textContent = label;
        chip.querySelector('[data-chip-remove]').setAttribute('aria-label', 'Remove ' + label);
        chip.querySelector('input[type="hidden"]').value = value;

        list.appendChild(chip);
        chipSyncMenu(picker);
    }

    /** A value already chosen is struck through in the menu, never removed from
        it — a menu that shrinks as you pick loses the place you were reading. */
    function chipSyncMenu(picker) {
        var options = chipOptions(picker);

        for (var i = 0; i < options.length; i++) {
            var taken = chipHas(picker, options[i].getAttribute('data-value'));
            options[i].classList.toggle('is-selected', taken);
        }
    }

    function chipFilter(picker) {
        var input = picker.querySelector('[data-chip-input]');
        var menu  = picker.querySelector('[data-chip-menu]');
        var q     = input.value.trim().toLowerCase();
        var shown = 0;
        var options = chipOptions(picker);

        for (var i = 0; i < options.length; i++) {
            var hit = q === '' || options[i].textContent.toLowerCase().indexOf(q) !== -1;
            options[i].hidden = !hit;
            options[i].classList.remove('is-active');
            if (hit) { shown++; }
        }

        /* ── CREATE WHAT DOES NOT EXIST YET ───────────────────────────────
           A tag picker that can only filter is a dead end the moment you need a
           tag nobody has used before: the menu empties and there is nowhere to
           go. `Api\TaxonomyController::createTerm()` has claimed in its own
           docblock all along to be "used by the tag-input JS when the user types
           a new tag that doesn't exist yet" — it had no caller at all.

           The row appears only when there is no EXACT match, so typing the whole
           of an existing tag offers to pick it rather than to duplicate it. */
        var make = picker.querySelector('[data-chip-new]');
        if (make) {
            var exact = false;
            for (var k = 0; k < options.length; k++) {
                if (options[k].getAttribute('data-label').toLowerCase() === q) { exact = true; break; }
            }
            var offer = q !== '' && !exact && picker.hasAttribute('data-chip-taxonomy');
            make.hidden = !offer;
            if (offer) {
                // textContent, not innerHTML: this is whatever was typed.
                make.textContent = 'Create “' + input.value.trim() + '”';
                make.setAttribute('data-label', input.value.trim());
                shown++;
            }
        }

        menu.hidden = shown === 0;
        input.setAttribute('aria-expanded', String(!menu.hidden));
    }

    /* Create the term, then chip it. The picker is left disabled for the round
       trip so a double Enter cannot make two terms with the same name — the API
       would happily create both, and the author would have no way to tell them
       apart afterwards. */
    function chipCreate(picker, name) {
        var input = picker.querySelector('[data-chip-input]');
        var make  = picker.querySelector('[data-chip-new]');
        if (!name) { return; }

        input.disabled = true;
        make.textContent = 'Adding…';

        var body = new FormData();
        body.append('name', name);

        CMS.post('/api/v1/taxonomies/' + picker.getAttribute('data-chip-taxonomy') + '/terms', body)
            .then(function (data) {
                input.disabled = false;
                var term = data && data.term;
                if (!term) { return; }

                // THE NEW TERM JOINS THE MENU as well as the chips, so removing
                // the chip and changing your mind can put it back — without this
                // it would exist in the database and be unpickable until reload.
                var menu   = picker.querySelector('[data-chip-menu]');
                var option = doc.createElement('div');
                option.className = 'chip-picker__option';
                option.setAttribute('role', 'option');
                option.setAttribute('data-value', term.id);
                option.setAttribute('data-label', term.name);
                option.textContent = term.name;
                menu.insertBefore(option, make);

                chipAdd(picker, String(term.id), term.name);
                input.value = '';
                chipFilter(picker);
                input.focus();
            })
            .catch(function () {
                input.disabled = false;
                make.textContent = 'Could not add that.';
            });
    }

    function chipClose(picker) {
        var menu  = picker.querySelector('[data-chip-menu]');
        var input = picker.querySelector('[data-chip-input]');

        menu.hidden = true;
        input.setAttribute('aria-expanded', 'false');
    }

    function chipActive(picker) {
        return picker.querySelector('.chip-picker__option.is-active');
    }

    function chipMove(picker, step) {
        var visible = [];
        // INCLUDES the create row here and only here: it is not an option to
        // read values off, but it IS somewhere the arrow keys must be able to
        // reach — a row you can see and cannot select is a dead end.
        var options = picker.querySelectorAll('.chip-picker__option');

        for (var i = 0; i < options.length; i++) {
            if (!options[i].hidden) { visible.push(options[i]); }
        }
        if (!visible.length) { return; }

        var current = chipActive(picker);
        var index   = current ? visible.indexOf(current) : -1;
        var next    = index + step;

        if (next < 0) { next = visible.length - 1; }
        if (next >= visible.length) { next = 0; }

        if (current) { current.classList.remove('is-active'); }
        visible[next].classList.add('is-active');
        visible[next].scrollIntoView({ block: 'nearest' });
    }

    /* ═══ WIRING ══════════════════════════════════════════════════════════
       Delegated from the document, so a row cloned into a repeater after load
       behaves identically to one the server rendered. A per-element listener
       bound at DOMContentLoaded would not reach it, and "the second row does
       nothing" is the kind of bug that ships. */
    doc.addEventListener('click', function (e) {
        var choose = closest(e.target, '[data-media-choose]');
        if (choose) { mediaOpen(closest(choose, '[data-media-picker]')); return; }

        var clear = closest(e.target, '[data-media-clear]');
        if (clear) { mediaSet(closest(clear, '[data-media-picker]'), '0', null); return; }

        var add = closest(e.target, '[data-repeater-add]');
        if (add) { repeaterAdd(closest(add, '[data-repeater]')); return; }

        var remove = closest(e.target, '[data-repeater-remove]');
        if (remove) { closest(remove, '[data-repeater-row]').remove(); return; }

        var chipX = closest(e.target, '[data-chip-remove]');
        if (chipX) {
            var picker = closest(chipX, '[data-chip-picker]');
            closest(chipX, '.chip').remove();
            chipSyncMenu(picker);
            return;
        }

        var make = closest(e.target, '[data-chip-new]');
        if (make) {
            var maker = closest(make, '[data-chip-picker]');
            chipCreate(maker, make.getAttribute('data-label'));
            return;
        }

        var option = closest(e.target, '.chip-picker__option');
        if (option) {
            var owner = closest(option, '[data-chip-picker]');
            chipAdd(owner, option.getAttribute('data-value'), option.getAttribute('data-label'));
            owner.querySelector('[data-chip-input]').value = '';
            chipFilter(owner);
            owner.querySelector('[data-chip-input]').focus();
            return;
        }

        // A click anywhere else closes every open menu.
        var open = doc.querySelectorAll('[data-chip-picker]');
        for (var i = 0; i < open.length; i++) { chipClose(open[i]); }
    });

    doc.addEventListener('input', function (e) {
        var picker = closest(e.target, '[data-chip-picker]');
        if (picker && e.target.matches('[data-chip-input]')) { chipFilter(picker); }
    });

    doc.addEventListener('focusin', function (e) {
        var picker = closest(e.target, '[data-chip-picker]');
        if (picker && e.target.matches('[data-chip-input]')) { chipFilter(picker); }
    });

    doc.addEventListener('keydown', function (e) {
        var picker = closest(e.target, '[data-chip-picker]');
        if (!picker || !e.target.matches('[data-chip-input]')) { return; }

        if (e.key === 'ArrowDown') { e.preventDefault(); chipFilter(picker); chipMove(picker, 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); chipMove(picker, -1); }
        else if (e.key === 'Escape') { chipClose(picker); }
        else if (e.key === 'Enter') {
            var active = chipActive(picker);
            if (active && active.hasAttribute('data-chip-new')) {
                e.preventDefault();
                chipCreate(picker, active.getAttribute('data-label'));
            } else if (active) {
                e.preventDefault();
                chipAdd(picker, active.getAttribute('data-value'), active.getAttribute('data-label'));
                e.target.value = '';
                chipFilter(picker);
            } else if (picker.hasAttribute('data-chip-taxonomy') && e.target.value.trim() !== '') {
                // ENTER WITH NOTHING HIGHLIGHTED CREATES. Typing a new tag and
                // pressing Enter is the gesture every tag input in existence
                // has; requiring an arrow-key press first to reach the row you
                // are already looking at is a step for nothing.
                e.preventDefault();
                chipCreate(picker, e.target.value.trim());
            }
        } else if (e.key === 'Backspace' && e.target.value === '') {
            var chips = picker.querySelectorAll('.chip');
            if (chips.length) { chips[chips.length - 1].remove(); chipSyncMenu(picker); }
        }
    });

    // Stash each empty picker's server-rendered glyph before anything overwrites
    // it, so Remove can put back the exact markup the server would have sent.
    var pickers = doc.querySelectorAll('[data-media-picker]');
    for (var p = 0; p < pickers.length; p++) {
        var preview = pickers[p].querySelector('[data-media-preview]');
        if (preview && !preview.querySelector('img')) {
            pickers[p].setAttribute('data-empty-glyph', preview.innerHTML);
        }
    }

    var status = doc.querySelector('[data-status-select]');
    if (status) { bindStatus(status); }

    /* ═══ ADD A TERM WITHOUT LEAVING THE ENTRY ═════════════════════════════
       `Api\TaxonomyController::createTerm()` had existed with no caller at all —
       four JSON endpoints and no interface. Without this, an author who needs a
       category that does not exist yet has to abandon a half-written entry, walk
       to Structure → Taxonomies → Terms, create it, come back and find their
       draft. It is the most common thing anyone does on an entry form.

       THE NEW TERM IS TICKED. Creating one and leaving it unticked would make
       the whole gesture two steps for no reason: you asked for this category
       because you want THIS entry in it.

       Delegated from the document, so a taxonomy field inside a repeater row
       added after load behaves identically to one the server rendered. */
    function termMessage(box, text) {
        var el = box.querySelector('[data-term-msg]');
        if (!el) { return; }
        el.textContent = '';
        if (text) {
            // `.alert-flag` is the sanctioned inline alert SURFACE — its own box,
            // its own edge. Painting the line red instead breaks the rule that
            // alert colour is a surface, never a state.
            var flag = doc.createElement('span');
            flag.className = 'alert-flag';
            flag.textContent = text;
            el.appendChild(flag);
        }
        el.hidden = !text;
    }

    /* UNDER ITS PARENT, where the server filed it: inside the parent's own
       sub-list, or in a new one straight after the parent's row — the
       `.check-list--sub` shape `FieldRenderer::checkTree()` draws, rather than
       at the end of the top level. */
    function placeTermRow(list, row, parentId) {
        var id = parseInt(parentId, 10) || 0;
        var parentInput = id ? list.querySelector('input[value="' + id + '"]') : null;
        var parentRow = parentInput ? parentInput.closest('.check-list__row') : null;
        if (!parentRow) { list.appendChild(row); return; }
        var sub = parentRow.nextElementSibling;
        if (!sub || !sub.classList.contains('check-list--sub')) {
            sub = doc.createElement('div');
            sub.className = 'check-list check-list--sub';
            parentRow.parentNode.insertBefore(sub, parentRow.nextSibling);
        }
        sub.appendChild(row);
    }

    /* AND THE NEXT ONE CAN SIT UNDER IT: the new term joins the picker at the
       end of its parent's branch, one step deeper, indented as the server
       indents the rest. */
    function addParentOption(select, term) {
        var opt = doc.createElement('option');
        var parentId = parseInt(term.parent_id, 10) || 0;
        var parentOpt = parentId ? select.querySelector('option[value="' + parentId + '"]') : null;
        var depth = parentOpt ? (parseInt(parentOpt.getAttribute('data-depth'), 10) || 0) + 1 : 0;
        opt.value = String(term.id);
        opt.setAttribute('data-depth', String(depth));
        opt.textContent = new Array(depth + 1).join('   ') + term.name;
        var next = null;
        if (parentOpt) {
            next = parentOpt.nextElementSibling;
            while (next && (parseInt(next.getAttribute('data-depth'), 10) || 0) >= depth) {
                next = next.nextElementSibling;
            }
        }
        select.insertBefore(opt, next);
    }

    function addTerm(button) {
        var box  = button.closest('[data-term-add]');
        var name = box.querySelector('[data-term-name]');
        var under = box.querySelector('[data-term-parent]');
        var text = name.value.trim();
        if (text === '') { name.focus(); return; }

        button.disabled = true;
        termMessage(box, '');

        var body = new FormData();
        body.append('name', text);
        if (under && under.value !== '0') { body.append('parent_id', under.value); }

        CMS.post('/api/v1/taxonomies/' + box.getAttribute('data-term-add') + '/terms', body)
            .then(function (data) {
                button.disabled = false;
                var term = data && data.term ? data.term : null;
                if (!term) { termMessage(box, 'The term was not created.'); return; }

                var list = box.parentElement.querySelector('[data-term-list]');
                var row  = doc.createElement('label');
                row.className = 'check-list__row';

                var input = doc.createElement('input');
                input.type    = box.getAttribute('data-input-type');
                input.name    = box.getAttribute('data-input-name');
                input.value   = term.id;
                input.checked = true;

                var span = doc.createElement('span');
                // textContent, never innerHTML: the name is whatever the author
                // typed and it is stored RAW. Escaping happens here, at output,
                // by using the DOM instead of string concatenation.
                span.textContent = term.name;

                row.appendChild(input);
                row.appendChild(span);
                placeTermRow(list, row, term.parent_id);
                if (under) { addParentOption(under, term); }

                name.value = '';
                name.focus();
            })
            .catch(function (err) {
                button.disabled = false;
                termMessage(box, (err && err.message) ? err.message : 'Could not add that.');
            });
    }

    doc.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-term-create]');
        if (btn) { e.preventDefault(); addTerm(btn); }
    });

    /* ═══ THE PICKER, LENT OUT ════════════════════════════════════════════
       ONE media dialog in this admin, and this is it. `editor.js` inserts
       images into a contenteditable surface and has nothing for `mediaSet()`
       to write into, so it passes a callback instead of a picker element.

       Deliberately the smallest possible surface: one function, one argument,
       called with `{id, url}` or not at all if the author cancels. Anything
       more would be an API to maintain rather than a door to walk through. */
    window.CmsMedia = {
        pick: function (onChosen) {
            if (typeof onChosen !== 'function') { return; }
            mediaOpen(onChosen);
        }
    };
    // The same picker under the CMS's old name, for a site's own admin
    // scripts — kept through 0.7.x (Cms\Compat\OldNames).
    window.ImprintMedia = window.CmsMedia;

    // ENTER ADDS, and does not submit the entry. Without this the key that
    // obviously means "add this term" saves the whole post instead — with the
    // term box still full, so the term is not created either.
    doc.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') { return; }
        var name = e.target.closest('[data-term-name]');
        if (!name) { return; }
        e.preventDefault();
        addTerm(name.closest('[data-term-add]').querySelector('[data-term-create]'));
    });
}());
