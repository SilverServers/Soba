/**
 * A STATIC COPY'S SEARCH (`Public\SearchController`, `Export\StaticCopy`).
 *
 * The copy's search page is the site's with nothing searched yet; this does
 * the search, in the visitor's browser, against the copy's index
 * (`search/index.js`): every page and entry the site's search can find, each
 * with its result drawn as the site draws it. The rules are the site's: the
 * words anywhere in a title or in the words a visitor reads (the index holds
 * those, without the markup), with no regard to capitals or accents, spaces
 * typed twice as one, title matches first and then the newest, the first 50.
 *
 * THE INDEX IS A SCRIPT, loaded only once something is searched for: a page
 * opened from a folder, with no web server, may load a script beside it and
 * may not fetch a file.
 *
 * Linked only in a copy, on its search page.
 */
(function () {
    'use strict';

    var box = document.querySelector('[data-search-index]');
    if (!box) { return; }

    var q = (new URLSearchParams(window.location.search).get('q') || '').trim().slice(0, 100);
    var input = document.querySelector('.search-form input[name="q"]');
    if (input) { input.value = q; }
    if (q === '') { return; }

    var limit = parseInt(box.getAttribute('data-search-limit') || '50', 10);
    document.title = document.title.replace(/^Search\b/, 'Search: ' + q);

    function plain(s) {
        return String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    function say(tag, cls, words) {
        var el = document.createElement(tag);
        el.className = cls;
        el.textContent = words;
        return el;
    }

    function show(index) {
        var want = plain(q.replace(/\s+/g, ' '));
        var found = [];
        (index.items || []).forEach(function (item) {
            var inTitle = plain(item.t).indexOf(want) !== -1;
            if (inTitle || plain(item.x).indexOf(want) !== -1) {
                found.push({ inTitle: inTitle, item: item });
            }
        });
        found.sort(function (a, b) {
            if (a.inTitle !== b.inTitle) { return a.inTitle ? -1 : 1; }
            return a.item.d < b.item.d ? 1 : (a.item.d > b.item.d ? -1 : 0);
        });
        var capped = found.length >= limit;
        found = found.slice(0, limit);

        box.textContent = '';
        if (found.length === 0) {
            box.appendChild(say('p', 'post-list-empty', 'Nothing on this site matches “' + q + '”.'));
            return;
        }
        box.appendChild(say('p', 'search-summary', (capped ? 'The first ' : '') + found.length
            + (found.length === 1 ? ' result' : ' results') + ' for “' + q + '”'));
        var list = document.createElement('ul');
        list.className = 'post-list';
        // Each result as the site drew it, when the copy was made.
        list.innerHTML = found.map(function (f) { return f.item.h; }).join('\n');
        box.appendChild(list);
    }

    var script = document.createElement('script');
    script.src = box.getAttribute('data-search-index');
    script.onload = function () { show(window.cmsSearchIndex || { items: [] }); };
    document.head.appendChild(script);
}());
