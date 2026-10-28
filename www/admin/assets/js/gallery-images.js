/**
 * THE GALLERY'S IMAGE SET — picking, removing, reordering.
 *
 * One hidden input, `image_ids`, holds a comma-separated list in display order.
 * `GalleryController::parseImageIds()` splits it and `Gallery::setItems()`
 * TRUNCATES the gallery and re-inserts with `position` taken from array order —
 * so this list is not a diff, it is the whole gallery every save. An id missing
 * from it is an image removed; the order in it IS the order on the page.
 *
 * ── THE PICKER IS ITS OWN DIALOG, NOT entry-form.js's ───────────────────────
 * That one is bound to `[data-media-picker]`, selects exactly ONE file, and
 * closes on choose — it sets a featured image. This picks MANY and stays open.
 * Sharing it would mean adding a mode flag to a component whose whole contract
 * is "one file, then close".
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
 * arranging things in an order.
 */
(function () {
    'use strict';

    var doc  = document;
    var root = doc.querySelector('[data-gallery]');
    if (!root) { return; }

    var hidden   = root.querySelector('[data-gallery-ids]');
    var tiles    = root.querySelector('[data-gallery-tiles]');
    var template = root.querySelector('[data-gallery-template]');
    var emptyBox = root.querySelector('[data-gallery-empty]');
    if (!hidden || !tiles || !template) { return; }

    var dialog = null;
    /* The picker's pick-one mode: a function to hand the chosen file to, or
       null for "Add images" (many, stays open). */
    var pickOne = null;

    /* ── 1. THE HIDDEN INPUT IS REBUILT FROM THE DOM ─────────────────────── */
    function sync() {
        var nodes = tiles.querySelectorAll('[data-gallery-tile]');
        var ids   = [];
        for (var i = 0; i < nodes.length; i++) {
            ids.push(nodes[i].getAttribute('data-id'));
        }
        hidden.value = ids.join(',');
        if (emptyBox) { emptyBox.hidden = ids.length !== 0; }
    }

    function has(id) {
        return !!tiles.querySelector('[data-gallery-tile][data-id="' + id + '"]');
    }

    function addTile(item) {
        // NEVER TWICE. `setItems()` would happily insert the same attachment
        // twice at two positions, and the gallery would show a duplicate with no
        // way to tell the tiles apart.
        if (has(String(item.id))) { return; }

        var frag = template.content.cloneNode(true);
        var fig  = frag.querySelector('[data-gallery-tile]');
        var img  = fig.querySelector('img');
        var grip = fig.querySelector('[data-gallery-grip]');
        var kill = fig.querySelector('[data-gallery-remove]');
        var name = item.original_name || item.filename || '';

        fig.setAttribute('data-id', String(item.id));
        // ITS DETAILS TRAVEL WITH IT (migration 091): three inputs inside the
        // tile, keyed by the attachment id, so a drag moves them and a remove
        // takes them — GalleryController::parseItems() reads them by id.
        var inputs = fig.querySelectorAll('[data-detail]');
        for (var k = 0; k < inputs.length; k++) {
            inputs[k].name = 'item_' + inputs[k].getAttribute('data-detail') + '[' + item.id + ']';
        }
        if (img) {
            img.src = item.url;
            img.setAttribute('data-url', item.url);
            img.alt = item.alt_text || '';
        }
        // THE FILENAME IS NOT A CAPTION ANY MORE. A row of text under every
        // tile is height the public gallery does not have, and a preview that
        // is a different SHAPE is a second grid again. It survives where it is
        // actually useful — telling two similar thumbnails apart on hover, and
        // naming the thing the remove button would remove.
        if (grip) { grip.title = name; }
        if (kill && name) {
            kill.setAttribute('aria-label', 'Remove ' + name + ' from this gallery');
        }

        tiles.appendChild(frag);
    }

    /* ── 2. THE PICKER ───────────────────────────────────────────────────── */
    function buildDialog() {
        var d = doc.createElement('dialog');
        d.className = 'modal modal--wide';
        // `.modal__inner` IS NOT OPTIONAL, and leaving it out is not a small
        // mistake. `.modal` styles the dialog BOX — its width, its surface, its
        // shadow — and `.modal__inner` is what carries `padding: var(--sp-5)`
        // and the flex column that spaces the title, the body and the actions.
        // Without it this dialog had `padding: 0` (measured): the heading sat
        // flush against the top edge, the grid touched both sides, and `Done`
        // was jammed into the bottom-right corner with nothing around it.
        // `confirm.js` and `entry-form.js` both wrap; this was the only one that
        // did not, which is why it was the only picker that looked broken.
        d.innerHTML =
            '<div class="modal__inner">' +
              '<h2 class="modal__title">Add images</h2>' +
              '<div class="media-grid" data-picker-grid></div>' +
              '<div class="modal__actions">' +
                '<button type="button" class="btn" data-picker-close>Done</button>' +
              '</div>' +
            '</div>';
        doc.body.appendChild(d);
        // Closed without a pick (Done, Escape): the next open is "Add images".
        d.addEventListener('close', function () { pickOne = null; });

        d.addEventListener('click', function (e) {
            if (e.target.closest('[data-picker-close]')) { d.close(); return; }
            var tile = e.target.closest('[data-picker-item]');
            if (!tile) { return; }
            if (pickOne) {
                var hand = pickOne;
                pickOne = null;
                d.close();
                hand({ id: tile.getAttribute('data-id'), url: tile.getAttribute('data-url') });
                return;
            }
            addTile({
                id:            tile.getAttribute('data-id'),
                url:           tile.getAttribute('data-url'),
                original_name: tile.getAttribute('data-name'),
                alt_text:      tile.getAttribute('data-alt')
            });
            // STAYS SELECTED AND STAYS OPEN. Picking six images should be six
            // clicks, not six round trips through a dialog.
            tile.setAttribute('aria-pressed', 'true');
            tile.disabled = true;
            sync();
        });

        return d;
    }

    function openPicker(one) {
        if (!dialog) { dialog = buildDialog(); }
        pickOne = one || null;
        dialog.querySelector('.modal__title').textContent = pickOne ? 'Choose a thumbnail' : 'Add images';
        var grid = dialog.querySelector('[data-picker-grid]');
        grid.innerHTML = '<p class="form-help">Loading…</p>';
        dialog.showModal();

        CMS.api('/api/v1/media?type=image')
            .then(function (data) {
                var items = (data && data.items) || [];
                if (items.length === 0) {
                    grid.innerHTML =
                        '<div class="empty"><p class="empty__title">No images in the library yet</p></div>';
                    return;
                }
                grid.innerHTML = '';
                items.forEach(function (item) {
                    var b = doc.createElement('button');
                    b.type = 'button';
                    b.className = 'media-grid__item';
                    b.setAttribute('data-picker-item', '');
                    b.setAttribute('data-id', item.id);
                    b.setAttribute('data-url', item.url);
                    b.setAttribute('data-name', item.original_name || item.filename || '');
                    b.setAttribute('data-alt', item.alt_text || '');
                    if (!pickOne && has(String(item.id))) {
                        b.setAttribute('aria-pressed', 'true');
                        b.disabled = true;
                    }
                    var img = doc.createElement('img');
                    img.src = item.url;
                    img.alt = item.alt_text || '';
                    img.loading = 'lazy';
                    b.appendChild(img);
                    grid.appendChild(b);
                });
            })
            .catch(function (err) {
                grid.innerHTML = '';
                var p = doc.createElement('p');
                p.className = 'form-help';
                // textContent, not innerHTML: the message comes off the wire.
                p.textContent = err.message;
                grid.appendChild(p);
            });
    }

    /* ── 2b. AN ITEM'S DETAILS — caption, link, thumbnail (migration 091) ────
       Done writes the tile's own inputs and repaints the tile the way the
       public grid will draw it; nothing is saved until the gallery is. */
    var details = root.querySelector('[data-gallery-details]');
    var editing = null;
    var thumb   = { id: '', url: '' };

    function detail(tile, key) { return tile.querySelector('[data-detail="' + key + '"]'); }

    function say(message) {
        var box = details.querySelector('[data-gd-error]');
        box.textContent = message;
        box.hidden = message === '';
    }

    function paintThumb() {
        var box   = details.querySelector('[data-gd-thumb]');
        var img   = box.querySelector('img');
        var glyph = box.querySelector('[data-gd-glyph]');
        if (thumb.id) {
            if (!img) { img = doc.createElement('img'); img.alt = ''; box.appendChild(img); }
            img.src = thumb.url;
            if (glyph) { glyph.style.display = 'none'; }
        } else {
            if (img) { img.remove(); }
            if (glyph) { glyph.style.display = ''; }
        }
        details.querySelector('[data-gd-choose]').textContent = thumb.id ? 'Replace' : 'Choose';
        details.querySelector('[data-gd-clear]').hidden = !thumb.id;
    }

    function openDetails(tile) {
        editing = tile;
        var t = detail(tile, 'thumb');
        thumb = { id: t.value, url: t.getAttribute('data-thumb-url') || '' };
        details.querySelector('[data-gd-caption]').value = detail(tile, 'caption').value;
        details.querySelector('[data-gd-link]').value    = detail(tile, 'link').value;
        paintThumb();
        say('');
        details.showModal();
        details.querySelector('[data-gd-caption]').focus();
    }

    function saveDetails() {
        if (!editing) { return; }
        var caption = details.querySelector('[data-gd-caption]').value.trim();
        var link    = details.querySelector('[data-gd-link]').value.trim();
        /* THE SERVER REFUSES ANYTHING ELSE TOO (parseItems()); this says why
           while the box is still open, instead of dropping it on save. */
        if (link !== '' && !/^(https?:\/\/\S+|\/(?!\/)\S*)$/i.test(link)) {
            say('A link is a page on this site, starting with /, or a whole address starting with https://.');
            return;
        }
        detail(editing, 'caption').value = caption;
        detail(editing, 'link').value    = link;
        var t = detail(editing, 'thumb');
        t.value = thumb.id;
        t.setAttribute('data-thumb-url', thumb.url);

        var img = editing.querySelector('img');
        if (img) { img.src = thumb.id ? thumb.url : (img.getAttribute('data-url') || img.src); }
        var cap = editing.querySelector('[data-detail-caption]');
        if (cap) { cap.textContent = caption; cap.hidden = caption === ''; }
        var mark = editing.querySelector('[data-detail-link]');
        if (mark) { mark.hidden = link === ''; mark.title = link; }

        details.close();
        editing = null;
    }

    if (details) {
        details.addEventListener('click', function (e) {
            if (e.target.closest('[data-gd-cancel]')) { details.close(); editing = null; return; }
            if (e.target.closest('[data-gd-ok]'))     { saveDetails(); return; }
            if (e.target.closest('[data-gd-clear]'))  { thumb = { id: '', url: '' }; paintThumb(); return; }
            if (e.target.closest('[data-gd-choose]')) {
                openPicker(function (picked) { thumb = picked; paintThumb(); });
            }
        });
        /* Enter in a box is Done — not a save of the whole gallery, which is
           what an Enter inside the form would otherwise be. */
        details.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.matches('input')) { e.preventDefault(); saveDetails(); }
        });
    }

    /* ── 3. REORDER ──────────────────────────────────────────────────────────
       THE SAME SYSTEM THE FORM BUILDER AND THE FIELD GROUP ROWS USE. The tiles
       are a grid rather than a list, which reorder.js does not care about: the
       insertion point is the nearest sibling centre plus a before/after read
       from the axis those siblings actually run in.

       A HARD FAILURE, NOT A SILENT SKIP. The old CDN call sites guarded with
       `if (typeof Sortable === 'undefined') return;` and a gallery that
       quietly stops reordering reads to the person using it as though they
       mis-dragged. */
    if (window.Reorder && typeof window.Reorder.create === 'function') {
        window.Reorder.create(tiles, {
            handle:      '[data-gallery-grip]',
            ghostClass:  'sortable-ghost',
            chosenClass: 'sortable-chosen',
            animation:   150,
            // THE HIDDEN INPUT IS THE GALLERY. `setItems()` truncates and
            // re-inserts with `position` from array order, so the order here
            // IS what the visitor sees — tiles that moved on screen without
            // this running would save the order the owner started with.
            onEnd:       sync
        });
    } else {
        console.error('gallery-images: reorder.js did not load — the tiles cannot be reordered.');
    }

    /* ── 4. THE SETTINGS DRIVE THE PREVIEW, AS YOU CHANGE THEM ───────────────
       Layout and Columns write a class and a custom property onto the public
       container. This screen renders that same container with that same
       stylesheet, so the two controls have something to change HERE — and they
       change it on `input`, not on save, because a setting whose effect you can
       only see by saving and navigating is a setting you have to guess at.

       The names are the ones the controls post (`layout`, `columns`), so this
       cannot drift from what the server stores. */
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

    /* ── 5. EVENTS ───────────────────────────────────────────────────────── */
    root.addEventListener('click', function (e) {
        if (e.target.closest('[data-gallery-add]')) {
            e.preventDefault();
            openPicker();
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
            sync();
        }
    });

    root.addEventListener('submit', sync);

    sync();
}());
