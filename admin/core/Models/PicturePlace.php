<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Database\Database;

/**
 * THE PICTURE EACH PICTURE PLACE SHOWS (`Helpers\Places`, kind 'picture'):
 * a spot on the site's own pages, such as the picture at the head of a
 * service's page, that shows one picture an administrator picks from the
 * library. The site's template asks `Places::picture($key)` and draws its own
 * file where none is picked.
 *
 * ONE OPTION PER SPOT, `picture_place_{key}`: the attachment's id, or '0' for
 * none chosen (the options table holds it, an existing store, as field
 * groups attach only to content types). One picture may fill several spots, so the
 * choice cannot be a column on the picture's row as a gallery's place is.
 *
 * A PICTURE DELETED FROM THE LIBRARY leaves its spots empty (`forget()`), as
 * it leaves every gallery, and the site draws its own file there again.
 *
 * A SPOT A ONE-PICTURE GALLERY SHOWED: before picture places, a site gave
 * each spot a gallery place and a gallery of one picture. Once its
 * `places.php` calls the spot a picture place, the first time the spot is read
 * with nothing stored for it, the gallery's first picture becomes the spot's
 * and the gallery leaves the place (`adopt()`), so every spot keeps its
 * picture. The gallery itself stays in the library of galleries.
 */
final class PicturePlace
{
    /** The option key's prefix; the place's key follows it. */
    public const PREFIX = 'picture_place_';

    /** The id of the picture a spot shows, or 0 for none. */
    public static function chosen(string $key): int
    {
        $stored = Option::get(self::PREFIX . $key);
        if ($stored === null) {
            return self::adopt($key);
        }

        return ctype_digit($stored) ? (int) $stored : 0;
    }

    /** Show this picture in the spot, or none (0). The caller has checked the picture. */
    public static function choose(string $key, int $attachmentId): void
    {
        Option::set(self::PREFIX . $key, (string) max(0, $attachmentId));
    }

    /** A picture leaving the library leaves every spot that showed it empty. */
    public static function forget(int $attachmentId): void
    {
        Database::getInstance()->prepare(
            "UPDATE options SET option_value = '0' WHERE option_key LIKE :prefix AND option_value = :id"
        )->execute([':prefix' => self::PREFIX . '%', ':id' => (string) $attachmentId]);
    }

    /**
     * The spot's picture from the gallery that showed it there, moved over:
     * the gallery's first picture is stored as the spot's, and the gallery
     * leaves the place. 0, and nothing stored, when no gallery holds it.
     */
    private static function adopt(string $key): int
    {
        $gallery = Gallery::byPlace($key);
        if ($gallery === null) {
            return 0;
        }
        $id = 0;
        foreach (Gallery::getItems((int) $gallery['id']) as $item) {
            if (Media::image((int) ($item['attachment_id'] ?? 0)) !== null) {
                $id = (int) $item['attachment_id'];
                break;
            }
        }
        self::choose($key, $id);
        Gallery::place((int) $gallery['id'], null);

        return $id;
    }
}
