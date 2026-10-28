/**
 * sign-in-address.js — Security → Login protection's Randomize button.
 *
 * Randomize puts an address made at random IN the Address box. The owner keeps
 * it, edits it, or types their own over it; Save takes whatever the box holds.
 *
 *   <button type="button" data-randomize="f-slug" data-words="office desk …"
 *           data-alphabet="abc…" data-length="10">Randomize</button>
 *
 * ── THE SAME SHAPE AS THE SERVER'S ──────────────────────────────────────────
 * The word list, the alphabet and the length are printed onto the button from
 * LoginUrl::WORDS, ::ALPHABET and ::TAIL, the parts LoginUrl::suggest() uses.
 * One list, read in two places, never copied into this file.
 *
 * ── A SECRET, SO NOT Math.random ────────────────────────────────────────────
 * The address is what keeps the sign-in page hidden: crypto.getRandomValues,
 * with draws past the last whole multiple thrown away so no letter comes up
 * more often than another.
 *
 * ── WITH NOTHING STORED ─────────────────────────────────────────────────────
 * The screen suggests an address three ways: the box's placeholder, a hidden
 * field a save with the box left empty takes, and the help line's <code>. The
 * new address becomes the suggestion in all three, so a box emptied again
 * after Randomize still saves exactly the address the screen shows.
 */
(function () {
  'use strict';

  /** An unbiased index below n (n <= 256). */
  function index(n) {
    var limit = 256 - (256 % n);
    var byte = new Uint8Array(1);
    do {
      window.crypto.getRandomValues(byte);
    } while (byte[0] >= limit);
    return byte[0] % n;
  }

  function address(btn) {
    var words = (btn.getAttribute('data-words') || '').split(' ').filter(Boolean);
    var alphabet = btn.getAttribute('data-alphabet') || '';
    var length = parseInt(btn.getAttribute('data-length') || '', 10);
    if (!words.length || !alphabet || !(length > 0)) return '';
    var tail = '';
    for (var i = 0; i < length; i++) tail += alphabet.charAt(index(alphabet.length));
    return words[index(words.length)] + '-' + tail;
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-randomize]');
    if (!btn) return;
    var box = document.getElementById(btn.getAttribute('data-randomize'));
    var made = address(btn);
    if (!box || made === '') return;

    box.value = made;
    if (box.placeholder) box.placeholder = made;
    var kept = box.form ? box.form.querySelector('input[name="slug_suggestion"]') : null;
    if (kept) kept.value = made;
    var shown = document.querySelector('[data-suggestion]');
    if (shown) shown.textContent = made;
    box.dispatchEvent(new Event('input', { bubbles: true }));
  });
})();
