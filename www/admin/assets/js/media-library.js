/**
 * MEDIA LIBRARY — uploading from the library page.
 *
 * The picker dialog in `entry-form.js` already uploads, and this does NOT
 * duplicate it: both post to `/api/v1/media/upload` through `CMS.post`, which
 * owns the CSRF header and the envelope unwrapping. The difference is what
 * happens next — the picker sets a field and closes; the library has nothing to
 * set, so it reloads and lets the server render the new row.
 *
 * RELOADING RATHER THAN PREPENDING A TILE IN JS is deliberate. A tile built here
 * would be a second renderer for `media/list.php`'s markup, and the two would
 * disagree the first time a card gained an element — the same fault the repeater
 * comment in entry-form.js describes. The server already knows how to draw one.
 *
 * MULTIPLE FILES UPLOAD ONE AT A TIME, in sequence. The endpoint takes a single
 * `file`, and firing N parallel requests at a PHP-FPM pool on shared hosting is
 * how you turn "I dragged in 30 photos" into a pool exhaustion. Sequential is
 * slower and finishes.
 */
(function () {
    'use strict';

    var doc = document;

    function upload(input) {
        var files = input.files ? Array.prototype.slice.call(input.files) : [];
        if (files.length === 0) { return; }

        var done = 0;
        var failed = 0;

        // The control is disabled for the duration. Without it a second pick
        // mid-run starts an interleaved sequence against the same input, and
        // `input.value = ''` at the end of one run clears the other's queue.
        input.disabled = true;

        function next() {
            if (files.length === 0) {
                input.disabled = false;
                input.value = '';

                if (failed > 0 && done === 0) {
                    // Nothing landed, so there is nothing new to show and a
                    // reload would just redraw the same screen while looking
                    // like it did something.
                    return;
                }
                window.location.reload();
                return;
            }

            var file = files.shift();
            var body = new FormData();
            body.append('file', file);

            CMS.post('/api/v1/media/upload', body)
                .then(function () { done++; next(); })
                .catch(function (err) {
                    failed++;
                    // NAMED, because "Upload failed" on a 30-file drop does not
                    // tell you which one to fix.
                    CMS.flash(file.name + ': ' + err.message, 'error');
                    next();
                });
        }

        next();
    }

    doc.addEventListener('change', function (e) {
        var input = e.target;
        if (input && input.matches && input.matches('[data-media-upload]')) {
            upload(input);
        }
    });
}());
