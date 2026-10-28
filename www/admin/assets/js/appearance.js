/* ═══════════════════════════════════════════════════════════════════════════
   APPEARANCE — theme and radius, applied live, mixed and matched.
   ═══════════════════════════════════════════════════════════════════════════

   Two INDEPENDENT axes, both stamped on <html>:

     data-theme="tuxedo"      which palette
     data-radius="modern"     rounded (modern) or straight (classic)

   They compose freely — any theme × either radius — because they touch disjoint
   token sets. That is the entire reason radius is a token axis rather than a CSS
   variant: nothing has to be written twice, and the radius rules hold unchanged
   (they govern which TOKEN you wrote, not what it resolves to).

   ── MARKUP CONTRACT ─────────────────────────────────────────────────────────

     <select data-appearance="theme">   <option value="tuxedo">Tuxedo</option> …
     <select data-appearance="radius">  <option value="modern">Modern (rounded)</option>
                                        <option value="classic">Classic (straight)</option>

   Any control carrying data-appearance="<axis>" applies on `change`. That is the
   whole picker: a labelled <select> in a settings pane. Do NOT build a grid of
   theme swatch tiles — 21 swatches is a wall of colour that tells you nothing
   about how a theme reads on a real page, and the page behind the dropdown IS
   the preview.

   ── WHY IT APPLIES ON CHANGE, NOT ON SAVE ───────────────────────────────────
   Because the page you are looking at is the only honest preview. A theme picker
   whose result you cannot see until after a form submit makes people save, look,
   go back, and save again. Repainting live costs one attribute write.

   ── PERSISTENCE ─────────────────────────────────────────────────────────────
   Optional and orthogonal. If the <select> sits inside a form, let the form save
   it as normal — the live repaint above is purely visual and the form still owns
   the value. If you want it to persist without a form, put the endpoint on the
   control: data-appearance-url="/me/appearance". It POSTs {axis, value} plus the
   CSRF token from <meta name="csrf">.

   localStorage is the fallback for a signed-OUT viewer only. For a signed-in
   user the server value must win, or a shared machine shows the wrong person's
   theme, and the first-paint attribute (rendered server-side) will disagree with
   what JS then applies — a visible flash on every load.

   ── FIRST PAINT ─────────────────────────────────────────────────────────────
   BOTH attributes must be rendered SERVER-SIDE into the <html> tag. If JS stamps
   them after load, every navigation paints the default theme for one frame and
   then swaps. See php/views/head.php for the skeleton that goes with it.
   ═══════════════════════════════════════════════════════════════════════════ */

const APPEARANCE_AXES = {
  theme:  { attr: 'data-theme',  storage: 'appearance.theme'  },
  radius: { attr: 'data-radius', storage: 'appearance.radius' },
};

function applyAppearance(axis, value) {
  const spec = APPEARANCE_AXES[axis];
  if (!spec || !value) return;
  document.documentElement.setAttribute(spec.attr, value);
  // The first-paint skeleton paints an inline background on <html> so the blank
  // pre-stylesheet frame is already in-theme. Once a real stylesheet has painted
  // the shell, that inline background is stale — and after a live theme change
  // it is stale in the OLD theme's colour, showing through as a wrong-coloured
  // edge. Clear it the moment we repaint.
  if (axis === 'theme') document.documentElement.style.background = '';
}

function persistAppearance(el, axis, value) {
  const url = el.getAttribute('data-appearance-url');
  if (!url) {
    // No endpoint: signed-out fallback only. A signed-in user's value belongs on
    // the server (see the header note).
    try { localStorage.setItem(APPEARANCE_AXES[axis].storage, value); } catch (e) { /* private mode */ }
    return;
  }
  const csrf = document.querySelector('meta[name="csrf"]');
  const body = new FormData();
  body.append('axis', axis);
  body.append('value', value);
  if (csrf) body.append('_csrf', csrf.content);
  fetch(url, { method: 'POST', body, credentials: 'same-origin' }).catch(() => {
    // A failed save must not silently leave the user looking at a theme they do
    // not have. Say so; do not revert underneath them mid-interaction.
    if (typeof window.toast === 'function') window.toast('Could not save your appearance settings.', 'error');
  });
}

function initAppearance() {
  document.addEventListener('change', (ev) => {
    const el = ev.target.closest('[data-appearance]');
    if (!el) return;
    const axis = el.getAttribute('data-appearance');
    if (!APPEARANCE_AXES[axis]) return;
    const value = el.value;
    applyAppearance(axis, value);
    persistAppearance(el, axis, value);
  });

  // Signed-out restore. Guarded: a server-rendered attribute always wins, so
  // this only fills a gap it finds — it never overrides what the page shipped.
  for (const [axis, spec] of Object.entries(APPEARANCE_AXES)) {
    if (document.documentElement.hasAttribute(spec.attr)) continue;
    let stored = null;
    try { stored = localStorage.getItem(spec.storage); } catch (e) { /* private mode */ }
    if (stored) applyAppearance(axis, stored);
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initAppearance);
} else {
  initAppearance();
}
