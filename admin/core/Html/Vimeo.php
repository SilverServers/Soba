<?php

declare(strict_types=1);

namespace Cms\Html;

/**
 * A VIMEO EMBED. A writer copies a video's embed code from Vimeo (Share →
 * Embed) and pastes it into a body; the editor shows the video, saving keeps
 * the embed code, and every theme prints it as it is: Vimeo's own player.
 *
 * Vimeo's code is the frame alone at a fixed size, or the frame filling a box
 * whose padding keeps the video's shape at any width, and in either case
 * Vimeo's player script after it. The box and the frame keep only the styles
 * Vimeo writes on them, and the frame's only inside the box: on its own,
 * `position:absolute` would cover whatever it landed in.
 */
final class Vimeo implements EmbedFrame
{
    /** The attributes Vimeo's embed code writes on its <iframe>, older codes' included. Nothing else is kept. */
    public const ATTRIBUTES = [
        'src', 'width', 'height', 'frameborder', 'allow', 'referrerpolicy', 'style', 'title',
        'allowfullscreen', 'webkitallowfullscreen', 'mozallowfullscreen',
    ];

    /** The frame filling Vimeo's box. */
    private const FILL = 'position:absolute;top:0;left:0;width:100%;height:100%';

    /**
     * Is this the `src` of a Vimeo embed? A video on Vimeo's player host,
     * with whatever query Vimeo put on it (a private video's `h=` among it),
     * or a showcase or a live event on vimeo.com.
     */
    public static function isEmbed(string $src): bool
    {
        return Embed::address($src, ['player.vimeo.com'], '#^/video/\d+/?$#')
            || Embed::address($src, ['vimeo.com'], '#^/(?:showcase/\d+/embed|event/\d+/embed(?:/[A-Za-z0-9]+)?)/?$#');
    }

    public static function attributes(): array
    {
        return self::ATTRIBUTES;
    }

    public static function frameStyle(string $style, bool $wrapped): bool
    {
        return $wrapped && Embed::style($style) === self::FILL;
    }

    /** The box: its top padding the video's height as a share of its width, and the frame's frame of reference. */
    public static function boxStyle(string $style): bool
    {
        return preg_match('/^padding:\d{1,3}(?:\.\d{1,4})?% 0 0 0;position:relative$/', Embed::style($style)) === 1;
    }

    public static function scripts(): array
    {
        return ['https://player.vimeo.com/api/player.js'];
    }
}
