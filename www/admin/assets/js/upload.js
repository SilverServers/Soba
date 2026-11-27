/**
 * upload.js — one file into the library, whatever its size. Adds `CMS.upload`.
 *
 * Loaded in <head> after admin.js, so every screen that uploads (the library,
 * a file's details, the picker in entry-form.js) sends a file the same way.
 *
 * **Why pieces.** PHP takes no request larger than its own `post_max_size`
 * and no file larger than `upload_max_filesize`, often 2 or 8 MB on shared
 * hosting, whatever the site's own limit. A file that fits one request goes
 * whole, as it always has; a larger one goes in pieces, each a request of its
 * own, one after the other (`Media\Pieces`). Both sizes come from the head's
 * `upload-limits` (`partials/head.php`): the site's largest file, then the
 * size of a piece.
 *
 * **Why HEIC is drawn first.** A phone's photo is often HEIC, which PHP can
 * seldom read on shared hosting (GD has no decoder, and Imagick has one only
 * where ImageMagick was built with libheif). Where the browser can draw one,
 * as Safari can, it is drawn and sent as a JPEG; where it cannot, the upload
 * is refused in a sentence that says what to do instead.
 *
 * ES5, no build step, no dependency, as every other admin script.
 */
(function () {
    'use strict';

    var CMS = window.CMS;
    if (!CMS) { return; }

    /** What a HEIC photo is told where the browser cannot draw one. */
    var HEIC_REFUSED = 'This browser cannot turn a HEIC photo into a JPEG. Upload it from Safari, or save it as a JPEG first.';

    /** The site's largest file and the size of a piece, in bytes, from the head. */
    CMS.uploadLimits = function () {
        var el    = document.querySelector('meta[name="upload-limits"]');
        var parts = ((el && el.getAttribute('content')) || '').split(' ');
        var max   = parseInt(parts[0], 10);
        var piece = parseInt(parts[1], 10);

        return { max: max > 0 ? max : 10485760, piece: piece > 0 ? piece : 1048576 };
    };

    /** Is this a HEIC or HEIF photo, by its type or, where a system gives none, its name? */
    function isHeic(file) {
        return /^image\/hei[cf](-sequence)?$/i.test(file.type || '') || /\.hei[cf]$/i.test(file.name || '');
    }

    /**
     * A HEIC photo drawn and kept as a JPEG of the same size, its name ending
     * .jpg. The browser draws it the right way up from its own orientation.
     */
    function heicToJpeg(file) {
        return new Promise(function (resolve, reject) {
            var address = URL.createObjectURL(file);
            var img     = new Image();

            img.onload = function () {
                var canvas = document.createElement('canvas');
                canvas.width  = img.naturalWidth;
                canvas.height = img.naturalHeight;
                var drawn = canvas.getContext('2d');
                if (!drawn || !canvas.width || !canvas.height) {
                    URL.revokeObjectURL(address);
                    reject(new Error(HEIC_REFUSED));
                    return;
                }
                drawn.drawImage(img, 0, 0);
                URL.revokeObjectURL(address);
                canvas.toBlob(function (blob) {
                    if (!blob) { reject(new Error(HEIC_REFUSED)); return; }
                    var name = (file.name || 'photo').replace(/\.hei[cf]$/i, '') + '.jpg';
                    resolve(new File([blob], name, { type: 'image/jpeg', lastModified: file.lastModified }));
                }, 'image/jpeg', 0.92);
            };
            img.onerror = function () {
                URL.revokeObjectURL(address);
                reject(new Error(HEIC_REFUSED));
            };
            img.src = address;
        });
    }

    /**
     * POST a form and get the payload back, as `CMS.post` does — the CSRF
     * header, `{success, data}` unwrapped, the server's own sentence on a
     * refusal — over XMLHttpRequest, because fetch cannot say how much of a
     * request has been sent.
     */
    function send(path, body, onSent) {
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', CMS.url(path));
            xhr.setRequestHeader('X-CSRF-Token', CMS.csrf());
            if (onSent && xhr.upload) {
                xhr.upload.onprogress = function (e) {
                    if (e.lengthComputable) { onSent(e.loaded, e.total); }
                };
            }
            xhr.onload = function () {
                var payload = null;
                try { payload = JSON.parse(xhr.responseText); } catch (err) { payload = null; }
                if (!payload || typeof payload !== 'object') {
                    // A 413 from the web server, a 500 with an HTML error page,
                    // or a redirect to the sign-in screen: none of them is JSON.
                    reject(new Error('The server did not answer properly (' + xhr.status + ').'));
                    return;
                }
                if (payload.success === false) {
                    reject(new Error(payload.error || 'Something went wrong.'));
                    return;
                }
                resolve(Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload);
            };
            xhr.onerror = function () { reject(new Error('The upload stopped: the connection was lost. Send the file again.')); };
            xhr.send(body);
        });
    }

    /** 32 hex characters, the upload's name for its pieces. */
    function uploadName() {
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        var out = '';
        for (var i = 0; i < bytes.length; i++) { out += (bytes[i] < 16 ? '0' : '') + bytes[i].toString(16); }
        return out;
    }

    /** The fields beside the file, `replace` aside, which says where it goes. */
    function withFields(body, fields) {
        for (var key in fields) {
            if (Object.prototype.hasOwnProperty.call(fields, key) && key !== 'replace'
                && fields[key] !== null && fields[key] !== undefined && fields[key] !== '') {
                body.append(key, fields[key]);
            }
        }
        return body;
    }

    /** A file too large for one request, a piece at a time, each once the last is answered. */
    function inPieces(file, fields, size, progress) {
        var count  = Math.ceil(file.size / size);
        var upload = uploadName();
        var index  = 0;

        function next() {
            var body = withFields(new FormData(), fields);
            body.append('piece', file.slice(index * size, Math.min(file.size, (index + 1) * size)), 'piece');
            body.append('upload', upload);
            body.append('index', String(index));
            body.append('count', String(count));
            body.append('name', file.name);
            body.append('size', String(file.size));
            if (fields.replace) { body.append('replace', String(fields.replace)); }

            return send('/api/v1/media/upload/piece', body, function (loaded, total) {
                if (progress) { progress(Math.min(1, (index * size + Math.min(loaded, total)) / file.size)); }
            }).then(function (data) {
                index++;
                if (data && data.done) { return data; }
                if (index >= count) { throw new Error('The file did not arrive whole. Send it again.'); }
                return next();
            });
        }

        return next();
    }

    /**
     * ONE FILE INTO THE LIBRARY: whole when it fits one request, else in
     * pieces; a HEIC photo as a JPEG first; one over the site's limit refused
     * before a byte is sent, in the server's own words.
     *
     * @param {File}     file
     * @param {Object}   [fields]   folder_id, visibility, alt_text as an upload
     *                              takes them; or `replace`, a library file's id,
     *                              to put this one in its place, as Replace does
     * @param {Function} [progress] called with the share sent so far, 0 to 1
     * @returns {Promise<Object>}   the file, as the media API gives one
     */
    CMS.upload = function (file, fields, progress) {
        fields = fields || {};

        var ready = isHeic(file) ? heicToJpeg(file) : Promise.resolve(file);

        return ready.then(function (f) {
            var limits = CMS.uploadLimits();
            if (f.size > limits.max) {
                throw new Error('File exceeds the ' + Math.round(limits.max / 1048576) + ' MB limit.');
            }
            if (f.size > limits.piece) {
                return inPieces(f, fields, limits.piece, progress);
            }
            var body = withFields(new FormData(), fields);
            body.append('file', f);

            return send(fields.replace ? '/api/v1/media/' + fields.replace + '/replace' : '/api/v1/media/upload', body,
                function (loaded, total) { if (progress) { progress(total ? Math.min(1, loaded / total) : 0); } });
        });
    };
}());
