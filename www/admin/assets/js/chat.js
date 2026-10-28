/**
 * chat.js — the assistant's chat behaviours.
 *
 * Four small things that make a transcript-plus-composer usable, and nothing
 * else. The turn itself — POSTing, polling progress, rendering the reply — is
 * `assistant.js`'s job and stays there; this file never touches the network.
 *
 * ── WHY THESE FOUR ─────────────────────────────────────────────────────────
 *   1. SCROLL TO THE BOTTOM ON LOAD. A transcript that opens at the top makes
 *      you scroll to find the thing you just asked, every single time.
 *   2. THE COMPOSER GROWS. A one-row textarea for a paragraph-long instruction
 *      is a peephole. It grows to the CSS ceiling (200px) and then scrolls —
 *      unbounded, it would eat the transcript it belongs to.
 *   3. ENTER SENDS, SHIFT+ENTER NEWLINES. The convention every chat uses. It
 *      submits the FORM rather than calling anything, so the no-JavaScript
 *      path and `assistant.js`'s upgraded path both keep working — this file
 *      does not need to know which one is live.
 *   4. THE EXAMPLES FILL THE COMPOSER. A suggestion you cannot act on is
 *      decoration. They fill and focus rather than submitting, because sending
 *      something the owner has not read is exactly the reflex this whole
 *      feature is built to prevent.
 *
 * Everything is guarded: this script also loads on a conversation with no
 * examples and no messages.
 */
(function () {
    'use strict';

    var doc    = document;
    var scroll = doc.querySelector('[data-chat-scroll]');
    var input  = doc.querySelector('[data-chat-input]');

    /* ── 1. Open at the newest message ───────────────────────────────────── */
    if (scroll) {
        // Not smooth: on load there is nothing to animate FROM, and a smooth
        // scroll from the top is a second of watching the page fly past.
        scroll.scrollTop = scroll.scrollHeight;
    }

    if (!input) { return; }

    /* ── 2. Grow to fit, up to the ceiling ───────────────────────────────── */
    function autosize() {
        // Reset first: without it the box can only ever grow, because
        // scrollHeight is measured against the height already set.
        input.style.height = 'auto';
        input.style.height = input.scrollHeight + 'px';
    }
    input.addEventListener('input', autosize);
    autosize();

    /* ── 3. Enter sends ──────────────────────────────────────────────────── */
    input.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.shiftKey) { return; }
        // An IME composing a character uses Enter to commit it. Sending there
        // would truncate the word being typed — and it is the reflex that makes
        // a chat box unusable in Japanese, Chinese or Korean.
        if (e.isComposing || e.keyCode === 229) { return; }
        if (input.value.trim() === '') { e.preventDefault(); return; }

        var form = input.closest('form');
        if (!form) { return; }
        e.preventDefault();
        // requestSubmit, not submit(): it fires the submit EVENT, which is what
        // assistant.js listens on. `form.submit()` bypasses handlers entirely
        // and would silently drop the polled path back to a full page post.
        if (typeof form.requestSubmit === 'function') { form.requestSubmit(); }
        else { form.submit(); }
    });

    /* ── 4. Examples fill the composer ───────────────────────────────────── */
    doc.addEventListener('click', function (e) {
        var ex = e.target.closest('[data-chat-example]');
        if (!ex) { return; }
        e.preventDefault();
        input.value = ex.textContent.trim();
        autosize();
        input.focus();
        // Caret to the end, so typing continues the sentence rather than
        // landing in front of it.
        input.setSelectionRange(input.value.length, input.value.length);
    });
}());
