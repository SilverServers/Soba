/* PRODUCT — a chooser for each thing a customer picks, and the photo that follows.
   ════════════════════════════════════════════════════════════════════════════

   ── WHAT THE SERVER ALREADY DREW ────────────────────────────────────────────
   One select of every option ("M / Navy", "XL / Moss — sold out"), which is
   what posts `variation_id` and is the whole form without this file. Each of
   its options carries what this needs: the terms it is made of, whether it
   can be bought, its price and regular price as the shop formats them, how
   many are left, and its photo.

   Beside it, hidden, a select per attribute — Size, Colour — listing the terms
   this product's options use, in the taxonomy's own order.

   ── WHAT THIS DOES ──────────────────────────────────────────────────────────
   Shows the per-attribute selects, hides the combined one, and keeps the two in
   step: once every attribute is chosen, the matching option becomes the value
   the form posts. A choice no option can be bought in says "sold out" beside
   it, given what else is already chosen, so a customer sees that XL in Moss
   exists and is gone rather than that it was never made.

   It decides nothing. `Commerce\Cart` re-reads the option, its price and its
   stock on the server, so a crafted POST of a sold-out option is refused there
   exactly as the combined select's own disabled row would refuse it here.

   ── AND THE PHOTOS ──────────────────────────────────────────────────────────
   The thumbnails under the main photo swap it, and an option with a photo of
   its own puts it there when chosen — as soon as the choice so far names one
   photo, so Grey chosen before a size already shows the grey hoodie. The
   thumbnail of whatever is in the frame is the current one.

   No build step. Plain script tag, deferred. */

(function () {
  'use strict';

  var photo  = document.querySelector('[data-product-photo] img');
  var thumbs = document.querySelectorAll('[data-photo-thumb]');

  function show(url) {
    if (!photo || !url) {
      return;
    }
    if (photo.getAttribute('src') !== url) {
      photo.removeAttribute('srcset');
      photo.src = url;
    }
    for (var k = 0; k < thumbs.length; k++) {
      if (thumbs[k].getAttribute('data-full') === url) {
        thumbs[k].setAttribute('aria-current', 'true');
      } else {
        thumbs[k].removeAttribute('aria-current');
      }
    }
  }

  // THE THUMBNAILS ARE LINKS, so each photo opens without this file. A plain
  // click puts it in the frame instead; a click with a modifier still opens it
  // where the visitor asked.
  for (var t = 0; t < thumbs.length; t++) {
    thumbs[t].addEventListener('click', function (e) {
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || !photo) {
        return;
      }
      e.preventDefault();
      show(this.getAttribute('data-full'));
    });
  }

  var combined = document.querySelector('[data-variant-select]');
  var panel    = document.querySelector('[data-choosers]');
  if (!combined || !panel) {
    return;
  }

  var choosers = panel.querySelectorAll('select[data-chooser]');
  var price    = document.querySelector('[data-product-price]');
  var note     = panel.querySelector('[data-chooser-note]');
  var options  = [];

  for (var i = 0; i < combined.options.length; i++) {
    var o = combined.options[i];
    if (!o.value) {
      continue;
    }
    var terms = {};
    try {
      terms = JSON.parse(o.getAttribute('data-terms') || '{}') || {};
    } catch (err) {
      terms = {};
    }
    options.push({
      el: o,
      terms: terms,
      buy: o.getAttribute('data-buy') === '1',
      price: o.getAttribute('data-price') || '',
      was: o.getAttribute('data-was') || '',
      left: o.getAttribute('data-left') || '',
      image: o.getAttribute('data-image') || ''
    });
  }

  /* Every attribute must name a term for an option to be one of them; an
     option made before attributes existed has no terms and is not offered by
     the choosers at all, which is why the combined select stays the fallback. */
  if (options.length === 0 || !options.some(function (op) { return Object.keys(op.terms).length > 0; })) {
    return;
  }

  // The choosers take over. The combined select stays in the form, because it
  // is what posts, but it is no longer the thing a person fills in.
  panel.hidden = false;
  combined.required = false;
  var combinedWrap = combined.closest('[data-variant-wrap]') || combined;
  combinedWrap.hidden = true;
  for (var c = 0; c < choosers.length; c++) {
    choosers[c].required = true;
    for (var n = 0; n < choosers[c].options.length; n++) {
      var opt = choosers[c].options[n];
      opt.setAttribute('data-name', opt.textContent);
    }
  }

  function chosen() {
    var map = {};
    for (var j = 0; j < choosers.length; j++) {
      if (choosers[j].value) {
        map[choosers[j].getAttribute('data-chooser')] = choosers[j].value;
      }
    }
    return map;
  }

  function matches(op, map, except) {
    for (var key in map) {
      if (Object.prototype.hasOwnProperty.call(map, key) && key !== except
          && String(op.terms[key] || '') !== String(map[key])) {
        return false;
      }
    }
    return true;
  }

  function say(text) {
    if (note) {
      note.textContent = text;
      note.hidden = text === '';
    }
  }

  function setPrice(op) {
    if (!price || !op) {
      return;
    }
    while (price.firstChild) {
      price.removeChild(price.firstChild);
    }
    if (op.was) {
      var del = document.createElement('del');
      del.textContent = op.was;
      price.appendChild(del);
      price.appendChild(document.createTextNode(' '));
    }
    var strong = document.createElement('strong');
    strong.textContent = op.price;
    price.appendChild(strong);
  }

  function refresh() {
    var map = chosen();

    // EACH TERM SAYS WHETHER IT CAN STILL BE HAD, given the others chosen.
    for (var j = 0; j < choosers.length; j++) {
      var key = choosers[j].getAttribute('data-chooser');
      for (var m = 0; m < choosers[j].options.length; m++) {
        var term = choosers[j].options[m];
        if (!term.value) {
          continue;
        }
        var trial = {};
        for (var k in map) {
          if (Object.prototype.hasOwnProperty.call(map, k)) {
            trial[k] = map[k];
          }
        }
        trial[key] = term.value;
        var any = options.some(function (op) { return op.buy && matches(op, trial, ''); });
        term.textContent = term.getAttribute('data-name') + (any ? '' : ' — sold out');
        term.disabled = !any && term.value !== choosers[j].value;
      }
    }

    var complete = Object.keys(map).length === choosers.length;
    var found = null;
    if (complete) {
      for (var p = 0; p < options.length; p++) {
        if (matches(options[p], map, '') && Object.keys(options[p].terms).length === choosers.length) {
          found = options[p];
          break;
        }
      }
    }

    if (!complete) {
      // A CHOICE SO FAR THAT NAMES ONE PHOTO shows it: every option still in
      // play has the same one. Nothing chosen changes nothing.
      var shared = '';
      for (var s = 0; s < options.length && Object.keys(map).length; s++) {
        if (!matches(options[s], map, '')) {
          continue;
        }
        if (!options[s].image || (shared && shared !== options[s].image)) {
          shared = '';
          break;
        }
        shared = options[s].image;
      }
      show(shared);
      combined.value = '';
      say('');
      return;
    }
    if (!found) {
      combined.value = '';
      say('Not made in that combination.');
      return;
    }

    show(found.image);
    setPrice(found);
    if (!found.buy) {
      combined.value = '';
      say('Sold out.');
      return;
    }
    combined.value = found.el.value;
    var left = parseInt(found.left, 10);
    say(left > 0 && left <= 5 ? 'Only ' + left + ' left.' : '');
  }

  for (var r = 0; r < choosers.length; r++) {
    choosers[r].addEventListener('change', refresh);
  }

  // A SUBMIT WITH NOTHING BUYABLE CHOSEN goes nowhere: every chooser is
  // `required`, and a complete choice of something sold out leaves the posted
  // select empty, which the server refuses with a sentence anyway.
  var form = combined.form;
  if (form) {
    form.addEventListener('submit', function (e) {
      if (!combined.value) {
        e.preventDefault();
        refresh();
        if (note && note.hidden) {
          say('Choose one of each.');
        }
      }
    });
  }

  refresh();
}());
