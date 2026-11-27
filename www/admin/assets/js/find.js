/**
 * FIND ANYTHING BY ITS NAME — the top bar's box (`Ui\Find`).
 *
 * As one types, what the account may open whose name holds the words: the
 * admin's screens as its column names them, entries, pages and terms, each
 * with its kind, best first, in the bar's own dropdown. Arrow keys move
 * through them, Enter opens the one chosen or the first, Escape closes the
 * list. Enter before the list has come sends the box to /admin/find, which
 * opens the first itself, as it does without a script.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-find]');
    if (!form || !window.CMS) { return; }
    var input = form.querySelector('[data-find-input]');
    var menu  = form.querySelector('[data-find-menu]');
    var MIN   = 2;
    var timer = null;
    var asked = 0;
    var shown = { q: null, results: [] };
    var active = -1;

    function close() {
        menu.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    }

    function mark(i) {
        var options = menu.querySelectorAll('[role="option"]');
        active = i;
        for (var k = 0; k < options.length; k++) {
            options[k].classList.toggle('is-active', k === i);
            options[k].setAttribute('aria-selected', k === i ? 'true' : 'false');
        }
        if (i >= 0 && options[i]) {
            input.setAttribute('aria-activedescendant', options[i].id);
            options[i].scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

    function draw(q, results) {
        shown = { q: q, results: results };
        menu.textContent = '';
        if (!results.length) {
            var none = document.createElement('p');
            none.className = 'nav-top__find-none';
            none.textContent = 'Nothing by that name';
            menu.appendChild(none);
        }
        results.forEach(function (r, i) {
            var a = document.createElement('a');
            a.className = 'nav-top__link';
            a.href = r.href;
            a.id = 'find-option-' + i;
            a.setAttribute('role', 'option');
            a.setAttribute('aria-selected', 'false');
            var name = document.createElement('span');
            name.className = 'nav-top__find-name';
            name.textContent = r.name;
            var kind = document.createElement('span');
            kind.className = 'nav-top__find-kind';
            kind.textContent = r.kind;
            a.appendChild(name);
            a.appendChild(kind);
            menu.appendChild(a);
        });
        menu.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        mark(-1);
    }

    function search() {
        var q = input.value.trim();
        if (q.length < MIN) { shown = { q: null, results: [] }; close(); return; }
        var mine = ++asked;
        CMS.api('/api/v1/find', { query: { q: q } }).then(function (data) {
            if (mine !== asked) { return; }
            draw(q, (data && data.results) || []);
        }).catch(function () {
            if (mine === asked) { close(); }
        });
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(search, 150);
    });

    input.addEventListener('keydown', function (ev) {
        var count = shown.results.length;
        if (ev.key === 'ArrowDown' && !menu.hidden && count) {
            ev.preventDefault();
            mark((active + 1) % count);
        } else if (ev.key === 'ArrowUp' && !menu.hidden && count) {
            ev.preventDefault();
            mark(active <= 0 ? count - 1 : active - 1);
        } else if (ev.key === 'Escape') {
            close();
        } else if (ev.key === 'Enter') {
            // WHAT IS SHOWN IS FOR WHAT IS TYPED: the one chosen, else the first.
            // Otherwise the form goes, and /admin/find opens the first itself.
            if (shown.q === input.value.trim() && count) {
                ev.preventDefault();
                window.location.href = shown.results[active >= 0 ? active : 0].href;
            }
        }
    });

    document.addEventListener('click', function (ev) {
        if (!form.contains(ev.target)) { close(); }
    });
    input.addEventListener('focus', function () {
        if (shown.q !== null && shown.q === input.value.trim()) {
            menu.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }
    });
})();
