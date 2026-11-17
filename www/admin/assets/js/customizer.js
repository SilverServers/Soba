/**
 * APPEARANCE → CUSTOMIZER, AS EACH CHOICE IS MADE (`customizer.php`).
 *
 * The frame beside the controls holds the site's own home page. Each choice
 * is written onto that page as the site will write it once saved: the
 * palette, corners and fonts as attributes on its `<html>` (`Site\Look`),
 * the palette's family sheet linked when it is not there yet, and the logo
 * and the name beside it in the header's link home (`[data-look-brand]`).
 * Nothing is saved until Save.
 */
(function () {
    'use strict';

    var form  = document.querySelector('[data-customizer]');
    var frame = document.querySelector('[data-customizer-preview]');
    if (!form || !frame) { return; }

    function page() {
        try { return frame.contentDocument; } catch (e) { return null; }
    }

    function value(name) {
        var el = form.querySelector('[name="' + name + '"]');
        return el ? el.value : '';
    }

    // THE PALETTE'S FAMILY SHEET, linked once into the page in the frame.
    function sheet(doc, family) {
        if (doc.querySelector('link[data-look-family="' + family + '"]')) { return; }
        var href = frame.getAttribute('data-sheet-' + family);
        if (!href) { return; }
        var link = doc.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.setAttribute('data-look-family', family);
        doc.head.appendChild(link);
    }

    // THE LOGO: the picker's picture, or none.
    function brand(doc) {
        var link = doc.querySelector('[data-look-brand]');
        if (!link) { return; }
        var picker = form.querySelector('[data-look-logo] [data-media-picker]');
        var id     = picker ? picker.querySelector('[data-media-value]').value : '0';
        var pic    = picker ? picker.querySelector('[data-media-preview] img') : null;
        var src    = id !== '0' && pic ? pic.getAttribute('src') : '';
        var withName = form.querySelector('[name="logo_name"]').checked;
        var img  = link.querySelector('.site-header__logo');
        var text = link.querySelector('.site-header__text');
        if (src) {
            if (!img) {
                img = doc.createElement('img');
                img.className = 'site-header__logo';
                link.insertBefore(img, link.firstChild);
            }
            img.src = src;
            img.alt = withName ? '' : (text ? text.textContent : '');
            link.classList.add('site-header__name--logo');
            if (text) { text.hidden = !withName; }
        } else {
            if (img) { img.remove(); }
            link.classList.remove('site-header__name--logo');
            if (text) { text.hidden = false; }
        }
    }

    function apply() {
        var doc = page();
        if (!doc || !doc.documentElement || !doc.head) { return; }
        var palette = form.querySelector('[name="palette"]');
        var chosen  = palette.options[palette.selectedIndex];
        sheet(doc, chosen ? chosen.getAttribute('data-family') : 'signature');
        var root = doc.documentElement;
        root.setAttribute('data-theme', palette.value);
        root.setAttribute('data-radius', value('radius'));
        root.setAttribute('data-font-body', value('font_body'));
        root.setAttribute('data-font-head', value('font_head'));
        brand(doc);
    }

    form.addEventListener('change', apply);
    frame.addEventListener('load', apply);
    // A picture chosen in the library is written into the picker by its own
    // script, which says nothing: its thumbnail changing is the news.
    if (window.MutationObserver) {
        form.querySelectorAll('[data-media-preview]').forEach(function (el) {
            new MutationObserver(apply).observe(el, { childList: true, subtree: true });
        });
    }
}());
