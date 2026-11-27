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

    /* ═══ 2. THE MEDIA PICKER ═════════════════════════════════════════════
       One dialog for every picker in the admin: a picture field's, and a file
       field's (`data-media-kind="file"`), which lists every kind of file the
       library holds, or only the kinds its `data-media-accept` names. It finds
       a file by its name, narrows to a folder and pages through the library,
       as the library screen does, and takes many files at once, each sent by
       `CMS.upload()` (upload.js), its progress shown as it goes.

       CHOOSING SEVERAL (`CmsMedia.pickMany()`, a gallery's): each tile is
       pressed or not, across pages and searches, the ones the caller already
       has are shown chosen and not offered again, files uploaded are chosen as
       they land, and Add hands over all of them, in the order chosen. */

    var mediaDialog = null;
    var mediaTarget = null;   // the [data-media-picker] awaiting a choice, or a callback
    var mediaChosen = null;   // the chosen file, as the media API gives one
    var mediaKind   = 'image';
    var mediaAccept = '';     // ".pdf,.docx", or '' for every kind
    var mediaGlyph  = '';     // a file's glyph: the server's markup, from the picker
    var mediaPage   = 1;
    var mediaAsked  = 0;      // the latest list asked for; an older answer is dropped
    var mediaShown  = {};     // id → file, for the page on screen
    var mediaBusy   = false;  // uploading: the dialog stays open until it is done
    var mediaMany   = false;  // choosing several (`CmsMedia.pickMany()`)
    var mediaPicked = [];     // the files chosen so far, in the order chosen
    var mediaTaken  = {};     // id → true, for what the caller already has

    /** Build the dialog once and reuse it — one node, one listener set. */
    function mediaDialogEl() {
        if (mediaDialog) { return mediaDialog; }

        mediaDialog = doc.createElement('dialog');
        mediaDialog.className = 'modal modal--wide';
        mediaDialog.setAttribute('data-media-dialog', '');
        mediaDialog.innerHTML =
            '<form method="dialog" class="modal__inner">' +
              '<h2 class="modal__title" data-media-title>Media</h2>' +
              '<div class="filterbar">' +
                '<input class="input input--search" type="search" placeholder="File name"' +
                      ' aria-label="Find a file by its name" data-media-search>' +
                '<select class="select" aria-label="Folder" data-media-folder hidden></select>' +
              '</div>' +
              '<div data-media-results></div>' +
              '<nav class="pager" aria-label="Pages of the library" data-media-pager hidden>' +
                '<button type="button" class="btn btn--sm" data-media-prev>Previous</button>' +
                '<span class="pager__status" data-media-page></span>' +
                '<button type="button" class="btn btn--sm" data-media-next>Next</button>' +
              '</nav>' +
              '<div data-media-progress hidden>' +
                '<p class="form-help" data-media-progress-text></p>' +
                '<div class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"' +
                    ' aria-valuenow="0" aria-label="Upload">' +
                  '<div class="progress-bar__fill" data-media-progress-fill></div>' +
                '</div>' +
              '</div>' +
              '<div class="modal__actions">' +
                '<label class="btn" data-media-upload-label>Upload' +
                  '<input type="file" multiple hidden data-media-upload>' +
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
            if (closest(e.target, '[data-media-cancel]') && !mediaBusy) { mediaDialog.close(); }
            if (closest(e.target, '[data-media-confirm]')) { mediaApply(); }
            if (closest(e.target, '[data-media-prev]')) { mediaList(mediaPage - 1); }
            if (closest(e.target, '[data-media-next]')) { mediaList(mediaPage + 1); }
        });

        // A choice is made twice over by a double click, as in a file chooser;
        // choosing several, a click is the whole gesture.
        mediaDialog.addEventListener('dblclick', function (e) {
            var tile = closest(e.target, '.media-grid__item');
            if (tile && !mediaMany) { mediaSelect(tile); mediaApply(); }
        });

        // Esc while files are going up would close the dialog on them.
        mediaDialog.addEventListener('cancel', function (e) {
            if (mediaBusy) { e.preventDefault(); }
        });

        var search = mediaDialog.querySelector('[data-media-search]');
        search.addEventListener('input', CMS.debounce(function () { mediaList(1); }, 300));
        // ENTER FINDS, and does not close the dialog: a lone search box would
        // otherwise send the dialog's form, which closes it.
        search.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); mediaList(1); }
        });
        mediaDialog.querySelector('[data-media-folder]').addEventListener('change', function () { mediaList(1); });

        mediaDialog.querySelector('[data-media-upload]')
            .addEventListener('change', mediaUpload);

        return mediaDialog;
    }

    function mediaSelect(tile) {
        var id = tile.getAttribute('data-id');
        if (tile.disabled) { return; }

        if (mediaMany) {
            var at = mediaPickedAt(id);
            if (at === -1 && mediaShown[id]) { mediaPicked.push(mediaShown[id]); }
            if (at !== -1) { mediaPicked.splice(at, 1); }
            tile.setAttribute('aria-pressed', at === -1 ? 'true' : 'false');
            mediaConfirm();
            return;
        }

        var all = mediaDialog.querySelectorAll('.media-grid__item');
        for (var i = 0; i < all.length; i++) {
            all[i].setAttribute('aria-pressed', all[i] === tile ? 'true' : 'false');
        }

        mediaChosen = mediaShown[id] || null;
        mediaConfirm();
    }

    /** Where a file is among those chosen, or -1. */
    function mediaPickedAt(id) {
        for (var i = 0; i < mediaPicked.length; i++) {
            if (String(mediaPicked[i].id) === String(id)) { return i; }
        }
        return -1;
    }

    /** The confirm button says what it does: Choose one, or Add however many are chosen. */
    function mediaConfirm() {
        var btn = mediaDialog.querySelector('[data-media-confirm]');
        if (mediaMany) {
            btn.disabled = mediaPicked.length === 0;
            btn.textContent = mediaPicked.length ? 'Add ' + mediaPicked.length : 'Add';
        } else {
            btn.disabled = !mediaChosen;
            btn.textContent = 'Choose';
        }
    }

    /** Hand a chosen file to whoever asked: a picker, or a caller's function. */
    function mediaGive(file) {
        if (!mediaTarget || !file) { return; }

        // ── A CALLBACK TARGET, FOR CALLERS THAT ARE NOT A PICKER ELEMENT ──
        // `editor.js` needs to choose an image and insert it into a
        // contenteditable surface — there is no `[data-media-picker]` for it to
        // write into. Rather than build a SECOND dialog (two markup sources,
        // two upload paths, two things to keep in step), it borrows this one
        // through `CmsMedia.pick()` below, and is given `{id, url}`.
        if (typeof mediaTarget === 'function') {
            var fn = mediaTarget;
            mediaTarget = null;
            mediaDialog.close();
            fn({ id: String(file.id), url: file.url });
            return;
        }

        mediaSet(mediaTarget, String(file.id), file.is_image === false ? null : file.url, file);
        mediaDialog.close();
    }

    function mediaApply() {
        if (mediaMany) {
            var fn = mediaTarget;
            if (!mediaPicked.length || typeof fn !== 'function') { return; }
            mediaTarget = null;
            mediaDialog.close();
            fn(mediaPicked.slice());
            return;
        }
        if (mediaChosen) { mediaGive(mediaChosen); }
    }

    /**
     * Write a chosen attachment into a picker: the id, the preview, the verbs.
     * A file field's picker shows the file's name, a link to it, and its size
     * (`file`, as the media API gives one); a picture's shows the picture.
     */
    function mediaSet(picker, id, url, file) {
        var value   = picker.querySelector('[data-media-value]');
        var preview = picker.querySelector('[data-media-preview]');
        var choose  = picker.querySelector('[data-media-choose]');
        var clear   = picker.querySelector('[data-media-clear]');
        var named   = picker.querySelector('[data-media-name]');
        var has     = !!(id && id !== '0');

        value.value = has ? id : '0';

        if (picker.getAttribute('data-media-kind') === 'file') {
            // THE GLYPH STAYS: it is the kind of thing that goes here, chosen
            // or not. The name says which.
            named.innerHTML = '';
            if (has && file) {
                var link = CMS.el('a', 'link', file.original_name || file.filename);
                link.href = file.url;
                link.target = '_blank';
                link.rel = 'noopener';
                named.appendChild(link);
                named.appendChild(doc.createTextNode(' '));
                named.appendChild(CMS.el('span', 'media-picker__size', file.size_words || ''));
            }
            named.hidden = !has;
        } else if (url) {
            preview.innerHTML = '';
            var img = doc.createElement('img');
            img.src = url;
            img.alt = '';
            preview.appendChild(img);
        } else {
            // Back to the KIND of thing that goes here, not an empty box. The
            // glyph markup is the server's, so it is recovered rather than
            // re-authored in JS — one source for the shape.
            preview.innerHTML = picker.getAttribute('data-empty-glyph') || '';
        }

        choose.textContent = has ? 'Replace' : 'Choose';
        clear.hidden = !has;
        // A change the form's own leaving-guard and conditional logic hear.
        value.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /**
     * @param {Element|Function} picker a [data-media-picker], or a function to hand the choice to
     * @param {Object} [opts] { many: choosing several, title: the dialog's own, taken: ids the caller has }
     */
    function mediaOpen(picker, opts) {
        opts = opts || {};
        mediaTarget = picker;     // a [data-media-picker] element, or a function
        mediaChosen = null;
        mediaMany   = !!opts.many && typeof picker === 'function';
        mediaPicked = [];
        mediaTaken  = {};
        (opts.taken || []).forEach(function (id) { mediaTaken[String(id)] = true; });
        mediaKind   = typeof picker !== 'function' && picker.getAttribute('data-media-kind') === 'file' ? 'file' : 'image';
        mediaAccept = mediaKind === 'file' ? (picker.getAttribute('data-media-accept') || '') : '';
        mediaGlyph  = mediaKind === 'file' ? picker.querySelector('[data-media-preview]').innerHTML : '';

        var dialog = mediaDialogEl();
        var upload = dialog.querySelector('[data-media-upload]');

        dialog.querySelector('[data-media-title]').textContent = opts.title || (mediaKind === 'file' ? 'Files' : 'Media');
        // EACH PICK STARTS FROM THE WHOLE LIBRARY: no search, every folder.
        dialog.querySelector('[data-media-search]').value = '';
        dialog.querySelector('[data-media-folder]').value = '';
        // What the chooser offers: pictures for a picture, the field's kinds
        // for a file field, anything the library takes for one that names none.
        upload.setAttribute('accept', mediaKind === 'file' ? mediaAccept : 'image/*');
        if (mediaKind === 'file' && mediaAccept === '') { upload.removeAttribute('accept'); }
        mediaConfirm();
        dialog.querySelector('[data-media-results]').innerHTML = '';
        dialog.showModal();

        mediaFolders();
        mediaList(1);
    }

    /** The library's folders, for the folder box: none drawn when there are none. */
    function mediaFolders() {
        var select = mediaDialog.querySelector('[data-media-folder]');

        CMS.api('/api/v1/media/folders')
            .then(function (folders) {
                select.innerHTML = '';
                folders = folders || [];
                select.hidden = folders.length === 0;
                var all = [['', 'All folders'], ['0', 'Unfiled']];
                folders.forEach(function (f) { all.push([String(f.id), f.name]); });
                all.forEach(function (pair) {
                    var o = doc.createElement('option');
                    o.value = pair[0];
                    o.textContent = pair[1];
                    select.appendChild(o);
                });
                select.value = '';
            })
            .catch(function () { select.hidden = true; });
    }

    /** The folder chosen in the folder box, for a file uploaded while it is: null for all of them, or none. */
    function mediaFolder() {
        var select = mediaDialog.querySelector('[data-media-folder]');
        return !select.hidden && select.value !== '' && select.value !== '0' ? select.value : null;
    }

    /** One page of the library, as the search and the folder narrow it. */
    function mediaList(page) {
        var dialog  = mediaDialog;
        var results = dialog.querySelector('[data-media-results]');
        var query   = { page: Math.max(1, page) };
        var search  = dialog.querySelector('[data-media-search]').value.trim();
        var folder  = dialog.querySelector('[data-media-folder]');

        if (mediaKind === 'image') { query.type = 'image'; }
        if (mediaAccept) { query.accept = mediaAccept.replace(/\./g, ''); }
        if (search) { query.search = search; }
        if (!folder.hidden && folder.value !== '') { query.folder_id = folder.value; }

        var asked = ++mediaAsked;
        CMS.api('/api/v1/media', { query: query })
            .then(function (data) {
                if (asked !== mediaAsked) { return; }
                mediaPage = (data && data.page) || 1;
                mediaRender(results, (data && data.items) || [], search !== '' || !!query.folder_id);
                mediaPager((data && data.total_pages) || 1);
            })
            .catch(function (err) { CMS.flash(err.message, 'error'); });
    }

    /** "Page 2 of 7", with Previous and Next where there is somewhere to go; nothing for one page. */
    function mediaPager(pages) {
        var pager = mediaDialog.querySelector('[data-media-pager]');
        pager.hidden = pages <= 1;
        mediaDialog.querySelector('[data-media-page]').textContent = 'Page ' + mediaPage + ' of ' + pages;
        mediaDialog.querySelector('[data-media-prev]').hidden = mediaPage <= 1;
        mediaDialog.querySelector('[data-media-next]').hidden = mediaPage >= pages;
    }

    function mediaRender(results, items, narrowed) {
        results.innerHTML = '';
        mediaShown  = {};
        mediaChosen = null;
        mediaConfirm();

        if (!items.length) {
            // An empty state is the purest case of telling, so it says which
            // empty this is — nothing yet, or nothing that matches — and the
            // act that ends the emptiness is already in the footer.
            var empty = doc.createElement('div');
            empty.className = 'empty';
            empty.appendChild(CMS.el('p', 'empty__title', narrowed
                ? 'Nothing matches'
                : (mediaKind === 'file' ? 'No files of this kind yet' : 'No images yet')));
            results.appendChild(empty);
            return;
        }

        var grid = CMS.el('div', 'media-grid');

        items.forEach(function (item) {
            mediaShown[String(item.id)] = item;
            var name = item.original_name || item.filename || ('File ' + item.id);

            var tile = doc.createElement('button');
            tile.type = 'button';
            tile.className = 'media-grid__item';
            tile.setAttribute('data-id', item.id);
            tile.setAttribute('data-url', item.url);
            // CHOSEN, OR ALREADY THE CALLER'S, which is shown chosen and not offered again.
            var taken = !!mediaTaken[String(item.id)];
            tile.setAttribute('aria-pressed', taken || (mediaMany && mediaPickedAt(item.id) !== -1) ? 'true' : 'false');
            tile.disabled = taken;
            // The filename is the only name an attachment has; without it the
            // grid is a wall of thumbnails with nothing to aim at by keyboard.
            tile.setAttribute('aria-label', name);

            if (item.is_image) {
                var img = doc.createElement('img');
                img.src = item.url;
                img.alt = item.alt_text || '';
                img.loading = 'lazy';
                tile.appendChild(img);
            }

            if (mediaKind !== 'file') {
                grid.appendChild(tile);
                return;
            }

            // A FILE IS A CARD: the tile, and its name and size under it, as
            // the library screen draws one.
            var card = CMS.el('div', 'media-card');
            tile.className += ' media-card__thumb';
            if (!item.is_image) {
                var kind = CMS.el('span', 'media-card__kind');
                kind.innerHTML = mediaGlyph;
                tile.appendChild(kind);
            }
            var meta = CMS.el('div', 'media-card__meta');
            meta.appendChild(CMS.el('span', 'media-card__name', name));
            meta.appendChild(CMS.el('span', 'media-card__size', item.size_words || ''));
            card.appendChild(tile);
            card.appendChild(meta);
            grid.appendChild(card);
        });

        results.appendChild(grid);
    }

    /** How the files going up are doing, in words and as a bar. */
    function mediaProgress(text, share) {
        var box  = mediaDialog.querySelector('[data-media-progress]');
        var bar  = box.querySelector('.progress-bar');
        var pct  = Math.round(Math.max(0, Math.min(1, share)) * 100);

        box.hidden = text === null;
        if (text === null) { return; }
        box.querySelector('[data-media-progress-text]').textContent = text;
        box.querySelector('[data-media-progress-fill]').style.width = pct + '%';
        bar.setAttribute('aria-valuenow', String(pct));
    }

    /**
     * EVERY FILE CHOSEN, ONE AT A TIME (`CMS.upload()`): a pool on shared
     * hosting takes a sequence, where thirty at once would take it down. Each
     * goes into the folder the folder box shows, if it shows one. One that
     * lands is chosen as it always was; several land in the library and the
     * list opens on them, newest first, to choose from. A file refused is
     * named with the server's reason, and the rest go on.
     */
    function mediaUpload(e) {
        var input = e.target;
        var files = input.files ? Array.prototype.slice.call(input.files) : [];
        if (!files.length) { return; }

        var landed = [];
        var total  = files.length;
        var label  = mediaDialog.querySelector('[data-media-upload-label]');
        var into   = mediaFolder();

        mediaBusy = true;
        input.disabled = true;
        label.setAttribute('aria-disabled', 'true');

        function finish() {
            mediaBusy = false;
            input.disabled = false;
            input.value = '';
            label.removeAttribute('aria-disabled');
            mediaProgress(null, 0);

            // CHOOSING SEVERAL, what landed is chosen, and shown first.
            if (mediaMany) {
                landed.forEach(function (f) {
                    if (!mediaTaken[String(f.id)] && mediaPickedAt(f.id) === -1) { mediaPicked.push(f); }
                });
                if (landed.length) {
                    mediaDialog.querySelector('[data-media-search]').value = '';
                    mediaList(1);
                }
                mediaConfirm();
                return;
            }

            if (landed.length === 1 && total === 1) {
                mediaGive(landed[0]);
                return;
            }
            if (landed.length) {
                CMS.flash(landed.length + (landed.length === 1 ? ' file' : ' files') + ' added to the library. Choose one.');
                mediaDialog.querySelector('[data-media-search]').value = '';
                mediaList(1);
            }
        }

        function next() {
            if (!files.length) { finish(); return; }
            var file = files.shift();
            var n    = total - files.length;
            var says = total > 1 ? 'Uploading ' + n + ' of ' + total + ': ' + file.name : 'Uploading ' + file.name;

            mediaProgress(says, 0);
            CMS.upload(file, into ? { folder_id: into } : {}, function (share) { mediaProgress(says, share); })
                .then(function (data) { landed.push(data); next(); })
                .catch(function (err) {
                    // NAMED, because "Upload failed" on a ten-file drop does not
                    // tell you which one to fix.
                    CMS.flash(file.name + ': ' + err.message, 'error');
                    next();
                });
        }

        next();
    }

    /* ═══ 3. THE REPEATER ═════════════════════════════════════════════════
       Rows are cloned from the server's own <template>, with `__ROW__` replaced
       by the next index. Building the row in JS instead would be a second
       renderer for a shape FieldRenderer already owns — and the two would
       diverge the first time a field type gained an attribute. */
    function repeaterAdd(repeater, focus) {
        var rows = repeater.querySelector('[data-repeater-rows]');
        var tpl  = repeater.querySelector('[data-repeater-template]');
        if (!rows || !tpl) { return null; }

        // The next index is one past the HIGHEST in use, not the row count:
        // removing row 0 of two and then adding would otherwise reuse index 1
        // and overwrite the row already there.
        var next = 0;
        var used = rows.querySelectorAll('[data-repeater-row]');
        for (var i = 0; i < used.length; i++) {
            var m = /\[(\d+)\]/.exec(used[i].innerHTML);
            if (m && (parseInt(m[1], 10) + 1) > next) { next = parseInt(m[1], 10) + 1; }
        }

        // A TABLE'S ROW IS A <tr>, which a <div> would not parse: it goes into
        // a <tbody>, as it will live (`FieldRenderer::renderRepeaterTable()`).
        var wrap = doc.createElement(rows.tagName === 'TBODY' ? 'tbody' : 'div');
        wrap.innerHTML = tpl.innerHTML.split('__ROW__').join(String(next));

        var row = wrap.firstElementChild;
        rows.appendChild(row);

        // RETURNED, so "Make options" can fill the rows it adds. Focus only
        // when a person pressed Add: making fifteen rows must not scroll the
        // page fifteen times.
        if (focus !== false) {
            var first = row.querySelector('input, select, textarea');
            if (first) { first.focus(); }
        }
        return row;
    }

    /* ═══ 3a. A REPEATER DRAWN AS A TABLE ═══════════════════════════════════
       `FieldRenderer::renderRepeaterTable()`. Tab moves along a row, as it does
       anywhere; Enter moves down its column, Shift+Enter up, as in a
       spreadsheet, and a cell never sends the form. A block pasted from a
       spreadsheet, its rows on lines and its cells split by tabs, fills the
       cells from the one it lands in, rightward and down, adding rows where the
       table adds them. Each value goes in as it would be typed and is checked as
       a typed one is: a cell it does not fit keeps what it had and says why, and
       the first such cell says so as the form would. What had nowhere to go is
       said in one sentence: the lines past a table that cannot add rows, and
       the cells past its last column, an empty one aside. */
    function tableRows(table) {
        return Array.prototype.slice.call(table.querySelectorAll('[data-repeater-rows] > [data-repeater-row]'));
    }

    /** A row's cells a person types in, in column order: one control a cell. */
    function tableCells(row) {
        var out = [];
        for (var i = 0; i < row.children.length; i++) {
            if (row.children[i].tagName !== 'TD') { continue; }
            var control = row.children[i].querySelector('input:not([type=hidden]), select, textarea');
            if (control) { out.push(control); }
        }
        return out;
    }

    /** Put one pasted value in a cell, as if typed; '' when it fits, else why not. */
    function tablePut(el, raw) {
        var v   = String(raw).trim();
        var why = '';
        if (el.type === 'number') {
            // A spreadsheet's figure is the same number typed: the column's own
            // prefix and thousands in threes come off ($1,250.00 is 1250.00);
            // anything else that is not a number is refused, never guessed at.
            var box    = closest(el, '.prefixed');
            var prefix = box ? box.querySelector('.prefixed__prefix').textContent.trim() : '';
            var n      = v.replace(/\u00a0/g, ' ');
            if (prefix && n.indexOf(prefix) === 0) { n = n.slice(prefix.length).trim(); }
            if (/^-?\d{1,3}([, ]\d{3})+(\.\d+)?$/.test(n)) { n = n.replace(/[, ]/g, ''); }
            if (n !== '' && !/^-?(\d+(\.\d*)?|\.\d+)$/.test(n)) {
                why = '"' + v + '" is not a number.';
            } else {
                el.value = n;
            }
        } else if (el.tagName === 'SELECT') {
            var found = null;
            for (var i = 0; i < el.options.length; i++) {
                var o = el.options[i];
                if (o.value.toLowerCase() === v.toLowerCase() || o.text.trim().toLowerCase() === v.toLowerCase()) { found = o.value; break; }
            }
            if (found === null) { why = '"' + v + '" is not one of its choices.'; } else { el.value = found; }
        } else if (el.type === 'checkbox') {
            el.checked = /^(1|yes|true|x)$/i.test(v);
        } else if (el.type === 'date') {
            if (v !== '' && !/^\d{4}-\d{2}-\d{2}$/.test(v)) { why = '"' + v + '" is not a date written as 2026-12-31.'; } else { el.value = v; }
        } else {
            el.value = v;
        }
        el.setCustomValidity(why);
        if (why) {
            el.setAttribute('data-paste-refused', '');
            return why;
        }
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
        return el.checkValidity() ? '' : el.validationMessage;
    }

    function tablePaste(e) {
        var table = closest(e.target, '[data-repeater-table]');
        var row   = table && closest(e.target, '[data-repeater-row]');
        var col   = row ? tableCells(row).indexOf(e.target) : -1;
        if (col < 0) { return; }
        var text = ((e.clipboardData || window.clipboardData).getData('text/plain') || '').replace(/\r/g, '').replace(/\n$/, '');
        if (!/[\t\n]/.test(text)) { return; }   // one value: the box's own paste
        e.preventDefault();

        var lines   = text.split('\n');
        var rows    = tableRows(table);
        var start   = rows.indexOf(row);
        var refused = [];
        var left    = 0;
        var over    = 0;
        for (var r = 0; r < lines.length; r++) {
            var target = rows[start + r];
            if (!target) {
                // A table whose rows are its choices has no more to add.
                if (!table.querySelector('[data-repeater-add]')) { left = lines.length - r; break; }
                target = repeaterAdd(table, false);
                rows   = tableRows(table);
            }
            var cells  = tableCells(target);
            var values = lines[r].split('\t');
            for (var c = 0; c < values.length; c++) {
                if (col + c >= cells.length) {
                    if (values[c].trim() !== '') { over++; }
                    continue;
                }
                if (tablePut(cells[col + c], values[c])) { refused.push(cells[col + c]); }
            }
        }
        var said = [];
        if (left) { said.push(left + (left === 1 ? ' line' : ' lines') + ' of what was pasted had no row to go in.'); }
        if (over) { said.push(over + (over === 1 ? ' cell' : ' cells') + ' of what was pasted had no column to go in.'); }
        if (said.length) { CMS.flash(said.join(' '), 'error'); }
        if (refused.length) { refused[0].reportValidity(); }
    }

    function tableEnter(e) {
        if (e.key !== 'Enter' || e.altKey || e.ctrlKey || e.metaKey || e.isComposing) { return; }
        var table = closest(e.target, '[data-repeater-table]');
        if (!table || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'BUTTON' || e.target.isContentEditable) { return; }
        var row = closest(e.target, '[data-repeater-row]');
        var col = row ? tableCells(row).indexOf(e.target) : -1;
        if (col < 0) { return; }
        e.preventDefault();
        var rows   = tableRows(table);
        var next   = rows[rows.indexOf(row) + (e.shiftKey ? -1 : 1)];
        var target = next && tableCells(next)[col];
        if (target) {
            target.focus();
            if (typeof target.select === 'function') { target.select(); }
        }
    }

    /* ═══ 3b. A PRODUCT'S OPTIONS, MADE AND SET IN ONE PRESS ═══════════════
       "Make options" reads the sizes and colours ticked on this product — the
       same `taxonomy_<key>[]` inputs the entry form posts, chips or tick boxes —
       and adds a row for every combination not already in the panel, in the
       taxonomy's own order, named "M / Navy" and carrying its terms in hidden
       inputs. `Variation::sync()` keeps only terms of this type's option
       taxonomies, so the inputs are a convenience and never a trust.

       "Set" puts one price, sale price, count or photo into every row, or every
       row of one colour. Both only fill the form: nothing is saved until Save,
       which is the same contract every other control on this screen keeps. */
    function optionRows(repeater) {
        return repeater.querySelectorAll('[data-repeater-rows] > [data-repeater-row]');
    }

    function optionTerms(row) {
        var map = {};
        var hidden = row.querySelectorAll('input[data-option-term]');
        for (var i = 0; i < hidden.length; i++) {
            map[hidden[i].getAttribute('data-option-term')] = hidden[i].value;
        }
        return map;
    }

    function optionsMake(button) {
        var panel    = closest(button, '[data-variations-panel]');
        var repeater = panel && panel.querySelector('[data-variations]');
        var spec;
        try { spec = JSON.parse(button.getAttribute('data-options-make') || '[]'); } catch (err) { spec = []; }
        if (!repeater || !spec.length) { return; }

        var lists = [];
        for (var t = 0; t < spec.length; t++) {
            var ticked = {};
            var inputs = doc.querySelectorAll('input[name="taxonomy_' + spec[t].tax + '[]"]');
            for (var i = 0; i < inputs.length; i++) {
                if (inputs[i].type !== 'checkbox' || inputs[i].checked) { ticked[inputs[i].value] = true; }
            }
            var chosen = spec[t].terms.filter(function (term) { return ticked[String(term.id)]; });
            // A TAXONOMY WITH NOTHING TICKED IS ONE THIS PRODUCT IS NOT MADE
            // IN: a tee sold in sizes alone has no colour to combine.
            if (chosen.length) { lists.push({ tax: spec[t].tax, terms: chosen }); }
        }
        if (!lists.length) {
            CMS.flash('Choose this product\'s ' + spec.map(function (x) { return x.label.toLowerCase(); }).join(' or ')
                      + ' first, where it is filed.', 'error');
            return;
        }

        var combos = [[]];
        lists.forEach(function (list) {
            var next = [];
            combos.forEach(function (c) {
                list.terms.forEach(function (term) { next.push(c.concat([{ tax: list.tax, term: term }])); });
            });
            combos = next;
        });

        // WHAT IS ALREADY HERE, by its terms and by its name, so pressing it
        // twice, or after typing "M / Navy" by hand, adds nothing twice.
        var have = {};
        var rows = optionRows(repeater);
        for (var r = 0; r < rows.length; r++) {
            var terms = optionTerms(rows[r]);
            var key = Object.keys(terms).sort().map(function (k) { return k + ':' + terms[k]; }).join('|');
            if (key) { have[key] = true; }
            var named = rows[r].querySelector('input[name$="[label]"]');
            if (named && named.value.trim()) { have['label:' + named.value.trim().toLowerCase()] = true; }
        }

        var sku  = (doc.querySelector('[name="field_product_sku"]') || { value: '' }).value.trim();
        var made = 0;
        combos.forEach(function (c) {
            var key = c.map(function (x) { return x.tax + ':' + x.term.id; }).sort().join('|');
            var label = c.map(function (x) { return x.term.name; }).join(' / ');
            if (have[key] || have['label:' + label.toLowerCase()]) { return; }

            var row = repeaterAdd(repeater, false);
            if (!row) { return; }
            var labelBox = row.querySelector('input[name$="[label]"]');
            var index = (/\[(\d+)\]/.exec(labelBox.name) || [])[1];
            labelBox.value = label;
            if (sku) {
                row.querySelector('input[name$="[sku]"]').value = sku + '-' + c.map(function (x) { return x.term.slug.toUpperCase(); }).join('-');
            }
            c.forEach(function (x) {
                var hidden = doc.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'variations[' + index + '][terms][' + x.tax + ']';
                hidden.value = x.term.id;
                hidden.setAttribute('data-option-term', x.tax);
                row.appendChild(hidden);
            });
            made++;
        });

        CMS.flash(made ? made + (made === 1 ? ' option' : ' options') + ' added. Save to keep them.'
                       : 'Every combination is already here.', made ? 'success' : 'info');
    }

    function optionsBulkField(tool) {
        var field = tool.querySelector('[data-bulk-field]').value;
        tool.querySelector('[data-bulk-value]').hidden = field === 'image_id';
        tool.querySelector('[data-bulk-photo]').hidden = field !== 'image_id';
    }

    function optionsBulkApply(tool) {
        var panel    = closest(tool, '[data-variations-panel]');
        var repeater = panel && panel.querySelector('[data-variations]');
        if (!repeater) { return; }

        var field = tool.querySelector('[data-bulk-field]').value;
        var scope = tool.querySelector('[data-bulk-scope]').value;     // '' or "tax:termId"
        var value = tool.querySelector('[data-bulk-value]').value;
        var photo = tool.querySelector('[data-bulk-photo]');
        var photoId  = photo ? photo.querySelector('[data-media-value]').value : '0';
        var photoImg = photo ? photo.querySelector('[data-media-preview] img') : null;

        var set = 0;
        var rows = optionRows(repeater);
        for (var r = 0; r < rows.length; r++) {
            if (scope) {
                var cut = scope.split(':');
                if (String(optionTerms(rows[r])[cut[0]] || '') !== cut[1]) { continue; }
            }
            if (field === 'image_id') {
                var picker = rows[r].querySelector('[data-media-picker]');
                if (picker) { mediaSet(picker, photoId, photoId !== '0' && photoImg ? photoImg.getAttribute('src') : null); }
            } else {
                var box = rows[r].querySelector('[name$="[' + field + ']"]');
                if (box) { box.value = value; }
                // A COUNT IS ONLY READ WHEN COUNTING IS ON, so setting one turns
                // counting on — the count typed here would otherwise do nothing.
                if (field === 'stock_qty') {
                    var count = rows[r].querySelector('input[type=checkbox][name$="[manage_stock]"]');
                    if (count) { count.checked = true; }
                }
            }
            set++;
        }
        CMS.flash(set ? 'Set on ' + set + (set === 1 ? ' option' : ' options') + '. Save to keep it.'
                      : 'No option matches.', set ? 'success' : 'info');
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

    function chipAdd(picker, value, label, slug) {
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
        // ITS SLUG, for a logic rule naming the term by it (`logic.js`).
        if (slug) { chip.querySelector('input[type="hidden"]').setAttribute('data-term-slug', slug); }

        list.appendChild(chip);
        chipSyncMenu(picker);
        // A CHIP IS A CHANGE, as a tick is: conditional logic reads the form again.
        picker.dispatchEvent(new Event('change', { bubbles: true }));
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
                option.setAttribute('data-slug', term.slug || '');
                option.textContent = term.name;
                menu.insertBefore(option, make);

                chipAdd(picker, String(term.id), term.name, term.slug || '');
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

        var makeOptions = closest(e.target, '[data-options-make]');
        if (makeOptions) { optionsMake(makeOptions); return; }

        var bulk = closest(e.target, '[data-bulk-apply]');
        if (bulk) { optionsBulkApply(closest(bulk, '[data-options-bulk]')); return; }

        var remove = closest(e.target, '[data-repeater-remove]');
        if (remove) { closest(remove, '[data-repeater-row]').remove(); return; }

        var chipX = closest(e.target, '[data-chip-remove]');
        if (chipX) {
            var picker = closest(chipX, '[data-chip-picker]');
            closest(chipX, '.chip').remove();
            chipSyncMenu(picker);
            picker.dispatchEvent(new Event('change', { bubbles: true }));
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
            chipAdd(owner, option.getAttribute('data-value'), option.getAttribute('data-label'), option.getAttribute('data-slug'));
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
                chipAdd(picker, active.getAttribute('data-value'), active.getAttribute('data-label'), active.getAttribute('data-slug'));
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
                // ITS SLUG, for a logic rule naming the term by it (`logic.js`).
                input.setAttribute('data-term-slug', term.slug || '');

                var span = doc.createElement('span');
                // textContent, never innerHTML: the name is whatever the author
                // typed and it is stored RAW. Escaping happens here, at output,
                // by using the DOM instead of string concatenation.
                span.textContent = term.name;

                row.appendChild(input);
                row.appendChild(span);
                placeTermRow(list, row, term.parent_id);
                if (under) { addParentOption(under, term); }
                // TICKED ON ARRIVAL IS A CHANGE: conditional logic reads the form again.
                input.dispatchEvent(new Event('change', { bubbles: true }));

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
        /** One file: `onChosen({id, url})`. `opts.title` names the dialog. */
        pick: function (onChosen, opts) {
            if (typeof onChosen !== 'function') { return; }
            mediaOpen(onChosen, { title: opts && opts.title });
        },
        /** Several pictures: `onChosen(files)`, each as the media API gives one,
            in the order chosen. `opts.taken` lists the ids the caller has already. */
        pickMany: function (onChosen, opts) {
            if (typeof onChosen !== 'function') { return; }
            mediaOpen(onChosen, { many: true, title: (opts && opts.title) || 'Add pictures', taken: (opts && opts.taken) || [] });
        }
    };

    /* A LONG LIST OF WHAT AN ENTRY MAY BELONG TO, SEARCHED AS ONE TYPES
       (`[data-parent-find]` over its select): the words narrow the options to
       those whose names hold them, "Nothing yet" and the one chosen kept, and
       the first match is chosen, so typing part of a name finds it. Emptied,
       every option is back. The select is still what posts. */
    function parentFind(find) {
        var select = doc.getElementById(find.getAttribute('aria-controls'));
        if (!select) { return; }
        if (!select.__all) {
            select.__all = Array.prototype.map.call(select.options, function (o) { return o.cloneNode(true); });
        }
        var q      = find.value.trim().toLowerCase();
        var chosen = select.value;
        var first  = null;
        select.innerHTML = '';
        select.__all.forEach(function (o, i) {
            var hit = q !== '' && i > 0 && o.textContent.toLowerCase().indexOf(q) !== -1;
            if (q === '' || i === 0 || hit || o.value === chosen) {
                select.appendChild(o.cloneNode(true));
            }
            if (hit && first === null) { first = o.value; }
        });
        select.value = first !== null ? first : chosen;
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }
    doc.addEventListener('input', function (e) {
        var find = closest(e.target, '[data-parent-find]');
        if (find) { parentFind(find); }
    });

    // ENTER ADDS, and does not submit the entry. Without this the key that
    // obviously means "add this term" saves the whole post instead — with the
    // term box still full, so the term is not created either.
    doc.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') { return; }
        // In the parent's find box, Enter keeps what was found rather than saving.
        if (e.target.matches && e.target.matches('[data-parent-find]')) { e.preventDefault(); return; }
        var name = e.target.closest('[data-term-name]');
        if (!name) { return; }
        e.preventDefault();
        addTerm(name.closest('[data-term-add]').querySelector('[data-term-create]'));
    });

    // THE SET TOOL SHOWS THE BOX THE FIELD NEEDS: a number for a price or a
    // count, the library's picker for a photo.
    doc.addEventListener('change', function (e) {
        var bulkField = closest(e.target, '[data-bulk-field]');
        if (bulkField) { optionsBulkField(closest(bulkField, '[data-options-bulk]')); }
    });

    // A repeater drawn as a table: its keys and its paste (3a). A cell a paste
    // refused is itself again as soon as someone types in it.
    doc.addEventListener('keydown', tableEnter);
    doc.addEventListener('paste', tablePaste);
    doc.addEventListener('input', function (e) {
        if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-paste-refused')) {
            e.target.removeAttribute('data-paste-refused');
            e.target.setCustomValidity('');
        }
    });
}());
