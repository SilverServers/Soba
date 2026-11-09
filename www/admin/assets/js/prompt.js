/**
 * prompt.js — the ASK-FOR-A-VALUE dialog.
 *
 * `confirm.js`'s sibling. That one asks a yes/no question about a destructive
 * submit; this one asks for a string. Same `<dialog class="modal">`, same
 * chrome, same reasons.
 *
 * ── WHY NOT window.prompt() ─────────────────────────────────────────────────
 * The same three reasons `confirm.js` gives for not using `window.confirm()`,
 * and they are not weaker here: the browser's prompt cannot be styled, cannot
 * be themed, and is invisible to Playwright — so an assertion about the link
 * dialog would sail straight past it, which is the exact shape of a guard that
 * passes an error page.
 *
 * It is also modal to the whole BROWSER rather than to the page: it freezes the
 * tab, it renders at the top of the window with no relationship to the control
 * that opened it, and on some platforms it offers to stop the page from making
 * further dialogs — a browser-chrome escape hatch that silently disables a
 * feature of the admin.
 *
 * The editor shipped with two of these (the link address, and an image's alt
 * text) and the file said so out loud rather than pretending it was fine. This
 * is that note being paid off.
 *
 * ── ONE FIELD OR SEVERAL, AND STILL ONE COMPONENT ───────────────────────────
 * `ask({label, value})` asks for a string and resolves to a string. `ask({fields:
 * [...]})` asks for several and resolves to an object keyed by field name. Same
 * dialog, same chrome, same cancel contract.
 *
 * The alternative was a second dialog component for the image-details form, and
 * "inventing a component when one exists" is a thing this project has been told
 * off for by name. A dialog that asks for two strings is not a different kind of
 * thing from one that asks for one.
 *
 * A field is `{name, label, value, type, options, placeholder, help, required}`.
 * `type` is 'text' (the default), 'number', 'select' or 'textarea'; a select
 * takes `options` as `[[value, label], …]`, and a textarea `rows`. `help` is a
 * line under the control, for the thing the label cannot say in two words.
 *
 * A textarea with `file: {label, accept}` also takes a file: a button that chooses
 * one, or one dropped on the box, whose words become the box's. `wide: true` on
 * the ask is a dialog with room for a document, for that ask alone.
 *
 * ── AND `fields: []` IS A DIALOG THAT ONLY TELLS YOU SOMETHING ──────────────
 * An EMPTY list is not the same as no list: it means "many fields, and there
 * are none", so the box is a title and a body with one button to close it. The
 * keyboard-shortcuts list is that, and it is the third shape this component has
 * rather than the third component — which is the thing this project keeps being
 * told not to build.
 *
 * It resolves to `null` either way, because there is nothing to collect: a
 * caller that awaited a value from a dialog with no fields is a caller with a
 * bug, and `null` is the answer that makes that obvious rather than `{}`.
 *
 * ── PROMISE-SHAPED, BECAUSE THE CALLER IS MID-SELECTION ─────────────────────
 * `Prompt.ask()` returns a Promise resolving to the string, or to `null` when
 * cancelled — deliberately the same contract `window.prompt()` had, so the call
 * sites read the same and the swap cannot change behaviour by accident.
 *
 * A cancel is `null` and an empty answer is `''`, and they are NOT the same
 * thing: an image's alt text is legitimately empty for a decorative picture,
 * and the caller has to be able to tell "the author said nothing" from "the
 * author changed their mind".
 *
 * ── AND THE SELECTION SURVIVES ──────────────────────────────────────────────
 * Opening a modal moves focus, which collapses a contenteditable's selection.
 * That is the caller's problem to solve and `editor.js` does — it remembers the
 * caret offset before calling and restores it after. Stated here because the
 * next caller will hit it too.
 *
 * ES5, no build step, no dependency.
 */
(function () {
    'use strict';

    var dialog  = null;
    var resolve = null;   // the pending Promise's resolver
    var multi   = false;  // is the OPEN ask a many-field one?
    var closing = false;  // did finish() close it? (see the `close` listener)

    function build() {
        if (dialog) { return dialog; }

        dialog = document.createElement('dialog');
        dialog.className = 'modal';
        dialog.setAttribute('data-prompt-dialog', '');
        dialog.innerHTML =
            '<form method="dialog" class="modal__inner" data-prompt-form>' +
              '<h2 class="modal__title" data-prompt-title></h2>' +
              '<p class="modal__body" data-prompt-body hidden></p>' +
              // THE ONE-FIELD SHAPE IS STILL THE MARKUP, not a special case of
              // the many-field one. Every existing caller and every assertion
              // about `[data-prompt-input]` keeps working exactly as it did.
              '<div class="field" data-prompt-single>' +
                '<label class="label" for="prompt-value" data-prompt-label></label>' +
                '<input class="input" type="text" id="prompt-value" data-prompt-input ' +
                       'autocomplete="off" spellcheck="false">' +
              '</div>' +
              '<div data-prompt-fields hidden></div>' +
              '<div class="modal__actions">' +
                '<button type="button" class="btn" data-prompt-cancel>Cancel</button>' +
                '<button type="submit" class="btn btn--primary" data-prompt-go></button>' +
              '</div>' +
            '</form>';

        document.body.appendChild(dialog);

        dialog.querySelector('[data-prompt-cancel]').addEventListener('click', function () {
            finish(null);
        });

        // SUBMIT, NOT A CLICK HANDLER. The form is `method="dialog"`, so Enter
        // in the text box submits it — which is what Enter means in a one-field
        // dialog, and what `window.prompt()` did. Binding the button's click
        // instead would leave Enter doing nothing at all.
        dialog.querySelector('[data-prompt-form]').addEventListener('submit', function (e) {
            e.preventDefault();
            finish(readValue());
        });

        // Esc closes a native dialog on its own. Without this the Promise is
        // never settled and the caller waits forever.
        //
        // A CLOSE THIS SCRIPT MADE IS NOT ONE. `dialog.close()` delivers its
        // `close` event a task LATER — after the caller's `.then` has run. A
        // caller that answers one ask with another (a refusal after a bad
        // address) had its SECOND dialog shut, and settled as a cancel, by the
        // first one's late event. Measured: the second dialog never appeared.
        dialog.addEventListener('close', function () {
            if (closing) { closing = false; return; }
            finish(null);
        });

        return dialog;
    }

    /**
     * What the dialog is currently holding — a string, or an object.
     *
     * The SHAPE IS DECIDED BY THE ASK, not by what happens to be on screen: a
     * multi-field ask that resolved to a bare string would silently drop every
     * field but the first, which is the kind of bug that looks like the caller's.
     */
    function readValue() {
        if (!multi) { return dialog.querySelector('[data-prompt-input]').value; }

        var out = {};
        Array.prototype.forEach.call(
            dialog.querySelectorAll('[data-prompt-fields] [data-prompt-field]'),
            function (el) { out[el.dataset.promptField] = el.value; }
        );
        return out;
    }

    function finish(value) {
        var fn = resolve;
        resolve = null;
        if (dialog.open) { closing = true; dialog.close(); }
        if (fn) { fn(value); }
    }

    /**
     * A FILE'S WORDS INTO A TEXT BOX: the row under it, with a button that chooses
     * a file, and the box itself taking one dropped on it. What the browser is
     * given goes in the box, where the writer can see it before anything is done
     * with it; nothing here sends it anywhere.
     *
     * READ AS BYTES, not as text. `readAsText()` turns what is not UTF-8 into
     * replacement characters before anyone can tell, so a document saved by an
     * older Windows editor lost its curly quotes. It is UTF-8 if it is valid as
     * UTF-8, and Windows-1252 if not.
     */
    function fileRow(file, box) {
        var row = document.createElement('div');
        row.className = 'prompt-file';

        var input = document.createElement('input');
        input.type = 'file';
        input.hidden = true;
        input.accept = file.accept || '';
        input.setAttribute('data-prompt-file', '');

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn--sm';
        button.textContent = file.label || 'Choose a file';
        button.addEventListener('click', function () { input.click(); });

        var said = document.createElement('span');
        said.className = 'form-help';
        said.setAttribute('data-prompt-file-said', '');

        var accepted = function (chosen) {
            var name = chosen.name.toLowerCase();
            return chosen.type.indexOf('text/') === 0 || (file.accept || '').split(',').some(function (a) {
                return a.charAt(0) === '.' && name.slice(-a.length) === a;
            });
        };
        var read = function (chosen) {
            if (!accepted(chosen)) {
                said.textContent = chosen.name + ' is not a text file.';
                return;
            }
            var reader = new FileReader();
            reader.onload = function () {
                var bytes = new Uint8Array(reader.result);
                var text;
                try {
                    text = new TextDecoder('utf-8', { fatal: true }).decode(bytes);
                } catch (err) {
                    text = new TextDecoder('windows-1252').decode(bytes);
                }
                box.value = text;
                said.textContent = chosen.name;
                box.focus();
            };
            reader.onerror = function () { said.textContent = chosen.name + ' could not be read.'; };
            reader.readAsArrayBuffer(chosen);
        };

        input.addEventListener('change', function () {
            if (input.files.length) { read(input.files[0]); }
            input.value = '';
        });
        var carriesFile = function (e) {
            return !!e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') !== -1;
        };
        box.addEventListener('dragover', function (e) {
            if (carriesFile(e)) { e.preventDefault(); }
        });
        box.addEventListener('drop', function (e) {
            if (carriesFile(e) && e.dataTransfer.files.length) {
                e.preventDefault();
                read(e.dataTransfer.files[0]);
            }
        });

        row.appendChild(input);
        row.appendChild(button);
        row.appendChild(said);
        return row;
    }

    /**
     * Ask for a value.
     *
     * @param {object} opts  title · label · body (optional detail) ·
     *                       value (prefill) · verb (the confirm button) ·
     *                       placeholder
     * @returns {Promise<string|null>}  the string, or null if cancelled
     */
    function ask(opts) {
        opts = opts || {};
        var d = build();

        var body   = d.querySelector('[data-prompt-body]');
        var input  = d.querySelector('[data-prompt-input]');
        var single = d.querySelector('[data-prompt-single]');
        var many   = d.querySelector('[data-prompt-fields]');

        d.classList.toggle('modal--wide', !!opts.wide);
        d.querySelector('[data-prompt-title]').textContent = opts.title || '';
        body.textContent = opts.body || '';
        body.hidden = !opts.body;
        d.querySelector('[data-prompt-go]').textContent = opts.verb || 'OK';

        var told = !!(opts.fields && opts.fields.length === 0);
        multi = !!(opts.fields && opts.fields.length);
        single.hidden = multi || told;
        many.hidden   = !multi;
        // NOTHING TO CANCEL when there is nothing to enter. Two buttons that do
        // the same thing is a choice the reader has to think about for no
        // reason, so the tell-you-something shape keeps one.
        //
        // SET HERE, not up with the other button text — `var told` is hoisted
        // but its VALUE is not, so assigning from it three lines earlier set
        // `hidden = undefined` and the Cancel button stayed. Measured in the
        // browser, not read off the page.
        d.querySelector('[data-prompt-cancel]').hidden = told;
        // DISABLED, NOT MERELY HIDDEN. A hidden control still takes part in the
        // form — and `required`, autofocus and Enter all still find it. The same
        // rule the entry form's password box follows.
        input.disabled = multi || told;

        // REBUILT PER ASK, not diffed. The dialog is a singleton and the fields
        // are not: an image details form and a link form share nothing, and a
        // stale control left behind from the previous ask is a value the caller
        // never asked for arriving in its result object.
        many.textContent = '';

        if (multi) {
            opts.fields.forEach(function (f, i) {
                var wrap = document.createElement('div');
                wrap.className = 'field';

                var id = 'prompt-f-' + i;
                var label = document.createElement('label');
                label.className = 'label';
                label.setAttribute('for', id);
                label.textContent = f.label || f.name;
                wrap.appendChild(label);

                var el;
                if (f.type === 'select') {
                    el = document.createElement('select');
                    el.className = 'select';
                    (f.options || []).forEach(function (pair) {
                        var o = document.createElement('option');
                        o.value = pair[0];
                        o.textContent = pair[1];
                        el.appendChild(o);
                    });
                } else if (f.type === 'textarea') {
                    el = document.createElement('textarea');
                    el.className = 'textarea';
                    el.rows = f.rows || 6;
                    el.spellcheck = false;
                    if (f.placeholder) { el.placeholder = f.placeholder; }
                } else {
                    el = document.createElement('input');
                    el.className = 'input';
                    el.type = f.type === 'number' ? 'number' : 'text';
                    el.autocomplete = 'off';
                    el.spellcheck = f.type !== 'number';
                    if (f.placeholder) { el.placeholder = f.placeholder; }
                    if (f.min !== undefined) { el.min = f.min; }
                    if (f.max !== undefined) { el.max = f.max; }
                }
                el.id = id;
                el.dataset.promptField = f.name;
                el.value = f.value === undefined || f.value === null ? '' : String(f.value);
                if (f.required) { el.required = true; }
                wrap.appendChild(el);
                if (f.file) { wrap.appendChild(fileRow(f.file, el)); }

                if (f.help) {
                    var help = document.createElement('p');
                    help.className = 'form-help';
                    help.textContent = f.help;
                    wrap.appendChild(help);
                }
                many.appendChild(wrap);
            });
        } else {
            d.querySelector('[data-prompt-label]').textContent = opts.label || '';
            input.value = opts.value || '';
            input.placeholder = opts.placeholder || '';
        }

        return new Promise(function (res) {
            // A SECOND ASK WHILE ONE IS OPEN settles the first as a cancel
            // rather than dropping its Promise on the floor — an unsettled
            // Promise is a caller that never continues.
            if (resolve) { finish(null); }
            resolve = res;
            d.showModal();
            // Select rather than just focus: a prefilled value ("https://") is
            // a starting point to type over, not something to arrow past.
            var first = multi
                ? d.querySelector('[data-prompt-fields] [data-prompt-field]')
                : input;
            if (first) {
                first.focus();
                if (first.select) { first.select(); }
            }
        });
    }

    window.Prompt = { ask: ask };
}());
