/**
 * SUMMARY — the answers read back, before the visitor commits.
 *
 * On a long form this is the difference between submitting and scrolling back
 * up to check. It collects nothing and stores nothing: it is a VIEW of the
 * other fields, rebuilt whenever one of them changes.
 *
 * ── IT READS THE FORM, NOT A SECOND COPY OF THE DEFINITION ─────────────────
 * Every label and value comes out of the DOM that is already on the page. A
 * summary built from a JSON copy of the field list would be a second renderer
 * to keep in step — which is the drift this project has been bitten by more
 * than once — and it would still have to read the DOM for the answers.
 *
 * ── ONLY WHAT HAS BEEN ANSWERED ────────────────────────────────────────────
 * An empty field is left out rather than listed as blank. A recap whose rows
 * are mostly "—" is longer than the form it is summarising and tells the
 * visitor nothing they did not just do.
 */
(function (window, document) {
    'use strict';
    Array.prototype.forEach.call(document.querySelectorAll('[data-summary]'), function (box) {
        var list = box.querySelector('[data-summary-list]');
        var form = box.closest('form');
        if (!list || !form) { return; }

        /** The words shown above a field, or its name as a last resort. */
        function labelFor(field) {
            var el = field.querySelector('.cms-form__label, .cms-form__section-title');
            if (el) { return el.textContent.replace('*', '').trim(); }
            var one = field.querySelector('[name]');
            return one ? (one.getAttribute('name') || '').replace(/\[.*$/, '') : '';
        }

        /** What has been answered in one field, as text. */
        function answerFor(field) {
            var out = [];
            Array.prototype.forEach.call(
                field.querySelectorAll('input, select, textarea'),
                function (c) {
                    if (c.type === 'radio' || c.type === 'checkbox') {
                        if (!c.checked) { return; }
                        // A lone checkbox has no value worth printing — its
                        // label is the answer, and "on" is not English.
                        var lab = c.closest('label');
                        var say = (c.value && c.value !== '1')
                            ? c.value
                            : (lab ? lab.textContent.trim() : 'Yes');
                        out.push(say);
                        return;
                    }
                    if (c.type === 'hidden' || c.type === 'submit') {
                        // Ranking and Tags carry their answers in hidden
                        // inputs — those ARE the answer and belong here.
                        if (c.value && c.getAttribute('name')) { out.push(c.value); }
                        return;
                    }
                    if (c.value && String(c.value).trim() !== '') {
                        out.push(String(c.value).trim());
                    }
                });
            return out.join(', ');
        }

        function paint() {
            list.textContent = '';
            Array.prototype.forEach.call(
                form.querySelectorAll('.cms-form__field'),
                function (field) {
                    if (field.contains(box) || box.contains(field)) { return; }
                    // A field conditional logic has hidden was not asked, so it
                    // has no answer to recap.
                    if (field.hidden || field.style.display === 'none') { return; }

                    var answer = answerFor(field);
                    if (answer === '') { return; }

                    var dt = document.createElement('dt');
                    dt.className = 'cms-form__summary-term';
                    dt.textContent = labelFor(field);

                    var dd = document.createElement('dd');
                    dd.className = 'cms-form__summary-value';
                    dd.textContent = answer;

                    list.appendChild(dt);
                    list.appendChild(dd);
                });
        }

        // `input` for typing, `change` for the controls that do not fire it.
        form.addEventListener('input', paint);
        form.addEventListener('change', paint);
        paint();
    });
})(window, document);
