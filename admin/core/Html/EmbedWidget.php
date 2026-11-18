<?php

declare(strict_types=1);

namespace Cms\Html;

use DOMElement;

/**
 * A PROVIDER WHOSE EMBED CODE IS AN EMPTY ELEMENT ITS OWN SCRIPT FILLS: a
 * `<div>` naming what to draw, with the provider's script after it (Calendly's
 * booking page), or the script tag itself, which puts the provider's form
 * where it stands (Kit). Until the script runs, and wherever it is blocked,
 * nothing shows there. What `Html\Sanitizer` asks of one before it keeps it;
 * `Html\Embed` lists them.
 */
interface EmbedWidget
{
    /** Is this element one of this provider's embeds, as its code writes it? */
    public static function isWidget(DOMElement $el): bool;

    /** May the element keep this attribute with this value? Only what the provider's code writes. */
    public static function keepsOnWidget(string $name, string $value): bool;

    /** The provider's own script, each address as its code writes it; none when the script is the embed. */
    public static function scripts(): array;
}
