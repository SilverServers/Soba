/**
 * admin.js — the few things every other admin script was writing out by hand.
 *
 * Loaded before every other component script. Adds one global, `CMS`.
 *
 * **Why.** Five files were each building the same fetch call: join BASE_URL,
 * remember the CSRF header, `.then(r => r.json())`, check `res.success`, read
 * `res.data`, and hope the `.catch` was there. That is five chances to forget the
 * CSRF header — and forgetting it is not a visible failure. It is a 403 the write
 * path swallows, so a screen's every write can be broken while every page still
 * loads. A shared helper that cannot forget is worth more than the lines it
 * saves.
 *
 * ES5 on purpose, to match the rest of the admin JavaScript, and no build step.
 */
(function () {
    'use strict';

    var CMS = {};

    /**
     * A value the server put in the document head.
     *
     * Both of the values below come from a <meta> the server emits, rather than
     * from globals — `_CMS_BASE_URL` and `_CMS_CSRF_TOKEN` — that an inline
     * script has to set. Neither failure is visible: a missing base URL only
     * breaks a subdirectory install, and a missing CSRF token is a 403 the write
     * path swallows, which looks exactly like "nothing happened". A <meta> is
     * one carrier the server always emits. The globals are still honoured as a
     * fallback so nothing that sets them breaks.
     */
    function meta(name, fallback) {
        var el = document.querySelector('meta[name="' + name + '"]');
        return (el && el.getAttribute('content')) || fallback || '';
    }

    /** The CSRF token for this session, for the X-CSRF-Token header. */
    CMS.csrf = function () {
        return meta('csrf-token', window._CMS_CSRF_TOKEN);
    };

    /** Absolute URL for a path, whether or not the install is in a subdirectory. */
    CMS.url = function (path) {
        var base = meta('base-url', window._CMS_BASE_URL);

        if (/^https?:\/\//.test(path)) { return path; }

        return base + (path.charAt(0) === '/' ? path : '/' + path);
    };

    /**
     * Call a CMS API endpoint and get the payload back.
     *
     * Resolves with the `data` from `{success: true, data: …}` and rejects with an
     * Error carrying the server's message from `{success: false, error: …}`, so
     * callers write one `.then` and one `.catch` instead of unwrapping by hand and
     * silently doing nothing when `success` is false.
     *
     * The CSRF header goes on every non-GET request. www/index.php rejects any
     * /admin/ or /api/ POST without it, and a caller that forgets gets a 403 that
     * looks exactly like "nothing happened".
     *
     * @param {string} path    Path such as '/api/v1/media', or a full URL.
     * @param {Object} [opts]  { method, body, query }. `body` may be a FormData, or
     *                         a plain object, which is sent as form fields.
     * @returns {Promise<*>}
     */
    CMS.api = function (path, opts) {
        opts = opts || {};

        var url    = CMS.url(path);
        var method = (opts.method || 'GET').toUpperCase();
        var init   = { method: method, credentials: 'same-origin', headers: {} };

        if (opts.query) {
            var parts = [];
            for (var key in opts.query) {
                if (Object.prototype.hasOwnProperty.call(opts.query, key)) {
                    parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(opts.query[key]));
                }
            }
            if (parts.length) { url += (url.indexOf('?') === -1 ? '?' : '&') + parts.join('&'); }
        }

        if (method !== 'GET' && method !== 'HEAD') {
            init.headers['X-CSRF-Token'] = CMS.csrf();
        }

        if (opts.body instanceof FormData) {
            init.body = opts.body;
        } else if (opts.body && typeof opts.body === 'object') {
            var fd = new FormData();
            for (var field in opts.body) {
                if (Object.prototype.hasOwnProperty.call(opts.body, field)) {
                    fd.append(field, opts.body[field]);
                }
            }
            init.body = fd;
        }

        return fetch(url, init)
            .then(function (response) {
                return response.json().catch(function () {
                    // A 500 with an HTML error page, or a redirect to the login
                    // screen after the session expired. Either way it is not JSON.
                    throw new Error('The server did not answer properly (' + response.status + ').');
                });
            })
            .then(function (payload) {
                if (payload && payload.success === false) {
                    throw new Error(payload.error || 'Something went wrong.');
                }

                return payload && Object.prototype.hasOwnProperty.call(payload, 'data')
                    ? payload.data
                    : payload;
            });
    };

    /** Shorthand for a POST. */
    CMS.post = function (path, body) {
        return CMS.api(path, { method: 'POST', body: body });
    };

    /**
     * A short-lived message in the corner of the screen.
     *
     * Emits the `.toast-region` / `.toast` markup that core.css styles. A class
     * name that no stylesheet defines is invisible, not broken: the call builds
     * a correctly-structured, completely unstyled box in the corner and nothing
     * says so.
     *
     * @param {string} message
     * @param {string} [type] 'success' (default) | 'error' | 'warning' | 'info'
     */
    CMS.flash = function (message, type) {
        var region = document.querySelector('.toast-region');

        if (!region) {
            region = document.createElement('div');
            region.className = 'toast-region';
            // Announced to screen readers without stealing focus.
            region.setAttribute('role', 'status');
            region.setAttribute('aria-live', 'polite');
            document.body.appendChild(region);
        }

        var note = document.createElement('div');
        note.className = 'toast toast--' + (type || 'success');
        var msg = document.createElement('div');
        msg.className = 'toast__msg';
        msg.textContent = message;
        note.appendChild(msg);
        region.appendChild(note);

        window.setTimeout(function () {
            note.classList.add('is-leaving');
            window.setTimeout(function () { note.remove(); }, 250);
        }, 4000);

        return note;
    };

    /** Run `fn` once the calls stop, not once per keystroke. */
    CMS.debounce = function (fn, wait) {
        var timer = null;

        return function () {
            var context = this;
            var args    = arguments;

            window.clearTimeout(timer);
            timer = window.setTimeout(function () { fn.apply(context, args); }, wait || 250);
        };
    };

    /** `<tag class="…">text</tag>` — the one-liner four files had a copy of. */
    CMS.el = function (tag, className, text) {
        var node = document.createElement(tag);

        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = text; }

        return node;
    };

    /**
     * COPY — `<button data-copy="#field">` puts the field's value on the
     * clipboard, and the button says so for a moment. select() + execCommand
     * is the fallback: the async clipboard API is unavailable on an insecure
     * origin, and a Copy button that silently does nothing is worse than none.
     * (The media screen's button predates this and keeps its own.)
     */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-copy]') : null;
        var field = btn ? document.querySelector(btn.getAttribute('data-copy')) : null;
        if (!field) { return; }
        var was = btn.textContent;
        function done() {
            btn.textContent = 'Copied';
            window.setTimeout(function () { btn.textContent = was; }, 1500);
        }
        function fallback() {
            field.select();
            try { document.execCommand('copy'); done(); } catch (err) { CMS.flash('Could not copy. Select it and copy it by hand.', 'error'); }
        }
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(field.value).then(done, fallback);
        } else {
            fallback();
        }
    });

    /**
     * REVEAL — `<select data-reveal="name">` shows only the controls marked
     * `data-reveal-for="name"` whose `data-reveal-when` names its value
     * (several, space-separated). Without script every control shows, and
     * the server reads only the one the select names.
     */
    function reveal(select) {
        var name = select.getAttribute('data-reveal');
        document.querySelectorAll('[data-reveal-for="' + name + '"]').forEach(function (el) {
            el.hidden = (' ' + el.getAttribute('data-reveal-when') + ' ').indexOf(' ' + select.value + ' ') === -1;
        });
    }
    document.addEventListener('change', function (e) {
        if (e.target.matches && e.target.matches('select[data-reveal]')) { reveal(e.target); }
    });
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('select[data-reveal]').forEach(reveal);
    });

    window.CMS = CMS;
}());
