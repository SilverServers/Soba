/**
 * REPEATING ROWS — one question, as many answers as they need.
 *
 * ── THE ROW INDEX IS REWRITTEN ON EVERY CHANGE ─────────────────────────────
 * Every input is `field[row][column]` and PHP rebuilds the array from those
 * indices, not from the order they appear in the request. So adding a row and
 * then deleting an earlier one would leave `[0]` and `[2]` — which PHP reads
 * as a two-element array with gaps and every reader downstream has to guess
 * about. `renumber()` runs after both.
 *
 * ── ONE ROW IS ALREADY IN THE MARKUP ───────────────────────────────────────
 * Server-rendered, so a visitor with no JavaScript can still answer once. That
 * is a smaller answer than they might have given and it is a real one;
 * rendering none would leave the question with no box under it.
 */
(function (window, document) {
    'use strict';

    Array.prototype.forEach.call(document.querySelectorAll('[data-repeater]'), function (box) {
        var rows = box.querySelector('[data-rep-rows]');
        var add  = box.querySelector('[data-rep-add]');
        var name = box.getAttribute('data-repeater-name') || '';
        if (!rows || !add || !name) { return; }

        function renumber() {
            var all = rows.querySelectorAll('[data-rep-row]');
            Array.prototype.forEach.call(all, function (row, i) {
                Array.prototype.forEach.call(row.querySelectorAll('input[name]'), function (input) {
                    // `field[3][City]` → `field[0][City]`. Only the ROW index
                    // moves; the column is the author's own label.
                    input.name = input.name.replace(/\[\d+\]/, '[' + i + ']');
                });
                // Ids have to stay unique or every label points at the first
                // row's box — clicking "City" on row three would focus row one.
                Array.prototype.forEach.call(row.querySelectorAll('input[id]'), function (input, c) {
                    var id = input.id.replace(/-\d+-\d+$/, '-' + i + '-' + c);
                    var label = row.querySelector('label[for="' + input.id + '"]');
                    input.id = id;
                    if (label) { label.setAttribute('for', id); }
                });
            });
            // REMOVE IS HIDDEN ON THE ONLY ROW. A control that empties the
            // question is not a control, it is a trap.
            Array.prototype.forEach.call(rows.querySelectorAll('[data-rep-kill]'), function (k) {
                k.hidden = all.length < 2;
            });
        }

        add.addEventListener('click', function () {
            var first = rows.querySelector('[data-rep-row]');
            if (!first) { return; }
            var copy = first.cloneNode(true);
            // A CLONE CARRIES THE FIRST ROW'S ANSWERS. Emptied, or "Add
            // another" duplicates what they typed and they have to clear it.
            Array.prototype.forEach.call(copy.querySelectorAll('input'), function (i) {
                i.value = '';
            });
            rows.appendChild(copy);
            renumber();
            var focusable = copy.querySelector('input');
            if (focusable) { focusable.focus(); }
        });

        rows.addEventListener('click', function (e) {
            var kill = e.target.closest('[data-rep-kill]');
            if (!kill) { return; }
            var row = kill.closest('[data-rep-row]');
            if (row && rows.querySelectorAll('[data-rep-row]').length > 1) {
                row.remove();
                renumber();
            }
        });

        renumber();
    });
})(window, document);
