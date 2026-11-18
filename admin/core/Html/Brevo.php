<?php

declare(strict_types=1);

namespace Cms\Html;

/**
 * A BREVO SIGN-UP FORM. A writer copies the form's iframe code from Brevo (the
 * form's Share step, Iframe); the page shows Brevo's own form in its frame, and
 * what a visitor types goes to Brevo, as on any other site. There is no
 * script: the frame is the whole of it.
 */
final class Brevo implements EmbedFrame
{
    /** The attributes Brevo's iframe code writes. Nothing else is kept. */
    public const ATTRIBUTES = ['src', 'width', 'height', 'frameborder', 'scrolling', 'allowfullscreen', 'style'];

    /** The frame centred, never wider than the page: the one style Brevo's code writes. */
    private const CENTRED = 'display:block;margin-left:auto;margin-right:auto;max-width:100%';

    /** A form served from Brevo's form host, on the account's own name there or without one. */
    public static function isEmbed(string $src): bool
    {
        return Embed::addressOn($src, '/^(?:[0-9a-f]{8}\.)?sibforms\.com$/', '#^/serve/[A-Za-z0-9_-]+/?$#');
    }

    public static function attributes(): array
    {
        return self::ATTRIBUTES;
    }

    public static function frameStyle(string $style, bool $wrapped): bool
    {
        return Embed::style($style) === self::CENTRED;
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
