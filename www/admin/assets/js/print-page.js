/**
 * THE PRINT BUTTON on a page made to be printed (an order's packing slip or
 * invoice). The browser's own Print does the same; this puts it where the
 * person is looking.
 *
 * ES5, no build step, no dependency.
 */
(function () {
    'use strict';

    var button = document.querySelector('[data-print]');
    if (button) {
        button.addEventListener('click', function () { window.print(); });
    }
}());
