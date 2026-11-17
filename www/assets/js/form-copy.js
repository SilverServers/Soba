/**
 * A FORM IN A STATIC COPY OF THE SITE (`Export\Forms`).
 *
 * The copy's pages were drawn when the copy was made, and so was the token
 * each form carries: hours old by the first visit, and the same for every
 * visitor. This asks the copy's form handler — the form's own action, by GET —
 * for a fresh token as the page loads, and for a fresh question when the form
 * asks the built-in one, so the handler's time-trap and its rule against a
 * page sent twice work as they do on the site. A page left open past an hour
 * asks again when it is looked at, and a question answered wrongly (an
 * answered question is spent) is replaced by a new one.
 *
 * Linked only in a copy, beside form.js, which sends the form.
 */
(function () {
    'use strict';

    var STALE = 60 * 60 * 1000;

    // OPENED FROM A FOLDER, with no web server, nothing can send a form: each
    // gives its place to the words given for it, drawn beside it and hidden
    // (`Export\Forms::offline()`), or to nothing when none were given.
    if (window.location.protocol === 'file:') {
        forms().forEach(function (form) {
            var id = (form.getAttribute('action').match(/form=(\d+)$/) || [])[1];
            var words = id ? document.querySelector('.cms-form__offline[data-form-id="' + id + '"]') : null;
            (form.closest('.cms-form') || form).style.display = 'none';
            if (words) { words.hidden = false; }
        });
        return;
    }

    function ask(form, tokenToo) {
        return fetch(form.getAttribute('action'), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || !d.token) { return; }
                if (tokenToo) {
                    var t = form.querySelector('input[name="t"]');
                    if (t) { t.value = d.token; }
                    form.setAttribute('data-copy-asked', String(Date.now()));
                }
                if (d.captcha) {
                    var q = form.querySelector('.cms-form__captcha-q');
                    var n = form.querySelector('input[name="_captcha_nonce"]');
                    var a = form.querySelector('input[name="_captcha"]');
                    if (q) { q.textContent = d.captcha.question; }
                    if (n) { n.value = d.captcha.nonce; }
                    if (a) { a.value = ''; }
                }
            })
            .catch(function () {});
    }

    function bind(form) {
        if (form.hasAttribute('data-copy-bound')) { return; }
        form.setAttribute('data-copy-bound', '');
        ask(form, true);

        // A WRONG ANSWER SPENT THE QUESTION: form.js shows the error, and a
        // new question goes in. Not a new token — sent again within seconds,
        // a new one would be "too fast".
        var wrap = form.closest('.cms-form');
        var alert = wrap ? wrap.querySelector('.cms-form__alert') : null;
        if (alert && form.querySelector('input[name="_captcha_nonce"]') && window.MutationObserver) {
            new MutationObserver(function () {
                if (alert.classList.contains('cms-form__alert--error')) { ask(form, false); }
            }).observe(alert, { attributes: true, attributeFilter: ['class'], childList: true });
        }
    }

    // EVERY FORM THAT POSTS TO THE COPY'S HANDLER, the CMS's own and any a
    // theme draws itself.
    function forms() {
        // `forms/send.php…` with no slash before it on a copy's front page
        // whose links are relative.
        return Array.prototype.filter.call(document.querySelectorAll('form[action]'), function (form) {
            return /(^|\/)forms\/send\.php\?form=\d+$/.test(form.getAttribute('action') || '');
        });
    }

    function bindAll() { forms().forEach(bind); }

    function refreshStale() {
        forms().forEach(function (form) {
            var at = parseInt(form.getAttribute('data-copy-asked') || '0', 10);
            if (Date.now() - at > STALE) { ask(form, true); }
        });
    }

    bindAll();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindAll);
    }
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') { refreshStale(); }
    });
    // A page brought back by the browser's Back button is the page as it was.
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) { forms().forEach(function (form) { ask(form, true); }); }
    });
}());
