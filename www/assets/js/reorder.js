/**
 * Reorder — drag-and-drop for lists AND grids. In-house, no dependencies.
 *
 * A drag library pulled from a third-party host into an authenticated admin
 * session, with no subresource integrity, executes with the logged-in user's
 * privileges. This project authors everything itself; nothing is fetched from
 * a third party at runtime.
 *
 * ── API ────────────────────────────────────────────────────────────────────
 * SortableJS's option names, so a call site written against it works unchanged:
 *
 *     Reorder.create(container, {
 *         handle:      '[data-grip]',            // drag only from here (optional)
 *         ghostClass:  'sortable-ghost',         // on the gap left behind
 *         chosenClass: 'sortable-chosen',        // on the thing you are carrying
 *         animation:   150,                      // ms; 0 disables the shuffle
 *         onEnd:       function (evt) { evt.oldIndex; evt.newIndex; }
 *     });
 *
 * `Sortable` is aliased to this at the bottom, so any call site still written
 * against that name keeps working rather than silently doing nothing — a guard
 * such as `if (typeof Sortable === 'undefined') return;` fails SILENTLY, and a
 * reorder UI that quietly stops reordering is the kind of bug nobody reports
 * because it looks like they mis-dragged.
 *
 * ── THE THREE PARTS OF A DRAG, AND WHY EACH IS A REAL ELEMENT ──────────────
 *
 *   THE MIRROR      `[data-reorder-mirror]`     what you are carrying
 *   THE INDICATOR   `[data-reorder-indicator]`  where it will land
 *   THE SOURCE      `[data-reorder-source]`     taken out of the flow
 *
 * Moving the real element between slots as you drag, marked `ghostClass`, has
 * NONE of them: nothing follows the pointer, and the only "preview" is the thing
 * having already jumped. A drag needs a shuffle and a preview — a dotted line
 * where the item will go.
 *
 * All three are ELEMENTS WITH DATA ATTRIBUTES rather than pseudo-elements,
 * because a drop indicator that cannot be queried cannot be proved to be in the
 * right place.
 *
 * ── WHY THERE IS NO HIT TEST ───────────────────────────────────────────────
 * Requiring the pointer to be INSIDE some sibling's bounding box before doing
 * anything:
 *
 *     var withinX = …, withinY = …;
 *     if (withinX && withinY) { target = items[i]; break; }
 *     if (!target) return;                      // ← the bug
 *
 * fails between two rows — a 12px gap — and past the last row, where there is
 * no such box. A SLOW drag survives it by crossing boxes on the way; a FLICK,
 * which is what a real drag is, lands in the gap and moves nothing at all:
 * dragging the first field to 30px below the last one leaves the order exactly
 * as it started.
 *
 * So there is no hit test. The pointer always has a nearest insertion
 * point, the indicator always shows which one it is, and a drag always commits
 * somewhere — which is why Escape exists below.
 *
 * ── ONE ALGORITHM FOR LISTS AND GRIDS ──────────────────────────────────────
 * The insertion point is the item whose centre is nearest the pointer, plus a
 * before/after decision along THE AXIS THE CONTAINER LAYS ITEMS OUT IN — read
 * off the container's computed style once, when the drag arms. Grid and
 * flex-row run left to right and wrap downward; flex-column, block and CSS
 * multi-column run down the page.
 *
 * Guessing the axis from each item's own proportions (`width > height * 1.5`)
 * calls a square tile in a row "vertical". Guessing it from whether the item's
 * neighbour shares its row is also wrong, for the case that matters most — the
 * last tile of a part-filled grid row is alone on that row.
 *
 * Past every item on the block axis, it appends or prepends outright — "drop it
 * at the end" is the commonest drag there is and it should not depend on which
 * column the pointer happened to be over.
 *
 * ── WHY POINTER EVENTS, NOT HTML5 DRAG-AND-DROP ────────────────────────────
 * HTML5 DnD does not fire on touch devices, its drag image cannot be styled, and
 * its event model differs across engines. Pointer events unify mouse, touch and
 * pen, so this works on a tablet — which is the device an owner updating their
 * own site is most likely holding. The gallery editor runs on this too; on an
 * HTML5 implementation, gallery reordering would not exist on a tablet at all.
 *
 * ── KEYBOARD ───────────────────────────────────────────────────────────────
 * There is no keyboard reorder on a handle: arrow keys on a focused grip would
 * reorder the list while you believe you are scrolling the page. See the note
 * above `Reorder.create` for what that costs.
 */
(function (window, document) {
    'use strict';

    /* A press has to travel before it is a drag, or every click on a handle
       flashes a mirror on screen and vanishes. Same threshold the form
       builder's palette drag uses, so the two gestures feel identical. */
    var THRESHOLD = 4;

    /* ── THE SHARED PARTS ────────────────────────────────────────────────────
       Exposed on Reorder because the form builder's palette drag is a DIFFERENT
       gesture — it creates a field in a second container from a source that
       stays put — and it would otherwise draw its own floating proxy and its
       own drop marker. Two drop markers of different shapes on one screen is
       an inconsistency nobody can read. Same objects, one look. */

    /**
     * DOES THIS CONTAINER LAY ITEMS OUT LEFT TO RIGHT? Grid and flex-row do and
     * wrap downward; flex-column, block and CSS multi-column (the gallery's
     * Masonry) run down the page. Read from the CONTAINER, because inferring
     * it from the items themselves is wrong — see the header.
     */
    function flowIsRowMajor(container) {
        var cs = window.getComputedStyle(container);
        return (cs.display.indexOf('flex') !== -1 && cs.flexDirection.indexOf('row') === 0)
            || cs.display.indexOf('grid') !== -1;
    }

    /** The thing that follows the pointer. `spec.clone` copies an element;
     *  `spec.label` makes a plain chip for a drag that has no element yet. */
    function mirror(spec) {
        var el = document.createElement('div');
        el.className = 'reorder-mirror' + (spec.className ? ' ' + spec.className : '');
        el.setAttribute('data-reorder-mirror', '');
        el.setAttribute('aria-hidden', 'true');

        if (spec.clone) {
            var r = spec.clone.getBoundingClientRect();
            var copy = spec.clone.cloneNode(true);
            // cloneNode copies the ATTRIBUTE, not the live value, so a field the
            // owner has typed into would be carried around empty — the mirror
            // has to be a picture of what is on screen or it is a picture of
            // something else.
            var from = spec.clone.querySelectorAll('input, textarea, select');
            var to   = copy.querySelectorAll('input, textarea, select');
            for (var i = 0; i < from.length; i++) {
                if (to[i]) { to[i].value = from[i].value; }
            }
            // Nothing inside a mirror is real: it must not be focusable, must
            // not be reachable by the pointer, and must not answer to a
            // querySelector meant for the live copy.
            var ids = copy.querySelectorAll('[id]');
            for (var j = 0; j < ids.length; j++) { ids[j].removeAttribute('id'); }
            copy.removeAttribute('id');
            el.style.width  = r.width + 'px';
            el.style.height = r.height + 'px';
            el.appendChild(copy);
        } else {
            el.classList.add('reorder-mirror--chip');
            el.textContent = spec.label || '';
        }

        document.body.appendChild(el);

        /** Put its top-left at a point. The caller keeps the grab offset. */
        el.at = function (x, y) {
            el.style.transform = 'translate3d(' + Math.round(x) + 'px,' +
                                 Math.round(y) + 'px,0)';
        };
        return el;
    }

    /** The dotted box that shows where it will land. A real element, sized to
     *  the thing being carried, so the gap you see is the gap you get. */
    function indicator(spec) {
        var el = document.createElement('div');
        el.className = 'reorder-drop' + (spec && spec.className ? ' ' + spec.className : '');
        el.setAttribute('data-reorder-indicator', '');
        el.setAttribute('aria-hidden', 'true');
        return el;
    }

    /**
     * WHERE THE LINE GOES, and which way round it lies.
     *
     * ── WHY A LINE AND NOT A BOX ────────────────────────────────────────────
     * A dashed box the size of the item, inserted into the flow so a gap opens
     * where it will land, reads as a hole, not as an answer: it never shows
     * where the item will actually go.
     *
     * The question a drop preview answers is "between WHICH TWO", and the only
     * shape that answers it is a line between two things — the same reason a
     * text caret is a line. Horizontal between rows, vertical between two items
     * sharing a row, because those are two different questions and one glyph
     * cannot mean both.
     *
     * ── AND WHY IT IS POSITIONED, NOT IN THE FLOW ───────────────────────────
     * A twelve-column grid has no track to spare for a 3px element: an in-flow
     * marker either takes a whole column — compressing the row and pushing its
     * last field onto the next line — or fights the tracks. Absolute inside the
     * container costs the layout nothing, so the fields stay exactly where they
     * are and the only thing that moves is the line.
     *
     * The shuffle is still there: picking an item up takes it out of the flow,
     * the others close over it, and `_clearFlip`'s transitions animate that.
     *
     * @param {DOMRect[]} rects   the laid-out items, in DOM order
     * @param {number}    index   where the item would land, among them
     * @return {{vertical: boolean, left: number, top: number,
     *           width: number, height: number}}  container-relative
     */
    function indicatorAt(container, rects, plan, y) {
        var box = container.getBoundingClientRect();
        var cs  = window.getComputedStyle(container);
        var gap = parseFloat(cs.columnGap) || parseFloat(cs.rowGap) || 8;
        // Container-relative, and scrolled: the line is a child of the
        // container, so a scrolled list must not leave it behind at the top.
        var ox  = box.left - container.scrollLeft;
        var oy  = box.top  - container.scrollTop;
        var index = plan.index;

        if (rects.length === 0) {
            return { vertical: false, left: 0, top: 0,
                     width: container.clientWidth, height: 0 };
        }

        // THE ORIENTATION IS THE PLAN'S, NOT RE-DERIVED. It was worked out
        // once, alongside the index, from the same geometry — and the two must
        // agree or the line promises one thing and the drop does another,
        // which is worse than no line at all. Deciding it twice is how they
        // come apart.
        if (plan.vertical) {
            // Between two items sharing a row: a vertical line at the seam, as
            // tall as the row.
            var row = [];
            for (var k = 0; k < rects.length; k++) {
                if (y >= rects[k].top && y <= rects[k].bottom) { row.push(k); }
            }
            if (row.length) {
                var first = row[0], last = row[row.length - 1];
                var seam;
                if (index <= first)    { seam = rects[first].left - gap / 2; }
                else if (index > last) { seam = rects[last].right + gap / 2; }
                else                   { seam = (rects[index - 1].right + rects[index].left) / 2; }

                var top = Infinity, bottom = -Infinity;
                row.forEach(function (i) {
                    if (rects[i].top < top) { top = rects[i].top; }
                    if (rects[i].bottom > bottom) { bottom = rects[i].bottom; }
                });
                return { vertical: true, left: seam - ox, top: top - oy,
                         width: 0, height: bottom - top };
            }
        }

        // Between rows — or anywhere in a list that runs down the page: a
        // horizontal line across the container.
        var ly;
        if (index <= 0)                 { ly = rects[0].top - gap / 2; }
        else if (index >= rects.length) { ly = rects[rects.length - 1].bottom + gap / 2; }
        else                            { ly = (rects[index - 1].bottom + rects[index].top) / 2; }

        return { vertical: false, left: 0, top: ly - oy,
                 width: container.clientWidth, height: 0 };
    }

    /* ── THE INSTANCE ──────────────────────────────────────────────────────── */

    function Reorder(container, options) {
        this.el      = container;
        this.opts    = options || {};
        this.handle  = this.opts.handle || null;
        this.ghostCls  = this.opts.ghostClass  || 'sortable-ghost';
        this.chosenCls = this.opts.chosenClass || 'sortable-chosen';
        this.anim    = typeof this.opts.animation === 'number' ? this.opts.animation : 150;
        this.onEnd   = typeof this.opts.onEnd === 'function' ? this.opts.onEnd : null;

        this.dragEl    = null;   // the source, out of the flow
        this.mirrorEl  = null;
        this.dropEl    = null;   // the indicator line
        this.dropIndex = -1;     // where it stands for
        this.hadStatic = false;
        this.startIdx  = -1;
        this.pointerId = null;
        this.armed     = false;
        this.origin    = null;   // where the press landed
        this.grab      = null;   // pointer offset inside the element
        this.scroller  = null;
        this.scrollRaf = 0;
        this.scrollBy  = 0;
        this.rowMajor  = false;  // the container's flow axis, set when the drag arms

        this._onDown = this._onDown.bind(this);
        this._onMove = this._onMove.bind(this);
        this._onUp   = this._onUp.bind(this);
        this._onEsc  = this._onEsc.bind(this);
        this._scrollStep = this._scrollStep.bind(this);

        this.el.addEventListener('pointerdown', this._onDown);
        this._markHandles();
    }

    /**
     * Direct children only — a nested list must not steal the outer list's
     * items — and never the indicator, which is furniture rather than content.
     * Counting it would put every index below it out by one and `onEnd` would
     * report a move that did not happen.
     */
    Reorder.prototype._items = function () {
        return Array.prototype.filter.call(this.el.children, function (c) {
            return c.nodeType === 1 && !c.hasAttribute('data-reorder-indicator');
        });
    };

    /** The items that are actually LAID OUT: everything but the carried one. */
    Reorder.prototype._others = function () {
        var drag = this.dragEl;
        return this._items().filter(function (c) { return c !== drag; });
    };

    Reorder.prototype._indexOf = function (el) {
        return this._items().indexOf(el);
    };

    /**
     * The element a pointerdown should drag: the direct child containing the
     * target. With a `handle` selector, the press must have landed inside one.
     */
    Reorder.prototype._draggableFrom = function (target) {
        if (this.handle && !target.closest(this.handle)) return null;
        var items = this._items();
        for (var i = 0; i < items.length; i++) {
            if (items[i] === target || items[i].contains(target)) return items[i];
        }
        return null;
    };

    /**
     * Handles are made focusable and announced, so the keyboard path below is
     * discoverable rather than a secret. Done here, not in markup, so the call
     * sites did not have to change.
     */
    Reorder.prototype._markHandles = function () {
        var self = this;
        this._items().forEach(function (item) {
            var h = self.handle ? item.querySelector(self.handle) : item;
            if (!h || h.dataset.reorderHandle) return;
            h.dataset.reorderHandle = '1';
            h.style.touchAction = 'none';   // stop the browser scrolling instead of dragging
        });
    };

    /* ── WHERE IT WOULD LAND ───────────────────────────────────────────────── */

    /**
     * The index the carried item would take, given a pointer position. No hit
     * test: every point on the screen has a nearest insertion point, and the
     * indicator shows which one, so there is no position from which a drag
     * silently does nothing.
     *
     * @return {number} index among `_others()`
     */
    Reorder.prototype._dropPlan = function (x, y) {
        return dropPlan(this._others(), x, y, this.rowMajor, this.el);
    };

    /**
     * WHERE AN ITEM WOULD GO, given the laid-out items and a pointer. Module
     * level and not a method, because the form builder's palette drag needs
     * exactly this answer for a DIFFERENT gesture and was computing its own —
     * a y-only version that could not tell left from right, so dropping a new
     * field between two half-width fields sharing a row always landed it
     * before the first of them. One algorithm, or the two gestures on that one
     * screen disagree about where a drop goes.
     *
     * @param {Element[]} others   the items that are laid out, in DOM order
     * @param {boolean} rowMajor   does the container flow left-to-right
     * @return {number} index among `others`
     */
    function dropPlan(others, x, y, rowMajor, container) {
        if (others.length === 0) { return { index: 0, vertical: false }; }

        var rects = others.map(function (el) {
            var r = el.getBoundingClientRect();
            return { top: r.top, bottom: r.bottom, left: r.left, right: r.right,
                     width: r.width,
                     cx: r.left + r.width / 2, cy: r.top + r.height / 2 };
        });

        // PAST EVERYTHING, ON THE BLOCK AXIS. "Put it at the end" should not
        // depend on which column the pointer drifted over on the way down.
        var maxBottom = -Infinity, minTop = Infinity;
        for (var i = 0; i < rects.length; i++) {
            if (rects[i].bottom > maxBottom) { maxBottom = rects[i].bottom; }
            if (rects[i].top < minTop) { minTop = rects[i].top; }
        }
        if (y > maxBottom) { return { index: others.length, vertical: false }; }
        if (y < minTop)    { return { index: 0, vertical: false }; }

        // ── THE ROW THE POINTER IS ACTUALLY IN ──────────────────────────────
        // Items whose vertical span contains the pointer. This comes BEFORE any
        // nearest-centre reasoning, because "nearest" crosses rows and the
        // answer people expect never does: a pointer in row two, to the right
        // of the only tile in row two, is asking to go after THAT tile — not
        // after whichever tile in row one happens to be 14px closer. Measured
        // on the seeded 3-column gallery of four: dropping tile 1 to the right
        // of tile 4 landed it second, because tile 2's centre was 193px away
        // and tile 4's was 207px.
        var row = [];
        for (var k = 0; k < rects.length; k++) {
            if (y >= rects[k].top && y <= rects[k].bottom) { row.push(k); }
        }

        if (rowMajor && row.length) {
            // Which item is the pointer actually over?
            var over = -1;
            for (var m = 0; m < row.length; m++) {
                if (x >= rects[row[m]].left && x <= rects[row[m]].right) {
                    over = row[m];
                    break;
                }
            }

            // IN THE ROW BUT NOT ON AN ITEM — a gap between two of them, or the
            // empty tail of a part-filled row. There is nothing there but
            // space, so it can only mean "beside".
            if (over === -1) {
                for (var n = 0; n < row.length; n++) {
                    if (x < rects[row[n]].cx) {
                        return { index: row[n], vertical: true };
                    }
                }
                return { index: row[row.length - 1] + 1, vertical: true };
            }

            var r = rects[over];

            // ── BESIDE IT, OR A ROW OF ITS OWN ──────────────────────────────
            // A full-width item fills its row, so if the whole of it meant
            // "beside", there would be no way left to say "put this on its own
            // row here" — pulling a field down onto a row of its own between
            // two others could not be expressed at all.
            //
            // So a full-width item is read in three bands: its outer quarters
            // mean BESIDE — a vertical line, and the consumer's own auto-fit
            // splits the row to make room — and its middle means a NEW ROW
            // above or below, a horizontal line. Both gestures are reachable,
            // and the line says which one is about to happen while there is
            // still time to move.
            //
            // An item that does NOT fill its row is never ambiguous: there is
            // visibly space next to it, so all of it means beside.
            var full = r.width >= container.clientWidth - 1;
            if (full) {
                var edge = Math.min(r.width * 0.25, 120);
                if (x < r.left + edge)  { return { index: over,     vertical: true }; }
                if (x > r.right - edge) { return { index: over + 1, vertical: true }; }
                return { index: y > r.cy ? over + 1 : over, vertical: false };
            }
            return { index: x > r.cx ? over + 1 : over, vertical: true };
        }

        if (row.length) {
            // DOWN THE PAGE — including CSS multi-column, where several items
            // can share a y span across columns, so x still decides which one
            // is being pointed at.
            var pick = row[0];
            for (var q = 1; q < row.length; q++) {
                if (Math.abs(x - rects[row[q]].cx) < Math.abs(x - rects[pick].cx)) {
                    pick = row[q];
                }
            }
            return { index: y > rects[pick].cy ? pick + 1 : pick, vertical: false };
        }

        // IN THE GAP BETWEEN TWO ROWS, where no span contains the pointer.
        var best = 0, bestD = Infinity;
        for (var j = 0; j < rects.length; j++) {
            var dx = x - rects[j].cx, dy = y - rects[j].cy;
            var d  = dx * dx + dy * dy;
            if (d < bestD) { bestD = d; best = j; }
        }
        var b = rects[best];
        if (!rowMajor)    { return { index: y > b.cy ? best + 1 : best, vertical: false }; }
        if (y < b.top)    { return { index: best,     vertical: false }; }
        if (y > b.bottom) { return { index: best + 1, vertical: false }; }
        return { index: x > b.cx ? best + 1 : best, vertical: false };
    }

    /** Draw the line where the item would land, and remember where that is. */
    Reorder.prototype._placeIndicator = function (plan, x, y) {
        this.dropIndex = plan.index;

        var rects = this._others().map(function (el) {
            return el.getBoundingClientRect();
        });
        var at = indicatorAt(this.el, rects, plan, y);

        this.dropEl.classList.toggle('reorder-drop--v', at.vertical);
        this.dropEl.classList.toggle('reorder-drop--h', !at.vertical);
        this.dropEl.style.left   = Math.round(at.left) + 'px';
        this.dropEl.style.top    = Math.round(at.top) + 'px';
        this.dropEl.style.width  = Math.round(at.width) + 'px';
        this.dropEl.style.height = Math.round(at.height) + 'px';
    };

    /** Clear the inline transform/transition FLIP left behind. */
    Reorder.prototype._clearFlip = function () {
        this._items().forEach(function (el) {
            el.style.transition = '';
            el.style.transform  = '';
        });
    };

    /* ── AUTO-SCROLL ───────────────────────────────────────────────────────── */

    /** The nearest ancestor that actually scrolls, or null. */
    Reorder.prototype._scrollerFor = function (el) {
        for (var n = el.parentElement; n && n !== document.body; n = n.parentElement) {
            var oy = window.getComputedStyle(n).overflowY;
            if ((oy === 'auto' || oy === 'scroll') && n.scrollHeight > n.clientHeight) {
                return n;
            }
        }
        return null;
    };

    /**
     * A long form is taller than its scroll box, so without this the bottom of
     * the list is somewhere you cannot drag TO — you can only drag to the edge
     * of the window and give up. 48px of margin, and the speed rises as you
     * push into it so a small correction is small.
     */
    Reorder.prototype._autoScroll = function (y) {
        if (!this.scroller) { this.scrollBy = 0; return; }
        var r = this.scroller.getBoundingClientRect();
        var edge = 48;
        if (y < r.top + edge)         { this.scrollBy = -Math.ceil((r.top + edge - y) / 4); }
        else if (y > r.bottom - edge) { this.scrollBy =  Math.ceil((y - r.bottom + edge) / 4); }
        else                          { this.scrollBy = 0; }

        if (this.scrollBy && !this.scrollRaf) {
            this.scrollRaf = requestAnimationFrame(this._scrollStep);
        }
    };

    Reorder.prototype._scrollStep = function () {
        this.scrollRaf = 0;
        if (!this.dragEl || !this.scrollBy || !this.scroller) return;
        this.scroller.scrollTop += this.scrollBy;
        this.scrollRaf = requestAnimationFrame(this._scrollStep);
    };

    /* ── THE DRAG ──────────────────────────────────────────────────────────── */

    Reorder.prototype._onDown = function (ev) {
        if (ev.button !== 0 && ev.pointerType === 'mouse') return;
        var el = this._draggableFrom(ev.target);
        if (!el) return;

        // Never hijack a press on a real control inside the row.
        if (ev.target.closest('a, button, input, select, textarea, label')) return;

        var r = el.getBoundingClientRect();
        this.dragEl    = el;
        this.startIdx  = this._indexOf(el);
        this.pointerId = ev.pointerId;
        this.armed     = false;
        this.origin    = { x: ev.clientX, y: ev.clientY };
        // WHERE IN THE ELEMENT YOU TOOK HOLD OF IT. Carry that offset, or the
        // mirror snaps its corner to the pointer and the thing jumps out from
        // under your finger the moment it lifts.
        this.grab      = { x: ev.clientX - r.left, y: ev.clientY - r.top };

        // CAPTURE ON THE CONTAINER, NOT THE ITEM. The item is about to leave
        // the flow, and a browser releases capture from an element that stops
        // being rendered — so a fast drag would drop wherever it escaped. The
        // container stays put. Captured events still bubble to these document
        // listeners, so nothing else changes.
        try { this.el.setPointerCapture(ev.pointerId); } catch (e) { /* not fatal */ }

        document.addEventListener('pointermove', this._onMove);
        document.addEventListener('pointerup', this._onUp);
        document.addEventListener('pointercancel', this._onUp);
        document.addEventListener('keydown', this._onEsc);

        // preventDefault stops the text selection a drag across a list would
        // otherwise paint. It also stops the handle taking focus, which is now
        // exactly what is wanted — see the note on the removed keyboard path.
        ev.preventDefault();
    };

    /** Everything that only happens once the press has become a drag. */
    Reorder.prototype._arm = function () {
        var el = this.dragEl;

        // The container's flow axis, measured once — layout does not change
        // mid-drag, and reading it per pointermove is 60 style recalcs a second.
        this.rowMajor = flowIsRowMajor(this.el);

        // THE LINE IS A CHILD OF THE CONTAINER, positioned inside it, so the
        // container has to be a containing block. Set here and put back in
        // `_teardown` rather than asked of every stylesheet that ever hosts a
        // reorder — three of them today and no way to know about the fourth.
        this.hadStatic = window.getComputedStyle(this.el).position === 'static';
        if (this.hadStatic) { this.el.style.position = 'relative'; }

        this.dropEl = indicator({ className: this.ghostCls });
        this.el.appendChild(this.dropEl);
        this.dropIndex = this._indexOf(el);

        this.mirrorEl = mirror({ clone: el, className: this.chosenCls });

        // OUT OF THE FLOW, NOT HIDDEN IN PLACE. Leaving it laid out means the
        // list is one item longer than it looks and every gap is in the wrong
        // place.
        el.setAttribute('data-reorder-source', '');
        el.classList.add('reorder-source');

        this.scroller = this._scrollerFor(el);
        this.armed = true;
    };

    Reorder.prototype._onMove = function (ev) {
        if (!this.dragEl || ev.pointerId !== this.pointerId) return;

        if (!this.armed) {
            if (Math.abs(ev.clientX - this.origin.x) < THRESHOLD &&
                Math.abs(ev.clientY - this.origin.y) < THRESHOLD) return;
            this._arm();
        }

        this.mirrorEl.at(ev.clientX - this.grab.x, ev.clientY - this.grab.y);
        this._autoScroll(ev.clientY);
        this._placeIndicator(this._dropPlan(ev.clientX, ev.clientY),
                             ev.clientX, ev.clientY);
    };

    Reorder.prototype._onUp = function () {
        if (!this.dragEl) return;
        var el = this.dragEl, armed = this.armed, oldIdx = this.startIdx;

        if (armed) {
            // THE LINE IS THE PROMISE. Where it was drawn is where the item
            // goes — anything else means the place you were shown was not the
            // place you got. The line is positioned rather than in the flow,
            // so the index it stood for is what does the insert.
            var at = this._others()[this.dropIndex] || null;
            if (at) { this.el.insertBefore(el, at); }
            else    { this.el.appendChild(el); }
        }
        this._teardown();

        if (!armed) return;
        var newIdx = this._indexOf(el);

        // Fire only on an actual change — the call sites early-return on equal
        // indices anyway, but firing a "change" for a click is a lie.
        if (this.onEnd && newIdx !== oldIdx) {
            this.onEnd({ oldIndex: oldIdx, newIndex: newIdx, item: el });
        }
    };

    /**
     * ESCAPE PUTS IT BACK. A drag now always commits somewhere — there is no
     * hit test to fall outside of — so there has to be a way out of one started
     * by accident, and everywhere else in this admin that way is Escape.
     */
    Reorder.prototype._onEsc = function (ev) {
        if (ev.key !== 'Escape' || !this.dragEl) return;
        ev.preventDefault();
        var el = this.dragEl, home = this.startIdx, armed = this.armed;
        if (armed) {
            var others = this._others();
            var at = others[home] || null;
            if (at) { this.el.insertBefore(el, at); }
            else    { this.el.appendChild(el); }
        }
        this._teardown();
    };

    /** Every drag ends here, however it ended. Nothing may be left pinned. */
    Reorder.prototype._teardown = function () {
        document.removeEventListener('pointermove', this._onMove);
        document.removeEventListener('pointerup', this._onUp);
        document.removeEventListener('pointercancel', this._onUp);
        document.removeEventListener('keydown', this._onEsc);

        try { this.el.releasePointerCapture(this.pointerId); } catch (e) { /* fine */ }

        if (this.dragEl) {
            this.dragEl.removeAttribute('data-reorder-source');
            this.dragEl.classList.remove('reorder-source');
        }
        if (this.dropEl)   { this.dropEl.remove(); }
        if (this.hadStatic) { this.el.style.position = ''; }
        if (this.mirrorEl) { this.mirrorEl.remove(); }
        if (this.scrollRaf) { cancelAnimationFrame(this.scrollRaf); }

        this._clearFlip();

        this.dragEl = null;
        this.dropEl = null;
        this.dropIndex = -1;
        this.hadStatic = false;
        this.mirrorEl = null;
        this.startIdx = -1;
        this.pointerId = null;
        this.armed = false;
        this.origin = null;
        this.grab = null;
        this.scroller = null;
        this.scrollRaf = 0;
        this.scrollBy = 0;

        this._markHandles();
    };

    /* ── THERE IS NO KEYBOARD REORDER ──────────────────────────────────────
       A handle says "Drag to reorder", and dragging is all it does. Arrow keys
       on a grip make no sense to someone holding a mouse, and they are worse
       than confusing: a handle that is `tabindex="0"` takes focus on press, so
       clicking a grip and then pressing an arrow key — to scroll, say —
       silently reorders the form. Arrow keys that move things while you
       believe you are moving the page is not an accessible affordance, it is a
       trap with a label on it.

       WHAT THIS COSTS, SAID PLAINLY: there is no way to reorder without a
       pointer. If that has to come back it should come back as its own visible
       control — a move-up/move-down pair, or a "reorder" mode — and not as
       invisible behaviour bolted onto a handle whose whole meaning is "drag
       me". */


    Reorder.create = function (container, options) {
        if (!container) return null;
        return new Reorder(container, options);
    };

    // THE SHARED PARTS, for a gesture that is not a reorder. The form builder's
    // palette drag creates a field in another container from a source that
    // stays put — genuinely a different gesture — but it must not therefore
    // LOOK different, so it borrows these two rather than drawing its own.
    Reorder.mirror    = mirror;
    Reorder.indicator = indicator;

    /**
     * Where a drop at (x, y) would land among `items` in `container` — the
     * same answer the reorder gives itself, for a gesture that is not one.
     *
     * @param {Element}   container  read for its flow axis
     * @param {Element[]} items      laid out, in DOM order; exclude the
     *                               indicator and anything out of the flow
     */
    Reorder.dropPlan = function (container, items, x, y) {
        return dropPlan(items, x, y, flowIsRowMajor(container), container);
    };

    /**
     * Draw an indicator line for a drop at (x, y) — the same line the reorder
     * draws itself, for a gesture that is not one. The palette drag needs it:
     * two drop markers of different shapes, on one canvas, twelve inches
     * apart, is the fault this whole file exists to stop repeating.
     *
     * The caller owns the element (from `Reorder.indicator()`) and is
     * responsible for putting it in `container` and taking it out again.
     */
    Reorder.placeIndicator = function (container, el, items, plan, y) {
        var rects = items.map(function (n) { return n.getBoundingClientRect(); });
        var at = indicatorAt(container, rects, plan, y);
        el.classList.toggle('reorder-drop--v', at.vertical);
        el.classList.toggle('reorder-drop--h', !at.vertical);
        el.style.left   = Math.round(at.left) + 'px';
        el.style.top    = Math.round(at.top) + 'px';
        el.style.width  = Math.round(at.width) + 'px';
        el.style.height = Math.round(at.height) + 'px';
    };

    window.Reorder = Reorder;
    // Compatibility alias: call sites guarded on `typeof Sortable === 'undefined'`
    // and returned silently. Aliasing means no reorder UI can quietly die.
    if (!window.Sortable) window.Sortable = Reorder;
})(window, document);
