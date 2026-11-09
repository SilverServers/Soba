<?php

declare(strict_types=1);

namespace Cms\Html;

/**
 * A PROVIDER WHOSE EMBED CODE IS AN `<iframe>`: the provider's own player or
 * post, drawn from its own address. What `Html\Sanitizer` asks of one before
 * it keeps a frame; `Html\Embed` lists them.
 */
interface EmbedFrame
{
    /** Is this the `src` of one of this provider's embeds? */
    public static function isEmbed(string $src): bool;

    /** The attributes the provider's embed code writes on its frame. Nothing else is kept. */
    public static function attributes(): array;

    /**
     * Is this a `style` the provider's code writes on its frame? `$wrapped`
     * when the frame sits in the provider's own box (`boxStyle()`).
     */
    public static function frameStyle(string $style, bool $wrapped): bool;

    /** Is this the `style` of the provider's own box round its frame? */
    public static function boxStyle(string $style): bool;

    /** The provider's own script, each address as its code writes it. */
    public static function scripts(): array;
}
