/**
 * PAGE BREAKS — one long form becomes a few short ones.
 *
 * A twenty-field form asked all at once is a wall; the same form in three
 * steps is three questions. `Shortcode::renderForm()` emits a flat list with
 * `<hr data-pagebreak>` markers in it, and this groups what lies between them.
 *
 * ── THE MARKUP IS FLAT ON PURPOSE ──────────────────────────────────────────
 * Steps are built here, in the browser, rather than server-side — because with
 * no JavaScript a server-rendered step one would hide steps two and three
 * behind a Next button that does not exist. That is a form nobody can
 * complete. Flat markup submits everything at once and is merely long; this
 * improves it into steps when it can.
 *
 * ── VALIDATION BEFORE ADVANCING, OR THE ERRORS ARRIVE ON THE WRONG PAGE ────
 * The server validates everything at submit, and its complaint would name a
 * required field three steps back that the visitor cannot see. So Next checks
 * the CURRENT step's own controls with the browser's own validity API — no
 * second copy of the rules, just `reportValidity()` on the fields in view.
 */
(function (window, document) {
    'use strict';
    Array.prototype.forEach.call(document.querySelectorAll('.cms-form'), function (wrap) {
        var form  = wrap.querySelector('[data-cms-form]');
        var list  = wrap.querySelector('.cms-form__fields');
        if (!form || !list || !list.querySelector('[data-pagebreak]')) { return; }

        // ── GROUP THE FLAT LIST INTO STEPS ──────────────────────────────
        var steps = [];
        var step  = document.createElement('div');
        step.className = 'cms-form__step';
        Array.prototype.forEach.call(Array.prototype.slice.call(list.children), function (node) {
            if (node.hasAttribute && node.hasAttribute('data-pagebreak')) {
                steps.push(step);
                step = document.createElement('div');
                step.className = 'cms-form__step';
                node.remove();
                return;
            }
            step.appendChild(node);
        });
        steps.push(step);
        steps.forEach(function (s) { list.appendChild(s); });
        if (steps.length < 2) { return; }

        var at = 0;

        // ── THE CONTROLS ────────────────────────────────────────────────
        var nav  = document.createElement('div');
        nav.className = 'cms-form__pages';

        var back = document.createElement('button');
        back.type = 'button';
        back.className = 'cms-form__page-back';
        back.textContent = 'Back';

        var next = document.createElement('button');
        next.type = 'button';
        next.className = 'cms-form__page-next';
        next.textContent = 'Next';

        // WHERE YOU ARE, IN WORDS. A bar with no numbers is decoration: the
        // question a step indicator answers is "how much is left".
        var where = document.createElement('span');
        where.className = 'cms-form__page-count';

        nav.appendChild(back);
        nav.appendChild(where);
        nav.appendChild(next);
        list.parentNode.insertBefore(nav, list.nextSibling);

        var actions = wrap.querySelector('.cms-form__actions');

        function show() {
            steps.forEach(function (s, i) { s.hidden = i !== at; });
            back.hidden = at === 0;
            next.hidden = at === steps.length - 1;
            // SUBMIT ONLY ON THE LAST STEP. Left visible throughout, it is a
            // button that sends a half-answered form — and the visitor has no
            // way to know that Next was the one they wanted.
            if (actions) { actions.hidden = at !== steps.length - 1; }
            where.textContent = 'Step ' + (at + 1) + ' of ' + steps.length;
        }

        next.addEventListener('click', function () {
            // THE BROWSER'S OWN VALIDITY, on this step only. A second copy of
            // the rules here would be a third place they can disagree — the
            // server decides, `logic.js` reveals, and this only asks whether
            // what is on screen is fillable.
            var bad = null;
            Array.prototype.forEach.call(
                steps[at].querySelectorAll('input, select, textarea'),
                function (c) {
                    if (!bad && c.willValidate && !c.checkValidity()) { bad = c; }
                });
            if (bad) { bad.reportValidity(); return; }

            at = Math.min(at + 1, steps.length - 1);
            show();
            // BACK TO THE TOP OF THE FORM, not wherever the last step left the
            // page. Advancing to a step whose first question is above the fold
            // reads as nothing having happened.
            wrap.scrollIntoView({ block: 'start' });
        });

        back.addEventListener('click', function () {
            at = Math.max(at - 1, 0);
            show();
            wrap.scrollIntoView({ block: 'start' });
        });

        show();
    });
})(window, document);
