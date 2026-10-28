/* ═══════════════════════════════════════════════════════════════════════════
   TOOLTIPS — one body-level node, positioned by measurement.
   ═══════════════════════════════════════════════════════════════════════════

   Pairs with .tip-popup in css/core.css. Call initTooltips() once on DOMContentLoaded.

   ── THE RULE COMES FIRST ────────────────────────────────────────────────────
   If an element has its own visible TEXT LABEL, it does not get a tooltip. Not
   data-tip, not title="". Allowed only on: pips, status dots, drag handles,
   glyph-only icon buttons, and bare numbers with no self-evident header — and on
   any of those, pair data-tip with aria-label.

   Keep them to a FEW WORDS. If you want to write a sentence, the element is
   under-designed: fix the label, or move the explanation into a confirm dialog.
   Hover bubbles get missed and do not exist on touch, so nothing important may
   live only in one.

   ── WHY THIS SHAPE ──────────────────────────────────────────────────────────
   A per-element ::after tooltip cannot measure itself, so it cannot clamp to the
   viewport, cannot flip when it would clip, and cannot keep its arrow pointing
   at the trigger once clamped. One shared node measured on show does all three.

   Three bugs are already fixed in here; keep them fixed:
     1. TOP LAYER. An open <dialog> renders in the browser's top layer, above
        every z-index on the page. A <body>-parented tooltip was painted UNDER
        any modal no matter how high its z-index. show() promotes the node via
        the popover API, and the CSS undoes the UA popover defaults.
     2. MEASURE BEFORE PLACING. A closed popover has no layout, so width/height
        read 0 and the placement maths is junk. showPopover() must come first.
     3. SVG-SAFE TARGET LOOKUP. Inside an inline <svg> the event target is an
        SVG element, and SVGElement.closest() is missing on older engines — so
        `ev.target.closest()` returned nothing. A tooltip on a glyph-only button
        is EXACTLY an <svg> inside the trigger, so hovering the icon did nothing
        while focusing it worked. Walk parentNode by hand.
   ═══════════════════════════════════════════════════════════════════════════ */

function initTooltips() {
  const tip = document.createElement('div');
  tip.className = 'tip-popup';
  tip.setAttribute('role', 'tooltip');
  // A manual popover so the tooltip can be promoted into the browser's TOP LAYER
  // on show. Without it the tooltip is an ordinary <body> child, and an open
  // <dialog> — which IS in the top layer — paints over it no matter how high its
  // z-index. Feature-guarded: older engines keep the previous behaviour.
  const canPopover = typeof tip.showPopover === 'function';
  if (canPopover) tip.setAttribute('popover', 'manual');
  document.body.appendChild(tip);

  let activeEl = null;

  function show(el) {
    activeEl = el;
    const rect = el.getBoundingClientRect();
    tip.textContent = el.getAttribute('data-tip') || '';
    tip.classList.remove('tip-popup--above', 'tip-popup--right');
    // Must precede the measurements below — a closed popover has no layout, so
    // its width/height would read 0 and the placement maths would be junk.
    if (canPopover && !tip.matches(':popover-open')) {
      try { tip.showPopover(); } catch (e) { /* already open — fine */ }
    }
    // Measure the rendered tooltip (it has layout even at opacity:0) so we can
    // keep both axes inside the viewport. Long tips wrap within a max-width, so
    // height varies with the text — measure, never assume a single line.
    const tipRect = tip.getBoundingClientRect();
    const tipHeight = tipRect.height || 32;
    const tipWidth = tipRect.width;
    const margin = 8;

    // SIDE placement — an ancestor asks for it (the sidebar sets
    // data-tip-side="right" on its column). A trigger pinned to the left edge
    // has no useful space above it: the tooltip would land on the row above.
    // Point it out into the content band instead. Falls through to the default
    // above/below placement if there isn't room on that side.
    const wantsRight = el.closest('[data-tip-side="right"]') !== null;
    if (wantsRight && rect.right + margin + tipWidth <= window.innerWidth - margin) {
      tip.classList.add('tip-popup--right');
      // NO GAP ON THIS AXIS. The bubble sits EDGE-ON against the column and its
      // arrow — drawn outside it, via `right: 100%` — overlaps back into the
      // rail. That is the whole look: the tip reads as coming OUT of the column
      // rather than floating beside it.
      //
      // It used to get `+ margin` and still look right only by accident: the
      // trigger was a 34px square inset 9px in a 52px rail, so `rect.right + 8`
      // happened to land on the column's edge. The moment the rows went
      // full-bleed, `rect.right` became the column edge itself and the same
      // margin pushed the bubble 7px clear, stranding the arrow in the gap.
      // Anchored to the trigger's edge directly, it cannot drift again.
      tip.style.left = rect.right + 'px';
      // Vertically centre on the trigger, clamped into the viewport.
      const triggerMid = rect.top + rect.height / 2;
      const top = Math.min(
        Math.max(triggerMid - tipHeight / 2, margin),
        window.innerHeight - margin - tipHeight
      );
      tip.style.top = top + 'px';
      // Arrow y = trigger centre relative to the bubble's top edge, kept clear
      // of the rounded corners.
      let arrowY = triggerMid - top;
      arrowY = Math.min(Math.max(arrowY, 8), tipHeight - 8);
      tip.style.setProperty('--tip-arrow-y', arrowY + 'px');
      tip.classList.add('tip-popup--visible');
      return;
    }

    // Position above the trigger by default (the cursor covers below-tooltips
    // for small targets like milestone pips). Flip below only if above would
    // clip the top of the viewport.
    const aboveTop = rect.top - 8 - tipHeight;
    if (aboveTop < margin) {
      tip.style.top = (rect.bottom + 8) + 'px';
    } else {
      tip.style.top = aboveTop + 'px';
      tip.classList.add('tip-popup--above');
    }
    // Horizontal: center on the trigger, then clamp so the tooltip never spills
    // past either edge of the content band. The right edge is the viewport (the
    // last milestone pip on a full-width bar would otherwise clip off-screen);
    // the left edge is the sidebar's right edge, so the FIRST pip's tooltip
    // pushes clear of the sidebar instead of overlapping it. When clamped, the
    // arrow is re-offset to keep pointing back at the trigger center.
    const triggerCenter = rect.left + rect.width / 2;
    const half = tipWidth / 2;
    const rail = document.querySelector('.sb, .nav-rail');
    const railRect = rail ? rail.getBoundingClientRect() : null;
    const leftBound = (railRect && railRect.width > 0) ? railRect.right + margin : margin;
    const minCenter = leftBound + half;
    const maxCenter = window.innerWidth - margin - half;
    let center = triggerCenter;
    if (maxCenter >= minCenter) {
      center = Math.min(Math.max(center, minCenter), maxCenter);
    }
    tip.style.left = center + 'px';
    // Arrow x = distance from the tooltip's left edge to the trigger center,
    // clamped to stay within the bubble (away from the rounded corners).
    let arrowX = triggerCenter - (center - half);
    arrowX = Math.min(Math.max(arrowX, 10), tipWidth - 10);
    tip.style.setProperty('--tip-arrow-x', arrowX + 'px');
    tip.classList.add('tip-popup--visible');
  }

  function hide() {
    activeEl = null;
    tip.classList.remove('tip-popup--visible');
    // Leave the top layer, so a stale tooltip can never sit over a later modal.
    if (canPopover && tip.matches(':popover-open')) {
      try { tip.hidePopover(); } catch (e) { /* already closed — fine */ }
    }
  }

  /**
   * The `[data-tip]` ancestor of an event target — SVG-safe.
   *
   * Inside an inline <svg> the event target is an SVG element, and SVGElement
   * did not get .closest() until relatively recently; where it is missing, or
   * where the node is a text node, `ev.target.closest(…)` either throws or
   * silently returns nothing. A tooltip on a glyph-only button — which is the
   * ONLY place the house rules allow one — is exactly an <svg> inside the
   * trigger, so hovering the icon did nothing while focusing it worked. Walking
   * parentNode by hand covers every case.
   */
  const tipTarget = (node) => {
    let n = node;
    while (n && n !== document) {
      if (n.nodeType === 1 && typeof n.hasAttribute === 'function' && n.hasAttribute('data-tip')) return n;
      n = n.parentNode || (n.ownerSVGElement || null);
    }
    return null;
  };

  document.addEventListener('mouseover', (ev) => {
    const el = tipTarget(ev.target);
    if (!el || el === activeEl) return;
    show(el);
  });
  document.addEventListener('mouseout', (ev) => {
    const el = tipTarget(ev.target);
    if (!el || el !== activeEl) return;
    if (ev.relatedTarget && el.contains(ev.relatedTarget)) return;
    hide();
  });
  document.addEventListener('focusin', (ev) => {
    const el = tipTarget(ev.target);
    if (el) show(el);
  });
  document.addEventListener('focusout', (ev) => {
    const el = tipTarget(ev.target);
    if (el && el === activeEl) hide();
  });
  /* A LIVE data-tip. `show()` reads the attribute ONCE, which is right for a
     label that never changes and wrong for the controls in here that flip job
     under the pointer — the sidebar's collapse-all rewrites its own tip on the
     click that follows the hover, i.e. while the bubble is open. Without this
     the tooltip keeps the word the button no longer means: "Expand all" over a
     button that now collapses. Caught on the first render of it.

     Scoped to the ONE attribute and re-shown only when the mutated node is the
     node currently being described, so an unhovered control rewriting its tip
     (the rail toggle does, on every collapse) costs nothing. */
  const liveTips = new MutationObserver((records) => {
    for (const r of records) {
      if (r.target === activeEl) { show(activeEl); return; }
    }
  });
  liveTips.observe(document.body, {
    subtree: true,
    attributes: true,
    attributeFilter: ['data-tip'],
  });

  // Hide on scroll/resize so a stale tooltip doesn't lag behind its trigger.
  window.addEventListener('scroll', () => { if (activeEl) hide(); }, true);
  window.addEventListener('resize', () => { if (activeEl) hide(); });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initTooltips);
} else {
  initTooltips();
}
