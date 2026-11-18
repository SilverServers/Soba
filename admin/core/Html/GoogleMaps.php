<?php

declare(strict_types=1);

namespace Cms\Html;

/**
 * A GOOGLE MAP. A writer copies a map's embed code from Google Maps (Share →
 * Embed a map), from a map made in My Maps, or writes Google's Maps Embed API
 * code with a key of the site's own; the page shows Google's own map in its
 * frame, at the size the code gives it. There is no script: the frame is the
 * whole of it.
 */
final class GoogleMaps implements EmbedFrame
{
    /** The attributes Google's embed codes write on the frame, older ones' included. Nothing else is kept. */
    public const ATTRIBUTES = ['src', 'width', 'height', 'style', 'frameborder', 'allowfullscreen', 'loading', 'referrerpolicy'];

    /**
     * A map from Share → Embed a map (/maps/embed?pb=…), from the Maps Embed
     * API (/maps/embed/v1/place?key=…&q=…, and its other modes), or from My
     * Maps (/maps/d/embed?mid=…).
     */
    private const PATH = '#^/maps/(?:embed(?:/v1/(?:place|view|directions|streetview|search))?|d/(?:u/\d+/)?embed)/?$#';

    public static function isEmbed(string $src): bool
    {
        return Embed::address($src, ['www.google.com'], self::PATH);
    }

    public static function attributes(): array
    {
        return self::ATTRIBUTES;
    }

    /** The one style Google's code writes: no border. */
    public static function frameStyle(string $style, bool $wrapped): bool
    {
        return Embed::style($style) === 'border:0';
    }

    public static function boxStyle(string $style): bool
    {
        return false;
    }

    public static function scripts(): array
    {
        return [];
    }
}
