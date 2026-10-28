/**
 * THE TOGGLE BAR DESIGNER — on the header's location card.
 *
 * The bar is three zones — left, centre, right — holding blocks in order. The
 * whole bar is ONE JSON list in a hidden input (`[data-tb-value]`), the same
 * way the menu's items travel; this draws it, lets each block be changed, added,
 * removed and dragged, and writes the list back on every change. The server
 * (`Ui\MenuToggleBlocks`) cleans whatever arrives, so nothing here is trusted.
 *
 * The drag borrows `Reorder.mirror`, `Reorder.indicator`, `Reorder.dropPlan` and
 * `Reorder.placeIndicator`, so what you carry and the line that shows where it
 * lands look exactly as they do in the menu list above it.
 *
 * ES5, no build step, no dependency.
 */
(function () {
    'use strict';

    var THRESHOLD = 4;

    Array.prototype.forEach.call(document.querySelectorAll('[data-tb]'), init);

    function init(root) {
        var input    = root.querySelector('[data-tb-value]');
        var settings = root.querySelector('[data-tb-settings]');
        var types    = parse(root.querySelector('[data-tb-types]'), {});
        var media    = parse(root.querySelector('[data-tb-media]'), {});
        var blocks   = [];
        var selected = -1;

        try { blocks = JSON.parse(input.value || '[]'); } catch (e) { blocks = []; }
        if (!Array.isArray(blocks)) { blocks = []; }

        function zone(name) { return root.querySelector('[data-tb-zone="' + name + '"]'); }

        function save() {
            input.value = JSON.stringify(blocks);
            // The form's own change listeners — the unsaved-changes guard among
            // them — hear about it like any other field.
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        /* ── THE BAR ──────────────────────────────────────────────────────── */
        function draw() {
            ['left', 'center', 'right'].forEach(function (z) { zone(z).innerHTML = ''; });
            blocks.forEach(function (block, i) {
                var z = zone(block.align) || zone('right');
                z.appendChild(chip(block, i));
            });
        }

        function chip(block, i) {
            var meta = types[block.type] || { title: block.type, glyph: 'star' };
            var el   = document.createElement('button');
            el.type = 'button';
            el.className = 'tb__block';
            el.setAttribute('data-tb-index', String(i));
            el.setAttribute('aria-pressed', i === selected ? 'true' : 'false');
            el.title = meta.title + ' — click to change it, drag to move it';

            var glyph = root.querySelector('[data-tb-glyphs]');
            var src   = glyph && glyph.content.querySelector('[data-for="' + meta.glyph + '"]');
            if (src && src.firstElementChild) { el.appendChild(src.firstElementChild.cloneNode(true)); }

            var text = document.createElement('span');
            text.className = 'tb__block-name';
            text.textContent = nameOf(block, meta);
            el.appendChild(text);
            return el;
        }

        /** WHAT THE CHIP SAYS: the block's own words where it has some. */
        function nameOf(block, meta) {
            if (block.type === 'menu_toggle') { return block.closed_text || 'Menu toggle'; }
            if (block.type === 'spacer') { return 'Spacer ' + (block.width || 0) + 'px'; }
            if (block.type === 'icon' && block.title) { return block.title; }
            return meta.title;
        }

        /* ── ONE BLOCK'S SETTINGS ─────────────────────────────────────────── */
        function select(i) {
            selected = i;
            draw();
            settings.innerHTML = '';
            settings.hidden = i < 0;
            if (i < 0) { return; }

            var block = blocks[i];
            var tpl   = root.querySelector('[data-tb-form="' + block.type + '"]');
            if (!tpl) { return; }
            settings.appendChild(tpl.content.cloneNode(true));

            Array.prototype.forEach.call(settings.querySelectorAll('[data-tb-field]'), function (el) {
                var key = el.getAttribute('data-tb-field');
                var v   = block[key];
                if (el.type === 'checkbox') { el.checked = !!v; }
                else if (el.type === 'color') { el.value = v || '#000000'; }
                else { el.value = v === undefined || v === null ? '' : String(v); }
            });
            var alignSel = settings.querySelector('[data-tb-align]');
            if (alignSel) { alignSel.value = block.align; }
            Array.prototype.forEach.call(settings.querySelectorAll('[data-tb-colour-auto]'), function (box) {
                var key = box.getAttribute('data-tb-colour-auto');
                box.checked = !block[key];
                syncColour(key);
            });
            Array.prototype.forEach.call(settings.querySelectorAll('[data-tb-icon-preview]'), function (p) {
                drawIcon(p.getAttribute('data-tb-icon-preview'));
            });
            Array.prototype.forEach.call(settings.querySelectorAll('[data-tb-image-preview]'), function (p) {
                drawImage(p.getAttribute('data-tb-image-preview'));
            });
            syncDelete();
        }

        /** "The theme's own" ticked disables the colour, so it cannot be mistaken for one. */
        function syncColour(key) {
            var box  = settings.querySelector('[data-tb-colour-auto="' + key + '"]');
            var pick = settings.querySelector('[data-tb-field="' + key + '"]');
            if (box && pick) { pick.disabled = box.checked; }
        }

        /** THE GLYPH BESIDE AN ICON SELECT, cloned from the item dialog's grid —
            the one place every glyph is already drawn on this page. */
        function drawIcon(key) {
            var box = settings.querySelector('[data-tb-icon-preview="' + key + '"]');
            if (!box) { return; }
            box.innerHTML = '';
            var v = blocks[selected][key];
            var cell = v ? document.querySelector('[data-mi-icon-choice][value="' + v + '"]') : null;
            var svg = cell && cell.parentNode.querySelector('svg');
            if (svg) { box.appendChild(svg.cloneNode(true)); }
        }

        function drawImage(key) {
            var box   = settings.querySelector('[data-tb-image-preview="' + key + '"]');
            var pick  = settings.querySelector('[data-tb-pick="' + key + '"]');
            var clear = settings.querySelector('[data-tb-clear="' + key + '"]');
            if (!box) { return; }
            var id  = parseInt(blocks[selected][key], 10) || 0;
            var url = id > 0 ? media[id] : '';
            box.innerHTML = '';
            if (url) {
                var img = document.createElement('img');
                img.src = url;
                img.alt = '';
                box.appendChild(img);
            } else {
                // AN EMPTY SLOT SHOWS WHAT GOES IN IT: the image glyph, from the
                // item dialog's grid on this same page.
                var cell = document.querySelector('[data-mi-icon-choice][value="image"]');
                var svg  = cell && cell.parentNode.querySelector('svg');
                if (svg) {
                    var g = svg.cloneNode(true);
                    g.setAttribute('class', 'media-picker__glyph');
                    g.setAttribute('width', '22');
                    g.setAttribute('height', '22');
                    box.appendChild(g);
                }
            }
            if (pick) { pick.textContent = url ? 'Replace' : 'Choose'; }
            if (clear) { clear.hidden = !url; }
        }

        /** THE LAST TOGGLE STAYS: its Remove is disabled, and the reason is on screen. */
        function syncDelete() {
            var del  = settings.querySelector('[data-tb-delete]');
            var note = settings.querySelector('[data-tb-last]');
            if (!del) { return; }
            var toggles = blocks.filter(function (b) { return types[b.type] && types[b.type].toggle; }).length;
            var last = types[blocks[selected].type] && types[blocks[selected].type].toggle && toggles <= 1;
            del.disabled = !!last;
            if (note) { note.hidden = !last; }
        }

        settings.addEventListener('input', onField);
        settings.addEventListener('change', onField);

        function onField(e) {
            if (selected < 0) { return; }
            var el = e.target;
            if (el.hasAttribute('data-tb-align')) {
                if (e.type !== 'change') { return; }
                // TO THE END OF ITS NEW ZONE, as a drop past its last block would.
                var moved = blocks.splice(selected, 1)[0];
                moved.align = el.value;
                var at = blocks.length;
                for (var k = blocks.length - 1; k >= 0; k--) {
                    if (blocks[k].align === moved.align) { at = k + 1; break; }
                }
                blocks.splice(at, 0, moved);
                save();
                select(at);
                return;
            }
            var auto = el.getAttribute('data-tb-colour-auto');
            if (auto) {
                var pick = settings.querySelector('[data-tb-field="' + auto + '"]');
                blocks[selected][auto] = el.checked ? '' : (pick ? pick.value : '');
                syncColour(auto);
                save();
                return;
            }
            var key = el.getAttribute('data-tb-field');
            if (!key) { return; }
            var v = el.type === 'checkbox' ? el.checked
                  : el.type === 'number' ? (el.value === '' ? 0 : Number(el.value))
                  : el.value;
            blocks[selected][key] = v;
            if (el.tagName === 'SELECT' && settings.querySelector('[data-tb-icon-preview="' + key + '"]')) {
                drawIcon(key);
            }
            // The chip's words follow what is typed.
            var c = root.querySelector('[data-tb-index="' + selected + '"] .tb__block-name');
            if (c) { c.textContent = nameOf(blocks[selected], types[blocks[selected].type] || {}); }
            save();
        }

        settings.addEventListener('click', function (e) {
            var pick = e.target.closest('[data-tb-pick]');
            if (pick) {
                var key = pick.getAttribute('data-tb-pick');
                if (!window.CmsMedia) { return; }
                window.CmsMedia.pick(function (chosen) {
                    blocks[selected][key] = parseInt(chosen.id, 10) || 0;
                    media[chosen.id] = chosen.url;
                    drawImage(key);
                    save();
                });
                return;
            }
            var clear = e.target.closest('[data-tb-clear]');
            if (clear) {
                blocks[selected][clear.getAttribute('data-tb-clear')] = 0;
                drawImage(clear.getAttribute('data-tb-clear'));
                save();
                return;
            }
            var move = e.target.closest('[data-tb-move]');
            if (move) {
                // ONE PLACE ALONG ITS OWN ZONE: swap with the nearest block of
                // the same zone on that side, or do nothing at the end.
                var step = parseInt(move.getAttribute('data-tb-move'), 10);
                var here = blocks[selected];
                for (var j = selected + step; j >= 0 && j < blocks.length; j += step) {
                    if (blocks[j].align === here.align) {
                        blocks[selected] = blocks[j];
                        blocks[j] = here;
                        save();
                        select(j);
                        break;
                    }
                }
                return;
            }
            if (e.target.closest('[data-tb-delete]')) {
                blocks.splice(selected, 1);
                select(-1);
                save();
            }
        });

        /* ── ADD ──────────────────────────────────────────────────────────── */
        root.querySelector('[data-tb-add]').addEventListener('change', function (e) {
            var type = e.target.value;
            e.target.value = '';
            if (!types[type]) { return; }
            var block = JSON.parse(JSON.stringify(types[type].defaults));
            // Into the LEFT zone — the default toggle already holds the
            // right.
            block.align = 'left';
            blocks.push(block);
            save();
            select(blocks.length - 1);
        });

        /* ── CLICK TO CHANGE, DRAG TO MOVE ────────────────────────────────── */
        var drag = null;

        root.querySelector('[data-tb-bar]').addEventListener('pointerdown', function (e) {
            var el = e.target.closest('.tb__block');
            if (!el || e.button !== 0) { return; }
            drag = { el: el, index: parseInt(el.getAttribute('data-tb-index'), 10),
                     x: e.clientX, y: e.clientY, live: false, mirror: null, line: null, to: null };
            el.setPointerCapture(e.pointerId);
        });

        root.addEventListener('pointermove', function (e) {
            if (!drag) { return; }
            if (!drag.live) {
                if (Math.abs(e.clientX - drag.x) < THRESHOLD && Math.abs(e.clientY - drag.y) < THRESHOLD) { return; }
                drag.live = true;
                var r = drag.el.getBoundingClientRect();
                drag.dx = drag.x - r.left;
                drag.dy = drag.y - r.top;
                drag.mirror = window.Reorder.mirror({ clone: drag.el });
                drag.line   = window.Reorder.indicator({});
                drag.el.classList.add('is-dragging');
                document.documentElement.classList.add('is-dragging-menu');
            }
            drag.mirror.at(e.clientX - drag.dx, e.clientY - drag.dy);

            // WHICH ZONE, THEN WHERE IN IT. The zone under the pointer, or the
            // nearest along the bar when the pointer has wandered above or
            // below it — a drop should never depend on hitting a 40px strip.
            var z = zoneAt(e.clientX);
            var items = Array.prototype.filter.call(z.querySelectorAll('.tb__block'),
                function (n) { return n !== drag.el; });
            var plan = window.Reorder.dropPlan(z, items, e.clientX, e.clientY);
            if (drag.line.parentNode !== z) { z.appendChild(drag.line); }
            window.Reorder.placeIndicator(z, drag.line, items, plan, e.clientY);
            drag.to = { zone: z.getAttribute('data-tb-zone'), index: plan.index, items: items };
        });

        // A CHIP ACTIVATED FROM THE KEYBOARD fires `click` and no pointer
        // events at all, so without this it could not be opened without a mouse.
        root.querySelector('[data-tb-bar]').addEventListener('click', function (e) {
            if (e.detail !== 0) { return; }
            var el = e.target.closest('.tb__block');
            if (!el) { return; }
            var i = parseInt(el.getAttribute('data-tb-index'), 10);
            select(i === selected ? -1 : i);
            var again = root.querySelector('[data-tb-index="' + i + '"]');
            if (again) { again.focus(); }
        });

        root.addEventListener('pointerup', finish);
        root.addEventListener('pointercancel', function () { finish(null); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && drag && drag.live) { drag.to = null; finish(null); }
        });

        function zoneAt(x) {
            var zones = Array.prototype.slice.call(root.querySelectorAll('[data-tb-zone]'));
            var best = zones[0], dist = Infinity;
            zones.forEach(function (z) {
                var r = z.getBoundingClientRect();
                var d = x < r.left ? r.left - x : (x > r.right ? x - r.right : 0);
                if (d < dist) { dist = d; best = z; }
            });
            return best;
        }

        function finish(e) {
            if (!drag) { return; }
            var d = drag;
            drag = null;
            if (d.mirror && d.mirror.parentNode) { d.mirror.parentNode.removeChild(d.mirror); }
            if (d.line && d.line.parentNode) { d.line.parentNode.removeChild(d.line); }
            d.el.classList.remove('is-dragging');
            document.documentElement.classList.remove('is-dragging-menu');

            // NOT A DRAG AT ALL — a click, which opens the block's settings.
            if (!d.live) {
                if (e) { select(d.index === selected ? -1 : d.index); }
                return;
            }
            if (!d.to) { return; }

            // Take it out, then put it back before the block it landed ahead of
            // — or after the last block of its new zone — in the ONE list.
            var moved = blocks.splice(d.index, 1)[0];
            moved.align = d.to.zone;
            var before = d.to.items[d.to.index];
            var at;
            if (before) {
                at = parseInt(before.getAttribute('data-tb-index'), 10);
                if (at > d.index) { at -= 1; }
            } else {
                at = blocks.length;
                for (var k = blocks.length - 1; k >= 0; k--) {
                    if (blocks[k].align === d.to.zone) { at = k + 1; break; }
                }
            }
            blocks.splice(at, 0, moved);
            save();
            select(at);
        }

        draw();
    }

    function parse(el, fallback) {
        if (!el) { return fallback; }
        try { return JSON.parse(el.textContent || ''); } catch (e) { return fallback; }
    }
}());
