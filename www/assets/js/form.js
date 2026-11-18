/**
 * A FORM OF THE CMS'S, CHECKED AND SENT BY SCRIPT (`Helpers\Shortcode`).
 *
 * From this site's file, with nothing inline, so a page whose
 * Content-Security-Policy allows only this site's scripts still sends its
 * forms this way; and it shows and hides with the `hidden` attribute and
 * classes, never a style. Each form is bound once, however many forms, and
 * copies of this script, a page carries.
 *
 * IT ASKS FOR JSON: the controller answers a browser posting the form itself
 * with a page or a redirect, and a fetch that did not say so would get that
 * page and report a failure for a message that was sent. A real submission's
 * answer can carry its conversion, which `measure.js` sends.
 *
 * A FORM WITH AN AFTER-SENDING PAGE answers with it (`redirect`, a path on
 * this site), and the browser goes there instead of showing the thank-you
 * here, its button still disabled; the conversion, if any, waits for that
 * page in a cookie.
 *
 * A FORM THAT CHECKS ITS OWN ANSWERS (`novalidate`, its "Check each answer on
 * the page" setting) checks each field that says how (`data-field-error`) as
 * it is left and as the form is sent: left blank, an address that is not one,
 * text over its length. The words are the field's own, drawn into its message
 * element; an answer wrong is marked `is-invalid` and `aria-invalid`, its
 * message shown under it, and on sending the first one wrong takes the focus
 * and nothing goes.
 *
 * A REFUSAL NAMES ITS FIELDS (`errors`, name => message): each message goes
 * under its field and the form's status region says to look at them.
 *
 * A PAGE LEFT OPEN STILL SENDS. The form's token is renewed every half hour
 * (`data-token-url`), and an answer saying the form expired gets a new one
 * and sends once more, so nothing typed is lost to an open tab.
 */
(function () {
    'use strict';

    var RENEW_MS = 30 * 60 * 1000;
    // What an address looks like, as the server's own check takes it near
    // enough: something, an @, a name, a dot, a name, and no spaces.
    var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    function bind(form) {
        if (form.hasAttribute('data-cms-bound')) { return; }
        form.setAttribute('data-cms-bound', '');

        // ITS CONDITIONAL FIELDS, when logic.js is on the page: it is linked,
        // before this script, only for a form that has one, and it leaves a
        // form with none alone.
        if (window.Logic) { window.Logic.bind(form); }

        var wrap     = form.closest('.cms-form');
        var status   = wrap ? wrap.querySelector('[data-form-status]') : null;
        var label    = form.getAttribute('data-submit-label') || 'Submit';
        var sending  = form.getAttribute('data-sending-label') || 'Sending…';
        var fix      = form.getAttribute('data-msg-fix') || 'Please check the highlighted fields and try again.';
        var offline  = form.getAttribute('data-msg-offline') || 'We couldn’t send your message just now. Please try again.';
        var tokenUrl = form.getAttribute('data-token-url');
        var own      = form.noValidate;

        function button() { return form.querySelector('button[type="submit"]'); }

        function busy(on) {
            var btn = button();
            if (!btn) { return; }
            btn.disabled = on;
            btn.textContent = on ? sending : label;
        }

        // THE STATUS REGION: one message for the whole form, or none.
        function say(kind, words) {
            if (!status) { return; }
            ['error', 'success'].forEach(function (k) {
                status.classList.remove('cms-form__alert--' + k);
            });
            if (!words) {
                status.hidden = true;
                status.textContent = '';
                return;
            }
            status.classList.add('cms-form__alert--' + kind);
            status.hidden = false;
            status.textContent = words;
        }

        function controlOf(field) {
            return field.querySelector('input:not([type="hidden"]), select, textarea');
        }

        // What is wrong with one field's answer, in its own words, or ''.
        function problem(field) {
            var note = field.querySelector('[data-field-error]');
            var ctrl = controlOf(field);
            if (!note || !ctrl || ctrl.disabled || field.hidden) { return ''; }
            var value = String(ctrl.value || '');
            if (value.trim() === '') { return note.getAttribute('data-required') || ''; }
            var kind = note.getAttribute('data-kind') || '';
            if (kind === 'email' ? !EMAIL.test(value.trim())
                    : (kind !== '' && ctrl.validity && (ctrl.validity.typeMismatch || ctrl.validity.badInput))) {
                return note.getAttribute('data-format') || '';
            }
            var most = parseInt(note.getAttribute('data-max') || '0', 10);
            if (most > 0 && Array.from(value).length > most) { return note.getAttribute('data-long') || ''; }
            return '';
        }

        // Shown under its field, or taken away.
        function mark(field, words) {
            var note = field.querySelector('[data-field-error]');
            var ctrl = controlOf(field);
            if (ctrl) {
                ctrl.classList.toggle('is-invalid', !!words);
                if (words) { ctrl.setAttribute('aria-invalid', 'true'); } else { ctrl.removeAttribute('aria-invalid'); }
            }
            if (!note) { return; }
            if (words) { note.textContent = words; }
            note.hidden = !words;
        }

        function fields() {
            return Array.prototype.slice.call(form.querySelectorAll('[data-field]'));
        }

        function focusOn(field) {
            var ctrl = controlOf(field);
            if (ctrl) { ctrl.focus(); }
        }

        if (own) {
            fields().forEach(function (field) {
                if (!field.querySelector('[data-field-error]')) { return; }
                var ctrl = controlOf(field);
                if (!ctrl) { return; }
                ctrl.addEventListener('blur', function () { mark(field, problem(field)); });
                // Put right, the message goes as it is typed; it is not
                // shown for the first time until the field is left.
                ctrl.addEventListener('input', function () {
                    if (ctrl.getAttribute('aria-invalid') === 'true') { mark(field, problem(field)); }
                });
            });
        }

        // A NEW TOKEN for a page left open: the server takes the old one and
        // gives one that is good for as long again (`FormController::handleToken()`).
        function renew() {
            var t = form.querySelector('input[name="t"]');
            if (!tokenUrl || !t) { return Promise.resolve(false); }
            var body = new FormData();
            body.append('t', t.value);
            return fetch(tokenUrl, { method: 'POST', body: body, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (d && typeof d.token === 'string' && d.token !== '') { t.value = d.token; return true; }
                    return false;
                })
                .catch(function () { return false; });
        }
        if (tokenUrl) { window.setInterval(renew, RENEW_MS); }

        // BACK FROM THE AFTER-SENDING PAGE, a page the browser kept as it was
        // left has its button disabled: it can send again.
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) { busy(false); }
        });

        function send(again) {
            busy(true);
            fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    d = d || {};
                    if (d.success) {
                        // Only a path on this site: one slash, not two, and no backslash.
                        if (typeof d.redirect === 'string' && /^\/(?![\/\\])/.test(d.redirect)) {
                            window.location.assign(d.redirect);
                            return;
                        }
                        say('success', d.message || '');
                        form.reset();
                        form.hidden = true;
                        if (d.conversion && window.cmsMeasure) { window.cmsMeasure.convert(d.conversion); }
                        return;
                    }
                    if (d.code === 'expired' && !again && tokenUrl) {
                        renew().then(function (ok) {
                            if (ok) { send(true); } else { say('error', d.message || offline); busy(false); }
                        });
                        return;
                    }
                    var named = d.errors && typeof d.errors === 'object' ? d.errors : null;
                    var first = null;
                    if (named) {
                        fields().forEach(function (field) {
                            var words = named[field.getAttribute('data-field')];
                            mark(field, typeof words === 'string' ? words : '');
                            if (typeof words === 'string' && !first) { first = field; }
                        });
                    }
                    say('error', first ? fix : (d.message || offline));
                    busy(false);
                    if (first) { focusOn(first); }
                })
                .catch(function () {
                    // NOT SENT, AND NOT REFUSED: the network, or an answer
                    // that was not this form's. What was typed is still here.
                    say('error', offline);
                    busy(false);
                });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (own) {
                var first = null;
                fields().forEach(function (field) {
                    if (!field.querySelector('[data-field-error]')) { return; }
                    var words = problem(field);
                    mark(field, words);
                    if (words && !first) { first = field; }
                });
                if (first) {
                    focusOn(first);
                    return;
                }
            }
            say('', '');
            send(false);
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
