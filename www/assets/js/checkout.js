/* CHECKOUT — the delivery-address toggle, the province field, and the figures.
   ════════════════════════════════════════════════════════════════════════════

   ── WHAT THIS DOES NOT DO ───────────────────────────────────────────────────
   It does not validate, total, price or submit anything. Every one of those is
   decided on the server by `Commerce\Checkout`, because a checkout that trusts
   the browser is a checkout a crafted POST walks straight through. When the
   province changes it ASKS the server for the figures (`/checkout/totals`)
   and puts them in place; it works none out. The rule
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

/* ── THE PROVINCE FIELD, AND THE FIGURES THAT FOLLOW IT ───────────────────────
   Each address has a list of provinces or states and a text box, one of them
   hidden (`shop/checkout.php`). The country decides which: Canada and the
   United States get their own list, anywhere else the box. Only that
   country's group of the list is offered, so a Canadian address cannot be
   given a state.

   When the place an order is taxed and delivered for changes — the country,
   the province, which address it goes to, or whether it is collected — the
   totals are drawn again by the server for it. Answers can arrive out of order, so only the latest is put in place. */
(function () {
  'use strict';

  var form = document.querySelector('form.shop-checkout');
  if (!form) { return; }

  var box    = form.querySelector('[data-checkout-totals]');
  var button = form.querySelector('[data-totals-refresh]');
  var fields = form.querySelectorAll('[data-region-field]');
  var asked  = 0;

  function syncRegion(field) {
    var who     = field.getAttribute('data-region-field');
    var country = form.querySelector('[name="' + who + '_country"]');
    var code    = country ? country.value : '';
    var select  = field.querySelector('[data-region-select]');
    var text    = field.querySelector('[data-region-text]');
    var star    = field.querySelector('[data-region-star]');
    var word    = field.querySelector('[data-region-word]');
    var words   = {};
    try { words = JSON.parse(field.getAttribute('data-region-words') || '{}') || {}; } catch (err) { words = {}; }

    var listed = false;
    var groups = select.querySelectorAll('optgroup');
    for (var i = 0; i < groups.length; i++) {
      var mine = groups[i].getAttribute('data-region-country') === code;
      groups[i].hidden = !mine;
      groups[i].disabled = !mine;
      listed = listed || mine;
    }
    // A CHOICE FROM ANOTHER COUNTRY'S LIST IS NO CHOICE: Ontario stays
    // chosen while the country is changed to the United States otherwise.
    var chosen = select.options[select.selectedIndex];
    if (chosen && chosen.parentNode && chosen.parentNode.disabled) {
      select.value = '';
    }

    select.hidden = !listed;
    select.required = listed && field.hasAttribute('data-region-required');
    text.hidden = listed;
    if (star) { star.hidden = !listed; }
    if (word) { word.textContent = words[code] || field.getAttribute('data-region-default') || ''; }
  }

  function query() {
    var keys = ['billing_country', 'billing_region', 'billing_region_text',
                'shipping_country', 'shipping_region', 'shipping_region_text'];
    var parts = [];
    for (var i = 0; i < keys.length; i++) {
      var el = form.querySelector('[name="' + keys[i] + '"]');
      if (el) { parts.push(encodeURIComponent(keys[i]) + '=' + encodeURIComponent(el.value)); }
    }
    var ship = form.querySelector('[name="ship_elsewhere"]');
    if (ship && ship.checked) { parts.push('ship_elsewhere=1'); }
    var how = form.querySelector('[name="delivery_method"]:checked');
    if (how) { parts.push('delivery_method=' + encodeURIComponent(how.value)); }
    return parts.join('&');
  }

  function refresh() {
    if (!box || !box.getAttribute('data-checkout-totals')) { return; }
    var mine = ++asked;
    var req  = new XMLHttpRequest();
    req.open('GET', box.getAttribute('data-checkout-totals') + '?' + query());
    req.onload = function () {
      if (mine === asked && req.status === 200) { box.innerHTML = req.responseText; }
    };
    req.send();
  }

  // THE BUTTON IS HOW A PAGE WITHOUT THIS SCRIPT ASKS FOR THE SAME THING, and
  // it is taken out rather than hidden: it comes before "Place order", and
  // Enter in any box presses a form's first submit button, hidden or not.
  if (button && button.parentNode) { button.parentNode.removeChild(button); }

  for (var f = 0; f < fields.length; f++) { syncRegion(fields[f]); }

  form.addEventListener('change', function (e) {
    var name = e.target.name || '';
    if (/^(billing|shipping)_country$/.test(name)) {
      for (var i = 0; i < fields.length; i++) { syncRegion(fields[i]); }
    }
    if (/^(billing|shipping)_(country|region)$/.test(name) || name === 'ship_elsewhere' || name === 'delivery_method') {
      refresh();
    }
  });

  // A browser that restored the form on a back-navigation fires no change
  // event, and the figures it drew may be for another address.
  refresh();
})();
