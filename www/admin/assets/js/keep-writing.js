/**
 * WRITING IS NOT LOST — the page and entry editors (`form[data-keep-writing]`).
 *
 * An evening's writing was lost three ways, and each has its answer here.
 *
 *   LEAVING WITH CHANGES UNSAVED ASKS FIRST: the browser's own "Leave site?",
 *   once something has been typed since the screen was drawn, and never for
 *   the Save itself.
 *
 *   THE SIGN-IN IS KEPT WHILE SOMEBODY WRITES. It ends after a while with no
 *   request, and typing is not a request, so a long piece outlived it and Save
 *   went to the sign-in page. While keys are pressed this asks the site
 *   whether the sign-in is still good (`GET /api/v1/session`), which keeps it;
 *   a screen left open and unattended still signs out on time. If it has
 *   ended anyway, the screen says so before Save is pressed, and once the
 *   writer has signed in again in another tab, Save works here again.
 *
 *   WHO ELSE HAS IT OPEN is said when the screen is drawn, and again with
 *   each ask while somebody writes, which also keeps them in it and says
 *   whether somebody else has saved it since this screen was drawn
 *   (`Content\Presence`). Leaving the editor takes them out of it at once.
 *
 *   A COPY IS KEPT IN THIS BROWSER: the title, the text and the excerpt, as
 *   they are typed, in this tab's own storage (gone when the tab closes). A
 *   save that never arrived leaves it, and when this screen next opens with
 *   different words, the copy is offered back. A save that did arrive drops
 *   it: the screen says it was saved, or a new page opened as its own.
 *
 * Linked on the two editors; with it missing, they work as they always did.
 */
(function () {
    'use strict';

    var form = document.querySelector('form[data-keep-writing]');
    if (!form) { return; }

    var key      = form.getAttribute('data-keep-writing');
    var store    = 'cms-writing:' + key;
    var PENDING  = 'cms-writing-pending';
    var FIELDS   = ['title', 'content', 'excerpt'];
    var beat     = Math.max(30, parseInt(form.getAttribute('data-keep-writing-beat') || '300', 10)) * 1000;
    var ping     = form.getAttribute('data-keep-writing-ping');
    var offer    = document.querySelector('[data-keep-writing-offer]');
    var gone     = document.querySelector('[data-keep-writing-signed-out]');
    var editing  = form.getAttribute('data-keep-writing-presence');
    var opened   = form.getAttribute('data-keep-writing-opened') || '';
    var present  = document.querySelector('[data-presence]');
    var savedBy  = document.querySelector('[data-presence-saved]');

    var dirty = false;
    var leaving = false;
    var posting = false;
    var typed = false;
    var signedOut = false;

    // This tab's storage can be refused (a private window, blocked site
    // data); then nothing is kept, and the screen still works.
    function read(name) {
        try { return JSON.parse(window.sessionStorage.getItem(name) || 'null'); } catch (e) { return null; }
    }
    function write(name, value) {
        try { window.sessionStorage.setItem(name, JSON.stringify(value)); } catch (e) { /* nothing kept */ }
    }
    function drop(name) {
        try { window.sessionStorage.removeItem(name); } catch (e) { /* nothing kept */ }
    }

    function field(name) { return form.elements.namedItem(name); }

    /** The writing as it stands: the title, the text and the excerpt this screen has. */
    function words() {
        var out = {};
        FIELDS.forEach(function (name) {
            var el = field(name);
            if (el && typeof el.value === 'string') { out[name] = el.value; }
        });
        return out;
    }

    /**
     * What a reader would see, to compare by: the markup's tidying (the
     * editor's, the site's on saving) is not a change anybody made.
     */
    function seen(fields) {
        var parts = [];
        FIELDS.forEach(function (name) {
            var v = fields[name] || '';
            if (name === 'content') {
                var doc = new DOMParser().parseFromString(v, 'text/html');
                v = (doc.body.textContent || '') + ' ' + doc.body.querySelectorAll('img, iframe, video, table').length;
            }
            parts.push(String(v).replace(/\s+/g, ' ').trim());
        });
        return parts.join('\u0000');
    }

    // ── WHAT THE LAST SAVE CAME TO ─────────────────────────────────────────
    // Said saved: the copy goes. A new page or entry saved opens as its own,
    // under its own key, straight from the form it was written in (the admin
    // names its own pages as the referrer), so the copy kept as "new" goes
    // then, and only then: a sign-in in between keeps it.
    var pending = read(PENDING);
    if (form.hasAttribute('data-keep-writing-saved')) {
        drop(store);
    }
    if (pending && pending.key !== key && /:new$/.test(pending.key)
        && pending.key.split(':')[0] === key.split(':')[0]
        && pending.from === document.referrer && Date.now() - (pending.at || 0) < 120000) {
        drop('cms-writing:' + pending.key);
    }
    drop(PENDING);

    // DRAWN AGAIN WITH WHAT IS WRONG: the boxes hold writing the site has
    // not saved, so leaving asks, and the copy stays.
    if (form.hasAttribute('data-keep-writing-unsaved')) {
        dirty = true;
        write(store, { at: Date.now(), fields: words() });
    }

    // ── A COPY THAT DID NOT ARRIVE, OFFERED BACK ───────────────────────────
    var kept = read(store);
    if (kept && kept.fields && seen(kept.fields) !== seen(words())) {
        if (offer) {
            var when = new Date(kept.at || Date.now());
            var today = new Date().toDateString() === when.toDateString();
            var slot = offer.querySelector('[data-keep-writing-when]');
            if (slot) {
                slot.textContent = (today ? '' : when.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ', ')
                    + when.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
            }
            offer.hidden = false;
        }
    } else if (kept && !dirty) {
        drop(store);
    }

    if (offer) {
        offer.addEventListener('click', function (e) {
            var restore = e.target.closest('[data-keep-writing-restore]');
            var discard = e.target.closest('[data-keep-writing-discard]');
            if (restore && kept) {
                FIELDS.forEach(function (name) {
                    var el = field(name);
                    if (!el || typeof kept.fields[name] !== 'string') { return; }
                    el.value = kept.fields[name];
                    // The rich editor draws its surface again from the box
                    // (`editor.js`), and whatever counts or follows the box
                    // is told it changed.
                    el.dispatchEvent(new Event('cms:replaced'));
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                });
                dirty = true;
                offer.hidden = true;
            } else if (discard) {
                drop(store);
                kept = null;
                offer.hidden = true;
            }
        });
    }

    // ── KEPT AS IT IS TYPED ─────────────────────────────────────────────────
    var timer = null;
    function keep() {
        timer = null;
        write(store, { at: Date.now(), fields: words() });
    }
    function changed(e) {
        // A person's change, not a script's setting a box as the page starts.
        if (!e.isTrusted) { return; }
        var el = e.target;
        if (!(el.form === form || form.contains(el))) { return; }
        dirty = true;
        typed = true;
        if (!timer) { timer = window.setTimeout(keep, 600); }
    }
    document.addEventListener('input', changed, true);
    document.addEventListener('change', changed, true);

    // ── SAVING IS NOT LEAVING ───────────────────────────────────────────────
    // Bubbling to the window comes after the form's own handlers, so a submit
    // one of them stopped is not taken for a save.
    window.addEventListener('submit', function (e) {
        if (!e.defaultPrevented) { posting = true; }
        if (e.target !== form || e.defaultPrevented) { return; }
        leaving = true;
        if (timer) { window.clearTimeout(timer); }
        if (dirty) { keep(); }
        write(PENDING, { key: key, at: Date.now(), from: window.location.href });
    });

    // BACK TO A SCREEN THE BROWSER KEPT, after a save that went nowhere: it
    // is being left again, and asks again.
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) { leaving = false; posting = false; }
    });

    window.addEventListener('beforeunload', function (e) {
        if (!dirty || leaving) { return; }
        e.preventDefault();
        e.returnValue = '';
    });

    // ── STILL SIGNED IN? ────────────────────────────────────────────────────
    function token(value) {
        document.querySelectorAll('input[name="_csrf_token"]').forEach(function (el) { el.value = value; });
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) { meta.setAttribute('content', value); }
    }
    // WHO ELSE IS IN IT, as the answer says: one line a person, and a
    // second notice when somebody else has saved since this screen was drawn.
    function presence(info) {
        if (!info) { return; }
        var list = present ? present.querySelector('[data-presence-list]') : null;
        if (list) {
            list.textContent = '';
            (info.others || []).forEach(function (who) {
                var li = document.createElement('li');
                li.textContent = who.name + ', since ' + who.opened + (who.writing ? ', last writing at ' + who.writing : '');
                list.appendChild(li);
            });
            present.hidden = !(info.others && info.others.length);
        }
        if (savedBy) {
            var title = savedBy.querySelector('[data-presence-saved-title]');
            if (info.saved && title) {
                title.textContent = info.saved.name + ' saved this at ' + info.saved.at + ', after you opened it';
            }
            savedBy.hidden = !info.saved;
        }
    }
    function asking() {
        if (!editing) { return ping; }
        return ping + (ping.indexOf('?') === -1 ? '?' : '&')
            + 'editing=' + encodeURIComponent(editing) + '&opened=' + encodeURIComponent(opened);
    }
    function ask() {
        if (!ping || !window.CMS) { return; }
        // ONLY A 401 IS "SIGNED OUT". A dropped connection or a server that
        // stumbled is asked again next time, and says nothing it does not know.
        fetch(window.CMS.url(asking()), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) {
                if (r.status === 401) {
                    signedOut = true;
                    if (gone) { gone.hidden = false; }
                    return null;
                }
                return r.ok ? r.json() : null;
            })
            .then(function (payload) {
                var data = payload && payload.success ? payload.data : null;
                if (!data) { return; }
                if (signedOut && data.token) {
                    // SIGNED IN AGAIN, elsewhere: this screen's forms carry
                    // the new session's token, so Save works here as it is.
                    token(data.token);
                }
                signedOut = false;
                if (gone) { gone.hidden = true; }
                presence(data.editing);
            })
            .catch(function () { /* asked again next time */ });
    }
    // LEAVING THE EDITOR takes them out of it, so nobody is told about a
    // screen that has closed.
    window.addEventListener('pagehide', function () {
        // NOT FOR A FORM SENT FROM HERE: a save, a restore or a new share link
        // draws this editor again, and a leave arriving after it would take
        // its writer out of it (one that leads elsewhere lapses on its own).
        if (posting || !editing || !window.CMS || !navigator.sendBeacon) { return; }
        var field = document.querySelector('input[name="_csrf_token"]');
        var body = new FormData();
        body.append('editing', editing);
        body.append('_csrf_token', field ? field.value : '');
        try { navigator.sendBeacon(window.CMS.url('/api/v1/session/leave'), body); } catch (e) { /* gone with the window */ }
    });

    window.setInterval(function () {
        // Only while somebody is writing, or to see them signed in again.
        if (typed || signedOut) {
            typed = false;
            ask();
        }
    }, beat);
    // Signed out, the screen looks again sooner: a writer who has just
    // signed in elsewhere should not wait five minutes to save.
    window.setInterval(function () {
        if (signedOut) { ask(); }
    }, 15000);
}());
