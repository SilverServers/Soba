/* ═══════════════════════════════════════════════════════════════════════════
   SIDEBAR + ENTITY RAIL — collapse behaviour.
   ═══════════════════════════════════════════════════════════════════════════

   Pairs with css/shell.css and php/views/partials/{sidebar,entity_rail}.php.
   Call initSidebar() and initEntityRail() once on DOMContentLoaded.

   ── THE ONE THING TO UNDERSTAND ─────────────────────────────────────────────
   THE JS ONLY WRITES THE COOKIE. THE SERVER DECIDES WHAT RENDERS.

   Collapse state must be applied server-side, in the markup, on first paint. If
   JS restores it after load, every navigation paints the section OPEN for one
   frame and then snaps it shut — the single most-noticed chrome jitter there
   is. So: the click handler toggles the class for immediate feedback AND writes
   the cookie; the next page load has the class already in the HTML.

   Read that cookie in the renderer. If you skip that half, this file is a
   flicker generator.

   ── AND THE ONE TRAP ────────────────────────────────────────────────────────
   On mobile the sidebar node is RELOCATED into the drawer, which is outside
   [data-sidebar]. A handler scoped to the sidebar therefore never sees those
   clicks, and sections cannot be collapsed on a phone. Hence the second,
   document-delegated handler below — which skips anything still inside
   [data-sidebar] so nothing double-fires.
   ═══════════════════════════════════════════════════════════════════════════ */

const SB_COOKIE_MAX_AGE = 31536000; // one year

/* How long an open flyout survives the pointer leaving it. Long enough to cross
   the gap between the glyph and the panel on a diagonal, short enough that it
   does not linger over the page. */
const FLYOUT_CLOSE_MS = 320;
let flyTimer = null;

function sbSetCookie(k, v) {
  document.cookie = k + '=' + v + ';path=/;max-age=' + SB_COOKIE_MAX_AGE + ';samesite=lax';
}
function sbReadList(k) {
  const m = document.cookie.match(new RegExp('(?:^|; )' + k + '=([^;]*)'));
  return m && m[1] ? decodeURIComponent(m[1]).split(',').filter(Boolean) : [];
}

/** Collapse one section and persist it. Shared by both handlers below. */
function sbToggleSection(btn) {
  const sec = btn.closest('[data-sb-sec]');
  if (!sec) return false;
  const now = sec.classList.toggle('is-collapsed');
  btn.setAttribute('aria-expanded', now ? 'false' : 'true');
  // The CLASS alone. `hidden` used to be set here too, on the reasoning that it
  // takes collapsed rows out of tab order — but `display: none` already does
  // that, so it was a second mechanism saying the same thing, and railed it
  // pinned the flyout shut on any group the server had collapsed.

  // The cookie lists what is OPEN, not what is collapsed. Closed is the default
  // — see Sidebar::openSections() for why — so this records the exceptions.
  const set = new Set(sbReadList('sb_open'));
  if (now) set.delete(sec.dataset.sbSec); else set.add(sec.dataset.sbSec);
  sbSetCookie('sb_open', encodeURIComponent([...set].join(',')));

  // The collapse-all control describes the column, so it moves when the column
  // does — including when one chevron closes the last open group.
  sbSyncToggleAll(sec);
  return true;
}

/**
 * COLLAPSE ALL / EXPAND ALL — one button, and the column decides which job.
 *
 * `$btn` may live inside the sidebar or inside the relocated mobile drawer, so
 * the groups are found from the button's own root rather than from
 * `[data-sidebar]`. On a phone that selector matches nothing (see the header
 * note) and this would silently fold zero sections.
 *
 * The direction is read from the button's class, NOT recomputed from the
 * sections — with the current page's group force-opened by the server, a
 * "collapse all" that then counted one open section would flip itself back to
 * "expand" on the very click that collapsed it.
 */
function sbToggleAll(btn) {
  const root = btn.closest('.sb');
  if (!root) return false;

  const groups = root.querySelectorAll('[data-sb-group]');
  if (!groups.length) return false;

  const expanding = btn.classList.contains('is-all-collapsed');

  groups.forEach((sec) => {
    sec.classList.toggle('is-collapsed', !expanding);
    const toggle = sec.querySelector('[data-sb-toggle]');
    if (toggle) toggle.setAttribute('aria-expanded', expanding ? 'true' : 'false');
  });

  // ONE cookie write for the lot, not one per section. The cookie lists what is
  // OPEN — closed is the default — so expanding writes every group key and
  // collapsing writes an empty list.
  const keys = expanding ? [...groups].map((s) => s.dataset.sbSec).filter(Boolean) : [];
  sbSetCookie('sb_open', encodeURIComponent(keys.join(',')));

  // Flip the control to its other job. The glyph swap is CSS off this one class
  // (both glyphs are in the DOM); the tip and the accessible name are two
  // separate things and both have to move with it — a tooltip still reading
  // "Collapse all" over a button that now expands is worse than no tip.
  btn.classList.toggle('is-all-collapsed', !expanding);
  btn.setAttribute('data-tip', expanding ? 'Collapse all' : 'Expand all');
  btn.setAttribute('aria-label', expanding ? 'Collapse all sections' : 'Expand all sections');
  return true;
}

/**
 * Keep the collapse-all control honest after a SINGLE section is toggled.
 *
 * Collapse the last open group by its own chevron and the button must already
 * say "Expand all" — otherwise the next click is a no-op that looks like a dead
 * control. Recomputed from the DOM, because that is the only thing that knows
 * what just happened.
 */
function sbSyncToggleAll(sec) {
  const root = sec.closest('.sb');
  const btn  = root && root.querySelector('[data-sb-collapse-all]');
  if (!btn) return;

  const groups = root.querySelectorAll('[data-sb-group]');
  const anyOpen = [...groups].some((s) => !s.classList.contains('is-collapsed'));

  btn.classList.toggle('is-all-collapsed', !anyOpen);
  btn.setAttribute('data-tip', anyOpen ? 'Collapse all' : 'Expand all');
  btn.setAttribute('aria-label', anyOpen ? 'Collapse all sections' : 'Expand all sections');
}

/**
 * Place an open flyout: its FIRST ROW level with the glyph that opened it.
 *
 * ── WHY THE FIRST ROW AND NOT THE PANEL'S TOP EDGE ──────────────────────────
 * Aligning the panel's top edge put its TITLE in the cursor's path — move right
 * off the glyph and you land on a heading, which is not a thing you can click.
 * The first actionable row has to be what the pointer meets, so the panel is
 * offset by however far that row sits inside it. The title then lives above the
 * glyph line, out of the way, still naming the group.
 *
 * Measured rather than computed from constants: the title's height comes from
 * font metrics, and a hard-coded offset would drift the moment the type scale
 * or the padding moved.
 */
function placeFlyout(ev) {
  const t = ev.target;
  const group = t.matches && t.matches('[data-sb-group]')
    ? t
    : (t.closest && t.closest('[data-sb-group]'));
  if (!group || !group.closest('.sb.is-railed')) return;

  const panel = group.querySelector('.sb__sec-body');
  const row   = group.querySelector('.sb__item--parent');
  const first = panel && panel.querySelector('.sb__item');
  if (!panel || !row || !first) return;

  // Clear the previous placement before measuring, or the second open measures
  // the first one's correction and walks the panel up the screen. The CSS
  // margin goes too — it and an explicit `top` would otherwise both apply.
  panel.style.top = '';
  panel.style.marginTop = '';

  const panelRect = panel.getBoundingClientRect();
  const rowRect   = row.getBoundingClientRect();
  const firstRect = first.getBoundingClientRect();

  // Centre the first row on the glyph, so moving straight right lands on it.
  const delta = (rowRect.top + rowRect.height / 2) -
                (firstRect.top + firstRect.height / 2);

  const gap = 8;
  let top = panelRect.top + delta;

  // Then keep the whole panel on screen — bottom first, top second, so a panel
  // taller than the viewport pins to the top rather than scrolling off it.
  if (top + panelRect.height > window.innerHeight - gap) {
    top = window.innerHeight - gap - panelRect.height;
  }
  if (top < gap) top = gap;

  panel.style.marginTop = '0';
  panel.style.top = Math.round(top) + 'px';
}

function initSidebar() {
  const sb = document.querySelector('[data-sidebar]');
  if (!sb) return;

  sb.addEventListener('click', (ev) => {
    const allBtn = ev.target.closest('[data-sb-collapse-all]');
    if (allBtn) { sbToggleAll(allBtn); return; }

    const secBtn = ev.target.closest('[data-sb-toggle]');
    if (secBtn) {
      // RAILED, THERE IS NOTHING TO DISCLOSE IN PLACE. The submenu is a flyout,
      // so collapsing the section behind it would change a state nobody can
      // see. On a touch screen there is no hover either, so the tap has to be
      // what opens the flyout — that is the one thing this click does here.
      if (sb.classList.contains('is-railed')) {
        const sec  = secBtn.closest('[data-sb-group]');
        const open = sec.classList.contains('is-flyout-open');
        sb.querySelectorAll('.is-flyout-open').forEach((s) => s.classList.remove('is-flyout-open'));
        if (!open) { sec.classList.add('is-flyout-open'); placeFlyout({ target: secBtn }); }
        return;
      }
      sbToggleSection(secBtn);
      return;
    }

    const railBtn = ev.target.closest('[data-sb-rail]');
    if (!railBtn) return;

    const railed = sb.classList.toggle('is-railed');
    sbSetCookie('sb_railed', railed ? '1' : '0');

    // A tooltip is legal ONLY on a glyph-only element — i.e. exactly when
    // railed. Add them on collapse, strip them on expand. Leaving them on an
    // expanded row would put a hover bubble on a control that already carries
    // its own visible label, which is the rule this whole system exists under.
    //
    // SCOPED TO THE LEAD ROWS, and only those. This used to say `.sb__item`,
    // which caught two kinds of row that must NOT get one:
    //   · a GROUP row — railed it opens a flyout whose title is already the
    //     group's name, so a tip put the same word on screen twice, 40px
    //     apart, in two different surfaces;
    //   · a row inside a `.sb__sec-body` — those keep their visible labels in
    //     the flyout, so they are never glyph-only at all.
    // Dashboard, Assistant and Pages are the only rows that genuinely lose
    // their label to the rail.
    sb.querySelectorAll('.sb__sec--lead .sb__item').forEach((it) => {
      if (railed) it.setAttribute('data-tip', it.dataset.label || '');
      else it.removeAttribute('data-tip');
    });

    // The collapse TOGGLE never gets a tooltip — the chevron speaks for itself.
    // It does get an accurate accessible name, which is a different thing.
    railBtn.setAttribute('aria-label', railed ? 'Expand sidebar' : 'Collapse sidebar');
  });

  /* ── THE FLYOUT IS HELD OPEN, NOT TIED TO :hover ───────────────────────
     Pure `:hover` made the rail unusable. The panel opens beside the glyph, and
     the moment the pointer leaves the 34px icon on its way to the panel — any
     path that is not dead horizontal — the hover is lost and the menu vanishes
     before it can be reached. That is not a menu.

     So an explicit class holds it open, and leaving starts a SHORT TIMER rather
     than closing immediately. Crossing the gap re-enters the section and cancels
     the timer; genuinely moving away lets it fire. The same class is what makes
     it work on touch, where there is no hover at all, and on a column railed by
     the toggle rather than served that way. */
  sb.addEventListener('pointerover', (ev) => {
    if (!sb.classList.contains('is-railed')) return;
    const sec = ev.target.closest('[data-sb-group]');
    if (!sec) return;
    window.clearTimeout(flyTimer);
    if (!sec.classList.contains('is-flyout-open')) {
      sb.querySelectorAll('.is-flyout-open').forEach((s) => s.classList.remove('is-flyout-open'));
      sec.classList.add('is-flyout-open');
      placeFlyout({ target: sec });
    }
  });

  sb.addEventListener('pointerout', (ev) => {
    if (!sb.classList.contains('is-railed')) return;
    // Moving INTO the panel keeps us inside the same section, so nothing to do.
    if (ev.relatedTarget && ev.relatedTarget.closest &&
        ev.relatedTarget.closest('[data-sb-group].is-flyout-open')) return;
    window.clearTimeout(flyTimer);
    flyTimer = window.setTimeout(() => {
      sb.querySelectorAll('.is-flyout-open').forEach((s) => s.classList.remove('is-flyout-open'));
    }, FLYOUT_CLOSE_MS);
  });

  /* ── KEEP AN OPEN FLYOUT ON SCREEN ──────────────────────────────────────
     The flyout is `position: fixed` with `top: auto`, which resolves to its own
     static position — level with the row that opened it. That is right for
     nearly every row and wrong for the ones near the bottom of a short window,
     where the panel runs off the end. Measured at 560px tall: the Structure
     flyout opened at y=505 and its last row was below the fold.

     CSS opens and closes it; this only nudges `top` when it would overflow. So
     with JavaScript off the flyout still works — it can just hang low on a
     short viewport, which is strictly better than not opening. */
  sb.addEventListener('focusin', placeFlyout);

  // Fallback for RELOCATED sections (see the header note). In-sidebar clicks are
  // left to the scoped listener above so nothing fires twice.
  document.addEventListener('click', (ev) => {
    const allBtn = ev.target.closest('[data-sb-collapse-all]');
    if (allBtn && !allBtn.closest('[data-sidebar]')) { sbToggleAll(allBtn); return; }

    const btn = ev.target.closest('[data-sb-toggle]');
    if (!btn || btn.closest('[data-sidebar]')) return;
    sbToggleSection(btn);
  });
}

/* ── THE SECONDARY ENTITY RAIL ──────────────────────────────────────────────
   Same idiom, its own cookie, and deliberately independent: collapsing the
   entity rail must never touch the main sidebar's state. A page with an entity
   rail solves its own width. Force-collapsing the sidebar on a page's behalf was
   proposed and rejected — the sidebar is the USER'S state, nothing else's. */
function initEntityRail() {
  document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('[data-er-toggle]');
    if (!btn) return;
    const rail = btn.closest('.entity-rail-col');
    if (!rail) return;

    const now = rail.classList.toggle('is-collapsed');
    sbSetCookie('er_collapsed', now ? '1' : '0');

    const label = btn.getAttribute('aria-label') || '';
    btn.setAttribute(
      'aria-label',
      now ? label.replace('Collapse', 'Expand') : label.replace('Expand', 'Collapse')
    );

    // Tips only while the rows are glyph-only — the main sidebar's railed rule,
    // applied to the two-letter marks.
    rail.querySelectorAll('.entity-rail__row').forEach((row) => {
      if (now) row.setAttribute('data-tip', row.dataset.label || '');
      else row.removeAttribute('data-tip');
    });
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => { initSidebar(); initEntityRail(); });
} else {
  initSidebar();
  initEntityRail();
}
