/**
 * RANKING — the visitor drags the options into their order.
 *
 * ── THE ORDER IS THE ANSWER, AND THE INDICES ARE THE ORDER ─────────────────
 * Each option carries a hidden input named `field[0]`, `field[1]`, … and PHP
 * rebuilds the array from THOSE INDICES, not from the order the elements
 * happen to appear in the request. So a drag that only moved DOM nodes would
 * look right on screen, submit, and store the order the author typed — a
 * silent no-op. Every drop rewrites the indices.
 *
 * That is the same discipline `field-group-form.js` uses for the same reason,
 * and it is why `onEnd` here does more than repaint.
 *
 * ── IT DEGRADES TO A REAL ANSWER, NOT TO NOTHING ───────────────────────────
 * The hidden inputs are in the markup in the author's order, so a visitor with
 * no JavaScript posts that order. It is a ranking nobody chose, but it is an
 * answer, it validates, and the form submits. A control that silently posts
 * empty is worse than one that posts a default.
 *
 * ── ONE DRAG SYSTEM ────────────────────────────────────────────────────────
 * `reorder.js` is the admin's own file, served here as a byte-identical copy
 * of the admin's. The drag a visitor does is the drag the owner does —
 * mirror, dotted line, hit-testing that works in the gaps. A second
 * implementation for the front end would be a second drag system to keep in
 * step with the first.
 */
(function (window, document) {
    'use strict';

    if (!window.Reorder || typeof window.Reorder.create !== 'function') {
        // LOUD, NOT SILENT. A ranking that quietly stops reordering reads to
        // the person using it as though they mis-dragged.
        if (document.querySelector('[data-ranking]')) {
            window.console && console.error(
                'ranking: reorder.js did not load — the options cannot be reordered.');
        }
        return;
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-ranking]'), function (list) {
        function reindex() {
            Array.prototype.forEach.call(list.querySelectorAll('[data-rank]'), function (row, i) {
                var input = row.querySelector('input[type="hidden"]');
                if (input) {
                    // `field[3]` → `field[0]`. The name up to the bracket is
                    // the field's; only the index moves.
                    input.name = input.name.replace(/\[\d+\]$/, '[' + i + ']');
                }
                // AND THE NUMBER ON SCREEN. It is the answer, so a row that
                // says 1 while sitting third is the screen lying about what is
                // about to be submitted.
                var num = row.querySelector('[data-rank-num]');
                if (num) { num.textContent = String(i + 1); }
            });
        }

        window.Reorder.create(list, {
            handle: '[data-rank-grip]',
            animation: 150,
            onEnd: reindex
        });
    });
})(window, document);
