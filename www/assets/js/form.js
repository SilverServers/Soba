/**
 * A FORM OF THE CMS'S, SENT BY SCRIPT (`Helpers\Shortcode`).
 *
 * From this site's file, with nothing inline, so a page whose
 * Content-Security-Policy allows only this site's scripts still sends its
 * forms this way. Each form is bound once, however many forms, and copies
 * of this script, a page carries.
 *
 * IT ASKS FOR JSON: the controller answers a browser posting the form itself
 * with a page or a redirect, and a fetch that did not say so would get that
 * page and report "Something went wrong" for a message that was sent. A real
 * submission's answer can carry its conversion, which `measure.js` sends.
 */
(function () {
    'use strict';

    function bind(form) {
        if (form.hasAttribute('data-cms-bound')) { return; }
        form.setAttribute('data-cms-bound', '');

        // ITS CONDITIONAL FIELDS, when logic.js is on the page: it is linked,
        // before this script, only for a form that has one, and it leaves a
        // form with none alone.
        if (window.Logic) { window.Logic.bind(form); }

        var wrap = form.closest('.cms-form');
        var msg = wrap ? wrap.querySelector('.cms-form__alert') : null;
        var label = form.getAttribute('data-submit-label') || 'Submit';

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = form.querySelector('.cms-form__submit');
            if (btn) { btn.disabled = true; btn.textContent = 'Sending...'; }
            var failed = function (words) {
                if (msg) {
                    msg.removeAttribute('hidden');
                    msg.className = 'cms-form__alert cms-form__alert--error';
                    msg.textContent = words;
                }
                if (btn) { btn.disabled = false; btn.textContent = label; }
            };
            fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.success) {
                        failed(d.message || 'Something went wrong.');
                        return;
                    }
                    if (msg) {
                        msg.removeAttribute('hidden');
                        msg.className = 'cms-form__alert cms-form__alert--success';
                        msg.textContent = d.message;
                    }
                    form.reset();
                    form.style.display = 'none';
                    if (d.conversion && window.cmsMeasure) { window.cmsMeasure.convert(d.conversion); }
                })
                .catch(function () { failed('Something went wrong. Please try again.'); });
        });
    }

    function bindAll() {
        Array.prototype.forEach.call(document.querySelectorAll('form[data-cms-form]'), bind);
    }

    bindAll();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindAll);
    }
}());
