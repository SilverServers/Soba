<?php

declare(strict_types=1);

namespace Cms\Ui;

use Cms\Models\Media;

/**
 * A GALLERY'S TILES IN THE ADMIN: the Galleries screen's and a gallery
 * field's (`FieldRenderer::renderGallery()`), one markup for both, which
 * `gallery-images.js` drives.
 *
 * THE TILE AREA IS THE GALLERY. Each tile is the picture as the public grid
 * draws it (`cms-gallery__item`, gallery.css), so the editor shows the shape
 * the page will have. The chrome sits over the picture, not in a row below
 * it: its grip, the pen that opens its details and Remove. Under it are the
 * words the page will print, its title on a line of its own, then its
 * caption, as `Shortcode::galleryHtml()` draws them.
 *
 * ITS DETAILS TRAVEL WITH IT: a hidden input for each, inside the tile,
 * named by `$name` with the detail and the picture's id in place of
 * `{detail}` and `{id}` (`item_{detail}[{id}]` on the Galleries screen), so a
 * drag moves them and a remove takes them.
 */
final class GalleryTiles
{
    /** What each detail is called in the pen's name. */
    private const WORDS = ['title' => 'title', 'caption' => 'caption', 'link' => 'link', 'thumb' => 'thumbnail'];

    /**
     * One picture's tile.
     *
     * @param array<string, mixed> $item    as `Gallery::getItems()` gives one: its attachment_id,
     *                                      mime_type, original_name, filename and alt_text, and the
     *                                      details it keeps (title, caption, link_url, thumb_attachment_id)
     * @param list<string>         $details the details it keeps, of title, caption, link and thumb
     */
    public static function tile(array $item, string $name, array $details): string
    {
        $aid      = (int) $item['attachment_id'];
        $url      = Media::getUrl($aid);
        $thumbId  = in_array('thumb', $details, true) ? (int) ($item['thumb_attachment_id'] ?? 0) : 0;
        $thumbUrl = $thumbId > 0 ? Media::getUrl($thumbId) : '';
        $picture  = str_starts_with((string) ($item['mime_type'] ?? ''), 'image/') && $url !== '';

        return self::figure($aid, $picture ? ($thumbUrl !== '' ? $thumbUrl : $url) : '', $url,
            (string) ($item['alt_text'] ?? ''), (string) (($item['original_name'] ?? '') ?: ($item['filename'] ?? '')), [
                'title'   => (string) ($item['title'] ?? ''),
                'caption' => (string) ($item['caption'] ?? ''),
                'link'    => (string) ($item['link_url'] ?? ''),
                'thumb'   => $thumbId > 0 ? (string) $thumbId : '',
            ], $thumbUrl, $name, $details);
    }

    /** The tile `gallery-images.js` copies for a picture added: no picture, words or names yet. */
    public static function template(string $name, array $details): string
    {
        return self::figure(null, '', '', '', '', [], '', $name, $details);
    }

    /**
     * @param array<string, string> $values each detail's value
     * @param list<string>          $details
     */
    private static function figure(?int $aid, string $src, string $url, string $alt, string $label, array $values,
                                   string $thumbUrl, string $name, array $details): string
    {
        $e     = static fn (string $s): string => View::escape($s);
        $has   = static fn (string $detail): bool => in_array($detail, $details, true);
        $title = $has('title') ? ($values['title'] ?? '') : '';
        $words = $values['caption'] ?? '';
        $link  = $values['link'] ?? '';
        $what  = array_map(static fn (string $d): string => self::WORDS[$d] ?? $d, $details);
        $last  = array_pop($what);
        $what  = ucfirst($what === [] ? (string) $last : implode(', ', $what) . ' and ' . $last);

        $html = '<figure class="cms-gallery__item gtile" data-gallery-tile' . ($aid !== null ? ' data-id="' . $aid . '"' : '') . '>';
        // A FILE THAT IS NOT A PICTURE shows its kind, as the library does.
        $html .= $aid === null || $src !== ''
            ? '<img' . ($src !== '' ? ' src="' . $e($src) . '" data-url="' . $e($url) . '"' : '') . ' alt="' . $e($alt) . '" loading="lazy">'
            : '<span class="gtile__kind">' . Icons::render('file', 'icon', '', 24) . '</span>';
        $html .= '<figcaption class="gtile__bar">'
               . '<span class="gtile__grip" data-gallery-grip' . ($label !== '' ? ' title="' . $e($label) . '"' : '') . '>'
               . Icons::render('grip-vertical', 'icon icon--sm') . '</span>';
        // THE LINK MARK SAYS, WITHOUT WORDS, THAT THE TILE GOES SOMEWHERE.
        if ($has('link')) {
            $html .= '<span class="gtile__has-link" data-detail-link title="' . $e($link) . '"' . ($link === '' ? ' hidden' : '') . '>'
                   . Icons::render('link', 'icon icon--sm') . '</span>';
        }
        $html .= '<button type="button" class="gtile__edit" data-gallery-edit aria-label="' . $e($what . ($label !== '' ? ' of ' . $label : '')) . '">'
               . Icons::render('pen', 'icon icon--sm') . '</button>'
               . '<button type="button" class="gtile__kill" data-gallery-remove aria-label="'
               . $e($label !== '' ? 'Remove ' . $label . ' from this gallery' : 'Remove from this gallery') . '">'
               . Icons::render('trash', 'icon icon--sm') . '</button>'
               . '</figcaption>';
        $html .= '<div class="cms-gallery__caption" data-detail-words' . ($title === '' && $words === '' ? ' hidden' : '') . '>';
        if ($has('title')) {
            $html .= '<span class="cms-gallery__title" data-detail-title' . ($title === '' ? ' hidden' : '') . '>' . $e($title) . '</span>';
        }
        $html .= '<span data-detail-caption' . ($words === '' ? ' hidden' : '') . '>' . $e($words) . '</span></div>';
        foreach ($details as $detail) {
            $html .= '<input type="hidden"'
                   . ($aid !== null ? ' name="' . $e(strtr($name, ['{detail}' => $detail, '{id}' => (string) $aid])) . '"' : '')
                   . ' value="' . $e((string) ($values[$detail] ?? '')) . '" data-detail="' . $e($detail) . '"'
                   . ($detail === 'thumb' ? ' data-thumb-url="' . $e($thumbUrl) . '"' : '') . '>';
        }

        return $html . '</figure>';
    }
}
