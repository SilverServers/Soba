/**
 * SIGNATURE — the visitor draws, and a PNG goes into a hidden input.
 *
 * ── POINTER EVENTS, FOR THE SAME REASON EVERYTHING ELSE HERE USES THEM ─────
 * A signature is the one field almost nobody fills in with a mouse. Pointer
 * events unify mouse, pen and touch, so a finger on a tablet and a stylus draw
 * through one code path — and `touch-action: none` on the canvas is what stops
 * the browser scrolling the page instead of drawing on it.
 *
 * ── THE CANVAS IS SIZED IN DEVICE PIXELS ───────────────────────────────────
 * A canvas has two sizes: its CSS box and its bitmap. Left equal on a 2x
 * display the signature is drawn at half resolution and stored blurry, which
 * looks like a bad scan of a real signature — the one thing a signature must
 * not look like.
 */
(function (window, document) {
    'use strict';

    Array.prototype.forEach.call(document.querySelectorAll('[data-signature]'), function (box) {
        var pad   = box.querySelector('[data-signature-pad]');
        var value = box.querySelector('[data-signature-value]');
        var clear = box.querySelector('[data-signature-clear]');
        if (!pad || !value) { return; }

        var ctx     = pad.getContext('2d');
        var drawing = false;
        var drawn   = false;

        function resize() {
            var ratio = window.devicePixelRatio || 1;
            var rect  = pad.getBoundingClientRect();
            if (!rect.width) { return; }
            pad.width  = Math.round(rect.width * ratio);
            pad.height = Math.round(rect.height * ratio);
            ctx.scale(ratio, ratio);
            // The ink. `currentColor` is not available to a canvas, so the
            // stroke is read off the element — one place decides the colour and
            // it is the stylesheet, like everything else.
            ctx.lineWidth   = 2;
            ctx.lineCap     = 'round';
            ctx.lineJoin    = 'round';
            ctx.strokeStyle = window.getComputedStyle(pad).color || '#000';
        }

        function at(e) {
            var r = pad.getBoundingClientRect();
            return { x: e.clientX - r.left, y: e.clientY - r.top };
        }

        pad.addEventListener('pointerdown', function (e) {
            drawing = true;
            pad.setPointerCapture(e.pointerId);
            var p = at(e);
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
            e.preventDefault();
        });

        pad.addEventListener('pointermove', function (e) {
            if (!drawing) { return; }
            var p = at(e);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            drawn = true;
            e.preventDefault();
        });

        function stop() {
            if (!drawing) { return; }
            drawing = false;
            // WRITTEN ON EVERY STROKE END, not on submit. A `submit` handler
            // that fills the input races the AJAX one that reads it, and which
            // runs first depends on the order they were bound — the kind of
            // bug that works until somebody adds a listener.
            value.value = drawn ? pad.toDataURL('image/png') : '';
        }

        pad.addEventListener('pointerup', stop);
        pad.addEventListener('pointercancel', stop);
        pad.addEventListener('pointerleave', stop);

        if (clear) {
            clear.addEventListener('click', function () {
                ctx.clearRect(0, 0, pad.width, pad.height);
                drawn = false;
                value.value = '';
            });
        }

        // RESIZING CLEARS THE CANVAS — setting `width` resets the bitmap. That
        // is why it happens once at setup and on an actual width change, not on
        // every resize event: a phone's address bar sliding away must not wipe
        // a signature somebody just drew.
        var lastWidth = 0;
        function maybeResize() {
            var w = Math.round(pad.getBoundingClientRect().width);
            if (w === lastWidth) { return; }
            lastWidth = w;
            resize();
            drawn = false;
            value.value = '';
        }
        maybeResize();
        window.addEventListener('resize', maybeResize);
    });
})(window, document);
