/* CHECKOUT — the delivery-address toggle, and nothing else.
   ════════════════════════════════════════════════════════════════════════════

   ── WHAT THIS DOES NOT DO ───────────────────────────────────────────────────
   It does not validate, total, price or submit anything. Every one of those is
   decided on the server by `Commerce\Checkout`, because a checkout that trusts
   the browser is a checkout a crafted POST walks straight through. The rule
   this file follows is the one the form fields already follow: the control
   restricts the browser, never the request.

   ── AND WHY THE FIELDS ARE HIDDEN RATHER THAN REMOVED ───────────────────────
   `hidden` on the wrapper, not `remove()`. A customer who ticks the box, types
   an address, unticks it to compare and ticks it again still has what they
   typed — and a validation failure re-renders the same markup with the same
   values, so the two paths agree.

   The server does not care whether they are hidden: `Checkout::validate()`
   ignores the shipping fields entirely unless `ship_elsewhere` is ticked, and
   `Checkout::place()` blanks them. So hiding is a courtesy to the reader, not a
   part of the logic — which is the only safe kind of client-side behaviour on
   this page.

   No build step. Plain script tag, deferred. */

(function () {
  'use strict';

  var toggle = document.querySelector('[data-ship-toggle]');
  var fields = document.querySelector('[data-ship-fields]');

  if (!toggle || !fields) { return; }

  function sync() {
    fields.hidden = !toggle.checked;
  }

  // Sync on load as well as on change: a validation failure re-renders with the
  // box already ticked, and the server has stamped `hidden` to match. Running
  // it once here means the two can never disagree — including after a browser
  // restores form state on a back-navigation, which fires no change event.
  sync();
  toggle.addEventListener('change', sync);
})();
