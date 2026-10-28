/**
 * OPEN ON CLICK — the one menu behaviour CSS cannot express.
 *
 * Migration 050's per-location bag offers "sub-menus open on hovering" or "on
 * clicking the item". Hover is `:hover`, and keyboard access is `:focus-within`,
 * both of which the stylesheet already handles. A CLICK that opens a panel and
 * leaves it open is the one answer that needs a script.
 *
 * ── AND WITHOUT IT, THE LINK SIMPLY WORKS ──────────────────────────────────
 * That is the whole design. With scripting off, a parent item is an ordinary
 * link to an ordinary page and `:focus-within` still opens its panel on tab.
 * Nothing is unreachable and nothing is broken — the owner's chosen gesture is
 * absent, which is the correct failure for a presentational preference.
 *
 * Authored here, fetched from nobody.
 */
(function () {
    'use strict';

    // ── CLOSE AFTER CLICK — every menu, whatever opens its panels ─────────
    // An item marked "close the menu when it is clicked" is almost always a link
    // to a place on this same page (#about). Following it scrolls the page and
    // leaves a hover- or focus-opened panel hanging over the section it just
    // scrolled to. So: shut everything, give focus back to the page, close the
    // phone panel, and suppress hover-opening until the pointer leaves the menu
    // — otherwise the panel reopens the instant the page stops moving under a
    // pointer that has not moved.
    Array.prototype.forEach.call(document.querySelectorAll('nav.menu'), function (nav) {
        nav.addEventListener('click', function (e) {
            var item = e.target.closest('.menu__item--close-on-click');
            if (!item || !e.target.closest('.menu__link')) { return; }

            closeAll(nav, null);
            var sw = nav.querySelector('.menu__switch');
            if (sw) { sw.checked = false; }
            if (document.activeElement && nav.contains(document.activeElement)) {
                document.activeElement.blur();
            }
            nav.classList.add('is-suppressed');
        });
        nav.addEventListener('mouseleave', function () {
            nav.classList.remove('is-suppressed');
        });
    });

    // ── SUB-MENUS A CLICK OPENS ──────────────────────────────────────────
    // Three places a click, not a hover, opens a sub-menu:
    //   click     — the location's "Sub-menus open on: clicking the item";
    //   accordion — an accordion menu, whose sub-menus open beneath the item;
    //   phone     — the phone's panel, whose sub-menus fold (Advanced: "On a
    //               phone, sub-menus start" / "On a phone, opening a sub-menu").
    // One engine, because the gesture is the same in all three: the first click
    // on a closed item opens it and never navigates; a click on an open one
    // follows the link or closes it, as the owner set the second click.
    //
    // `menu--js` says the script is running. The stylesheet folds the phone's
    // sub-menus only under it — WITH NO SCRIPT they stay open and every link
    // works, which is the safe half.
    var PHONE = window.matchMedia('(max-width: 720px)');

    function modeOf(nav) {
        if (PHONE.matches) { return 'phone'; }
        if (nav.classList.contains('menu--accordion')) { return 'accordion'; }
        if (nav.classList.contains('menu--open-click')) { return 'click'; }
        return 'hover';
    }

    /** Where the sub-menus START in a mode: the phone's and the accordion's settings. */
    function startState(nav, mode) {
        if (mode === 'phone') { return nav.getAttribute('data-mobile-state') || 'expand_all'; }
        if (mode === 'accordion') {
            return { open_all: 'expand_all', collapse_parents: 'collapse_all' }[nav.getAttribute('data-accordion')]
                || 'expand_active';
        }
        return 'collapse_all';
    }

    Array.prototype.forEach.call(document.querySelectorAll('nav.menu[data-mobile-state]'), function (nav) {
        // WHAT A SECOND CLICK DOES, as the owner set it: follow the link, or
        // close the sub-menu again. `go` is the default because a parent item is
        // usually a real page and swallowing the second click on it is how a
        // section becomes unreachable by pointer.
        var second = nav.getAttribute('data-second-click') === 'close' ? 'close' : 'go';
        var mode   = null;

        // THE STARTING STATE FOR THE MODE THE MENU IS IN — again whenever the
        // window crosses the phone's width, or a phone's all-open panel would
        // arrive on a desktop as a bar with every dropdown hanging open.
        function settle() {
            var now = modeOf(nav);
            if (now === mode) { return; }
            mode = now;
            var state = startState(nav, mode);
            Array.prototype.forEach.call(nav.querySelectorAll('li.menu__item--parent'), function (li) {
                if (mode === 'hover') {
                    li.classList.remove('is-open');
                    var own = li.querySelector('.menu__link');
                    if (own) { own.removeAttribute('aria-expanded'); }
                    return;
                }
                setOpen(li, state === 'expand_all'
                    || (state === 'expand_active'
                        && (li.classList.contains('is-current-parent') || li.classList.contains('is-current'))));
            });
        }

        nav.classList.add('menu--js');
        settle();
        if (PHONE.addEventListener) { PHONE.addEventListener('change', settle); }
        else if (PHONE.addListener) { PHONE.addListener(settle); }

        nav.addEventListener('click', function (e) {
            if (mode === 'hover') { return; }
            var link = e.target.closest('.menu__link');
            if (!link || !nav.contains(link)) { return; }
            var li = link.parentElement;
            if (!li || !li.classList.contains('menu__item--parent')) { return; }
            // NOTHING TO OPEN: an item whose sub-menu is hidden on a phone is,
            // on a phone, just its link.
            if (mode === 'phone' && li.classList.contains('menu__item--sub-no-phone')) { return; }

            if (!li.classList.contains('is-open')) {
                // A CLOSED ITEM ALWAYS OPENS ON THE FIRST CLICK, and that click
                // never navigates — or "open on click" would take you off the
                // page before the sub-menu is visible.
                e.preventDefault();
                if (mode === 'click') {
                    closeAll(nav, li);
                } else if (mode === 'accordion' || nav.getAttribute('data-mobile-behaviour') === 'accordion') {
                    // An accordion: opening one closes the others beside it.
                    closeSiblings(li);
                }
                setOpen(li, true);
                return;
            }

            // AN ITEM THAT IS NOT A LINK has nothing to follow, so a second
            // click closes it whatever the setting — or it could never close.
            if (second === 'close' || link.classList.contains('menu__link--static')) {
                e.preventDefault();
                setOpen(li, false);
            }
            // `go`: fall through and let the browser follow the link.
        });

        // ── AND A DROPDOWN CLOSES ─────────────────────────────────────────
        // A panel opened by a click stays open until something closes it, and
        // "nothing closes it" is how a menu ends up covering the page. Clicking
        // away and Escape both do; Escape also returns focus to the item, or the
        // keyboard user is left adrift in a panel that has just vanished.
        // DROPDOWNS ONLY: an accordion's and a phone's sub-menus are part of the
        // page, and a click on the page is not a request to fold them.
        document.addEventListener('click', function (e) {
            if (mode === 'click' && !nav.contains(e.target)) { closeAll(nav, null); }
        });

        nav.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || mode !== 'click') { return; }
            var openLi = nav.querySelector('.is-open');
            if (!openLi) { return; }
            var link = openLi.querySelector('.menu__link');
            closeAll(nav, null);
            if (link && typeof link.focus === 'function') { link.focus(); }
        });
    });

    // ── STICKY ───────────────────────────────────────────────────────────
    // The location's Sticky tab, as data on the `<nav>`. Once the page has
    // scrolled past the bar it is fixed to the top of the window, and a
    // placeholder of its exact size keeps its place in the header so nothing
    // under it jumps.
    //
    // "HIDE UNTIL SCROLLING UP" measures how far the page has moved since it
    // last changed direction. Comparing each scroll event with the one before
    // does not work: a phone fires those a few pixels apart, so a 10px
    // tolerance never trips and the bar never hides.
    Array.prototype.forEach.call(document.querySelectorAll('nav.menu[data-sticky-desktop]'), function (nav) {
        var d         = nav.dataset;
        var num       = function (v, dflt) { var n = parseFloat(v); return isNaN(n) ? dflt : n; };
        var offset    = num(d.stickyOffset, 0);
        var opacity   = num(d.stickyOpacity, 1);
        var tolerance = num(d.stickyHideTolerance, 10);
        var hideFrom  = num(d.stickyHideOffset, 0);
        var stuck     = false;
        var holder    = null;
        var startY    = 0;
        var lastY     = window.pageYOffset;
        var turnY     = lastY;
        var goingDown = true;
        var resizing  = null;

        // A COLUMN IS NOT A BAR: neither "expand" nor "hide until scrolling
        // up" applies to a vertical or accordion menu.
        var column    = nav.classList.contains('menu--vertical') || nav.classList.contains('menu--accordion');

        function wanted() { return PHONE.matches ? d.stickyMobile === 'on' : d.stickyDesktop === 'on'; }
        function expand() {
            if (PHONE.matches) { return d.stickyExpandMobile === 'on'; }
            return !column && d.stickyExpand === 'on';
        }

        // WHERE THE BAR SITS IN THE PAGE — measured while it is IN the page.
        function measure() {
            startY = nav.getBoundingClientRect().top + window.pageYOffset - offset;
        }

        function stick() {
            var r = nav.getBoundingClientRect();
            holder = document.createElement('div');
            holder.setAttribute('aria-hidden', 'true');
            holder.style.cssText = 'flex:none;width:' + r.width + 'px;height:' + r.height + 'px';
            nav.parentNode.insertBefore(holder, nav);

            nav.classList.add('menu--stuck');
            nav.style.top = offset + 'px';
            nav.style.opacity = String(opacity);
            nav.style.setProperty('--menu-stuck-offset', offset + 'px');
            if (expand()) {
                // The background spans the window; the items stay where they were.
                nav.style.left = '0';
                nav.style.right = '0';
                nav.style.paddingLeft = r.left + 'px';
                nav.style.paddingRight = Math.max(0, document.documentElement.clientWidth - r.right) + 'px';
            } else {
                nav.style.left = r.left + 'px';
                nav.style.width = r.width + 'px';
            }
            // Where a phone's panel drops from, now the bar has left the header.
            nav.style.setProperty('--menu-stuck-bottom', (offset + nav.getBoundingClientRect().height) + 'px');
            stuck = true;
        }

        function unstick() {
            nav.classList.remove('menu--stuck', 'menu--sticky-hidden');
            ['top', 'left', 'right', 'width', 'opacity', 'paddingLeft', 'paddingRight'].forEach(function (p) {
                nav.style[p] = '';
            });
            nav.style.removeProperty('--menu-stuck-offset');
            nav.style.removeProperty('--menu-stuck-bottom');
            if (holder && holder.parentNode) { holder.parentNode.removeChild(holder); }
            holder = null;
            stuck = false;
        }

        function onScroll() {
            var y = window.pageYOffset;
            if (!wanted()) {
                if (stuck) { unstick(); }
            } else if (y > startY) {
                if (!stuck) { stick(); }
            } else if (stuck) {
                unstick();
            }

            if (stuck && d.stickyHide === 'on' && (PHONE.matches || !column)) {
                var down = y > lastY;
                if (y !== lastY && down !== goingDown) { goingDown = down; turnY = lastY; }
                var sw = nav.querySelector('.menu__switch');
                // NEVER WHILE IT IS IN USE: an open phone panel, or keyboard
                // focus inside the bar, keeps it on the screen.
                if (y < hideFrom || (sw && sw.checked) || nav.contains(document.activeElement)) {
                    nav.classList.remove('menu--sticky-hidden');
                } else if (goingDown && y - turnY > tolerance) {
                    nav.classList.add('menu--sticky-hidden');
                } else if (!goingDown && turnY - y > tolerance) {
                    nav.classList.remove('menu--sticky-hidden');
                }
            }
            lastY = y;
        }

        measure();
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        // A RESIZE MOVES THE BAR, so it is let go, measured again where it now
        // sits, and stuck again if the page is still past it.
        window.addEventListener('resize', function () {
            clearTimeout(resizing);
            resizing = setTimeout(function () {
                if (stuck) { unstick(); }
                measure();
                onScroll();
            }, 100);
        });
        // Images and fonts above the bar move it after the first measure.
        window.addEventListener('load', function () {
            if (!stuck) { measure(); onScroll(); }
        });
    });

    // ── IMAGE SWAP — Pro's, without its widget ─────────────────────────────
    // Pointing at an item in a panel, or tabbing to it, brings its picture up
    // in the panel's picture column; the panel opens on its first picture again
    // each time. Every picture is fetched the first time the panel opens, so a
    // pointer moving down the list never waits for one.
    Array.prototype.forEach.call(document.querySelectorAll('.menu__mega--swap'), function (panel) {
        var img = panel.querySelector('.menu__swap-img');
        if (!img) { return; }
        var first  = { src: img.getAttribute('src'), cls: img.className };
        var warmed = false;

        function over(e) {
            var link = e.target.closest ? e.target.closest('[data-image-swap-url]') : null;
            if (!link || !panel.contains(link)) { return; }
            img.src = link.getAttribute('data-image-swap-url');
            img.className = 'menu__swap-img menu__swap-img--' + (link.getAttribute('data-image-swap-size') || 'thumbnail');
        }
        panel.addEventListener('mouseover', over);
        panel.addEventListener('focusin', over);

        var li = panel.parentElement;
        function opened() {
            img.src = first.src;
            img.className = first.cls;
            if (warmed) { return; }
            warmed = true;
            Array.prototype.forEach.call(panel.querySelectorAll('[data-image-swap-url]'), function (a) {
                (new Image()).src = a.getAttribute('data-image-swap-url');
            });
        }
        li.addEventListener('mouseenter', opened);
        li.addEventListener('focusin', function (e) { if (e.target === li.querySelector('.menu__link')) { opened(); } });
    });

    // ── THE TOGGLE BAR'S SEARCH: an empty submit opens the box ─────────────
    // The expanding search is an icon until it is used. Pressing it with nothing
    // typed is asking for the box, not searching for nothing — so the box gets
    // focus (and the theme opens it) instead. WITH NO SCRIPT the icon submits
    // and the search page asks, which still works.
    // The Replacements tab's search box is the same control in the menu.
    Array.prototype.forEach.call(document.querySelectorAll('.menu__bar-search, .menu__search'), function (form) {
        form.addEventListener('submit', function (e) {
            var box = form.querySelector('.menu__bar-search-input, .menu__search-input');
            if (box && box.value.trim() === '') {
                e.preventDefault();
                box.focus();
            }
        });
    });

    /**
     * Close every open item in this menu except `keep` AND THE ITEMS IT SITS
     * INSIDE. It closed everything but `keep`, so opening a second-level item in
     * an open dropdown shut the dropdown it was in.
     */
    function closeAll(nav, keep) {
        Array.prototype.forEach.call(nav.querySelectorAll('.is-open'), function (li) {
            if (keep && (li === keep || li.contains(keep))) { return; }
            setOpen(li, false);
        });
    }

    /** Close the items beside `li`, and anything open inside them. */
    function closeSiblings(li) {
        Array.prototype.forEach.call(li.parentElement.children, function (sib) {
            if (sib === li) { return; }
            if (sib.classList.contains('is-open')) { setOpen(sib, false); }
            Array.prototype.forEach.call(sib.querySelectorAll('.is-open'), function (d) { setOpen(d, false); });
        });
    }

    /** Open or shut one item, and say so to a screen reader on its own link. */
    function setOpen(li, on) {
        li.classList.toggle('is-open', on);
        var own = li.querySelector('.menu__link');
        if (own) { own.setAttribute('aria-expanded', on ? 'true' : 'false'); }
    }
}());
