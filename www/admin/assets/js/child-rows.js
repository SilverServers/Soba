/**
 * A PARENT'S CHILDREN ON ITS OWN EDITOR — each child type's panel of rows
 * (`Ui\ChildRows`, `[data-children]`).
 *
 *   SAVED AS IT IS ADDED OR CHANGED, by itself: a box left or a choice made
 *   saves its row at once, and typing saves it once the typing stops, through
 *   the child's own save and checks (`ContentTypeController::handleRowSave()`,
 *   a new row `handleRowCreate()`). A refusal is said in its row, in the
 *   child's own words, and the row keeps what was typed. So a long list is
 *   never lost to one Save, and a page closed half-way keeps every row saved
 *   before it; leaving while a row has not been saved asks first.
 *
 *   ONE REQUEST AT A TIME FOR A ROW: a change while it is being saved is saved
 *   after, so a new row is made once and every later save is its own.
 *
 *   ITS ORDER is kept as it is arranged, by a row's grip (reorder.js) or by Up
 *   one and Down one, the keyboard's way (`handleOrder()`).
 *
 *   NOTHING HERE GOES WITH THE PARENT'S SAVE. A row's controls are named
 *   `rows[…][…]`, which the parent's save does not read, and they are taken
 *   out of what it posts, so a long list never runs the parent's own fields
 *   past what PHP reads of a form (`max_input_vars`); this strips the prefix
 *   when it sends a row. The panel is `[data-saves-itself]`, which
 *   keep-writing.js leaves alone. The parent's Save waits while a row is
 *   unsaved, and says why.
 *
 * ES5, no build step, no dependency.
 */
(function () {
    'use strict';

    var doc    = document;
    var NAME   = /^rows\[[^\]]*\]\[([^\]]+)\](.*)$/;
    var panels = [];

    function bindPanel(panel) {
        var list     = panel.querySelector('[data-child-rows]');
        var template = panel.querySelector('[data-child-template]');
        var empty    = panel.querySelector('[data-child-empty]');
        var base     = panel.getAttribute('data-row-base') || '';
        var parentId = panel.getAttribute('data-parent') || '';
        var single   = panel.getAttribute('data-single') || '';
        var made     = 0;
        var ordering = { busy: false, again: false };
        if (!list || !template) { return; }

        function rows() {
            return Array.prototype.filter.call(list.children, function (el) { return el.hasAttribute('data-child-row'); });
        }

        function state(row) {
            if (!row.__child) { row.__child = { saving: false, again: false, dirty: false, timer: null }; }
            return row.__child;
        }

        /** What the row posts, as the child's own save reads it: its prefix gone. */
        function body(row) {
            var fd  = new FormData();
            var els = row.querySelectorAll('input, select, textarea');
            for (var i = 0; i < els.length; i++) {
                var el = els[i];
                if (!el.name || el.disabled || el.type === 'file') { continue; }
                if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) { continue; }
                var m = NAME.exec(el.name);
                if (m) { fd.append(m[1] + m[2], el.value); }
            }
            return fd;
        }

        function say(row, words) {
            var box = row.querySelector('[data-child-state]');
            if (box) { box.textContent = words; }
        }

        function refuse(row, message) {
            var box = row.querySelector('[data-child-error]');
            if (!box) { return; }
            box.querySelector('[data-child-error-text]').textContent = message || '';
            box.hidden = !message;
        }

        function named(row) {
            var title = row.querySelector('[data-child-title]');
            var words = title && title.value.trim() !== '' ? title.value.trim() : 'New ' + single.toLowerCase();
            row.setAttribute('aria-label', words);
        }

        function save(row) {
            var s = state(row);
            if (s.timer) { window.clearTimeout(s.timer); s.timer = null; }
            if (s.saving) { s.again = true; return; }
            s.saving = true;
            s.dirty  = false;
            say(row, 'Saving…');

            var id  = row.getAttribute('data-id');
            var fd  = body(row);
            var url = id ? base + '/' + id + '/row' : base + '/rows';
            if (!id) { fd.append('parent_id', parentId); }

            CMS.api(url, { method: 'POST', body: fd })
                .then(function (data) {
                    if (!id && data && data.id) {
                        row.setAttribute('data-id', String(data.id));
                        var edit = row.querySelector('[data-child-edit]');
                        if (edit) { edit.href = data.edit; edit.hidden = false; }
                        // ITS PLACE IS WHERE IT IS ON THE SCREEN, which may not
                        // be last if it was moved before it was saved.
                        order();
                    }
                    var chip = row.querySelector('[data-child-status]');
                    if (chip && data && data.status) { chip.innerHTML = data.status; }
                    refuse(row, '');
                    say(row, s.dirty || s.again ? '' : 'Saved');
                })
                .catch(function (err) {
                    s.dirty = true;
                    refuse(row, err.message);
                    say(row, '');
                })
                .then(function () {
                    s.saving = false;
                    if (s.again) { s.again = false; save(row); }
                });
        }

        function later(row) {
            var s = state(row);
            s.dirty = true;
            say(row, '');
            if (s.timer) { window.clearTimeout(s.timer); }
            s.timer = window.setTimeout(function () { s.timer = null; save(row); }, 900);
        }

        /** The order on the screen, kept: the saved rows' ids, one request at a time. */
        function order() {
            if (ordering.busy) { ordering.again = true; return; }
            var ids = rows().map(function (r) { return r.getAttribute('data-id'); }).filter(Boolean);
            if (!ids.length) { return; }
            var fd = new FormData();
            fd.append('parent_id', parentId);
            ids.forEach(function (id) { fd.append('ids[]', id); });
            ordering.busy = true;
            CMS.api(base + '/order', { method: 'POST', body: fd })
                .catch(function (err) { CMS.flash(err.message, 'error'); })
                .then(function () {
                    ordering.busy = false;
                    if (ordering.again) { ordering.again = false; order(); }
                });
        }

        function shown() {
            if (empty) { empty.hidden = rows().length !== 0; }
        }

        function bindRow(row) {
            if (window.GalleryImages) {
                var galleries = row.querySelectorAll('[data-gallery]');
                for (var g = 0; g < galleries.length; g++) { window.GalleryImages.bind(galleries[g]); }
            }
            named(row);
        }

        function add() {
            made++;
            var holder = doc.createElement('div');
            holder.innerHTML = template.innerHTML.split('__ROW__').join('new' + made);
            var row = holder.firstElementChild;
            list.appendChild(row);
            bindRow(row);
            shown();
            var first = row.querySelector('input:not([type="hidden"]), select, textarea');
            if (first) { first.focus(); }
        }

        function move(row, by, button) {
            var all = rows();
            var at  = all.indexOf(row) + by;
            if (at < 0 || at >= all.length) { return; }
            list.insertBefore(row, by < 0 ? all[at] : all[at].nextSibling);
            // MOVED, THE BUTTON KEEPS THE FOCUS, so Up one pressed again moves
            // the same row again.
            if (button) { button.focus(); }
            order();
        }

        function trash(row) {
            var id = row.getAttribute('data-id');
            if (!id) { row.remove(); shown(); return; }
            say(row, 'Moving to the trash…');
            CMS.api(base + '/' + id + '/row/trash', { method: 'POST', body: new FormData() })
                .then(function () {
                    row.remove();
                    shown();
                    CMS.flash('Moved to the trash.', 'success');
                })
                .catch(function (err) { say(row, ''); refuse(row, err.message); });
        }

        list.addEventListener('input', function (e) {
            var row = e.target.closest('[data-child-row]');
            if (!row || !e.target.name) { return; }
            if (e.target.hasAttribute('data-child-title')) { named(row); }
            later(row);
        });
        list.addEventListener('change', function (e) {
            var row = e.target.closest('[data-child-row]');
            if (row && e.target.name) { save(row); }
        });
        // ENTER IN A ROW'S BOX SAVES THE ROW, and not the parent, which is
        // what an Enter in a box of its form would otherwise do.
        list.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' || !e.target.matches('input') || /^(checkbox|radio|button|submit|file)$/.test(e.target.type)) { return; }
            var row = e.target.closest('[data-child-row]');
            if (!row) { return; }
            e.preventDefault();
            save(row);
        });
        panel.addEventListener('click', function (e) {
            if (e.target.closest('[data-child-add]')) { e.preventDefault(); add(); return; }
            var row = e.target.closest('[data-child-row]');
            if (!row) { return; }
            var up = e.target.closest('[data-child-up]');
            if (up) { e.preventDefault(); move(row, -1, up); return; }
            var down = e.target.closest('[data-child-down]');
            if (down) { e.preventDefault(); move(row, 1, down); return; }
            // ONLY ONCE ASKED: confirm.js asks first and clicks again, confirmed.
            var bin = e.target.closest('[data-child-trash]');
            if (bin && bin.hasAttribute('data-confirmed')) { e.preventDefault(); trash(row); }
        });

        if (window.Reorder && typeof window.Reorder.create === 'function') {
            window.Reorder.create(list, {
                handle:      '[data-child-grip]',
                ghostClass:  'sortable-ghost',
                chosenClass: 'sortable-chosen',
                animation:   150,
                onEnd:       function () { order(); }
            });
        } else {
            console.error('child-rows: reorder.js did not load, so the rows cannot be dragged.');
        }

        rows().forEach(bindRow);
        panels.push({ unsaved: function () {
            return rows().some(function (r) { var s = state(r); return s.dirty || s.saving || !!s.timer; });
        }, flush: function () {
            rows().forEach(function (r) { var s = state(r); if (s.dirty || s.timer) { save(r); } });
        } });
    }

    var found = doc.querySelectorAll('[data-children][data-row-base]');
    for (var p = 0; p < found.length; p++) { bindPanel(found[p]); }
    if (!panels.length) { return; }

    function unsaved() {
        return panels.some(function (p) { return p.unsaved(); });
    }

    // THE PARENT'S SAVE WAITS for its rows: leaving the screen with one
    // still being saved would lose it.
    var form = doc.querySelector('.editor-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            if (!unsaved()) { return; }
            e.preventDefault();
            panels.forEach(function (p) { p.flush(); });
            CMS.flash('Wait for the rows to finish saving, then save.', 'warning');
        });
        form.addEventListener('formdata', function (e) {
            var names = [];
            e.formData.forEach(function (value, name) { if (NAME.test(name)) { names.push(name); } });
            names.forEach(function (name) { e.formData.delete(name); });
        });
    }

    window.addEventListener('beforeunload', function (e) {
        if (!unsaved()) { return; }
        e.preventDefault();
        e.returnValue = '';
    });
}());
