<?php

declare(strict_types=1);

namespace Cms\Html;

use DOMElement;

/**
 * EMBEDS FROM THE PROVIDERS LISTED HERE, AND NO OTHER: videos and posts
 * (YouTube, Vimeo, Facebook, Instagram, X), maps (Google Maps), booking
 * (Calendly) and sign-up forms (Brevo, Kit). A writer copies the embed code
 * from the provider (its Share or Embed option) and pastes it wherever content
 * is written; the editor shows it, saving keeps the code as pasted, and every
 * theme prints it as it is: the provider's own player, post, map, booking page
 * or form, exactly as that code draws it on any other site.
 *
 * Kept as pasted means limited to the provider's own markup and addresses.
 * Each provider's class says what its code writes, and `Html\Sanitizer` keeps
 * that and removes the rest, as it does for anything else in a body.
 *
 * ── THREE SHAPES OF CODE ────────────────────────────────────────────────────
 * A FRAME (`EmbedFrame`): an `<iframe>` on the provider's address, sometimes in
 * a box of the provider's that keeps its shape, sometimes with the provider's
 * script after it. A QUOTE (`EmbedQuote`): a `<blockquote>` that the
 * provider's own script, which follows it, turns into the post; until then,
 * and wherever that script is blocked, the blockquote is what shows. A WIDGET
 * (`EmbedWidget`): an empty element the provider's own script fills — a
 * `<div>` naming what to draw, with the script after it, or the script tag
 * itself, which draws where it stands.
 *
 * ── A PROVIDER'S SCRIPT ─────────────────────────────────────────────────────
 * Kept only at one of the addresses the provider's code writes, with nothing
 * inside it, and only in a body that has that provider's embed: a script with
 * nothing to draw is not an embed. The admin never runs one; the editor shows
 * the provider's own frame instead (`editor.js`).
 */
final class Embed
{
    /** @var list<class-string<EmbedFrame>> */
    public const FRAMES = [YouTube::class, Vimeo::class, Facebook::class, GoogleMaps::class, Brevo::class, Calendly::class];

    /** @var list<class-string<EmbedQuote>> */
    public const QUOTES = [Instagram::class, XPost::class];

    /** @var list<class-string<EmbedWidget>> */
    public const WIDGETS = [Calendly::class, Kit::class];

    /** Whose widget is this element, or null. */
    public static function widget(DOMElement $el): ?string
    {
        foreach (self::WIDGETS as $provider) {
            if ($provider::isWidget($el)) {
                return $provider;
            }
        }

        return null;
    }

    /** Whose frame is this `src`, or null. */
    public static function frame(string $src): ?string
    {
        foreach (self::FRAMES as $provider) {
            if ($provider::isEmbed($src)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Whose box is this `<div>`, or null: the provider's own style, and the
     * provider's frame as the one thing inside it.
     */
    public static function box(DOMElement $div): ?string
    {
        if (strtolower($div->nodeName) !== 'div' || !$div->hasAttribute('style')) {
            return null;
        }
        $frame = null;
        foreach ($div->childNodes as $child) {
            if ($child instanceof DOMElement) {
                if ($frame !== null || strtolower($child->nodeName) !== 'iframe') {
                    return null;
                }
                $frame = $child;
            } elseif ($child->nodeType === XML_TEXT_NODE && trim((string) $child->textContent) !== '') {
                return null;
            }
        }
        $provider = $frame === null ? null : self::frame($frame->getAttribute('src'));

        return $provider !== null && $provider::boxStyle($div->getAttribute('style')) ? $provider : null;
    }

    /** Whose quote is this `<blockquote>`, or null. */
    public static function quote(DOMElement $quote): ?string
    {
        foreach (self::QUOTES as $provider) {
            if ($provider::isQuote($quote)) {
                return $provider;
            }
        }

        return null;
    }

    /** Whose script is this `src`, or null: one of the addresses a provider's code writes, exactly. */
    public static function script(string $src): ?string
    {
        $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        foreach ([...self::FRAMES, ...self::QUOTES, ...self::WIDGETS] as $provider) {
            if (in_array($src, $provider::scripts(), true)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Is this one of a provider's addresses? https, one of `$hosts`, a path
     * matching `$path`, with whatever query the provider put on it, and no
     * name, password or port.
     */
    public static function address(string $url, array $hosts, string $path): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || preg_match('/[\s"\'<>\\\\]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && in_array(strtolower((string) ($parts['host'] ?? '')), $hosts, true)
            && preg_match($path, (string) ($parts['path'] ?? '')) === 1;
    }

    /**
     * Is this one of a provider's addresses on a host its account names
     * (`abc12345.sibforms.com`)? As `address()`, the host matched by
     * `$hostPattern`.
     */
    public static function addressOn(string $url, string $hostPattern, string $path): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        return $host !== '' && preg_match($hostPattern, $host) === 1 && self::address($url, [$host], $path);
    }

    /**
     * A `style` value as a provider writes it, compared without its spacing,
     * case or last semicolon: `border: none; overflow: hidden;` is
     * `border:none;overflow:hidden`. What is stored is the value as pasted.
     */
    public static function style(string $style): string
    {
        $style = strtolower(trim($style));
        $style = preg_replace('/\s+/', ' ', $style) ?? '';
        $style = preg_replace('/\s*([:;,])\s*/', '$1', $style) ?? '';

        return rtrim($style, ';');
    }
}
