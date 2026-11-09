/**
 * THE REFUND FORM'S RUNNING FIGURE.
 *
 * As the quantities and the delivery change, the order screen asks the server
 * what they come to (`GET /admin/orders/{id}/refund/quote`, which is
 * `Refund::workOut()`) and prints it beside the Refund button, and in the
 * amount box as the figure a blank amount records. The arithmetic is not
 * repeated here, so the figure shown cannot differ from the one recorded.
 *
 * Without this the form still works: a blank amount is worked out on submit.
 *
 * ES5, no build step, no dependency.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-refund-form]');
    if (!form || !window.fetch || !window.URLSearchParams) { return; }

    var sum    = form.querySelector('[data-refund-sum]');
    var amount = form.querySelector('[name="refund_amount"]');
    var timer  = null;
    var asked  = 0;

    function counts(el) {
        return el.name === 'refund_shipping' || el.name.indexOf('refund_qty[') === 0;
    }

    function ask() {
        var params = new URLSearchParams();
        var fields = form.querySelectorAll('input[name]');
        for (var i = 0; i < fields.length; i++) {
            if (counts(fields[i])) { params.append(fields[i].name, fields[i].value); }
        }

        // ONLY THE LATEST ANSWER IS PRINTED: a slow reply to an older count
        // must not overwrite the figure for the newer one.
        var mine = ++asked;
        window.fetch(form.getAttribute('data-quote') + '?' + params.toString(), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (r) {
            return r.ok ? r.json() : null;
        }).then(function (data) {
            if (mine !== asked || !data) { return; }
            sum.textContent = data.total > 0 ? data.text : '';
            if (amount) { amount.placeholder = data.total > 0 ? data.edit : ''; }
        }).catch(function () {});
    }

    form.addEventListener('input', function (e) {
        if (!e.target.name || !counts(e.target)) { return; }
        window.clearTimeout(timer);
        timer = window.setTimeout(ask, 150);
    });

    ask();
}());
