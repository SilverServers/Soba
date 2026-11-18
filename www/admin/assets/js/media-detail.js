/**
 * media-detail.js — the media library's detail panel.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 * A tile that is only `<figure><img></figure>` — no link, no button, no
 * handler — is an image that does nothing when clicked. `Api\MediaController`
 * carries update, replace, versions and delete, and this panel is their user
 * interface: without it, replacing a file and its `MediaVersion` history could
 * not be reached from the admin.
 *
 * ── THE PANEL IS FILLED FROM THE TILE, NOT FROM A FETCH ─────────────────────
 * Every value it shows was already sent with the page and sits on the tile as
 * `data-payload`. Re-reading it over the network would be latency bought with
 * nothing. The panel's MARKUP is server-rendered too, and only its values are
 * written here — building the shape in JS would be a second renderer for a
 * template that already owns it, which is the rule the repeater and the form
 * builder both follow.
 *
 * ── AFTER A WRITE, THE TILE IS UPDATED IN PLACE ─────────────────────────────
 * Save rewrites the tile's caption, its `alt`, and its payload, so the grid can
 * never disagree with what was just saved. Replace and Delete reload instead:
 * both change the file itself — a new size, a new address, one fewer row — and
 * patching that by hand is how a list starts lying. `media-library.js` reloads
 * after an upload for the same reason and states it.
 *
 * ── EVERY WRITE GOES THROUGH CMS.post ───────────────────────────────────────
 * which owns the CSRF header and unwraps the envelope. There is no second way
 * to POST from this admin.
 */
(function () {
    'use strict';

    var doc    = document;
    var layout = doc.querySelector('[data-media-layout]');
    var grid   = doc.querySelector('[data-media-grid]');
    var panel  = doc.querySelector('[data-media-detail]');
    if (!layout || !grid || !panel) { return; }

    var current = null;   // the payload of the selected tile
    var tile    = null;   // the selected element

    function q(sel)  { return panel.querySelector(sel); }
    function base()  { return (window.CMS && CMS.base) ? CMS.base : ''; }

    /* ── Messages ─────────────────────────────────────────────────────────
       One line inside the panel rather than a toast: the thing it is reporting
       on is right there, and a message that floats somewhere else makes you
       look away from it. */
    var msgTimer = null;
    function say(text, isError) {
        var el = q('[data-md-msg]');
        if (!el) { return; }
        el.textContent = '';
        if (isError) {
            // `.alert-flag` is the sanctioned inline alert SURFACE — its own box,
            // its own background, its own edge. Painting the strip itself red
            // instead breaks the rule that alert colour is a surface, never a
            // state on ordinary themed chrome.
            var flag = doc.createElement('span');
            flag.className = 'alert-flag';
            flag.textContent = text;
            el.appendChild(flag);
        } else {
            el.textContent = text;
        }
        el.hidden = false;
        el.classList.toggle('media-detail__msg--error', !!isError);
        clearTimeout(msgTimer);
        if (!isError) {
            msgTimer = setTimeout(function () { el.hidden = true; }, 3000);
        }
    }

    /* ── Select ───────────────────────────────────────────────────────────── */

    function select(el) {
        var raw = el.getAttribute('data-payload');
        if (!raw) { return; }
        try { current = JSON.parse(raw); } catch (e) { return; }

        if (tile) { tile.classList.remove('is-selected'); }
        tile = el;
        tile.classList.add('is-selected');

        // showModal(), not `hidden = false`. The native dialog brings the focus
        // trap, the backdrop and Esc with it. Guarded because select() also runs
        // when stepping with prev/next, and calling showModal() on an already
        // open dialog throws InvalidStateError.
        if (!panel.open) { panel.showModal(); }
        syncNav();

        q('[data-md-name]').textContent     = current.name;
        q('[data-md-mime]').textContent     = current.mime;
        q('[data-md-size]').textContent     = current.size +
            (current.width && current.height ? ' · ' + current.width + ' × ' + current.height : '');

        // THE DATES: boxes for an editor, facts for anyone else — the template
        // draws one or the other. Date taken is a photo's, so a document has
        // no box for it and no empty row.
        var added = q('[data-md-added]');
        if (added) { added.value = current.added || ''; }
        var taken = q('[data-md-taken]');
        if (taken) { taken.value = current.taken || ''; }
        var takenField = q('[data-md-taken-field]');
        if (takenField) { takenField.hidden = !current.is_image; }
        var addedText = q('[data-md-added-text]');
        if (addedText) { addedText.textContent = current.added_text || ''; }
        var takenText = q('[data-md-taken-text]');
        if (takenText) { takenText.textContent = current.taken_text || ''; }
        var takenRow = q('[data-md-taken-row]');
        if (takenRow) { takenRow.hidden = !current.taken_text; }

        // PREVIEW. An image previews as itself; anything else shows the tile's
        // own glyph rather than a broken <img>, which is what an `<img>` with a
        // PDF in its src renders as.
        var prev = q('[data-md-preview]');
        prev.innerHTML = '';
        if (current.is_image) {
            var img = doc.createElement('img');
            img.src = current.url;
            img.alt = current.alt || '';
            prev.appendChild(img);
        } else {
            var kind = el.querySelector('.media-card__kind');
            if (kind) { prev.appendChild(kind.cloneNode(true)); }
        }

        q('[data-md-title]').value = current.name;
        q('[data-md-url]').value   = current.url;

        var altField = q('[data-md-alt-field]');
        // ALT TEXT IS FOR IMAGES. Offering it on a PDF collects a value nothing
        // reads — the same fault as a placeholder on a checkbox.
        if (altField) { altField.hidden = !current.is_image; }
        var alt = q('[data-md-alt]');
        if (alt) { alt.value = current.alt || ''; }

        var folder = q('[data-md-folder]');
        if (folder) { folder.value = current.folder_id === null ? '' : String(current.folder_id); }

        var vis = q('[data-md-visibility]');
        if (vis) { vis.value = current.visibility || 'public'; }

        // A FILE THE SITE ALREADY HAD (`Media::register()`) stays where the
        // site keeps it: it is never replaced or made private, and removing it
        // only forgets it. The template draws both ways; this shows the one
        // that is true of this file.
        var site = !!current.site;
        var replace = q('[data-md-replace]');
        if (replace) { replace.hidden = site; }
        var visField = q('[data-md-visibility-field]');
        if (visField) { visField.hidden = site; }
        // ITS NAME, as an address carries it (`Media::rename()`): the site's
        // own file keeps the name the site gave it.
        var fileField = q('[data-md-file-field]');
        if (fileField) { fileField.hidden = site; }
        var fileName = q('[data-md-file]');
        if (fileName) { fileName.value = stemOf(current.file); }
        var siteNote = q('[data-md-site]');
        if (siteNote) { siteNote.hidden = !site; }
        Array.prototype.forEach.call(panel.querySelectorAll('[data-md-delete]'), function (b) {
            b.hidden = (b.getAttribute('data-md-delete') === 'site') !== site;
        });
        // A SECTION WITH NOTHING LEFT IN IT is not drawn: Filing, for such a
        // file in a library with no folders, would be a heading over nothing.
        var filing = q('[data-md-filing]');
        if (filing) {
            filing.hidden = !Array.prototype.some.call(filing.querySelectorAll('.field'), function (f) { return !f.hidden; });
        }

        var msg = q('[data-md-msg]');
        if (msg) { msg.hidden = true; }
    }

    /** A stored name without its extension: what the File name box holds. */
    function stemOf(name) {
        return String(name || '').replace(/\.[A-Za-z0-9]+$/, '');
    }

    function deselect() {
        if (panel.open) { panel.close(); }
        if (tile) { tile.classList.remove('is-selected'); }
        tile = null;
        current = null;
    }

    /* ── Stepping through the library ──────────────────────────────────────
       The modal covers the grid, so these are how you reach the next file
       without closing — the trade that makes a full-size preview affordable at
       all. They walk the TILES, so they follow whatever the current filter and
       search have left on screen rather than some second idea of the order. */

    function tiles() {
        return Array.prototype.slice.call(grid.querySelectorAll('[data-media-item]'));
    }

    function step(delta) {
        if (!tile) { return; }
        var all = tiles();
        var i = all.indexOf(tile) + delta;
        if (i < 0 || i >= all.length) { return; }
        select(all[i]);
        all[i].scrollIntoView({ block: 'nearest' });
    }

    /* AT THE ENDS THE CONTROL IS DISABLED, NOT HIDDEN — see the CSS note. A
       button that disappears takes its neighbours' positions with it, so `close`
       would slide under the pointer mid-step. */
    function syncNav() {
        var all = tiles();
        var i = all.indexOf(tile);
        var prev = q('[data-md-prev]');
        var next = q('[data-md-next]');
        if (prev) { prev.disabled = i <= 0; }
        if (next) { next.disabled = i < 0 || i >= all.length - 1; }
    }

    grid.addEventListener('click', function (e) {
        var el = e.target.closest('[data-media-item]');
        if (el) { select(el); }
    });

    // The tiles are focusable buttons, so they answer to the keyboard too — a
    // grid you can only reach with a pointer is not reachable.
    grid.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') { return; }
        var el = e.target.closest('[data-media-item]');
        if (!el) { return; }
        e.preventDefault();
        select(el);
    });

    // Esc is the dialog's own; listen for the RESULTING close so the tile's
    // selection is cleared however it was dismissed — Esc, the button, or a
    // future dismissal we have not thought of. Re-implementing Esc here would
    // be a second mechanism racing the native one.
    panel.addEventListener('close', function () {
        if (tile) { tile.classList.remove('is-selected'); }
        tile = null;
        current = null;
    });

    // Left/right step the library, and this listens on the DOCUMENT rather than
    // on the dialog. Bound to the dialog it died at exactly the moment you would
    // reach for it: stepping to the last file DISABLES the next button, the
    // browser drops focus from a disabled element to <body>, and <body> is not
    // inside the dialog — so the keydown never reached the listener and the
    // arrow keys stopped working at the end of the library.
    // `panel.open` is the gate, so this is inert whenever the modal is closed.
    doc.addEventListener('keydown', function (e) {
        if (!panel.open) { return; }
        if (e.key === 'ArrowLeft')  { e.preventDefault(); step(-1); }
        if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
    });

    panel.addEventListener('click', function (e) {
        if (e.target.closest('[data-md-close]')) { deselect(); return; }
        if (e.target.closest('[data-md-prev]'))  { step(-1); return; }
        if (e.target.closest('[data-md-next]'))  { step(1); return; }
    });

    /* ── Copy the address ─────────────────────────────────────────────────── */

    panel.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-md-copy]');
        if (!btn) { return; }
        var input = q('[data-md-url]');
        if (!input) { return; }

        // select() + execCommand is the fallback: the async clipboard API is
        // unavailable on an insecure origin, and a Copy button that silently
        // does nothing is worse than one that is not there.
        function done() {
            var was = btn.textContent;
            btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = was; }, 1500);
        }
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(input.value).then(done, function () { fallback(); });
        } else {
            fallback();
        }
        function fallback() {
            input.removeAttribute('readonly');
            input.select();
            try { doc.execCommand('copy'); done(); } catch (err) { say('Could not copy.', true); }
            input.setAttribute('readonly', '');
            input.blur();
        }
    });

    /* ── Save ─────────────────────────────────────────────────────────────── */

    panel.addEventListener('click', function (e) {
        if (!e.target.closest('[data-md-save]') || !current) { return; }
        var btn = e.target.closest('[data-md-save]');

        var body = new FormData();
        body.append('title', q('[data-md-title]').value);
        var alt = q('[data-md-alt]');
        if (alt && current.is_image) { body.append('alt_text', alt.value); }
        var folder = q('[data-md-folder]');
        if (folder) { body.append('folder_id', folder.value); }
        var vis = q('[data-md-visibility]');
        if (vis && !current.site) { body.append('visibility', vis.value); }
        // A NAME IS SENT ONLY WHEN IT WAS CHANGED: renaming moves the file and
        // every address the site keeps for it.
        var fileName = q('[data-md-file]');
        if (fileName && !current.site && fileName.value.trim() !== stemOf(current.file)) {
            body.append('file_name', fileName.value.trim());
        }
        // A DATE IS SENT ONLY WHEN IT WAS CHANGED. The box holds minutes, and
        // the server keeps the stored seconds for a date that comes back as
        // it went out — but a date nobody touched has no reason to travel.
        // A DATE THAT CANNOT BE SAVED IS SAID HERE, before anything is sent: a
        // file always has a date added, and a box with half a date in it
        // (`badInput`) reads as empty. The server refuses both as well.
        var added = q('[data-md-added]');
        if (added && added.value !== (current.added || '')) {
            if (added.value === '' || !added.validity.valid) { say('Date added needs a date and a time.', true); return; }
            body.append('created_at', added.value);
        }
        var taken = q('[data-md-taken]');
        if (taken && current.is_image && (taken.value !== (current.taken || '') || !taken.validity.valid)) {
            if (!taken.validity.valid) { say('Date taken needs a date and a time, or nothing.', true); return; }
            body.append('taken_at', taken.value);
        }

        btn.disabled = true;
        CMS.post('/api/v1/media/' + current.id + '/update', body)
            .then(function (data) {
                btn.disabled = false;
                say('Saved.');

                // THE TILE IS UPDATED IN PLACE, payload included. Without this
                // the grid keeps showing the old name and hands the old values
                // back the next time the tile is clicked — a panel that saves
                // and a list that forgets.
                var name = data && data.original_name ? data.original_name : q('[data-md-title]').value;
                current.name       = name;
                current.alt        = alt ? alt.value : current.alt;
                current.folder_id  = folder ? (folder.value === '' ? null : parseInt(folder.value, 10)) : current.folder_id;
                current.visibility = vis ? vis.value : current.visibility;
                if (added) { current.added = added.value; }
                if (taken && current.is_image) { current.taken = taken.value; }
                if (data && data.url) { current.url = data.url; q('[data-md-url]').value = data.url; }
                // RENAMED: the name it was given (a taken one gains -2), and the
                // picture shown from its new address.
                if (data && data.filename) {
                    current.file = data.filename;
                    if (fileName) { fileName.value = stemOf(data.filename); }
                    var shown = q('[data-md-preview] img');
                    if (shown && shown.getAttribute('src') !== data.url) { shown.src = data.url; }
                }

                if (tile) {
                    tile.setAttribute('data-payload', JSON.stringify(current));
                    var cap = tile.querySelector('.media-card__name');
                    if (cap) { cap.textContent = name; cap.setAttribute('title', name); }
                    var img = tile.querySelector('img');
                    if (img) {
                        img.alt = current.alt || '';
                        if (img.getAttribute('src') !== current.url) { img.src = current.url; }
                    }
                }
                q('[data-md-name]').textContent = name;
            })
            .catch(function (err) {
                btn.disabled = false;
                say((err && err.message) ? err.message : 'Could not save.', true);
            });
    });

    /* ── Replace ──────────────────────────────────────────────────────────── */

    panel.addEventListener('click', function (e) {
        if (!e.target.closest('[data-md-replace]') || !current) { return; }
        var input = q('[data-md-replace-input]');
        if (input) { input.click(); }
    });

    panel.addEventListener('change', function (e) {
        var input = e.target.closest('[data-md-replace-input]');
        if (!input || !input.files.length || !current) { return; }

        var body = new FormData();
        body.append('file', input.files[0]);
        input.value = '';
        say('Replacing…');

        CMS.post('/api/v1/media/' + current.id + '/replace', body)
            .then(function () {
                // RELOAD, do not patch. The file itself changed — new size, new
                // dimensions, a new version row — and the server already knows
                // how to draw all of that.
                window.location.reload();
            })
            .catch(function (err) {
                say((err && err.message) ? err.message : 'Could not replace the file.', true);
            });
    });

    /* ── Delete ───────────────────────────────────────────────────────────── */

    // confirm.js owns the dialog. It intercepts this button in the CAPTURE
    // phase and re-clicks it with `data-confirmed` set, so by the time this
    // handler runs the question has been asked and answered. One confirmation
    // mechanism in the admin, not a bare window.confirm() beside it.
    panel.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-md-delete]');
        if (!btn || !current) { return; }

        CMS.post('/api/v1/media/' + current.id + '/delete', new FormData())
            .then(function () { window.location.reload(); })
            .catch(function (err) {
                say((err && err.message) ? err.message : 'Could not delete the file.', true);
            });
    });
}());
