<?php

declare(strict_types=1);

namespace Cms\Media;

use Cms\Database\Database;

/**
 * SMALLER WIDTHS OF A PUBLIC IMAGE, for a browser to choose from.
 *
 * The library keeps one file of each image, up to 2560 px wide (`Optimizer`).
 * A phone, or a picture drawn a third of the page wide, downloaded all of it.
 * So an image that enters the library public is also kept at each of these
 * widths narrower than itself, as WebP, beside it: `…_photo.webp` has
 * `…_photo-768w.webp`. `srcset()` lists them with the image itself, and the
 * browser takes the narrowest that fills the space at the screen's density.
 *
 * Made where the original is: an upload or an import (`Media::store()`), a
 * Replace (made again), a file made public; forgotten when the image is
 * deleted or made private. Made with Imagick, as WebP is; a server without it
 * keeps the one file, and `srcset()` names that alone. An image added before
 * these existed gets its widths from `php admin/bin/media-sizes.php`.
 */
final class Sizes
{
    /** The widths kept, narrowest first: a phone, a tablet, a laptop, and twice two of them. */
    public const WIDTHS = [300, 768, 1024, 1536, 2048];

    private const QUALITY = 82;

    /** What is resized: a still raster image. */
    private const IMAGES = ['image/webp', 'image/jpeg', 'image/png'];

    /**
     * Make the widths for an attachment row, after forgetting any it had.
     * True when it now has every width narrower than itself.
     *
     * @param array<string, mixed> $attachment `Media::find()`'s row
     */
    public static function make(array $attachment): bool
    {
        self::forget($attachment);
        $width = (int) ($attachment['width'] ?? 0);
        if (($attachment['visibility'] ?? 'public') !== 'public' || !in_array($attachment['mime_type'] ?? '', self::IMAGES, true)
            || $width <= self::WIDTHS[0] || !extension_loaded('imagick') || \Imagick::queryFormats('WEBP') === []) {
            return false;
        }
        $dir  = PUBLIC_PATH . '/uploads';
        $path = $dir . '/' . $attachment['filename'];
        if (!is_file($path)) {
            return false;
        }

        $made = [];
        try {
            $source = new \Imagick();
            $source->readImage($path);
            if ($source->getNumberImages() > 1) {   // animated: one file it stays
                $source->clear();
                return false;
            }
            $base = pathinfo((string) $attachment['filename'], PATHINFO_FILENAME);
            foreach (self::WIDTHS as $w) {
                if ($w >= $width) {
                    break;
                }
                $h  = max(1, (int) round((int) $attachment['height'] * $w / $width));
                $im = clone $source;
                $im->resizeImage($w, $h, \Imagick::FILTER_LANCZOS, 1);
                $im->setImageFormat('webp');
                $im->setImageCompressionQuality(self::QUALITY);
                $file  = $base . '-' . $w . 'w.webp';
                $bytes = $im->getImageBlob();
                $im->clear();
                if (@file_put_contents($dir . '/' . $file, $bytes) !== strlen($bytes)) {
                    throw new \RuntimeException('could not write ' . $file);
                }
                $made[] = ['width' => $w, 'height' => $h, 'file' => $file];
            }
            $source->clear();
        } catch (\Throwable $e) {
            foreach ($made as $m) {
                @unlink($dir . '/' . $m['file']);
            }
            error_log('CMS media: no smaller widths of attachment ' . (int) $attachment['id'] . ' — ' . $e->getMessage());
            return false;
        }

        self::record((int) $attachment['id'], $made);

        return true;
    }

    /**
     * Delete an attachment's widths, and say it has none.
     *
     * @param array<string, mixed> $attachment
     */
    public static function forget(array $attachment): void
    {
        foreach (self::of($attachment) as $size) {
            $file = PUBLIC_PATH . '/uploads/' . $size['file'];
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (($attachment['sizes'] ?? null) !== null) {
            self::record((int) $attachment['id'], []);
        }
    }

    /**
     * An attachment's widths, narrowest first.
     *
     * @param array<string, mixed> $attachment
     * @return list<array{width: int, height: int, file: string}>
     */
    public static function of(array $attachment): array
    {
        $list = json_decode((string) ($attachment['sizes'] ?? ''), true);
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $s) {
            // A name made here, never a path: nothing outside the uploads folder.
            if (is_array($s) && preg_match('/^[A-Za-z0-9._-]+-\d+w\.webp$/', (string) ($s['file'] ?? '')) === 1) {
                $out[] = ['width' => (int) $s['width'], 'height' => (int) $s['height'], 'file' => (string) $s['file']];
            }
        }

        return $out;
    }

    /**
     * `srcset` for an image: each width's address, then the image itself at
     * its own width. '' for one with no widths, which needs none.
     *
     * @param array<string, mixed>|int $attachment a row, or its id
     */
    public static function srcset(array|int $attachment): string
    {
        $row = is_int($attachment) ? \Cms\Models\Media::find($attachment) : $attachment;
        if ($row === null || ($row['visibility'] ?? 'public') !== 'public') {
            return '';
        }
        $sizes = self::of($row);
        if ($sizes === []) {
            return '';
        }
        $base  = (defined('BASE_URL') ? BASE_URL : '') . '/uploads/';
        $parts = array_map(static fn (array $s): string => $base . rawurlencode($s['file']) . ' ' . $s['width'] . 'w', $sizes);
        $parts[] = $base . rawurlencode((string) $row['filename']) . ' ' . (int) $row['width'] . 'w';

        return implode(', ', $parts);
    }

    /**
     * An `<img>` for an attachment, with its widths for the browser to choose
     * from. `$sizes` says how wide it is drawn ("(min-width: 900px) 33vw,
     * 100vw"); the default is the width of the screen. `$attrs` adds to the
     * tag (class, loading, …), each value escaped. '' for no such image.
     *
     * @param array<string, string> $attrs
     */
    public static function img(int $id, string $sizes = '100vw', array $attrs = []): string
    {
        $row = \Cms\Models\Media::find($id);
        if ($row === null || !str_starts_with((string) ($row['mime_type'] ?? ''), 'image/')) {
            return '';
        }
        $tag = [
            'src'      => \Cms\Models\Media::getUrl($id),
            'srcset'   => self::srcset($row),
            'sizes'    => $sizes,
            'width'    => (int) ($row['width'] ?? 0) > 0 ? (string) (int) $row['width'] : '',
            'height'   => (int) ($row['height'] ?? 0) > 0 ? (string) (int) $row['height'] : '',
            'alt'      => (string) ($row['alt_text'] ?? ''),
            'loading'  => 'lazy',
            'decoding' => 'async',
        ];
        if ($tag['srcset'] === '') {
            unset($tag['srcset'], $tag['sizes']);
        }
        $html = '<img';
        foreach (array_merge($tag, $attrs) as $name => $value) {
            if ($value === '' && $name !== 'alt') {
                continue;
            }
            $html .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
        }

        return $html . '>';
    }

    /** @param list<array{width: int, height: int, file: string}> $sizes */
    private static function record(int $id, array $sizes): void
    {
        Database::getInstance()->prepare('UPDATE attachments SET sizes = ? WHERE id = ?')
            ->execute([$sizes === [] ? null : json_encode($sizes, JSON_UNESCAPED_SLASHES), $id]);
    }
}
