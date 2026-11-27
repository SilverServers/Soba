/**
 * edit-bar.js — the bar a signed-in account sees at the top of a public page
 * (`Site\EditBar`): Edit this page, this entry or this list, each a link to
 * its editor. Printed through the page's head only for such an account; its
 * links come with it, as data (`#cms-edit-bar`), so this file names no
 * address of its own. A page with no link to offer gets no bar, and the
 * pencils beside its entries need nothing from here.
 */
(function () {
    'use strict';

    function draw() {
        var data = document.getElementById('cms-edit-bar');
        var bar;
        try { bar = JSON.parse(data ? data.textContent : '{}'); } catch (e) { return; }
        var links = (bar && bar.links) || [];
        if (!links.length || document.querySelector('.cms-edit-bar')) { return; }

        var nav = document.createElement('nav');
        nav.className = 'cms-edit-bar';
        nav.setAttribute('aria-label', 'Edit');
        var list = document.createElement('ul');
        list.className = 'cms-edit-bar__links';
        links.forEach(function (link) {
            var item = document.createElement('li');
            var a = document.createElement('a');
            a.className = 'cms-edit-bar__link';
            a.href = link.url;
            a.textContent = link.label;
            item.appendChild(a);
            list.appendChild(item);
        });
        nav.appendChild(list);

        // FIRST IN THE PAGE, after the way past the header where the page
        // has one, so a keyboard still meets that first.
        var first = document.body.firstElementChild;
        if (first && first.matches('a[href^="#"]')) {
            first.insertAdjacentElement('afterend', nav);
        } else {
            document.body.insertBefore(nav, first);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', draw);
    } else {
        draw();
    }
}());
