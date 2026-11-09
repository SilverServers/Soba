<?php

declare(strict_types=1);

namespace Cms\Html;

use DOMElement;

/**
 * A PROVIDER WHOSE EMBED CODE IS A `<blockquote>` that the provider's own
 * script turns into the post. Until the script runs, and wherever it is
 * blocked, the blockquote is what shows. What `Html\Sanitizer` asks of one
 * before it keeps a quote; `Html\Embed` lists them.
 */
interface EmbedQuote
{
    /** Is this blockquote one of this provider's embeds? */
    public static function isQuote(DOMElement $quote): bool;

    /** The tags the provider's code writes, its blockquote included. */
    public static function tags(): array;

    /**
     * May this element of the embed keep this attribute with this value? Only
     * what the provider's code writes, and only the provider's own addresses.
     */
    public static function keeps(string $tag, string $name, string $value): bool;

    /** The provider's own script, each address as its code writes it. */
    public static function scripts(): array;
}
