/**
 * TAGS — several short answers in one field, typed rather than picked.
 *
 * ── WHAT POSTS IS THE HIDDEN INPUTS, NEVER THE BOX ─────────────────────────
 * The visible box has NO `name`. It is where you type; it is not an answer.
 * Give it one and whatever is half-typed when the visitor hits Submit arrives
 * as a tag nobody finished — the commonest bug in every chip control ever
 * written. Committing on submit is handled below instead, deliberately and
 * visibly.
 *
 * Each committed tag gets its own `field[0]`, `field[1]`, … hidden input, which
 * is the same array shape checkboxes and Ranking post, so `readValue`'s
 * `isMultiple` branch reads it and nothing new was needed to store it.
 *
 * ── ENTER, COMMA, OR LEAVING THE BOX ───────────────────────────────────────
 * All three commit. Comma because people paste lists; blur because a visitor
 * who types a tag and reaches for Submit means that tag, and losing it would
 * be the control quietly discarding an answer they can see on screen.
 */
(function (window, document) {
    'use strict';
    Array.prototype.forEach.call(document.querySelectorAll('[data-tags]'), function (box) {
        var list  = box.querySelector('[data-tag-list]');
        var entry = box.querySelector('[data-tag-entry]');
        var name  = box.getAttribute('data-tags-name') || '';
        if (!list || !entry || !name) { return; }
        var form  = box.closest('form');

        function reindex() {
            Array.prototype.forEach.call(list.querySelectorAll('input[type="hidden"]'),
                function (input, i) { input.name = name + '[' + i + ']'; });
        }

        function add(text) {
            var value = String(text).trim();
            if (value === '') { return; }

            // NO DUPLICATES. Two identical tags is one answer typed twice, and
            // it reads back as a mistake rather than as data.
            var already = Array.prototype.some.call(
                list.querySelectorAll('input[type="hidden"]'),
                function (i) { return i.value === value; });
            if (already) { return; }

            var li = document.createElement('li');
            li.className = 'cms-form__tag';

            var text_ = document.createElement('span');
            text_.className = 'cms-form__tag-text';
            text_.textContent = value;

            // A REAL BUTTON. It is a control, it takes focus, and it says what
            // it removes — "×" alone is a glyph with no accessible name.
            var kill = document.createElement('button');
            kill.type = 'button';
            kill.className = 'cms-form__tag-kill';
            kill.setAttribute('aria-label', 'Remove ' + value);
            kill.textContent = '×';
            kill.addEventListener('click', function () {
                li.remove();
                reindex();
                entry.focus();
            });

            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.value = value;

            li.appendChild(text_);
            li.appendChild(kill);
            li.appendChild(hidden);
            list.appendChild(li);
            reindex();
        }

        entry.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ',') {
                // ENTER MUST NOT SUBMIT THE FORM. On a one-field form the
                // browser submits on Enter, so a visitor adding their first tag
                // would send the form instead.
                e.preventDefault();
                add(entry.value);
                entry.value = '';
                return;
            }
            // Backspace on an empty box removes the last tag — the behaviour
            // every chip control has, and the only way to undo without aiming.
            if (e.key === 'Backspace' && entry.value === '') {
                var last = list.querySelector('.cms-form__tag:last-child');
                if (last) { last.remove(); reindex(); }
            }
        });

        entry.addEventListener('blur', function () {
            add(entry.value);
            entry.value = '';
        });

        // AND ON SUBMIT, because blur does not always fire first — a click
        // straight onto Submit from inside the box is the ordinary case.
        if (form) {
            form.addEventListener('submit', function () {
                add(entry.value);
                entry.value = '';
            });
        }

        // The <noscript> hidden input exists so a scriptless visitor still
        // posts something; with the script running it would post an empty tag.
        var ns = box.querySelector('noscript');
        if (ns) { ns.remove(); }
    });
})(window, document);
