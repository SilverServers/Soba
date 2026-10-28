<?php

declare(strict_types=1);

namespace Cms\Forms;

use Cms\Models\Media;

/**
 * WHAT A STRANGER IS ALLOWED TO PUT ON YOUR SERVER.
 *
 * The admin's uploader (`Controllers\Api\MediaController`) is used by somebody
 * who signed in. This one is used by anybody who can see the form, and the
 * posture is deliberately tighter in four ways. Each is a decision, not a
 * default, so each is written down.
 *
 *   NO SVG. The admin list allows it; this one does not. An SVG is a script
 *   document — `<svg onload>` is a working XSS vector — and the difference
 *   between the two lists is exactly the difference between "a colleague
 *   uploaded a logo" and "a stranger uploaded a file".
 *
 *   NO ARCHIVES. A zip is a container whose contents nothing here inspects,
 *   and an anonymous upload is the wrong place to accept one.
 *
 *   PRIVATE BY DEFAULT. A file goes to the private store, reachable through
 *   `/files/{id}` rather than sitting at a guessable public URL. Somebody
 *   attaching a CV to a job form has not agreed to publish it, and a public
 *   uploads directory is a directory anybody can walk.
 *
 *   SMALLER, AND THE FIELD CAN SHRINK IT FURTHER. 5 MB by default against the
 *   admin's 10, and the field's own Maximum size setting only ever lowers it —
 *   a per-field box that could RAISE the ceiling would be a control that
 *   overrides a security limit, which is not what that box is for.
 *
 * ── THE TYPE COMES FROM THE BYTES ───────────────────────────────────────────
 * `finfo`, never `$_FILES['type']`, which is a header the client writes and can
 * say anything. The extension is then re-derived from the detected type rather
 * than taken from the name, so `payload.php` uploaded as a PNG is stored as a
 * `.png` and cannot be executed by asking for it.
 */
final class Upload
{
    /**
     * What an anonymous visitor may send.
     *
     * @var array<string, string> mime => the extension it is stored under
     */
    public const ALLOWED = [
        'image/jpeg'    => 'jpg',
        'image/png'     => 'png',
        'image/gif'     => 'gif',
        'image/webp'    => 'webp',
        'application/pdf' => 'pdf',
        'text/plain'    => 'txt',
        'text/csv'      => 'csv',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];

    /** The ceiling a field's own setting can lower but never raise. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** `accept` for the file input — a courtesy to the visitor, never a check. */
    public static function acceptAttribute(): string
    {
        return implode(',', array_keys(self::ALLOWED));
    }

    /** The limit in force for one field: its own, clamped to ours. */
    public static function limitFor(array $field): int
    {
        $mb = (float) ($field['max'] ?? 0);
        if ($mb <= 0) {
            return self::MAX_BYTES;
        }

        return (int) min(self::MAX_BYTES, $mb * 1024 * 1024);
    }

    /**
     * Store one uploaded file, or return null with a reason.
     *
     * @param  array<string, mixed> $file   one entry of `$_FILES`
     * @param  array<string, mixed> $field  the field definition, for its limit
     * @param  string|null          $error  set to a visitor-facing message
     * @return int|null                     the attachment id
     */
    public static function accept(array $file, array $field, ?string &$error = null): ?int
    {
        $error = null;

        $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($code === UPLOAD_ERR_NO_FILE) {
            return null;   // nothing sent; the caller decides whether that is an error
        }
        if ($code !== UPLOAD_ERR_OK) {
            // The PHP-side ceilings produce their own codes, and a visitor
            // staring at a form needs to be told which limit they hit rather
            // than "upload failed".
            $error = $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
                ? 'That file is too large.'
                : 'That file could not be uploaded.';
            return null;
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        // NOT `file_exists`. `is_uploaded_file` is what distinguishes a real
        // upload from a path the request talked PHP into naming — the whole
        // point of the function.
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $error = 'That file could not be uploaded.';
            return null;
        }

        $limit = self::limitFor($field);
        if ((int) ($file['size'] ?? 0) > $limit) {
            $error = 'That file is larger than ' . round($limit / 1024 / 1024, 1) . ' MB.';
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo !== false ? (string) finfo_file($finfo, $tmp) : '';
        if ($finfo !== false) {
            finfo_close($finfo);
        }
        if (!isset(self::ALLOWED[$mime])) {
            $error = 'That kind of file is not accepted.';
            return null;
        }

        // THE NAME IS REBUILT, NOT CLEANED. Sanitising the visitor's filename
        // means reasoning about every way a name can be hostile; deriving one
        // means there is nothing to reason about. The extension comes from the
        // DETECTED type, so a PHP file posted as a PNG is stored as a PNG.
        $ext      = self::ALLOWED[$mime];
        $stored   = bin2hex(random_bytes(16)) . '.' . $ext;
        $original = (string) ($file['name'] ?? $stored);

        // PRIVATE. `Media::getUrl()` routes a private attachment through
        // `/files/{id}`, which is a controller and can refuse; the public
        // uploads directory is a directory anybody can walk.
        $dir = PUBLIC_PATH . '/uploads/private';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $error = 'That file could not be stored.';
            return null;
        }
        if (!move_uploaded_file($tmp, $dir . '/' . $stored)) {
            $error = 'That file could not be stored.';
            return null;
        }

        $width = $height = null;
        if (str_starts_with($mime, 'image/')) {
            $size = @getimagesize($dir . '/' . $stored);
            if (is_array($size)) {
                $width  = (int) $size[0];
                $height = (int) $size[1];
            }
        }

        return Media::create([
            'filename'      => $stored,
            'original_name' => $original,
            'mime_type'     => $mime,
            'file_size'     => (int) filesize($dir . '/' . $stored),
            'width'         => $width,
            'height'        => $height,
            'folder_id'     => null,
            'visibility'    => 'private',
            // The alt text a visitor did not write. Naming the form it arrived
            // from is more use in the library than an empty string.
            'alt_text'      => 'Uploaded through a form',
        ]);
    }
}
