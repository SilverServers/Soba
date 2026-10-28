/* ═══════════════════════════════════════════════════════════════════════════
   TOP NAV DROPDOWNS + MOBILE DRAWER
   ═══════════════════════════════════════════════════════════════════════════

   Pairs with css/nav.css and the nav-top.php and nav-drawer.php partials.
   Call initNavGroups() and initNavDrawer() once on DOMContentLoaded.

   ── THE LOAD-BEARING IDEA ───────────────────────────────────────────────────
   THE DRAWER RELOCATES THE ONE SIDEBAR NODE. IT NEVER RENDERS A SECOND COPY.

   Rendering the sidebar twice — once for desktop, once inside the drawer — is
   the obvious approach and it is wrong. It leaves the text filter dead (every JS
   binding attaches to the first match, which is the hidden desktop copy) and
   duplicates every element id in the sidebar. Moving the node means every
   existing binding keeps working on mobile for free, because it is the same
   node.

   Everything below follows from that: open() appends the node into the drawer,
   close() puts it back exactly where it was.
   ═══════════════════════════════════════════════════════════════════════════ */

/* ── Dropdown tab groups ─────────────────────────────────────────────────── */
function initNavGroups() {
  const groups = Array.from(document.querySelectorAll('[data-nav-group]'));
  if (!groups.length) return;

  const closeAll = (except) => {
    groups.forEach((g) => {
      if (g === except || (except && except.closest('[data-nav-group]') === g)) return;
      const t = g.querySelector('[aria-expanded]');
      const m = g.querySelector('.nav-top__group-menu');
      if (t) t.setAttribute('aria-expanded', 'false');
      if (m) m.hidden = true;
      g.classList.remove('flip-left');
    });
  };

  groups.forEach((group) => {
    const toggle = group.querySelector('[aria-expanded]');
    const menu = group.querySelector('.nav-top__group-menu');
    if (!toggle || !menu) return;

    toggle.addEventListener('click', (ev) => {
      ev.preventDefault();
      ev.stopPropagation();
      const open = menu.hidden;
      closeAll(group);
      menu.hidden = !open;
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

      // A right-flyout submenu that would overflow the viewport flips to the
      // left, where it stacks back over its parent. MEASURE, don't guess: the
      // available space depends on where the parent menu ended up, which depends
      // on the window. Only meaningful once the menu is actually laid out, so
      // this has to run after `hidden` is cleared.
      if (open && group.classList.contains('nav-top__group--sub')) {
        const r = menu.getBoundingClientRect();
        group.classList.toggle('flip-left', r.right > window.innerWidth - 8);
      }
    });
  });

  // Click-away and Escape both close. Escape also returns focus to the trigger —
  // without it, dismissing a menu by keyboard drops the user at the top of the
  // document, which is worse than not having opened it.
  document.addEventListener('click', () => closeAll(null));
  document.addEventListener('keydown', (ev) => {
    if (ev.key !== 'Escape') return;
    const open = groups.find((g) => {
      const m = g.querySelector('.nav-top__group-menu');
      return m && !m.hidden;
    });
    if (!open) return;
    const t = open.querySelector('[aria-expanded]');
    closeAll(null);
    if (t) t.focus();
  });
}

/* ── Mobile drawer ───────────────────────────────────────────────────────── */
function initNavDrawer() {
  const toggle   = document.querySelector('[data-drawer-toggle]');
  const drawer   = document.querySelector('[data-drawer]');
  const backdrop = document.querySelector('[data-drawer-backdrop]');
  const closer   = document.querySelector('[data-drawer-close]');
  if (!toggle || !drawer || !backdrop) return;

  const drawerRail = drawer.querySelector('[data-drawer-rail]');
  const navPanel   = drawer.querySelector('[data-drawer-panel="nav"]');
  const sidebar    = document.querySelector('[data-sidebar]');

  // Remember EXACTLY where the sidebar lives so close() can put it back — the
  // parent AND the next sibling. Parent alone would re-append it to the end of
  // the grid, which silently changes its grid placement.
  const homeParent = sidebar ? sidebar.parentNode : null;
  const homeNext   = sidebar ? sidebar.nextSibling : null;

  const tabs   = Array.from(drawer.querySelectorAll('[data-drawer-tab]'));
  const panels = Array.from(drawer.querySelectorAll('[data-drawer-panel]'));

  // Was the column railed when we took it? Restored on close, because the rail
  // is the USER'S desktop preference and opening a drawer is not a decision to
  // abandon it.
  let wasRailed = false;

  // Did THIS open actually move the sidebar? close() must put back exactly what
  // open() took and nothing else.
  let movedSidebar = false;

  /**
   * IS THE DESKTOP SIDEBAR ON SCREEN RIGHT NOW?
   *
   * The drawer may only take the sidebar when the viewport has already taken it
   * away. MEASURED, not inferred from a breakpoint: the width at which the
   * column hides is a CSS decision (`shell.css`, md/720) and the width at which
   * the hamburger appears is a different one (`nav.css`, lg/1080). They
   * disagreed by 360px, and every viewport in the gap showed BOTH navigations —
   * so opening the drawer at 900px pulled the live sidebar out of a layout it
   * was visible in and left a 240px empty column behind it, with the grid still
   * reading `240px 660px` and main still starting at x=240.
   *
   * Reading the geometry instead of the breakpoint means this stays correct
   * however those two rules are retuned, which is the only durable form of the
   * fix — the CSS below is corrected too, but a JS that trusts it would break
   * again the next time someone moves a number.
   */
  function sidebarOnScreen() {
    if (!sidebar || !homeParent || sidebar.parentNode !== homeParent) return false;
    const r = sidebar.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  }

  /**
   * THE DRAWER IS NEVER RAILED.
   *
   * `is-railed` is a WIDTH answer — trade the labels for 200px of main column.
   * The drawer has no main column beside it, so railed it buys nothing and costs
   * everything: a 320px sheet holding one column of nameless glyphs, with the
   * submenus behind hover flyouts that a touch screen cannot open. And the band
   * across the top goes blank, because the drawer hides the rail toggle
   * (there is no column to narrow) while `is-railed` hides collapse-all — two
   * correct rules that between them empty the strip.
   *
   * The class is what carries all of that, so the class is what comes off. Do
   * NOT try to undo it in CSS under `.nav-drawer__rail`: `is-railed` drives some
   * two dozen rules and re-stating every one of them inverted is a specificity
   * fight that loses the moment anybody adds the twenty-fifth.
   *
   * The tooltips go with it, under `sidebar.js`'s rule: a tip is legal only on a
   * glyph-only element, and expanded these rows carry their own visible labels.
   */
  function unrail() {
    if (!sidebar) return;
    wasRailed = sidebar.classList.contains('is-railed');
    if (!wasRailed) return;
    sidebar.classList.remove('is-railed');
    // A flyout is the railed column's stand-in for in-place disclosure. Expanded
    // there is nothing for it to stand in for, and a stuck one would sit over
    // the rows it belongs to.
    sidebar.querySelectorAll('.is-flyout-open').forEach((s) => s.classList.remove('is-flyout-open'));
    sidebar.querySelectorAll('.sb__sec--lead .sb__item[data-tip]')
      .forEach((it) => it.removeAttribute('data-tip'));
  }

  function rerail() {
    if (!sidebar || !wasRailed) return;
    sidebar.classList.add('is-railed');
    sidebar.querySelectorAll('.sb__sec--lead .sb__item')
      .forEach((it) => it.setAttribute('data-tip', it.dataset.label || ''));
    wasRailed = false;
  }

  function setTab(name) {
    tabs.forEach((t) => {
      const on = t.getAttribute('data-drawer-tab') === name;
      t.classList.toggle('is-active', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    panels.forEach((p) =>
      p.classList.toggle('is-active', p.getAttribute('data-drawer-panel') === name));
  }

  function open() {
    // TAKE THE SIDEBAR ONLY IF THE VIEWPORT HAS ALREADY HIDDEN IT.
    // Moving a VISIBLE column into a sheet that covers it is not a mobile
    // treatment — it is a 240px hole in the page plus a second copy of a
    // navigation the user can already see.
    movedSidebar = false;
    if (sidebar && drawerRail && !sidebarOnScreen()) {
      if (sidebar.parentNode !== drawerRail) drawerRail.appendChild(sidebar);
      movedSidebar = true;
      // Before anything reads the column's shape, take the rail off it.
      unrail();
    }

    // Then lift any GLOBAL section (bookmarks/pinned — the same on every page)
    // UP into "Navigate", where "go to this thing" belongs. "This page" is then
    // ONLY the page's own nav, and its tab shows solely when that exists — so a
    // page whose sidebar is nothing but bookmarks never renders an empty tab.
    // Gated on the move for the same reason as the move: with the column on
    // screen this would lift a section straight out of it.
    const global = movedSidebar && sidebar.querySelector('[data-sb-sec="pinned"]');
    if (global && navPanel) navPanel.appendChild(global);

    const hasPageNav = !!(movedSidebar &&
      sidebar.querySelector('[data-sb-sec]:not([data-sb-sec="pinned"])'));
    drawer.classList.toggle('has-page-nav', hasPageNav);

    // A DRAWER WITH NOTHING IN IT DOES NOT OPEN.
    // With the sidebar left in place and no destinations panel to fall back on,
    // every panel is empty — and this file already records what that looks like
    // to a user: "a blank sheet reads as a broken screen, not as a menu with
    // nothing in it". The CSS now keeps the hamburger off screen wherever that
    // would be the case, so this should be unreachable; it is here because
    // "should be unreachable" is exactly what was believed about the state that
    // produced the empty drawer the first time.
    if (!movedSidebar && !navPanel) return false;

    // OPEN ON "Navigate" ONLY IF THERE IS ONE. This said `setTab('nav')`
    // unconditionally, and the "nav" panel is rendered only when the top bar has
    // destinations to put in it — which on this install it does not. So setTab
    // deactivated the "page" panel the server had marked active, matched nothing
    // to replace it, and THE DRAWER OPENED EMPTY on every admin page. A blank
    // sheet reads as a broken screen, not as a menu with nothing in it, and the
    // whole sidebar was sitting inside it the entire time.
    setTab(navPanel ? 'nav' : 'page');
    document.body.classList.add('is-drawer-open');
    drawer.setAttribute('aria-hidden', 'false');
    toggle.setAttribute('aria-expanded', 'true');
  }

  function close() {
    document.body.classList.remove('is-drawer-open');
    drawer.setAttribute('aria-hidden', 'true');
    toggle.setAttribute('aria-expanded', 'false');

    // PUT BACK EXACTLY WHAT open() TOOK. With the column left in place there is
    // nothing to restore, and running the restore anyway would re-parent a
    // section that never left.
    if (!movedSidebar) return;

    // Put the global section back at the FOOT of the sidebar's scroll region,
    // then the whole sidebar back to its desktop home. Restoring in the wrong
    // order re-parents an orphaned section.
    const scroll = sidebar && sidebar.querySelector('.sb__scroll');
    const global = navPanel && navPanel.querySelector('[data-sb-sec="pinned"]');
    if (global && scroll) scroll.appendChild(global);
    if (sidebar && homeParent && sidebar.parentNode !== homeParent) {
      homeParent.insertBefore(sidebar, homeNext);
    }

    // The rail goes back on last, once the column is home — railed markup in the
    // drawer for even one frame is the flicker this whole cookie exists to avoid.
    rerail();
    movedSidebar = false;
  }

  tabs.forEach((t) => t.addEventListener('click', () => setTab(t.getAttribute('data-drawer-tab'))));

  toggle.addEventListener('click', (ev) => {
    ev.preventDefault();
    if (document.body.classList.contains('is-drawer-open')) close(); else open();
  });
  backdrop.addEventListener('click', close);
  if (closer) closer.addEventListener('click', close);
  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && document.body.classList.contains('is-drawer-open')) close();
  });

  // A nav link closes the drawer on click; the browser then navigates, so the
  // user lands on the new page with a clean view instead of the drawer still
  // sitting open over it. Delegated, because the relocated sidebar's rows are
  // not in the DOM at bind time.
  drawer.addEventListener('click', (ev) => {
    if (ev.target.closest('a[href]')) close();
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => { initNavGroups(); initNavDrawer(); });
} else {
  initNavGroups();
  initNavDrawer();
}
