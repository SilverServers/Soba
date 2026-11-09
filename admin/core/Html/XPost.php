<?php

declare(strict_types=1);

namespace Cms\Html;

use DOMElement;

/**
 * A POST FROM X. A writer copies a post's embed code from X (the post's menu,
 * Embed post, Copy Code) and pastes it into a body; the editor shows the post,
 * saving keeps the embed code, and every theme prints it as it is.
 *
 * X's code is a `<blockquote class="twitter-tweet">` holding the post's words,
 * its author and a link to the post, with X's script after it. The script puts
 * X's own frame in the blockquote's place. Until it does, and wherever it is
 * blocked, the blockquote is what shows: the words, the author and the link.
 * The post it names is that link: nothing else is stored, no id and no copy of
 * the post.
 *
 * ── OLDER CODES KEEP DRAWING ────────────────────────────────────────────────
 * X's code has changed with its name. An older one links to twitter.com, and
 * to t.co over http; loads its script from X's old host, on whichever scheme
 * the page is; and writes `twitter-video` for a video, `tw-align-*` to place it
 * and `lang` on the blockquote. Each is kept, so a post pasted years ago still
 * draws.
 */
final class XPost implements EmbedQuote
{
    /** What X's code links to in a post: the post, its author, a hashtag, and t.co for everything else. */
    private const LINKS = ['x.com', 'www.x.com', 'mobile.x.com', 'twitter.com', 'www.twitter.com', 'mobile.twitter.com', 't.co'];

    /** Where a post itself lives. */
    private const POSTS = ['x.com', 'www.x.com', 'mobile.x.com', 'twitter.com', 'www.twitter.com', 'mobile.twitter.com'];

    /** A post, under its author's name or under X's own `i/` and `i/web/`. */
    private const POST = '#^/(?:[A-Za-z0-9_]{1,20}|i(?:/web)?)/status(?:es)?/\d{1,25}/?$#';

    /** Is this blockquote X's? Its class, and a post of X's as the post it links to. */
    public static function isQuote(DOMElement $quote): bool
    {
        if (strtolower($quote->nodeName) !== 'blockquote' || !self::isClass($quote->getAttribute('class'))) {
            return false;
        }
        foreach ($quote->getElementsByTagName('a') as $link) {
            if (Embed::address(self::secured($link->getAttribute('href')), self::POSTS, self::POST)) {
                return true;
            }
        }

        return false;
    }

    /** The blockquote, its paragraph of words, and its links. A `<br>` in the words is cleaned as anywhere else. */
    public static function tags(): array
    {
        return ['blockquote', 'p', 'a'];
    }

    /**
     * WHAT X'S CODE WRITES, AND NOTHING ELSE: the options its Embed page offers,
     * each only with a value it means, and links only to X.
     */
    public static function keeps(string $tag, string $name, string $value): bool
    {
        return match ($tag . ' ' . $name) {
            'blockquote class'             => self::isClass($value),
            'blockquote align',
            'blockquote data-align'        => in_array($value, ['left', 'right', 'center', 'none'], true),
            'blockquote data-theme'        => in_array($value, ['light', 'dark'], true),
            'blockquote data-cards'        => $value === 'hidden',
            'blockquote data-conversation' => $value === 'none',
            'blockquote data-dnt'          => in_array($value, ['true', 'false'], true),
            'blockquote data-width',
            'blockquote data-media-max-width' => preg_match('/^\d{1,4}$/', $value) === 1,
            'blockquote lang',
            'blockquote data-lang',
            'p lang'                       => preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/i', $value) === 1,
            'p dir'                        => in_array($value, ['ltr', 'rtl', 'auto'], true),
            'a href'                       => Embed::address(self::secured($value), self::LINKS, '#^/#'),
            default                        => false,
        };
    }

    /** X's script, on its host and its old one, as https and on whichever scheme the page is. */
    public static function scripts(): array
    {
        return [
            'https://platform.x.com/widgets.js',
            'https://platform.twitter.com/widgets.js',
            '//platform.x.com/widgets.js',
            '//platform.twitter.com/widgets.js',
        ];
    }

    /** A post or a video, with only the classes X's code writes beside it. */
    private static function isClass(string $class): bool
    {
        $classes = preg_split('/\s+/', trim($class)) ?: [];

        return array_intersect($classes, ['twitter-tweet', 'twitter-video']) !== []
            && array_diff($classes, ['twitter-tweet', 'twitter-video', 'tw-align-left', 'tw-align-right', 'tw-align-center']) === [];
    }

    /** An older code's http link to t.co or twitter.com, as `Embed::address()` reads a provider's address. */
    private static function secured(string $url): string
    {
        return (string) preg_replace('#^\s*http://(t\.co|(?:www\.)?twitter\.com)/#i', 'https://$1/', $url);
    }
}
