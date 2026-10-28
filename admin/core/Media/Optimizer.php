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
