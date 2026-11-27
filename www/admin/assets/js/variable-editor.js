/**
 * variable-editor.js — SEO titles and descriptions edited as PILLS.
 *
 * Variables are drag-and-droppable pieces, rather than a monospace box holding
 * `{title} | {site_name}` with the variables listed in a paragraph of help
 * above it.
 *
 * Binds to every `[data-var-editor]` field. `Ui\VariableEditor` renders the
 * attributes, the variables that field's context supplies, and — once per
 * page — the `<template data-var-template>` the pill is cloned from.
 *
 * ── HOW IT BEHAVES ───────────────────────────────────────────────────────────
 *   · EACH VARIABLE IS ONE PIECE — a pill wearing its label ("Title", "Site
 *     title", "Separator"), never its braces. The caret cannot land inside
 *     one; Backspace and Delete take the whole pill.
 *   · "Insert variable" at the right of the label row. It drops a `%` at the
 *     caret — with a space either side when it needs one — and opens the list.
 *   · TYPING `%` at the start or after a space opens the same list, narrowed by
 *     what follows it: the start of a label. The first option is active; ↑/↓
 *     move, Enter or Tab chooses, Escape closes and leaves the `%` as typed.
 *   · A SPACE AFTER A NEW VARIABLE unless a word boundary (`BOUNDARY`) follows.
 *     Only when one is made — typed, chosen, pasted, loaded — never on every
 *     redraw, or a space the owner deleted would keep coming back.
 *   · `{name}` TYPED OUT BY HAND becomes a pill.
 *   · COPY AND CUT put the STORED form on the clipboard, so a pill pasted into
 *     another box arrives as a pill. A Yoast pattern pasted from a WordPress
 *     site — `%%title%% %%sep%% %%sitename%%` — arrives as the same pills:
 *     `YOAST` below maps its names to ours.
 *   · ONE LINE. Enter does nothing, and pasted line breaks become spaces.
 *   · A PILL DRAGS to a new place in its box, on the admin's own drag — the
 *     same mirror and dotted drop line every other drag in the app draws
 *     (`Reorder.mirror()`, `Reorder.indicator()`), pointer events rather than
 *     HTML5 drag-and-drop (reorder.js's header says why), Escape to put it
 *     back.
 *
 * ── CHOICES, AND WHY ─────────────────────────────────────────────────────────
 *   · THE LIST SHOWS EVERY VARIABLE THE CONTEXT SUPPLIES — at most seven per
 *     context, every one of them working there (`Head::variables()`). Hiding
 *     one would leave it reachable only by already knowing its name.
 *   · EACH PILL CARRIES AN ×. A thing that can be deleted carries its delete,
 *     here as on every `.chip`.
 *   · A VARIABLE INSERTED INTO A BOX NEVER CLICKED INTO GOES ON THE END, not at
 *     offset 0. A box that has had a caret in it gets it back.
 *   · A PILL DROPPED BETWEEN TWO WORDS gets a space either side of it: moving
 *     the characters and nothing else can glue a pill to a word.
 *   · CLICKING A PILL SELECTS IT, outlined, so Backspace, Delete or a keystroke
 *     acts on the whole pill.
 *
 * ── THE FIELD IS STILL THE THING THAT POSTS ──────────────────────────────────
 * editor.js's architecture, for the same reasons. The server renders a plain
 * `<input>` or `<textarea>` holding the stored text; this hides it and puts a
 * contenteditable surface beside it, and every change writes the stored text
 * back. With JavaScript off, or if this file fails, the page is the plain
 * field it was, `{title} {sep} {site_name}` and all — and it still saves.
 *
 * ── ONE MODEL: THE STORED TEXT ───────────────────────────────────────────────
 * Every edit that touches a pill goes through the stored text — cut it, splice
 * it, draw it again — never through DOM surgery. The caret is kept as an offset
 * INTO THAT TEXT, where a pill's `{name}` is one indivisible span, so a redraw
 * always puts the caret back and never inside a pill. Plain typing is left to
 * the browser and read back on `input`; anything the browser leaves that is not
 * text or a pill (a `<br>`, a wrapping `<span>`) is drawn again from the text.
 *
 * ── UNDO IS OURS ─────────────────────────────────────────────────────────────
 * A redraw empties the browser's own undo stack, so Ctrl+Z, Ctrl+Y and the Edit
 * menu's Undo are answered here, from snapshots of the stored text. A run of
 * typing is one step, as it is in a plain field.
 *
 * ES5, no build step, no dependency — like every other file in here.
 */
(function (window, document) {
    'use strict';

    /** A stored variable: `{name}`, or Yoast's `%%name%%` on the way in. A
        name is a letter, then letters, digits and underscores ({phone_line_2}),
        as `Head::buildTitle()` reads it, so "{2024}" stays text. */
    var TOKEN = /\{([a-z][a-z0-9_]*)\}|%%([a-z_]+)%%/g;
    /** The same, braces only — the one form that can be typed, and the one we store. */
    var BRACED = /\{([a-z][a-z0-9_]*)\}/g;

    /* YOAST'S NAMES FOR OURS — for a pattern pasted in from a WordPress site
       running it. Only these: an unknown `%%name%%` stays text rather than
       becoming a pill that resolves to nothing. */
    var YOAST = {
        title: 'title',
        sitename: 'site_name',
        sitedesc: 'tagline',
        sep: 'sep',
        page: 'page_num',
        term_title: 'term_name',
        pt_plural: 'content_type_label',
        pt_single: 'content_type_label'
    };

    /* Word boundaries: no space is added after a new variable when one of
       these follows it. */
    var BOUNDARY = /[\s.,'()"+\-;!?:\/»«‹›<>]/;

    /* A press has to travel before it is a drag — reorder.js's threshold, so
       the two gestures feel the same. */
    var THRESHOLD = 4;

    var editors = [];
    var seq = 0;

    /* ═══ THE STORED TEXT ═════════════════════════════════════════════════════ */

    /** `%%name%%` in Yoast's vocabulary, as `{name}` in ours; unknown ones kept. */
    function canon(text) {
        return String(text).replace(/%%([a-z_]+)%%/g, function (all, name) {
            return YOAST[name] ? '{' + YOAST[name] + '}' : all;
        });
    }

    /** Line breaks, tabs and no-break spaces are spaces — one line, one length. */
    function flat(text) {
        return String(text).replace(/[\r\n\t ]/g, ' ');
    }

    /** The stored text as parts: strings, and `{v: name}` for each variable. */
    function parse(text) {
        var parts = [], last = 0, m;
        BRACED.lastIndex = 0;
        while ((m = BRACED.exec(text)) !== null) {
            if (m.index > last) { parts.push(text.slice(last, m.index)); }
            parts.push({ v: m[1] });
            last = m.index + m[0].length;
        }
        if (last < text.length) { parts.push(text.slice(last)); }
        return parts;
    }

    /** Where each variable sits in the text: [{from, to, name}]. */
    function spans(text) {
        var out = [], m;
        BRACED.lastIndex = 0;
        while ((m = BRACED.exec(text)) !== null) {
            out.push({ from: m.index, to: m.index + m[0].length, name: m[1] });
        }
        return out;
    }

    /**
     * THE SPACE AFTER A VARIABLE: after the one ending at `end`, unless a
     * word boundary follows. At the very end of the text it adds one too — so
     * there is always somewhere after the last pill for the caret to go, and
     * variables are never stuck to each other.
     */
    function spaceAfter(text, end) {
        var next = text.charAt(end);
        if (next !== '' && BOUNDARY.test(next)) { return { text: text, added: false }; }
        return { text: text.slice(0, end) + ' ' + text.slice(end), added: true };
    }

    /**
     * THE GAP A PILL LEAVES closes to one space. With the pill gone from
     * `at`, a space there that now doubles the one before it — or leads the
     * text — was the pill's own, and goes too.
     */
    function closeGap(text, at) {
        if (text.charAt(at) === ' ' && (at === 0 || text.charAt(at - 1) === ' ')) {
            return { text: text.slice(0, at) + text.slice(at + 1), closed: true };
        }
        return { text: text, closed: false };
    }

    /** The same for every variable ending inside (from, to]; the caret follows. */
    function spaceAfterAll(text, from, to, caret) {
        var list = spans(text).filter(function (s) { return s.to > from && s.to <= to; });
        for (var i = list.length - 1; i >= 0; i--) {
            var r = spaceAfter(text, list[i].to);
            if (r.added && caret >= list[i].to) { caret++; }
            text = r.text;
        }
        return { text: text, caret: caret };
    }

    /* ═══ THE SURFACE ═════════════════════════════════════════════════════════ */

    /** The stored text of everything inside `node` — a pill is its `{name}`. */
    function serialize(node) {
        var out = '';
        for (var n = node.firstChild; n; n = n.nextSibling) {
            if (n.nodeType === 3) {
                out += n.nodeValue;
            } else if (n.nodeType === 1) {
                if (n.hasAttribute('data-var')) { out += '{' + n.getAttribute('data-var') + '}'; }
                else if (n.tagName !== 'BR') { out += serialize(n); }
            }
        }
        return out;
    }

    function current(ed) {
        return flat(serialize(ed.surface));
    }

    /**
     * THE STORED-TEXT OFFSET OF A DOM POSITION. Everything from the start of
     * the surface to the position is cloned and serialised, so a position that
     * lands inside a pill counts the whole pill — it snaps past it rather than
     * splitting it.
     */
    function offsetOf(root, node, offset) {
        var r = document.createRange();
        r.setStart(root, 0);
        try { r.setEnd(node, offset); } catch (e) { return 0; }
        return flat(serialize(r.cloneContents())).length;
    }

    function indexOf(node) {
        var i = 0;
        while ((node = node.previousSibling)) { i++; }
        return i;
    }

    /** The DOM position of a stored-text offset — never inside a pill. */
    function positionAt(root, target) {
        var acc = 0;
        for (var n = root.firstChild; n; n = n.nextSibling) {
            if (n.nodeType === 3) {
                var len = n.nodeValue.length;
                if (target <= acc + len) { return { node: n, offset: target - acc }; }
                acc += len;
            } else if (n.nodeType === 1) {
                var width = n.hasAttribute('data-var')
                    ? n.getAttribute('data-var').length + 2
                    : flat(serialize(n)).length;
                if (target <= acc) { return { node: root, offset: indexOf(n) }; }
                acc += width;
                if (target < acc) { return { node: root, offset: indexOf(n) + 1 }; }
            }
        }
        return { node: root, offset: root.childNodes.length };
    }

    /** The selection as stored-text offsets, or null when it is not in here. */
    function selectionIn(ed) {
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount) { return null; }
        var r = sel.getRangeAt(0);
        if (!ed.surface.contains(r.startContainer) || !ed.surface.contains(r.endContainer)) { return null; }
        return {
            start: offsetOf(ed.surface, r.startContainer, r.startOffset),
            end:   offsetOf(ed.surface, r.endContainer, r.endOffset)
        };
    }

    function select(ed, start, end) {
        var a = positionAt(ed.surface, start);
        var b = end === undefined || end === start ? a : positionAt(ed.surface, end);
        var r = document.createRange();
        r.setStart(a.node, a.offset);
        r.setEnd(b.node, b.offset);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(r);
    }

    /** A pill, cloned from the page's one template so its markup lives once. */
    function pill(ed, name) {
        var label = ed.labels[name];
        var chip = ed.chipTpl ? ed.chipTpl.cloneNode(true) : null;
        if (!chip) {
            // No template on the page: a pill without its ×. Still a pill,
            // still draggable — a missing template must not cost the owner
            // the whole field.
            chip = document.createElement('span');
            chip.className = 'chip var-editor__chip';
            chip.setAttribute('contenteditable', 'false');
            var text = document.createElement('span');
            text.className = 'chip__label';
            chip.appendChild(text);
        }
        chip.setAttribute('data-var', name);
        chip.querySelector('.chip__label').textContent = label || name;
        var x = chip.querySelector('[data-var-remove]');
        if (x) { x.setAttribute('aria-label', 'Remove ' + (label || name)); }
        // A VARIABLE THIS BOX'S CONTEXT DOES NOT SUPPLY — `{page_num}` pasted
        // into a page's title, say, or a name nothing knows. `Head::buildTitle()`
        // leaves it out, so it is drawn the way the house draws a choice that
        // does nothing here: struck through, like a taken option in
        // `.chip-picker__menu`.
        chip.classList.toggle('var-editor__chip--unavailable', !ed.supplied[name]);
        return chip;
    }

    function draw(ed, text) {
        var s = ed.surface;
        while (s.firstChild) { s.removeChild(s.firstChild); }
        parse(text).forEach(function (p) {
            s.appendChild(typeof p === 'string' ? document.createTextNode(p) : pill(ed, p.v));
        });
        s.classList.toggle('is-empty', text === '');
    }

    /** Write the stored text back into the field that posts. */
    function sync(ed) {
        var text = current(ed);
        ed.surface.classList.toggle('is-empty', text === '');
        if (ed.source.value !== text) {
            ed.source.value = text;
            // Anything else listening to the field hears about it, exactly as
            // if it had been typed there.
            ed.source.dispatchEvent(new Event('input', { bubbles: true }));
        }
        mark(ed);
    }

    /** Redraw from the text, put the caret back, post it, remember it. */
    function commit(ed, text, caret, kind) {
        draw(ed, text);
        focus(ed);
        select(ed, caret);
        sync(ed);
        remember(ed, kind, caret);
    }

    /** Into the surface without scrolling the page to it — the owner is looking where they clicked. */
    function focus(ed) {
        if (document.activeElement === ed.surface) { return; }
        try { ed.surface.focus({ preventScroll: true }); } catch (e) { ed.surface.focus(); }
    }

    /** Pills the selection covers wear an outline — they are what a key acts on. */
    function mark(ed) {
        var sel = window.getSelection();
        var r = sel && sel.rangeCount && !sel.isCollapsed ? sel.getRangeAt(0) : null;
        var live = r && ed.surface.contains(r.commonAncestorContainer) ? r : null;
        var chips = ed.surface.querySelectorAll('[data-var]');
        for (var i = 0; i < chips.length; i++) {
            chips[i].classList.toggle('is-selected', !!(live && live.intersectsNode(chips[i])));
        }
    }

    /* ═══ UNDO ════════════════════════════════════════════════════════════════ */

    function remember(ed, kind, caret) {
        var text = current(ed);
        var now = Date.now();
        var top = ed.history[ed.at];
        if (top && top.text === text) { top.caret = caret; return; }
        // A RUN OF TYPING IS ONE STEP, as it is in a plain field: typing within
        // a second of the last keystroke replaces the entry on top rather than
        // stacking one per character.
        if (kind === 'type' && ed.lastKind === 'type' && now - ed.lastAt < 1000 && ed.at > 0) {
            ed.history[ed.at] = { text: text, caret: caret };
        } else {
            ed.history = ed.history.slice(0, ed.at + 1);
            ed.history.push({ text: text, caret: caret });
            if (ed.history.length > 100) { ed.history.shift(); }
            ed.at = ed.history.length - 1;
        }
        ed.lastKind = kind;
        ed.lastAt = now;
    }

    function travel(ed, step) {
        var to = ed.at + step;
        if (to < 0 || to >= ed.history.length) { return; }
        ed.at = to;
        ed.lastKind = 'travel';
        draw(ed, ed.history[to].text);
        select(ed, Math.min(ed.history[to].caret, ed.history[to].text.length));
        sync(ed);
        close(ed);
    }

    /* ═══ THE LIST ════════════════════════════════════════════════════════════ */

    /**
     * THE `%query` THE CARET IS IN, or null. The mention plugin's rule: the `%`
     * starts the text or follows whitespace, and word characters run from it to
     * the caret. "50% off" never opens it.
     */
    function trigger(ed) {
        var sel = selectionIn(ed);
        if (!sel || sel.start !== sel.end) { return null; }
        var before = current(ed).slice(0, sel.end);
        var m = /(^|\s)%(\w*)$/.exec(before);
        if (!m) { return null; }
        return { from: sel.end - m[2].length - 1, to: sel.end, query: m[2] };
    }

    function options(ed) {
        return ed.menu.querySelectorAll('.chip-picker__option');
    }

    function active(ed) {
        return ed.menu.querySelector('.chip-picker__option.is-active');
    }

    function activate(ed, option) {
        var all = options(ed);
        for (var i = 0; i < all.length; i++) { all[i].classList.toggle('is-active', all[i] === option); }
        if (option) {
            ed.surface.setAttribute('aria-activedescendant', option.id);
            option.scrollIntoView({ block: 'nearest' });
        } else {
            ed.surface.removeAttribute('aria-activedescendant');
        }
    }

    function open(ed, query) {
        var q = query.toLowerCase();
        var list = ed.vars.filter(function (v) { return q === '' || v.label.toLowerCase().indexOf(q) === 0; });
        if (!list.length) { close(ed); return; }
        var key = q + '|' + list.length;
        if (ed.menu.hidden || ed.menuKey !== key) {
            ed.menu.innerHTML = '';
            list.forEach(function (v) {
                var o = document.createElement('div');
                o.className = 'chip-picker__option';
                o.id = ed.id + '-var-' + v.name;
                o.setAttribute('role', 'option');
                o.setAttribute('data-name', v.name);
                o.textContent = v.label;
                ed.menu.appendChild(o);
            });
            ed.menuKey = key;
            ed.menu.hidden = false;
            ed.surface.setAttribute('aria-expanded', 'true');
            activate(ed, ed.menu.firstElementChild);
        }
    }

    function close(ed) {
        if (ed.menu.hidden) { return; }
        ed.menu.hidden = true;
        ed.menuKey = '';
        ed.surface.setAttribute('aria-expanded', 'false');
        ed.surface.removeAttribute('aria-activedescendant');
    }

    /** Open, narrow or close the list for wherever the caret now is. */
    function refresh(ed) {
        var t = trigger(ed);
        if (!t) { ed.escaped = -1; close(ed); return; }
        // Escape closed it for THIS `%`: it stays closed until a new one.
        if (t.from === ed.escaped) { return; }
        open(ed, t.query);
    }

    function move(ed, step) {
        var all = Array.prototype.slice.call(options(ed));
        if (!all.length) { return; }
        var i = all.indexOf(active(ed)) + step;
        if (i < 0) { i = all.length - 1; }
        if (i >= all.length) { i = 0; }
        activate(ed, all[i]);
    }

    /** The chosen variable replaces the `%query` — then a space. */
    function choose(ed, name) {
        var t = trigger(ed);
        var text = current(ed);
        var sel = selectionIn(ed) || { start: text.length, end: text.length };
        var from = t ? t.from : sel.start;
        var to = t ? t.to : sel.end;
        var token = '{' + name + '}';
        var next = text.slice(0, from) + token + text.slice(to);
        var r = spaceAfter(next, from + token.length);
        close(ed);
        ed.escaped = -1;
        commit(ed, r.text, from + token.length + (r.added ? 1 : 0), 'insert');
    }

    /**
     * "INSERT VARIABLE": a `%` at the caret, which opens the list. A space
     * before it when the character behind is not whitespace, and after it when
     * the one ahead is not, because the trigger only fires between spaces.
     */
    function insertTrigger(ed) {
        var focused = document.activeElement === ed.surface;
        var text = current(ed);
        var sel = (focused && selectionIn(ed)) || ed.lastSel || { start: text.length, end: text.length };
        var start = Math.min(sel.start, text.length);
        var end = Math.min(sel.end, text.length);
        text = text.slice(0, start) + text.slice(end);
        var pre = start > 0 && !/\s/.test(text.charAt(start - 1));
        var post = start < text.length && !/\s/.test(text.charAt(start));
        var sign = (pre ? ' ' : '') + '%' + (post ? ' ' : '');
        ed.escaped = -1;
        commit(ed, text.slice(0, start) + sign + text.slice(start), start + (pre ? 2 : 1), 'insert');
        refresh(ed);
    }

    /* ═══ DELETING ════════════════════════════════════════════════════════════ */

    /** Backspace or Delete beside a pill, or across one, takes whole pills. */
    function erase(ed, forward) {
        var sel = selectionIn(ed);
        if (!sel) { return false; }
        var text = current(ed);
        if (sel.start !== sel.end) {
            var covered = spans(text).some(function (s) { return s.from < sel.end && s.to > sel.start; });
            // Plain text only: the browser's own deletion is right.
            if (!covered) { return false; }
            cut(ed, text, sel.start, sel.end);
            return true;
        }
        var hit = spans(text).filter(function (s) { return forward ? s.from === sel.start : s.to === sel.start; })[0];
        if (!hit) { return false; }
        cut(ed, text, hit.from, hit.to);
        return true;
    }

    /** Take [from, to) out, close the gap it leaves, caret where it was. */
    function cut(ed, text, from, to) {
        var r = closeGap(text.slice(0, from) + text.slice(to), from);
        commit(ed, r.text, from, 'delete');
    }

    /** The ×: the pill goes, and the space it brought with it. */
    function removePill(ed, chip) {
        var text = current(ed);
        var from = offsetOf(ed.surface, ed.surface, indexOf(chip));
        cut(ed, text, from, from + chip.getAttribute('data-var').length + 2);
    }

    /** A pill clicked, not dragged, is selected whole. */
    function selectPill(ed, chip) {
        focus(ed);
        var r = document.createRange();
        r.setStartBefore(chip);
        r.setEndAfter(chip);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(r);
        mark(ed);
    }

    /* ═══ DRAGGING A PILL ═════════════════════════════════════════════════════ */

    /** The caret position under a point, in either browser API. */
    function caretAt(x, y) {
        if (document.caretPositionFromPoint) {
            var p = document.caretPositionFromPoint(x, y);
            return p ? { node: p.offsetNode, offset: p.offset } : null;
        }
        if (document.caretRangeFromPoint) {
            var r = document.caretRangeFromPoint(x, y);
            return r ? { node: r.startContainer, offset: r.startOffset } : null;
        }
        return null;
    }

    /**
     * WHERE A DROP AT (x, y) LANDS — always somewhere in THIS box. The point is
     * pulled inside the text area first, so a pointer above, below or beside the
     * field still means a place in it (reorder.js's rule: no hit test, a drag
     * always has a nearest place), and a place inside a pill becomes the side
     * of it the pointer is nearer.
     */
    function dropAt(ed, x, y) {
        var s = ed.surface;
        var box = s.getBoundingClientRect();
        var cs = window.getComputedStyle(s);
        var left = box.left + parseFloat(cs.paddingLeft) + 1;
        var right = box.right - parseFloat(cs.paddingRight) - 1;
        var top = box.top + parseFloat(cs.paddingTop) + 1;
        var bottom = box.bottom - parseFloat(cs.paddingBottom) - 1;
        var cx = Math.min(Math.max(x, left), right);
        var cy = Math.min(Math.max(y, top), bottom);

        var pos = caretAt(cx, cy);
        if (!pos || !s.contains(pos.node)) {
            return { node: s, offset: x < left ? 0 : s.childNodes.length };
        }
        var el = pos.node.nodeType === 1 ? pos.node : pos.node.parentNode;
        var chip = el && el.closest ? el.closest('[data-var]') : null;
        if (chip && s.contains(chip)) {
            var pr = chip.getBoundingClientRect();
            return { node: s, offset: indexOf(chip) + (cx > pr.left + pr.width / 2 ? 1 : 0) };
        }
        return pos;
    }

    /** The edge of a node that faces `side` — for the line between two pills. */
    function edge(node, side) {
        if (!node) { return null; }
        if (node.nodeType === 3) {
            if (!node.nodeValue.length) { return null; }
            var r = document.createRange();
            var at = side === 'right' ? node.nodeValue.length - 1 : 0;
            r.setStart(node, at);
            r.setEnd(node, at + 1);
            var rects = r.getClientRects();
            var b = rects[side === 'right' ? rects.length - 1 : 0];
            return b ? { x: side === 'right' ? b.right : b.left, top: b.top, height: b.height } : null;
        }
        if (node.classList && node.classList.contains('reorder-source')) { return null; }
        var e = node.getBoundingClientRect();
        return e.width || e.height ? { x: side === 'right' ? e.right : e.left, top: e.top, height: e.height } : null;
    }

    /** Where to draw the dotted line for a drop position. */
    function lineFor(ed, pos) {
        var r = document.createRange();
        r.setStart(pos.node, pos.offset);
        r.collapse(true);
        var rects = r.getClientRects();
        if (rects.length && rects[0].height) {
            return { x: rects[0].left, top: rects[0].top, height: rects[0].height };
        }
        // BETWEEN TWO ELEMENTS a collapsed range measures nothing; the line
        // stands against whichever neighbour is laid out. The carried pill is
        // out of the flow and is skipped.
        var kids = pos.node.nodeType === 1 ? pos.node.childNodes : null;
        if (kids) {
            for (var i = pos.offset; i < kids.length; i++) {
                var after = edge(kids[i], 'left');
                if (after) { return after; }
            }
            for (var j = pos.offset - 1; j >= 0; j--) {
                var before = edge(kids[j], 'right');
                if (before) { return before; }
            }
        }
        var s = ed.surface.getBoundingClientRect();
        var cs = window.getComputedStyle(ed.surface);
        return { x: s.left + parseFloat(cs.paddingLeft), top: s.top + parseFloat(cs.paddingTop),
                 height: parseFloat(cs.lineHeight) || 20 };
    }

    function place(ed, x, y) {
        var d = ed.drag;
        d.pos = dropAt(ed, x, y);
        var at = lineFor(ed, d.pos);
        var host = ed.box.getBoundingClientRect();
        d.line.style.left = Math.round(at.x - host.left) + 'px';
        d.line.style.top = Math.round(at.top - host.top) + 'px';
        d.line.style.height = Math.round(at.height) + 'px';
        d.line.style.width = '0px';
    }

    function arm(ed) {
        var d = ed.drag;
        var text = current(ed);
        d.from = offsetOf(ed.surface, ed.surface, indexOf(d.chip));
        d.to = d.from + d.chip.getAttribute('data-var').length + 2;
        d.text = text;
        // THE HOUSE'S DRAG FURNITURE, borrowed rather than redrawn: the mirror
        // you carry and the dotted line where it lands. Without reorder.js on
        // the page, a clone at the pointer and a plain line — the same shapes.
        d.mirror = window.Reorder && window.Reorder.mirror
            ? window.Reorder.mirror({ clone: d.chip })
            : fallbackMirror(d.chip);
        // `mirror()` pins the box to the pill's own size, and its border then
        // squeezes the pill inside until the label ellipsises — "Site ti…" in
        // the air. Unpinned, the mirror wraps the pill it carries.
        d.mirror.style.width = '';
        d.mirror.style.height = '';
        d.line = window.Reorder && window.Reorder.indicator
            ? window.Reorder.indicator({})
            : document.createElement('div');
        d.line.classList.add('reorder-drop', 'reorder-drop--v');
        d.line.setAttribute('data-reorder-indicator', '');
        ed.box.appendChild(d.line);
        // OUT OF THE FLOW WHILE IT IS IN THE AIR — reorder.js's rule. The text
        // closes over the gap, so the line shows where it lands in the text as
        // it will read once it has.
        d.chip.classList.add('reorder-source');
        d.chip.setAttribute('data-reorder-source', '');
        d.armed = true;
        close(ed);
    }

    function fallbackMirror(chip) {
        var el = document.createElement('div');
        el.className = 'reorder-mirror';
        el.setAttribute('data-reorder-mirror', '');
        el.appendChild(chip.cloneNode(true));
        document.body.appendChild(el);
        el.at = function (x, y) { el.style.transform = 'translate3d(' + Math.round(x) + 'px,' + Math.round(y) + 'px,0)'; };
        return el;
    }

    function endDrag(ed) {
        var d = ed.drag;
        if (!d) { return; }
        document.removeEventListener('pointermove', ed.onMove);
        document.removeEventListener('pointerup', ed.onUp);
        document.removeEventListener('pointercancel', ed.onCancel);
        document.removeEventListener('keydown', ed.onEsc, true);
        try { ed.box.releasePointerCapture(d.id); } catch (e) { /* fine */ }
        d.chip.classList.remove('reorder-source');
        d.chip.removeAttribute('data-reorder-source');
        if (d.mirror) { d.mirror.remove(); }
        if (d.line) { d.line.remove(); }
        ed.drag = null;
    }

    function drop(ed) {
        var d = ed.drag;
        // THE LINE IS THE PROMISE: where it stood is where the pill goes.
        var p = offsetOf(ed.surface, d.pos.node, d.pos.offset);
        var a = d.from, b = d.to, text = d.text, token = text.slice(a, b);
        endDrag(ed);
        if (p >= a && p <= b) {
            // Put down where it was picked up: nothing moved.
            selectPill(ed, pillAt(ed, a));
            return;
        }
        if (p > b) { p -= (b - a); }
        // THE GAP IT LEAVES closes to one space.
        var gap = closeGap(text.slice(0, a) + text.slice(b), a);
        var rest = gap.text;
        if (gap.closed && p > a) { p--; }
        // WHERE IT LANDS, a space either side unless one — or a boundary, or
        // the edge of the text — is already there.
        var behind = rest.charAt(p - 1), ahead = rest.charAt(p);
        var pre = p > 0 && !BOUNDARY.test(behind) ? ' ' : '';
        var post = ahead === '' || !BOUNDARY.test(ahead) ? ' ' : '';
        if (ahead === ' ') { post = ''; }
        var next = rest.slice(0, p) + pre + token + post + rest.slice(p);
        var at = p + pre.length;
        commit(ed, next, at + token.length + post.length, 'drag');
        // The pill it moved is left selected, so where it went is plain to see.
        var moved = pillAt(ed, at);
        if (moved) { selectPill(ed, moved); }
    }

    /** The pill starting at a stored-text offset. */
    function pillAt(ed, offset) {
        var pos = positionAt(ed.surface, offset);
        var n = pos.node === ed.surface ? ed.surface.childNodes[pos.offset] : pos.node.nextSibling;
        if (pos.node.nodeType === 3 && pos.offset < pos.node.nodeValue.length) { return null; }
        return n && n.nodeType === 1 && n.hasAttribute('data-var') ? n : null;
    }

    function startDrag(ed, ev, chip) {
        var r = chip.getBoundingClientRect();
        ed.drag = {
            chip: chip, id: ev.pointerId, armed: false, pos: null,
            x: ev.clientX, y: ev.clientY, h: r.height,
            grab: { x: ev.clientX - r.left, y: ev.clientY - r.top }
        };
        // CAPTURE ON THE BOX, NOT THE PILL — the pill leaves the flow when the
        // drag arms, and a browser releases capture from an element it stops
        // rendering. reorder.js learned this the hard way.
        try { ed.box.setPointerCapture(ev.pointerId); } catch (e) { /* not fatal */ }
        document.addEventListener('pointermove', ed.onMove);
        document.addEventListener('pointerup', ed.onUp);
        document.addEventListener('pointercancel', ed.onCancel);
        document.addEventListener('keydown', ed.onEsc, true);
    }

    /* ═══ ONE EDITOR ══════════════════════════════════════════════════════════ */

    function init(field, tpl) {
        if (field.hasAttribute('data-var-ready')) { return; }
        var source = field.querySelector('input[name], textarea[name]');
        if (!source) { return; }

        // EVERY VARIABLE'S LABEL, and the list of the ones this box's context
        // supplies — the only ones offered (`Ui\VariableEditor::attrs()`).
        var all = [];
        try { all = JSON.parse(field.getAttribute('data-vars') || '[]'); } catch (e) { all = []; }
        var labels = {}, supplied = {};
        all.forEach(function (v) {
            labels[v.name] = v.label;
            if (!v.off) { supplied[v.name] = true; }
        });
        var vars = all.filter(function (v) { return !v.off; });

        var id = source.id || ('var-editor-' + (++seq));
        var isArea = source.tagName === 'TEXTAREA';
        var label = field.querySelector('.label');

        // THE LABEL ROW: the label, and "Insert variable" at its far end —
        // the label left and the buttons pushed right.
        var head = document.createElement('div');
        head.className = 'var-editor__head';
        if (label) {
            field.insertBefore(head, label);
            head.appendChild(label);
            if (!label.id) { label.id = id + '-label'; }
        } else {
            field.insertBefore(head, source);
        }
        var insert = document.createElement('button');
        insert.type = 'button';
        insert.className = 'btn btn--sm var-editor__insert';
        insert.setAttribute('data-var-insert', '');
        var plus = tpl ? tpl.content.querySelector('[data-var-icon="plus"] svg') : null;
        if (plus) { insert.appendChild(plus.cloneNode(true)); }
        insert.appendChild(document.createTextNode('Insert variable'));
        head.appendChild(insert);

        // THE BOX: the surface, and the list under it. The field that posts
        // stays where it was, hidden.
        var box = document.createElement('div');
        box.className = 'var-editor__box';
        source.parentNode.insertBefore(box, source.nextSibling);
        source.classList.add('var-editor__source');
        source.hidden = true;

        var surface = document.createElement('div');
        // The field's own look, borrowed whole — `.input` for a title,
        // `.textarea` for a description — so the pill box is visibly the
        // same kind of control as every other box on the form.
        surface.className = (isArea ? 'textarea' : 'input') + ' var-editor__field' +
            (isArea ? ' var-editor__field--area' : '');
        surface.setAttribute('contenteditable', 'true');
        surface.setAttribute('role', 'textbox');
        surface.setAttribute('aria-multiline', 'false');
        surface.setAttribute('aria-autocomplete', 'list');
        surface.setAttribute('aria-controls', id + '-vars');
        surface.setAttribute('aria-expanded', 'false');
        surface.setAttribute('spellcheck', 'true');
        surface.setAttribute('data-var-surface', '');
        if (label) { surface.setAttribute('aria-labelledby', label.id); }
        if (source.getAttribute('placeholder')) {
            surface.setAttribute('data-placeholder', source.getAttribute('placeholder'));
        }
        box.appendChild(surface);

        var menu = document.createElement('div');
        menu.className = 'chip-picker__menu var-editor__menu';
        menu.id = id + '-vars';
        menu.setAttribute('role', 'listbox');
        if (label) { menu.setAttribute('aria-labelledby', label.id); }
        menu.hidden = true;
        box.appendChild(menu);

        var chipTpl = tpl ? tpl.content.querySelector('.var-editor__chip') : null;

        var ed = {
            id: id, field: field, source: source, surface: surface, box: box, menu: menu,
            vars: vars, labels: labels, supplied: supplied, chipTpl: chipTpl,
            history: [], at: 0, lastKind: '', lastAt: 0, lastSel: null,
            escaped: -1, menuKey: '', drag: null, composing: false
        };

        // THE STORED TEXT, DRAWN — with the space after each variable, which
        // is added on load too. The field that posts is NOT rewritten
        // here: until the owner changes something, what posts is what came.
        var start = canon(flat(source.value));
        start = spaceAfterAll(start, 0, start.length, start.length).text;
        draw(ed, start);
        ed.history = [{ text: start, caret: start.length }];

        wire(ed, insert, label);
        field.setAttribute('data-var-ready', '');
        editors.push(ed);
    }

    function wire(ed, insert, label) {
        var s = ed.surface;

        // THE LABEL NAMES THE HIDDEN FIELD, so a click on it would focus
        // nothing. It focuses the pills instead, caret at the end.
        if (label) {
            label.addEventListener('click', function (e) {
                e.preventDefault();
                focus(ed);
                select(ed, current(ed).length);
            });
        }

        // The button must not take focus from the surface, or the caret it is
        // meant to insert at is gone before the click lands.
        insert.addEventListener('mousedown', function (e) { e.preventDefault(); });
        insert.addEventListener('click', function () { insertTrigger(ed); });

        ed.menu.addEventListener('mousedown', function (e) { e.preventDefault(); });
        ed.menu.addEventListener('click', function (e) {
            var o = e.target.closest('.chip-picker__option');
            if (o) { choose(ed, o.getAttribute('data-name')); }
        });

        s.addEventListener('compositionstart', function () { ed.composing = true; });
        s.addEventListener('compositionend', function () { ed.composing = false; typed(ed); });
        s.addEventListener('input', function (e) {
            if (e.isComposing || ed.composing) { return; }
            typed(ed);
        });

        s.addEventListener('beforeinput', function (e) {
            var t = e.inputType || '';
            if (t === 'historyUndo') { e.preventDefault(); travel(ed, -1); return; }
            if (t === 'historyRedo') { e.preventDefault(); travel(ed, 1); return; }
            // One line of plain text: no paragraphs, no bold, and nothing
            // dragged in from elsewhere as markup.
            if (t === 'insertParagraph' || t === 'insertLineBreak' ||
                t === 'insertFromDrop' || t === 'deleteByDrag' || t.indexOf('format') === 0) {
                e.preventDefault();
            }
        });

        s.addEventListener('keydown', function (e) { keyed(ed, e); });

        s.addEventListener('copy', function (e) { clip(ed, e, false); });
        s.addEventListener('cut', function (e) { clip(ed, e, true); });
        s.addEventListener('paste', function (e) { paste(ed, e); });

        // A SELECTION IS NOT DRAGGED OUT AS MARKUP — pills move with the
        // pointer drag below, and text is typed.
        s.addEventListener('dragstart', function (e) { e.preventDefault(); });
        s.addEventListener('drop', function (e) { e.preventDefault(); });

        s.addEventListener('blur', function () { close(ed); });

        // THE PILL: its × removes it; a press on the rest of it starts a drag,
        // which is a click if the pointer never travels. The × must not take
        // focus on the way down, or the list and the caret go with it.
        s.addEventListener('mousedown', function (e) {
            if (e.target.closest('[data-var-remove]')) { e.preventDefault(); }
        });
        s.addEventListener('click', function (e) {
            var x = e.target.closest('[data-var-remove]');
            if (x && s.contains(x)) {
                e.preventDefault();
                removePill(ed, x.closest('[data-var]'));
            }
        });
        s.addEventListener('pointerdown', function (e) {
            var chip = e.target.closest('[data-var]');
            if (!chip || !s.contains(chip) || e.target.closest('[data-var-remove]')) { return; }
            if (e.button !== 0 && e.pointerType === 'mouse') { return; }
            // No text selection painted across the page, no caret jump —
            // reorder.js's reason for the same call.
            e.preventDefault();
            startDrag(ed, e, chip);
        });

        ed.onMove = function (e) {
            var d = ed.drag;
            if (!d || e.pointerId !== d.id) { return; }
            if (!d.armed) {
                if (Math.abs(e.clientX - d.x) < THRESHOLD && Math.abs(e.clientY - d.y) < THRESHOLD) { return; }
                arm(ed);
            }
            // CARRIED JUST UNDER THE LINE, NOT ON IT. In a list the drop line
            // falls between rows, clear of what you carry; in a line of text it
            // is AT the pointer, and a mirror held where it was grabbed sits on
            // top of it — the promise hidden under the thing it is about. So
            // the pill hangs below the pointer's line of text, and the line and
            // the words either side of it stay in view.
            d.mirror.at(e.clientX - d.grab.x, e.clientY + (d.h - d.grab.y) + 4);
            place(ed, e.clientX, e.clientY);
        };
        ed.onUp = function (e) {
            var d = ed.drag;
            if (!d || e.pointerId !== d.id) { return; }
            if (!d.armed) {
                var chip = d.chip;
                endDrag(ed);
                selectPill(ed, chip);
                return;
            }
            if (!d.pos) { place(ed, e.clientX, e.clientY); }
            drop(ed);
        };
        ed.onCancel = function () { endDrag(ed); };
        // ESCAPE PUTS IT BACK — every drag in this admin has one.
        ed.onEsc = function (e) {
            if (e.key !== 'Escape' || !ed.drag) { return; }
            e.preventDefault();
            e.stopPropagation();
            endDrag(ed);
        };
    }

    /** After the browser's own edit: tidy, convert typed `{name}`, post. */
    function typed(ed) {
        var s = ed.surface, messy = false, made = [], acc = 0, m;
        for (var n = s.firstChild; n; n = n.nextSibling) {
            if (n.nodeType === 3) {
                BRACED.lastIndex = 0;
                while ((m = BRACED.exec(n.nodeValue)) !== null) { made.push(acc + m.index + m[0].length); }
                acc += n.nodeValue.length;
            } else if (n.nodeType === 1 && n.hasAttribute('data-var')) {
                acc += n.getAttribute('data-var').length + 2;
            } else {
                messy = true;
                acc += flat(serialize(n)).length;
            }
        }
        var sel = selectionIn(ed);
        var caret = sel ? sel.end : current(ed).length;
        if (messy || made.length) {
            var text = current(ed);
            for (var i = made.length - 1; i >= 0; i--) {
                var r = spaceAfter(text, made[i]);
                if (r.added && caret >= made[i]) { caret++; }
                text = r.text;
            }
            draw(ed, text);
            select(ed, caret);
        }
        sync(ed);
        remember(ed, made.length ? 'insert' : 'type', caret);
        refresh(ed);
    }

    function keyed(ed, e) {
        if (!ed.menu.hidden) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                move(ed, e.key === 'ArrowDown' ? 1 : -1);
                return;
            }
            if (e.key === 'Enter' || e.key === 'Tab') {
                var a = active(ed);
                if (a) {
                    e.preventDefault();
                    choose(ed, a.getAttribute('data-name'));
                    return;
                }
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                var t = trigger(ed);
                ed.escaped = t ? t.from : -1;
                close(ed);
                return;
            }
        }
        if (e.key === 'Enter') { e.preventDefault(); return; }
        var mod = e.ctrlKey || e.metaKey;
        if (mod && !e.altKey) {
            var k = e.key.toLowerCase();
            if (k === 'z') { e.preventDefault(); travel(ed, e.shiftKey ? 1 : -1); return; }
            if (k === 'y') { e.preventDefault(); travel(ed, 1); return; }
            if (k === 'b' || k === 'i' || k === 'u') { e.preventDefault(); return; }
        }
        if (e.key === 'Backspace' || e.key === 'Delete') {
            if (erase(ed, e.key === 'Delete')) { e.preventDefault(); }
        }
    }

    /** Copy and cut carry the stored text, so a pill pastes back as a pill. */
    function clip(ed, e, cut) {
        var sel = selectionIn(ed);
        if (!sel || sel.start === sel.end || !e.clipboardData) { return; }
        var text = current(ed);
        try {
            e.clipboardData.setData('text/plain', text.slice(sel.start, sel.end));
            e.preventDefault();
        } catch (err) {
            return;   // an old browser: its own copy stands
        }
        if (cut) { commit(ed, text.slice(0, sel.start) + text.slice(sel.end), sel.start, 'cut'); }
    }

    function paste(ed, e) {
        e.preventDefault();
        var data = e.clipboardData ? e.clipboardData.getData('text/plain') : '';
        data = canon(flat(String(data).replace(/\s*[\r\n]+\s*/g, ' ')));
        var text = current(ed);
        var sel = selectionIn(ed) || { start: text.length, end: text.length };
        var next = text.slice(0, sel.start) + data + text.slice(sel.end);
        var r = spaceAfterAll(next, sel.start, sel.start + data.length, sel.start + data.length);
        commit(ed, r.text, r.caret, 'paste');
    }

    /* ═══ THE PAGE ════════════════════════════════════════════════════════════ */

    document.addEventListener('selectionchange', function () {
        editors.forEach(function (ed) {
            if (document.activeElement === ed.surface) {
                var sel = selectionIn(ed);
                if (sel) { ed.lastSel = sel; }
                if (!ed.drag) { refresh(ed); }
            }
            mark(ed);
        });
    });

    function boot() {
        var tpl = document.querySelector('template[data-var-template]');
        var fields = document.querySelectorAll('[data-var-editor]');
        for (var i = 0; i < fields.length; i++) { init(fields[i], tpl); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    // Read-only access to the stored text of a field.
    window.VariableEditor = {
        value: function (field) {
            for (var i = 0; i < editors.length; i++) {
                if (editors[i].field === field) { return current(editors[i]); }
            }
            return null;
        }
    };
}(window, document));
