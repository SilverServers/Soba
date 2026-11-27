/**
 * ANNOUNCEMENTS: showing the ones `Site\Announcements` printed.
 *
 * Each is printed hidden, newest first, with its id, its version, its place
 * and its dates. In each place (the banner, the pop-up, the slide-in, and
 * each place a theme has) the first that has started and not ended, by the
 * visitor's own clock, and that this visitor has not dismissed, is shown; a
 * page a cache or a static copy kept shows it on time and hides it when it
 * ends. A dismissal is kept in this browser by the id and the version: a
 * changed message is a new version and shows again.
 *
 * A POP-UP is a <dialog> shown with showModal(), which keeps the focus inside
 * it and closes on Escape; it is shown once, and closed by its button, by
 * Escape or by a click beside it. A pop-up or a slide-in never shows beside
 * the consent banner (`[data-cms-consent]`), waiting until it is answered,
 * and a slide-in waits for the pop-up to close. A banner and a theme's place
 * are part of the page and show at once.
 *
 * ONE PREVIEWED (`data-preview`, a signed-in account's `?announcement=12`)
 * shows whatever its dates and whether or not it was dismissed, and nothing
 * about it is remembered.
 */
(function () {
    'use strict';

    var KEY = 'cms-announcement-';

    function seen(el) {
        try {
            return window.localStorage.getItem(KEY + el.getAttribute('data-cms-announcement')) === el.getAttribute('data-version');
        } catch (e) {
            return false;
        }
    }

    function remember(el) {
        if (el.hasAttribute('data-preview')) { return; }
        try {
            window.localStorage.setItem(KEY + el.getAttribute('data-cms-announcement'), el.getAttribute('data-version'));
        } catch (e) { /* a browser that keeps nothing shows it again: no harm */ }
    }

    /** Has it started and not ended, by this visitor's clock? */
    function now(el) {
        var at     = Date.now() / 1000;
        var starts = parseInt(el.getAttribute('data-starts'), 10) || 0;
        var ends   = parseInt(el.getAttribute('data-ends'), 10) || 0;
        return at >= starts && (!ends || at < ends);
    }

    function due(el) {
        return el.hasAttribute('data-preview') || (!seen(el) && now(el));
    }

    /** Is the consent banner asking? */
    function asking() {
        var banner = document.querySelector('[data-cms-consent]');
        return !!banner && !banner.hidden;
    }

    /** Run `then` once the consent banner is not asking. */
    function afterConsent(then) {
        if (!asking()) { then(); return; }
        var watch = new MutationObserver(function () {
            if (asking()) { return; }
            watch.disconnect();
            then();
        });
        watch.observe(document.querySelector('[data-cms-consent]'), { attributes: true, attributeFilter: ['hidden'] });
    }

    function close(el) {
        remember(el);
        if (el.tagName === 'DIALOG') {
            if (el.open) { el.close(); }
        } else {
            el.hidden = true;
        }
    }

    /** Show it: true when it is showing. */
    function show(el) {
        if (el.tagName === 'DIALOG') {
            if (typeof el.showModal !== 'function') { return false; }
            // SHOWN ONCE: seen from the moment it opens.
            remember(el);
            el.showModal();
            return true;
        }
        el.hidden = false;
        return true;
    }

    /** The place it takes: one shows at a time in each. */
    function place(el) {
        var at = el.getAttribute('data-placement') || 'banner';
        return at === 'slot' ? 'slot:' + (el.getAttribute('data-slot') || '') : at;
    }

    function bind(el) {
        el.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('[data-cms-announcement-close]')) {
                close(el);
                return;
            }
            // A CLICK BESIDE A POP-UP lands on the dialog itself, outside its box.
            if (el.tagName === 'DIALOG' && e.target === el) {
                var box = el.getBoundingClientRect();
                if (e.clientX < box.left || e.clientX > box.right || e.clientY < box.top || e.clientY > box.bottom) {
                    close(el);
                }
            }
        });
        // Escape: the browser closes it, and this remembers it.
        el.addEventListener('close', function () { remember(el); });
    }

    function start() {
        var chosen = {};
        Array.prototype.forEach.call(document.querySelectorAll('[data-cms-announcement]'), function (el) {
            bind(el);
            var at = place(el);
            if (!chosen[at] && due(el)) { chosen[at] = el; }
        });

        Object.keys(chosen).forEach(function (at) {
            if (at !== 'modal' && at !== 'slidein') { show(chosen[at]); }
        });

        var modal = chosen.modal;
        var slide = chosen.slidein;
        if (!modal && !slide) { return; }
        afterConsent(function () {
            var open = modal && due(modal) && show(modal);
            if (!slide) { return; }
            if (open) {
                modal.addEventListener('close', function () { if (due(slide)) { show(slide); } }, { once: true });
            } else if (due(slide)) {
                show(slide);
            }
        });
    }

    // AFTER THE CONSENT BANNER'S OWN SCRIPT, which shows it when the page is
    // read (`measure.js`, in the head), so a pop-up knows whether it is
    // asking. At once on a page already loaded.
    var started = false;
    function go() {
        if (started) { return; }
        started = true;
        start();
    }
    if (document.readyState === 'complete') {
        go();
    } else {
        document.addEventListener('DOMContentLoaded', go);
        window.addEventListener('load', go);
    }
}());
