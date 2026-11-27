/**
 * confirm.js — the confirm dialog for destructive submits.
 *
 * Any form carrying `data-confirm` is intercepted and asks first, in a native
 * `<dialog class="modal">`. Optional `data-confirm-detail` adds the consequence
 * and `data-confirm-verb` names the button (default "Delete").
 *
 * ── A BARE BUTTON WORKS TOO ─────────────────────────────────────────────────
 * For the media detail panel, whose Delete posts through `fetch` and so has
 * no form to intercept. A `<button data-confirm>` outside a
 * form, or a `type="button"` one inside it, gets the same dialog, and on
 * confirmation is re-clicked with `data-confirmed` set so the page's own
 * handler runs.
 *
 * IT IS HERE RATHER THAN IN THAT PANEL because a second confirmation mechanism
 * is how one of them ends up unstyled and unthemed — exactly what the note
 * below says about window.confirm(). One dialog, two triggers.
 *
 * ── WHY NOT window.confirm() ────────────────────────────────────────────────
 * core.css §18 answers this beside the CSS: the browser's confirm() cannot be
 * styled and cannot be themed.
 *
 * ── WHY THE DIALOG IS WHERE THE RED LIVES ───────────────────────────────────
 * A destructive TRIGGER does not need to shout, because the dialog it opens IS
 * the alert surface. That is what lets the Delete control on
 * a list row be a plain uncoloured `.link .link--xs` — the emphasis moves to
 * the moment it matters instead of sitting red on every row of the page.
 *
 * ── AND WHY THE DETAIL LINE IS NOT AN EXPLANATION ───────────────────────────
 * core.css §13: "the action has a non-obvious side effect → put it in the
 * confirm dialog." The detail says what will HAPPEN ("4 entries stay in the
 * database"), never how the feature works and never "this cannot be undone" on
 * something that can.
 *
 * ES5, no build step, no dependency.
 */
(function () {
    'use strict';

    var dialog  = null;
    var pending = null;   // the form OR button waiting on an answer

    function build() {
        if (dialog) { return dialog; }

        dialog = document.createElement('dialog');
        dialog.className = 'modal';
        dialog.setAttribute('data-confirm-dialog', '');
        dialog.innerHTML =
            '<div class="modal__inner">' +
              '<h2 class="modal__title" data-confirm-title></h2>' +
              '<p class="modal__body" data-confirm-body hidden></p>' +
              '<div class="modal__actions">' +
                '<button type="button" class="btn" data-confirm-cancel>Cancel</button>' +
                '<button type="button" class="btn btn--danger" data-confirm-go></button>' +
              '</div>' +
            '</div>';

        document.body.appendChild(dialog);

        dialog.querySelector('[data-confirm-cancel]').addEventListener('click', function () {
            pending = null;
            dialog.close();
        });

        dialog.querySelector('[data-confirm-go]').addEventListener('click', function () {
            var el = pending;
            pending = null;
            dialog.close();
            if (!el) { return; }
            // The flag is what lets the second pass through, either way.
            el.setAttribute('data-confirmed', '');
            if (el.tagName === 'FORM') { el.submit(); return; }
            // A bare button: re-click it so the page's own handler runs, then
            // disarm. Leaving the flag set would make every later click on the
            // same control skip the dialog.
            el.click();
            el.removeAttribute('data-confirmed');
        });

        // Esc closes a native dialog on its own; make sure it does not leave a
        // form armed behind it.
        dialog.addEventListener('close', function () { pending = null; });

        return dialog;
    }

    function ask(el) {
        var d      = build();
        var detail = el.getAttribute('data-confirm-detail') || '';
        var body   = d.querySelector('[data-confirm-body]');

        d.querySelector('[data-confirm-title]').textContent = el.getAttribute('data-confirm');
        body.textContent = detail;
        body.hidden = detail === '';
        d.querySelector('[data-confirm-go]').textContent =
            el.getAttribute('data-confirm-verb') || 'Delete';

        pending = el;
        d.showModal();
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;

        if (!form.matches || !form.matches('[data-confirm]')) { return; }
        if (form.hasAttribute('data-confirmed')) { return; }

        e.preventDefault();
        ask(form);
    });

    // CAPTURE PHASE, so the dialog is raised before any handler the page bound
    // on the button itself can run. In the bubble phase the page's own click
    // listener fires first and the delete has already happened by the time the
    // question is asked.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('button[data-confirm]') : null;
        // A BUTTON THAT SUBMITS its form is the case above. One that never
        // does (`type="button"`, such as a child's row's Trash inside its
        // parent's form) asks here, wherever it is.
        if (!btn || (btn.form && btn.type !== 'button')) { return; }
        if (btn.hasAttribute('data-confirmed')) { return; }

        e.preventDefault();
        e.stopPropagation();
        ask(btn);
    }, true);
}());
