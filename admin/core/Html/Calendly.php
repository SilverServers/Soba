<?php

declare(strict_types=1);

namespace Cms\Html;

use DOMElement;

/**
 * A CALENDLY BOOKING PAGE. A writer copies its code from Calendly (Share → Add
 * to website → Inline embed): a `<div class="calendly-inline-widget">` naming
 * the booking page and its size, with Calendly's script after it, which draws
 * Calendly's own page in it. Or Calendly's plain frame of the page. A visitor
 * books on Calendly, as on any other site. The popup kinds are calls of
 * Calendly's script written into a link, and nothing here keeps a script
 * written into a page.
 */
final class Calendly implements EmbedFrame, EmbedWidget
{
    /** The attributes Calendly's plain frame writes. Nothing else is kept. */
    public const ATTRIBUTES = ['src', 'style', 'frameborder', 'width', 'height', 'title'];

    /** A booking page: an account's, an event's, a team's, with whatever query Calendly put on it. */
    private const PAGE = '#^/[A-Za-z0-9][A-Za-z0-9_.-]*(?:/[A-Za-z0-9][A-Za-z0-9_.-]*){0,3}/?$#';

    /** A size, as Calendly's code writes one: widths and heights in pixels or a share of the page. */
    private const SIZE = '/^(?:min-|max-)?(?:width|height):\d{1,4}(?:px|%)(?:;(?:min-|max-)?(?:width|height):\d{1,4}(?:px|%))*$/';

    public static function isEmbed(string $src): bool
    {
        return Embed::address($src, ['calendly.com'], self::PAGE);
    }

    public static function attributes(): array
    {
        return self::ATTRIBUTES;
    }

    public static function frameStyle(string $style, bool $wrapped): bool
    {
        return preg_match(self::SIZE, Embed::style($style)) === 1;
    }

    public static function boxStyle(string $style): bool
    {
        return false;
    }

    /** Calendly's inline widget: its class alone, and a booking page to draw. */
    public static function isWidget(DOMElement $el): bool
    {
        return strtolower($el->nodeName) === 'div'
            && trim($el->getAttribute('class')) === 'calendly-inline-widget'
            && self::isEmbed($el->getAttribute('data-url'));
    }

    public static function keepsOnWidget(string $name, string $value): bool
    {
        return match ($name) {
            'class'       => $value === 'calendly-inline-widget',
            'data-url'    => self::isEmbed($value),
            'style'       => preg_match(self::SIZE, Embed::style($value)) === 1,
            'data-resize' => $value === 'true',
            default       => false,
        };
    }

    /** Calendly's script, at the address its code writes and at the one it moved from. */
    public static function scripts(): array
    {
        return ['https://assets.calendly.com/assets/external/widget.js', 'https://calendly.com/assets/external/widget.js'];
    }
}
