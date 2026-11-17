<?php

declare(strict_types=1);

namespace Cms\Media;

/**
 * NEW IMAGES ARE STORED AS WEBP. An image added to the library — an upload,
 * `Media::importFile()`, or a Replace of an image already stored as WebP —
 * comes through here on its way in:
 *
 *   - turned upright by its EXIF orientation, then its metadata removed: EXIF
 *     (camera, GPS), XMP, IPTC, comments. An RGB colour profile stays, so its
 *     colours do not shift;
 *   - wider than 2560 px, scaled down to 2560 wide — the height follows, and
 *     nothing is ever scaled up;
 *   - encoded as WebP at quality 82, its transparency kept.
 *
 * Stored as they come: an SVG, an animated GIF (an animated PNG or WebP too —
 * its animation would be lost), anything that is not an image, and an image
 * this server cannot make a WebP of (no Imagick with WebP, a file it cannot
 * read, a side past WebP's 16383 px). Images already in the library are never
 * touched.
 */
final class Optimizer
{
    public const MAX_WIDTH = 2560;
    public const QUALITY   = 82;

    /** What is turned into WebP. */
    private const CONVERTS = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** WebP's largest side. */
    private const WEBP_MAX = 16383;

    /**
     * The WebP to store for the file at `$path`, or null to store it as it is.
     *
     * @return array{bytes: string, width: int, height: int}|null
     */
    public static function webp(string $path, string $mime): ?array
    {
        if (!in_array($mime, self::CONVERTS, true) || !extension_loaded('imagick')
            || \Imagick::queryFormats('WEBP') === []) {
            return null;
        }
        $bytes = (string) @file_get_contents($path);
        if ($bytes === '' || self::animated($bytes, $mime)) {
            return null;
        }
        try {
            return self::encode($bytes);
        } catch (\Throwable $e) {
            error_log('CMS media: stored as it came, not as WebP — ' . $e->getMessage());
            return null;
        }
    }

    /**
     * A VISITOR'S PICTURE, WITHOUT WHAT IT SAYS ABOUT THEM. A photo from a
     * phone carries where it was taken (GPS), when, and the phone; a form
     * that keeps it as sent keeps that, and its email hands it on. The file
     * at `$path` is rewritten in place, in the format it came in: turned the
     * way its EXIF says it is shown, then every metadata block removed but an
     * RGB colour profile, as a library image is (`webp()`). Its size is
     * kept: what a visitor sends is evidence, not a page's picture.
     *
     * Through Imagick when it is here, else GD (which keeps no colour
     * profile). A GIF is left as it is, and so is an animated PNG or WebP:
     * neither carries a camera's metadata, and an animation would be lost.
     *
     * True when the file now carries no metadata, or is not a picture this
     * reads; false when it could not be read or rewritten, and the caller
     * should not keep it.
     */
    public static function scrub(string $path, string $mime): bool
    {
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return true;
        }
        $bytes = (string) @file_get_contents($path);
        if ($bytes === '') {
            return false;
        }
        if (self::animated($bytes, $mime)) {
            return true;
        }
        try {
            $out = extension_loaded('imagick') ? self::scrubbedImagick($bytes, $mime) : self::scrubbedGd($bytes, $mime, $path);
        } catch (\Throwable $e) {
            error_log('CMS media: a picture could not be read to remove its metadata — ' . $e->getMessage());
            return false;
        }
        if ($out === null || $out === '') {
            return false;
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $out) !== strlen($out) || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    private static function scrubbedImagick(string $bytes, string $mime): ?string
    {
        $im = new \Imagick();
        $im->readImageBlob($bytes);
        if ($im->getNumberImages() > 1) {
            $im->clear();
            return null;
        }
        $quality = $im->getImageCompressionQuality();
        self::upright($im);
        // The colour profile stays, whatever its colours: it says nothing
        // about the person, and the picture's colours depend on it.
        $icc = $im->getImageProfiles('icc', true)['icc'] ?? null;
        $im->stripImage();
        if (is_string($icc)) {
            $im->profileImage('icc', $icc);
        }
        if ($mime !== 'image/png') {
            // The quality it was saved at, so a second save does not soften it.
            $im->setImageCompressionQuality($quality > 0 ? min($quality, 95) : 90);
        }
        $out = $im->getImageBlob();
        $im->clear();

        return $out;
    }

    /** Without Imagick: GD keeps no metadata at all, colour profile included. */
    private static function scrubbedGd(string $bytes, string $mime, string $path): ?string
    {
        if (!extension_loaded('gd')) {
            return null;
        }
        $im = @imagecreatefromstring($bytes);
        if ($im === false) {
            return null;
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $turn = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
            if ($turn !== 0) {
                $turned = imagerotate($im, $turn, 0);
                if ($turned !== false) {
                    $im = $turned;
                }
            }
        }
        ob_start();
        if ($mime === 'image/png') {
            imagealphablending($im, false);
            imagesavealpha($im, true);
            $ok = imagepng($im);
        } elseif ($mime === 'image/webp') {
            imagealphablending($im, false);
            imagesavealpha($im, true);
            $ok = function_exists('imagewebp') && imagewebp($im, null, 90);
        } else {
            $ok = imagejpeg($im, null, 90);
        }
        $out = (string) ob_get_clean();

        return $ok ? $out : null;
    }

    /** @return array{bytes: string, width: int, height: int}|null */
    private static function encode(string $bytes): ?array
    {
        $im = new \Imagick();
        $im->readImageBlob($bytes);
        if ($im->getNumberImages() > 1) {   // an animated GIF
            $im->clear();
            return null;
        }
        $cmyk = $im->getImageColorspace() === \Imagick::COLORSPACE_CMYK;
        if ($cmyk) {
            $im->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
        }
        self::upright($im);

        // Every profile goes but an RGB colour profile, which is the colours'.
        $icc = $cmyk ? null : ($im->getImageProfiles('icc', true)['icc'] ?? null);
        $im->stripImage();
        if (is_string($icc) && substr($icc, 16, 4) === 'RGB ') {
            $im->profileImage('icc', $icc);
        }

        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        if ($w > self::MAX_WIDTH) {
            $h = max(1, (int) round($h * self::MAX_WIDTH / $w));
            $w = self::MAX_WIDTH;
            $im->resizeImage($w, $h, \Imagick::FILTER_LANCZOS, 1);
        }
        if ($w > self::WEBP_MAX || $h > self::WEBP_MAX) {
            $im->clear();
            return null;
        }
        $im->setImageFormat('webp');
        $im->setImageCompressionQuality(self::QUALITY);
        $out = $im->getImageBlob();
        $im->clear();

        return $out !== '' ? ['bytes' => $out, 'width' => $w, 'height' => $h] : null;
    }

    /** Turned the way its EXIF says it is shown. */
    private static function upright(\Imagick $im): void
    {
        switch ($im->getImageOrientation()) {
            case \Imagick::ORIENTATION_TOPRIGHT:    $im->flopImage(); break;
            case \Imagick::ORIENTATION_BOTTOMRIGHT: $im->rotateImage('#000', 180); break;
            case \Imagick::ORIENTATION_BOTTOMLEFT:  $im->flipImage(); break;
            case \Imagick::ORIENTATION_LEFTTOP:     $im->transposeImage(); break;
            case \Imagick::ORIENTATION_RIGHTTOP:    $im->rotateImage('#000', 90); break;
            case \Imagick::ORIENTATION_RIGHTBOTTOM: $im->transverseImage(); break;
            case \Imagick::ORIENTATION_LEFTBOTTOM:  $im->rotateImage('#000', -90); break;
        }
        $im->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
    }

    /** An animated PNG or WebP, from its bytes (Imagick reads only their first frame). */
    private static function animated(string $bytes, string $mime): bool
    {
        if ($mime === 'image/png') {
            $actl = strpos($bytes, 'acTL');
            return $actl !== false && $actl < (int) strpos($bytes, 'IDAT');
        }
        if ($mime === 'image/webp') {
            return substr($bytes, 12, 4) === 'VP8X' && (ord($bytes[20] ?? "\0") & 0x02) !== 0;
        }

        return false;
    }
}
