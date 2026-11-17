/**
 * TOOLS → STATIC COPY, WHILE A COPY IS MADE (`StaticCopyController`).
 *
 * The copy is a job on the server that goes on only when asked: this asks it
 * to take one more step, a few seconds' work, as soon as the last one answers,
 * and keeps the bar and the figures current. When it is done the page is
 * loaded again, which shows the copy and its download. A step that fails
 * says why, in the screen's alert, and stops asking.
 */
(function () {
    'use strict';

    var box = document.querySelector('[data-static-copy-step]');
    if (!box || !window.CMS || !CMS.api) { return; }

    var url  = box.getAttribute('data-static-copy-step');
    var bar  = box.querySelector('[data-static-copy-bar]');
    var fail = box.querySelector('[data-static-copy-error]');

    function show(name, n) {
        var el = box.querySelector('[data-static-copy="' + name + '"]');
        if (el) { el.textContent = String(n); }
    }

    function step() {
        CMS.api(url, { method: 'POST' })
            .then(function (d) {
                show('pages', d.pages);
                show('files', d.files);
                show('queued', d.queued);
                var done = d.pages + d.files;
                if (bar) { bar.style.width = Math.round(100 * done / Math.max(1, done + d.queued)) + '%'; }
                if (d.done) {
                    window.location.reload();
                    return;
                }
                step();
            })
            .catch(function (e) {
                if (fail) {
                    fail.querySelector('[data-static-copy-error-text]').textContent = e.message;
                    fail.removeAttribute('hidden');
                }
            });
    }

    step();
}());
