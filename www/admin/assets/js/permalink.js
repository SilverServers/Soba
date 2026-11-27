/**
 * permalink.js — the title card's address row.
 *
 * Emitted by the screen, inside $pageContent, so `CMS` (admin.js, in <head>) is
 * already there.
 *
 * Two behaviours:
 *
 *   1. EDIT — the slug is a link until you click Edit, then an input with OK and
 *      Cancel. Not a permanently-open box: the address of a published page is
 *      not something to change by tabbing into it, and the common case is
 *      reading it, not editing it.
 *
 *   2. AUTO — on a NEW entry the slug follows the title as you type, so the
 *      address row shows what the URL is about to be instead of a prefix ending
 *      in nothing. It stops the moment the slug is edited by hand, permanently.
 *
 * ── WHAT AUTO-SLUGGING IS AND IS NOT ────────────────────────────────────────
 * It is a PREVIEW of `Post::generateSlug()`, which is the one authority and runs
 * on every save. The hidden input posts whatever is shown, so a preview that
 * disagreed with the server would be a lie the user acts on — if these two ever
 * diverge, this one is wrong.
 *
 * It NEVER runs on an existing entry. The slug is the URL people have already
 * linked to and search engines have already indexed; re-deriving it because
 * someone fixed a typo in the title would break every one of those links
 * silently. `data-new` is the switch, and it is set by the SERVER from whether a
 * slug exists — not inferred here from an empty field.
 *
 * ES5, no build step, no dependency. Same as every other file in here.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-permalink]');
    if (!root) { return; }

    var prefix  = root.getAttribute('data-prefix') || '';
    /* '/' when the site ends its addresses with a slash (Settings → Permalinks). */
    var suffix  = root.getAttribute('data-suffix') || '';
    var isNew   = root.getAttribute('data-new') === '1';

    var display = root.querySelector('[data-permalink-display]');
    var editor  = root.querySelector('[data-permalink-editor]');
    var slugEl  = root.querySelector('[data-permalink-slug]');
    var linkEl  = root.querySelector('[data-permalink-link]');
    var input   = root.querySelector('[data-permalink-input]');
    var hidden  = root.querySelector('[data-permalink-value]');
    /* `1` while the address is made from the title, '' once it is typed: the
       save makes a made one unique, and refuses a typed one that is taken. */
    var auto    = root.querySelector('[data-permalink-auto]');
    var title   = document.getElementById('entry-title');

    var editBtn   = root.querySelector('[data-permalink-edit]');
    var okBtn     = root.querySelector('[data-permalink-ok]');
    var cancelBtn = root.querySelector('[data-permalink-cancel]');

    /* Once true, the title stops driving the slug — for good. A failed save
       that comes back with an address typed by hand keeps it so. */
    var manual = !isNew || (auto !== null && auto.value === '');
    /* The address is the author's now: the title stops driving it, and the
       save takes it as typed. */
    function typed() {
        manual = true;
        if (auto) { auto.value = ''; }
    }

    /* THE SAME TRANSFORM THE SERVER APPLIES. Any divergence makes the preview a
       lie, because the hidden input posts exactly what is displayed. */
    function slugify(text) {
        return text
            .toLowerCase()
            .trim()
            .replace(/[^\w\s-]/g, '')
            .replace(/[\s_]+/g, '-')
            .replace(/-{2,}/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function apply(slug) {
        if (slugEl) { slugEl.textContent = slug; }
        if (hidden) { hidden.value = slug; }
        if (input)  { input.value = slug; }
        /* Only an <a> has an href — on a draft the display is a <span>, because
           a link to a page that does not resolve is worse than no link. */
        if (linkEl && linkEl.tagName === 'A') { linkEl.href = prefix + slug + suffix; }
    }

    function open() {
        display.hidden = true;
        editor.hidden  = false;
        if (input) { input.focus(); input.select(); }
    }

    function close() {
        display.hidden = false;
        editor.hidden  = true;
    }

    function commit() {
        var slug = slugify(input ? input.value : '');
        /* AN EMPTY SLUG IS NOT A VALUE, it is a request for the server to make
           one. Committing "" would post an empty slug and the server would
           generate from the title — which is right — but the row would show a
           URL ending in nothing until the save. So an empty commit reverts to
           whatever was there and lets Cancel mean Cancel. */
        if (slug !== '') {
            /* An address committed as it was is still the title's. */
            if (!hidden || slug !== hidden.value) { typed(); }
            apply(slug);
        } else if (hidden) {
            apply(hidden.value);
        }
        close();
    }

    function cancel() {
        if (input && hidden) { input.value = hidden.value; }
        close();
    }

    editBtn.addEventListener('click', open);
    okBtn.addEventListener('click', commit);
    cancelBtn.addEventListener('click', cancel);

    input.addEventListener('keydown', function (e) {
        /* Enter inside the slug box must NOT submit the form — the box is a
           sub-editor, and a stray Enter saving the whole entry mid-edit is the
           kind of thing that gets noticed after it publishes. */
        if (e.key === 'Enter')  { e.preventDefault(); commit(); }
        if (e.key === 'Escape') { e.preventDefault(); cancel(); }
    });

    /* A SLUG TYPED AND NOT CONFIRMED STILL COUNTS. Typing a new address and
       going straight to Save — never pressing OK or Enter — saved the OLD one:
       the hidden input posts what was last committed, and the box's value went
       nowhere, with nothing on the screen to say so. An editor still open when
       the form submits is committed first, by OK's own rule (an emptied box
       keeps the address it had).
       `hidden.form` rather than a search upward: it is the form that posts the
       slug, wherever the row sits. Capture phase, so it runs before any other
       submit handler reads the value. */
    var form = hidden ? hidden.form : null;
    if (form) {
        form.addEventListener('submit', function () {
            if (editor && !editor.hidden) { commit(); }
        }, true);
    }

    if (isNew && title) {
        /* DEBOUNCED, because this repaints a URL under the field being typed in
           and doing it per keystroke is visual noise. 300ms reads as "keeping
           up" rather than "flickering". */
        var timer = null;
        title.addEventListener('input', function () {
            if (manual) { return; }
            clearTimeout(timer);
            timer = setTimeout(function () { apply(slugify(title.value)); }, 300);
        });
    }
}());
