<?php

declare(strict_types=1);

namespace Cms\Html;

/**
 * A FACEBOOK EMBED. A writer copies a post's or a video's embed code from
 * Facebook (its Embed option) and pastes it into a body; the editor shows it,
 * saving keeps the embed code, and every theme prints it as it is: Facebook's
 * own post or player, drawn from Facebook's plugin address.
 */
final class Facebook implements EmbedFrame
{
    /** The attributes Facebook's embed code writes on its <iframe>. Nothing else is kept. */
    public const ATTRIBUTES = [
        'src', 'width', 'height', 'style', 'scrolling', 'frameborder', 'allowfullscreen', 'allow',
        'allowtransparency', 'title',
    ];

    /** The one style Facebook writes on its frame. */
    private const STYLE = 'border:none;overflow:hidden';

    /** Is this the `src` of a Facebook embed? https on www.facebook.com, one of its plugins: a post, a video, a page or a comment. */
    public static function isEmbed(string $src): bool
    {
        return Embed::address($src, ['www.facebook.com'], '#^/plugins/(?:post|video|page|comment_embed)\.php$#');
    }

    public static function attributes(): array
    {
        return self::ATTRIBUTES;
    }

    public static function frameStyle(string $style, bool $wrapped): bool
    {
        return Embed::style($style) === self::STYLE;
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
