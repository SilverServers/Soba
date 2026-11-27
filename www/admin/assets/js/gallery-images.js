/**
 * A GALLERY'S PICTURES — adding, uploading, removing, reordering, and each
 * one's details: the Galleries screen's (`[data-gallery]` on its form) and
 * a gallery field's (`FieldRenderer::renderGallery()`), every one on the page.
 * The tiles are `Ui\GalleryTiles`'s, one markup for both.
 *
 * One hidden input, `[data-gallery-ids]`, holds the pictures' ids in display
 * order: the Galleries screen's `image_ids`, which
 * `GalleryController::parseImageIds()` splits and `Gallery::setItems()`
 * stores in that order, or a field's `field_{key}[ids]`, which
 * `FieldRenderer::sanitizeField()` reads the same way. It is not a diff: it is
 * the whole gallery every save. An id missing from it is a picture removed;
 * the order in it IS the order on the page. Each tile's details are hidden
 * inputs inside it, named by the root's `data-gallery-name`, so a drag moves
 * them and a remove takes them.
 *
 * ── THE PICKER IS THE ONE PICKER ────────────────────────────────────────────
 * `CmsMedia.pickMany()` (entry-form.js), with the library's search, folders,
 * pages and uploads: several chosen, then added at once, and a picture the
 * gallery has shown chosen and not offered again, since it never holds one
 * twice. A tile's thumbnail is `CmsMedia.pick()`.
 *
 * ── MANY AT ONCE, DROPPED IN OR UPLOADED ────────────────────────────────────
 * Each file is sent in turn by `CMS.upload()` (upload.js), its progress
 * shown, and added as it lands. A gallery field (`data-gallery-pictures`)
 * takes pictures alone, and says so of any other file before sending it. The
 * form waits for them: a save while files are still going up is held, and
 * says why.
 *
 * ── REORDERING IS reorder.js, LIKE EVERYTHING ELSE ──────────────────────────
 * It used to be native HTML5 drag-and-drop, justified in this header by the
 * claim that reorder.js "drives list ROWS with an up/down mechanism". That was
 * never true of the file it names: reorder.js decides its insertion point by
 * comparing the pointer against each sibling's CENTRE precisely so that a
 * wrapped grid and a single column take the same code path.
 *
 * WHAT THE CLAIM COST. HTML5 drag-and-drop DOES NOT FIRE ON TOUCH. The owner's
 * users are on tablets, so reordering a gallery did not exist for them at all —
 * and there was no keyboard path either, because HTML5 DnD has no keyboard
 * story. Two whole input methods, on the one screen whose entire job is
 * arranging things in an order. A file dropped from the desktop is another
 * thing: it is the browser's own drop, which reorder.js never sees.
 */
(function () {
    'use strict';

    var doc = document;

    function bind(root) {
        var hidden   = root.querySelector('[data-gallery-ids]');
        var tiles    = root.querySelector('[data-gallery-tiles]');
        var template = root.querySelector('[data-gallery-template]');
        var emptyBox = root.querySelector('[data-gallery-empty]');
        if (!hidden || !tiles || !template || root.__galleryBound) { return; }
        root.__galleryBound = true;

        // EACH DETAIL'S INPUT NAME, the detail and the picture's id in place.
        var names    = root.getAttribute('data-gallery-name') || 'item_{detail}[{id}]';
        var pictures = root.hasAttribute('data-gallery-pictures');
        var form     = root.tagName === 'FORM' ? root : root.closest('form');
        var sending  = 0;

        /* ── 1. THE HIDDEN INPUT IS REBUILT FROM THE DOM ─────────────────── */
        /* AND SAYS SO, after a person's change: a child's row on its parent's
           editor (child-rows.js) is saved on it. Not as the page starts, which
           changed nothing. */
        function sync(changed) {
            var nodes = tiles.querySelectorAll('[data-gallery-tile]');
            var ids   = [];
            for (var i = 0; i < nodes.length; i++) {
                ids.push(nodes[i].getAttribute('data-id'));
            }
            hidden.value = ids.join(',');
            if (emptyBox) { emptyBox.hidden = ids.length !== 0; }
            if (changed === true) { hidden.dispatchEvent(new Event('change', { bubbles: true })); }
        }
        function changed() { sync(true); }

        function has(id) {
            return !!tiles.querySelector('[data-gallery-tile][data-id="' + id + '"]');
        }

        function addTile(item) {
            // NEVER TWICE. `setItems()` would happily insert the same attachment
            // twice at two positions, and the gallery would show a duplicate with
            // no way to tell the tiles apart; a field keeps each picture once too.
            if (!item || has(String(item.id))) { return; }

            var frag = template.content.cloneNode(true);
            var fig  = frag.querySelector('[data-gallery-tile]');
            var img  = fig.querySelector('img');
            var grip = fig.querySelector('[data-gallery-grip]');
            var edit = fig.querySelector('[data-gallery-edit]');
            var kill = fig.querySelector('[data-gallery-remove]');
            var name = item.original_name || item.filename || '';

            fig.setAttribute('data-id', String(item.id));
            var inputs = fig.querySelectorAll('[data-detail]');
            for (var k = 0; k < inputs.length; k++) {
                inputs[k].name = names.split('{detail}').join(inputs[k].getAttribute('data-detail')).split('{id}').join(String(item.id));
            }
            if (img) {
                img.src = item.url;
                img.setAttribute('data-url', item.url);
                img.alt = item.alt_text || '';
            }
            // THE FILENAME IS NOT A CAPTION. A row of text under every tile is
            // height the public gallery does not have. It survives where it is
            // useful — telling two similar thumbnails apart on hover, and naming
            // what the buttons act on.
            if (grip) { grip.title = name; }
            if (edit && name) { edit.setAttribute('aria-label', edit.getAttribute('aria-label') + ' of ' + name); }
            if (kill && name) { kill.setAttribute('aria-label', 'Remove ' + name + ' from this gallery'); }

            tiles.appendChild(frag);
        }

        /* ── 2. THE PICKER: the one picker, choosing several ─────────────── */
        function choose() {
            if (!window.CmsMedia || typeof window.CmsMedia.pickMany !== 'function') {
                console.error('gallery-images: entry-form.js did not load, so the library cannot be opened here.');
                return;
            }
            var button = root.querySelector('[data-gallery-add]');
            window.CmsMedia.pickMany(function (files) {
                files.forEach(addTile);
                changed();
            }, { taken: hidden.value === '' ? [] : hidden.value.split(','), title: button ? button.textContent.trim() : '' });
        }

        /* ── 2a. MANY AT ONCE: dropped in, or chosen to upload ───────────── */
        var progress = root.querySelector('[data-gallery-progress]');

        function shown(text, share) {
            if (!progress) { return; }
            var pct = Math.round(Math.max(0, Math.min(1, share)) * 100);
            progress.hidden = text === null;
            if (text === null) { return; }
            progress.querySelector('[data-gallery-progress-text]').textContent = text;
            progress.querySelector('[data-gallery-progress-fill]').style.width = pct + '%';
            progress.querySelector('.progress-bar').setAttribute('aria-valuenow', String(pct));
        }

        function isPicture(file) {
            return /^image\//i.test(file.type || '') || /\.(jpe?g|png|gif|webp|svg|hei[cf])$/i.test(file.name || '');
        }

        function upload(list) {
            var files = Array.prototype.slice.call(list || []);
            if (pictures) {
                files = files.filter(function (file) {
                    if (isPicture(file)) { return true; }
                    CMS.flash(file.name + ': this field takes pictures only.', 'error');
                    return false;
                });
            }
            if (!files.length) { return; }

            var total = files.length;
            var n     = 0;
            sending++;

            // ONE AT A TIME: a pool on shared hosting takes a sequence, where
            // thirty at once would take it down.
            function next() {
                if (!files.length) {
                    sending--;
                    shown(null, 0);
                    sync();
                    return;
                }
                var file = files.shift();
                var says = total > 1 ? 'Uploading ' + (++n) + ' of ' + total + ': ' + file.name : 'Uploading ' + file.name;
                shown(says, 0);
                CMS.upload(file, {}, function (share) { shown(says, share); })
                    .then(function (data) { addTile(data); changed(); next(); })
                    .catch(function (err) {
                        // NAMED, because "Upload failed" on an eight-file drop does
                        // not tell you which one to fix.
                        CMS.flash(file.name + ': ' + err.message, 'error');
                        next();
                    });
            }
            next();
        }

        var chooser = root.querySelector('[data-gallery-upload]');
        if (chooser) {
            chooser.addEventListener('change', function () {
                upload(chooser.files);
                chooser.value = '';
            });
        }

        function carriesFiles(e) {
            return !!e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') !== -1;
        }
        root.addEventListener('dragover', function (e) {
            if (!carriesFiles(e)) { return; }
            e.preventDefault();
            tiles.classList.add('is-dropzone');
        });
        root.addEventListener('dragleave', function (e) {
            if (!root.contains(e.relatedTarget)) { tiles.classList.remove('is-dropzone'); }
        });
        root.addEventListener('drop', function (e) {
            tiles.classList.remove('is-dropzone');
            if (!carriesFiles(e) || !e.dataTransfer.files.length) { return; }
            e.preventDefault();
            upload(e.dataTransfer.files);
        });

        /* ── 2b. AN ITEM'S DETAILS ────────────────────────────────────────────
           The Galleries screen's are its title, caption, link and thumbnail
           (migrations 091, 127); a field's, its caption. Each box is there or
           not; Done writes the tile's own inputs and repaints the tile the way
           the public grid will draw it, and nothing is saved until the form is. */
        var details = root.querySelector('[data-gallery-details]');
        var editing = null;
        var thumb   = { id: '', url: '' };

        function detail(tile, key) { return tile.querySelector('[data-detail="' + key + '"]'); }
        function box(name) { return details.querySelector('[data-gd-' + name + ']'); }

        /* THE ENDS, AS THE SERVER TRIMS THEM: spaces, tabs and line breaks, and
           nothing else. `trim()` would take a no-break space as well, and a title
           or caption may carry one on purpose; the server keeps it, so a box
           opened and closed changes nothing. */
        function ends(s) { return s.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, ''); }

        /* WHY A BOX'S WORDS ARE TOO LONG, or ''. Counted in characters, as the
           server and the column count them (a pair of UTF-16 halves is one), and
           against the box's own `data-most`, which the server writes from the
           same limit it enforces. Never cut: the box keeps every character. */
        function tooLong(el, what, text) {
            var most = parseInt(el.getAttribute('data-most'), 10) || 0;
            var n    = Array.from(text).length;
            if (!most || n <= most) { return ''; }
            var comma = function (v) { return String(v).replace(/\B(?=(\d{3})+(?!\d))/g, ','); };
            return 'A ' + what + ' can be at most ' + comma(most) + ' characters; this one has ' + comma(n) + '.';
        }

        function say(message) {
            var el = box('error');
            el.textContent = message;
            el.hidden = message === '';
        }

        function paintThumb() {
            var frame = box('thumb');
            if (!frame) { return; }
            var img   = frame.querySelector('img');
            var glyph = frame.querySelector('[data-gd-glyph]');
            if (thumb.id) {
                if (!img) { img = doc.createElement('img'); img.alt = ''; frame.appendChild(img); }
                img.src = thumb.url;
                if (glyph) { glyph.style.display = 'none'; }
            } else {
                if (img) { img.remove(); }
                if (glyph) { glyph.style.display = ''; }
            }
            box('choose').textContent = thumb.id ? 'Replace' : 'Choose';
            box('clear').hidden = !thumb.id;
        }

        function openDetails(tile) {
            editing = tile;
            var t = detail(tile, 'thumb');
            thumb = t ? { id: t.value, url: t.getAttribute('data-thumb-url') || '' } : { id: '', url: '' };
            ['title', 'caption', 'link'].forEach(function (key) {
                if (box(key) && detail(tile, key)) { box(key).value = detail(tile, key).value; }
            });
            paintThumb();
            say('');
            details.showModal();
            (box('title') || box('caption')).focus();
        }

        function saveDetails() {
            if (!editing) { return; }
            var titleBox   = box('title');
            var captionBox = box('caption');
            var linkBox    = box('link');
            var title   = titleBox ? ends(titleBox.value) : '';
            var caption = captionBox ? ends(captionBox.value) : '';
            var link    = linkBox ? linkBox.value.trim() : '';
            /* THE SERVER REFUSES ANYTHING ELSE TOO; this says why while the box
               is still open, instead of dropping it on save. */
            var titleLong   = titleBox ? tooLong(titleBox, 'title', title) : '';
            var captionLong = captionBox ? tooLong(captionBox, 'caption', caption) : '';
            if (titleLong || captionLong) {
                say([titleLong, captionLong].filter(Boolean).join(' '));
                (titleLong ? titleBox : captionBox).focus();
                return;
            }
            if (link !== '' && !/^(https?:\/\/\S+|\/(?!\/)\S*)$/i.test(link)) {
                say('A link is a page on this site, starting with /, or a whole address starting with https://.');
                return;
            }
            if (titleBox)   { detail(editing, 'title').value   = title; }
            if (captionBox) { detail(editing, 'caption').value = caption; }
            if (linkBox)    { detail(editing, 'link').value    = link; }
            var t = detail(editing, 'thumb');
            if (t) {
                t.value = thumb.id;
                t.setAttribute('data-thumb-url', thumb.url);
            }

            var img = editing.querySelector('img');
            if (img && t) { img.src = thumb.id ? thumb.url : (img.getAttribute('data-url') || img.src); }
            var head  = editing.querySelector('[data-detail-title]');
            var cap   = editing.querySelector('[data-detail-caption]');
            var words = editing.querySelector('[data-detail-words]');
            if (head)  { head.textContent = title; head.hidden = title === ''; }
            if (cap)   { cap.textContent = caption; cap.hidden = caption === ''; }
            if (words) { words.hidden = title === '' && caption === ''; }
            var mark = editing.querySelector('[data-detail-link]');
            if (mark) { mark.hidden = link === ''; mark.title = link; }

            details.close();
            editing = null;
            changed();
        }

        if (details) {
            details.addEventListener('click', function (e) {
                if (e.target.closest('[data-gd-cancel]')) { details.close(); editing = null; return; }
                if (e.target.closest('[data-gd-ok]'))     { saveDetails(); return; }
                if (e.target.closest('[data-gd-clear]'))  { thumb = { id: '', url: '' }; paintThumb(); return; }
                if (e.target.closest('[data-gd-choose]') && window.CmsMedia) {
                    window.CmsMedia.pick(function (picked) { thumb = picked; paintThumb(); }, { title: 'Choose a thumbnail' });
                }
            });
            /* Enter in a box is Done — not a save of the whole form, which is
               what an Enter inside it would otherwise be. In the caption, a
               textarea, Enter is a new line. */
            details.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && e.target.matches('input')) { e.preventDefault(); saveDetails(); }
            });
        }

        /* ── 3. REORDER ──────────────────────────────────────────────────────
           THE SAME SYSTEM THE FORM BUILDER AND THE FIELD GROUP ROWS USE. The
           tiles are a grid rather than a list, which reorder.js does not care
           about: the insertion point is the nearest sibling centre plus a
           before/after read from the axis those siblings actually run in.

           A HARD FAILURE, NOT A SILENT SKIP. A gallery that quietly stops
           reordering reads to the person using it as though they mis-dragged. */
        if (window.Reorder && typeof window.Reorder.create === 'function') {
            window.Reorder.create(tiles, {
                handle:      '[data-gallery-grip]',
                ghostClass:  'sortable-ghost',
                chosenClass: 'sortable-chosen',
                animation:   150,
                // THE HIDDEN INPUT IS THE GALLERY: the order here IS what the
                // visitor sees, and tiles that moved on screen without this
                // running would save the order the owner started with.
                onEnd:       changed
            });
        } else {
            console.error('gallery-images: reorder.js did not load — the tiles cannot be reordered.');
        }

        /* ── 4. THE SETTINGS DRIVE THE PREVIEW, AS YOU CHANGE THEM ───────────
           On the Galleries screen, Layout and Columns write a class and a
           custom property onto the public container, which the screen renders
           with the same stylesheet, on `input`, not on save. The names are the
           ones the controls post (`layout`, `columns`), so this cannot drift
           from what the server stores. A field's are its definition's. */
        function repaint() {
            var layout = root.querySelector('[name="layout"]');
            var cols   = root.querySelector('[name="columns"]');

            if (layout) {
                tiles.classList.remove('cms-gallery--grid', 'cms-gallery--masonry');
                tiles.classList.add('cms-gallery--' + layout.value);
            }
            if (cols) {
                tiles.style.setProperty('--gallery-columns', String(parseInt(cols.value, 10) || 3));
            }
        }

        root.addEventListener('change', function (e) {
            if (e.target.name === 'layout' || e.target.name === 'columns') { repaint(); }
        });

        /* ── 5. EVENTS ─────────────────────────────────────────────────────── */
        root.addEventListener('click', function (e) {
            if (e.target.closest('[data-gallery-add]')) {
                e.preventDefault();
                choose();
                return;
            }
            var ed = e.target.closest('[data-gallery-edit]');
            if (ed) {
                e.preventDefault();
                var edited = ed.closest('[data-gallery-tile]');
                if (edited && details) { openDetails(edited); }
                return;
            }
            var rm = e.target.closest('[data-gallery-remove]');
            if (rm) {
                e.preventDefault();
                var tile = rm.closest('[data-gallery-tile]');
                if (tile) { tile.remove(); }
                changed();
            }
        });

        if (form) {
            form.addEventListener('submit', function (e) {
                if (sending > 0) {
                    e.preventDefault();
                    CMS.flash('Wait for the pictures to finish uploading, then save.', 'warning');
                    return;
                }
                sync();
            });
        }

        sync();
    }

    var roots = doc.querySelectorAll('[data-gallery]');
    for (var r = 0; r < roots.length; r++) { bind(roots[r]); }

    // A GALLERY DRAWN AFTER THE PAGE, such as a child's row added in place on
    // its parent's editor (child-rows.js), is bound by this.
    window.GalleryImages = { bind: bind };
}());
