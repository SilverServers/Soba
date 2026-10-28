/**
 * THE ASSISTANT COMPOSER — the progressive enhancement half.
 *
 * ── WHAT THIS UPGRADES, AND WHAT IT DOES NOT ────────────────────────────────
 * The form in the page points at `/send-blocking`, which runs the whole turn
 * server-side and redirects. That path works with this file absent, blocked, or
 * broken — which is the point, and why the markup is a real form rather than a
 * button with a click handler.
 *
 * This script swaps the target to `/send` and polls `/admin/assistant/progress`,
 * so a turn that takes thirty seconds says something during them instead of
 * looking hung.
 *
 * ── POLLED, NOT STREAMED, AND THAT IS NOT A PREFERENCE ──────────────────────
 * `mod_proxy_fcgi` buffers the entire FastCGI response, so a streamed body
 * arrives in one piece at the end — on shared hosting there is nothing to
 * stream. `TurnProgress` writes events the poller reads with a `from` cursor.
 *
 * ── IT NEVER APPLIES ANYTHING ───────────────────────────────────────────────
 * The model cannot write. This file sends a message and reports progress.
 * Applying a change set is a separate POST that a person makes, from a
 * rendered diff, through a confirm dialog — no code path here touches it, and
 * none should be added.
 */
(function () {
    'use strict';

    var doc  = document;
    var form = doc.querySelector('[data-assistant-send]');
    if (!form) { return; }

    var pollAction  = form.getAttribute('data-poll-action');
    var progressUrl = form.getAttribute('data-progress-url');
    var statusEl    = form.querySelector('[data-assistant-status]');
    var button      = form.querySelector('button[type="submit"]');
    var textarea    = form.querySelector('textarea[name="message"]');
    if (!pollAction || !progressUrl) { return; }

    var polling = false;

    function say(text) {
        if (!statusEl) { return; }
        statusEl.hidden = text === '';
        statusEl.textContent = text;
    }

    function busy(on) {
        if (button)   { button.disabled = on; }
        if (textarea) { textarea.readOnly = on; }
    }

    /** A turn id the client owns, so the poll can find this turn's events. */
    function newTurnId() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }
        // The server accepts /^[A-Za-z0-9-]{8,64}$/; this satisfies it without
        // pretending to be cryptographically anything.
        return 'turn-' + String(Date.now()) + '-' + Math.floor(Math.random() * 1e9);
    }

    function poll(turnId, from) {
        if (!polling) { return; }

        CMS.api(progressUrl + '?turn=' + encodeURIComponent(turnId) + '&from=' + from)
            .then(function (data) {
                var events = (data && data.events) || [];
                var next   = (data && typeof data.next === 'number') ? data.next : from;

                events.forEach(function (ev) {
                    if (ev.type === 'status' && ev.text) { say(ev.text); }
                    if (ev.type === 'tool'   && ev.name) { say('Looking at ' + ev.name + '…'); }
                });

                // `finished` IS THE SERVER'S FLAG, and the only correct test.
                // `TurnProgress::read()` consumes the `finished` event itself and
                // never returns it in `events`, so scanning event types for a
                // terminal one — which this did first — never sees it and polls
                // for ever. The flag is the contract; the events are the detail.
                if (data && data.finished) {
                    polling = false;
                    // RELOAD RATHER THAN RENDER THE REPLY HERE. The turn may carry
                    // a change set, and a change set is a DIFF the server builds
                    // from `buildDiff()` — rebuilding that in JS would be a second
                    // renderer for the one screen where being wrong means someone
                    // approves the wrong edit.
                    window.location.reload();
                    return;
                }

                setTimeout(function () { poll(turnId, next); }, 1200);
            })
            .catch(function () {
                // A failed poll is not a failed turn — the request is already in
                // flight server-side. Back off and keep asking; if the turn really
                // died, its `error` event arrives on a later poll.
                setTimeout(function () { poll(turnId, from); }, 3000);
            });
    }

    form.addEventListener('submit', function (e) {
        if (!textarea || textarea.value.trim() === '') {
            e.preventDefault();
            if (textarea) { textarea.focus(); }
            return;
        }

        e.preventDefault();

        var turnId = newTurnId();
        var body   = new FormData(form);
        body.append('turn_id', turnId);

        busy(true);
        say('Thinking…');
        polling = true;
        poll(turnId, 0);

        CMS.post(pollAction, body)
            .catch(function (err) {
                polling = false;
                busy(false);
                say('');
                CMS.flash(err.message, 'error');
            });
    });
}());
