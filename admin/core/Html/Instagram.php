<?php

declare(strict_types=1);

namespace Cms\Html;

use DOMElement;

/**
 * AN INSTAGRAM EMBED. A writer copies a post's or a reel's embed code from
 * Instagram (its Embed option) and pastes it into a body; the editor shows the
 * post, saving keeps the embed code, and every theme prints it as it is.
 *
 * Instagram's code is a `<blockquote class="instagram-media">` naming the post
 * (`data-instgrm-permalink`), with Instagram's script after it. The script
 * puts Instagram's own frame in the blockquote's place. Until it does, and
 * wherever it is blocked, the blockquote is Instagram's placeholder: grey
 * shapes, Instagram's mark and a link to the post. Every tag, attribute and
 * style of it is Instagram's, so each is kept only as Instagram writes it
 * (`keeps()`), and every link in it goes to Instagram.
 */
final class Instagram implements EmbedQuote
{
    private const HOST = 'www.instagram.com';

    /** A post, a reel or a video, sometimes under its account's name. */
    private const POST = '#^/(?:[A-Za-z0-9_.]+/)?(?:p|reel|tv)/[A-Za-z0-9_-]+/?$#';

    /**
     * Every style Instagram's code writes on its placeholder, as `Embed::style()`
     * compares them. A style that is not one of these is not Instagram's, and
     * goes.
     */
    private const STYLES = [
        'background:#fff;border:0;border-radius:3px;box-shadow:0 0 1px 0 rgba(0,0,0,0.5),0 1px 10px 0 rgba(0,0,0,0.15);margin:1px;max-width:540px;min-width:326px;padding:0;width:99.375%;width:-webkit-calc(100% - 2px);width:calc(100% - 2px)',
        'padding:16px',
        'background:#ffffff;line-height:0;padding:0 0;text-align:center;text-decoration:none;width:100%',
        'display:flex;flex-direction:row;align-items:center',
        'background-color:#f4f4f4;border-radius:50%;flex-grow:0;height:40px;margin-right:14px;width:40px',
        'display:flex;flex-direction:column;flex-grow:1;justify-content:center',
        'background-color:#f4f4f4;border-radius:4px;flex-grow:0;height:14px;margin-bottom:6px;width:100px',
        'background-color:#f4f4f4;border-radius:4px;flex-grow:0;height:14px;width:60px',
        'padding:19% 0',
        'display:block;height:50px;margin:0 auto 12px;width:50px',
        'padding-top:8px',
        'color:#3897f0;font-family:arial,sans-serif;font-size:14px;font-style:normal;font-weight:550;line-height:18px',
        'padding:12.5% 0',
        'display:flex;flex-direction:row;margin-bottom:14px;align-items:center',
        'background-color:#f4f4f4;border-radius:50%;height:12.5px;width:12.5px;transform:translatex(0px) translatey(7px)',
        'background-color:#f4f4f4;height:12.5px;transform:rotate(-45deg) translatex(3px) translatey(1px);width:12.5px;flex-grow:0;margin-right:14px;margin-left:2px',
        'background-color:#f4f4f4;border-radius:50%;height:12.5px;width:12.5px;transform:translatex(9px) translatey(-18px)',
        'margin-left:8px',
        'background-color:#f4f4f4;border-radius:50%;flex-grow:0;height:20px;width:20px',
        'width:0;height:0;border-top:2px solid transparent;border-left:6px solid #f4f4f4;border-bottom:2px solid transparent;transform:translatex(16px) translatey(-4px) rotate(30deg)',
        'margin-left:auto',
        'width:0px;border-top:8px solid #f4f4f4;border-right:8px solid transparent;transform:translatey(16px)',
        'background-color:#f4f4f4;flex-grow:0;height:12px;width:16px;transform:translatey(-4px)',
        'width:0;height:0;border-top:8px solid #f4f4f4;border-left:8px solid transparent;transform:translatey(-4px) translatex(8px)',
        'display:flex;flex-direction:column;flex-grow:1;justify-content:center;margin-bottom:24px',
        'background-color:#f4f4f4;border-radius:4px;flex-grow:0;height:14px;margin-bottom:6px;width:224px',
        'background-color:#f4f4f4;border-radius:4px;flex-grow:0;height:14px;width:144px',
        'color:#c9c8cd;font-family:arial,sans-serif;font-size:14px;line-height:17px;margin-bottom:0;margin-top:8px;overflow:hidden;padding:8px 0 7px;text-align:center;text-overflow:ellipsis;white-space:nowrap',
        'color:#c9c8cd;font-family:arial,sans-serif;font-size:14px;font-style:normal;font-weight:normal;line-height:17px;text-decoration:none',
    ];

    /** Is this blockquote Instagram's? Its class, and a post of Instagram's as the post it names. */
    public static function isQuote(DOMElement $quote): bool
    {
        return strtolower($quote->nodeName) === 'blockquote'
            && in_array('instagram-media', preg_split('/\s+/', trim($quote->getAttribute('class'))) ?: [], true)
            && Embed::address($quote->getAttribute('data-instgrm-permalink'), [self::HOST], self::POST);
    }

    /** The blockquote, the placeholder's boxes, its links and words, and Instagram's mark. */
    public static function tags(): array
    {
        return ['blockquote', 'div', 'a', 'p', 'svg', 'g', 'path'];
    }

    /**
     * WHAT INSTAGRAM'S CODE WRITES, AND NOTHING ELSE. The mark is a drawing:
     * a size, a view box, colours and one path, with no address, no script
     * and no event anywhere in it.
     */
    public static function keeps(string $tag, string $name, string $value): bool
    {
        if ($name === 'style') {
            return in_array($tag, ['blockquote', 'div', 'a', 'p'], true)
                && in_array(Embed::style($value), self::STYLES, true);
        }

        return match ($tag . ' ' . $name) {
            'blockquote class'                  => $value === 'instagram-media',
            'blockquote data-instgrm-captioned' => $value === '',
            'blockquote data-instgrm-permalink' => Embed::address($value, [self::HOST], self::POST),
            'blockquote data-instgrm-version'   => preg_match('/^\d{1,3}$/', $value) === 1,
            'a href'                            => Embed::address($value, [self::HOST], '#^/#'),
            'a target'                          => $value === '_blank',
            'svg width', 'svg height'           => preg_match('/^\d{1,3}(?:px)?$/', $value) === 1,
            'svg viewbox'                       => preg_match('/^[\d .-]{1,40}$/', $value) === 1,
            'svg version'                       => preg_match('/^\d(?:\.\d)?$/', $value) === 1,
            'svg xmlns'                         => in_array($value, ['https://www.w3.org/2000/svg', 'http://www.w3.org/2000/svg'], true),
            'svg xmlns:xlink'                   => in_array($value, ['https://www.w3.org/1999/xlink', 'http://www.w3.org/1999/xlink'], true),
            'g stroke', 'g fill'                => preg_match('/^(?:none|#[0-9A-Fa-f]{3,6})$/', $value) === 1,
            'g stroke-width'                    => preg_match('/^\d{1,2}$/', $value) === 1,
            'g fill-rule'                       => in_array($value, ['evenodd', 'nonzero'], true),
            'g transform'                       => preg_match('/^translate\(-?\d{1,4}(?:\.\d{1,6})?, ?-?\d{1,4}(?:\.\d{1,6})?\)$/', $value) === 1,
            'path d'                            => preg_match('/^[MLHVCSQTAZmlhvcsqtaz0-9 .,-]{1,8000}$/', $value) === 1,
            default                             => false,
        };
    }

    /** Instagram's script, as its code writes it (on whichever scheme the page is) and as https. */
    public static function scripts(): array
    {
        return ['//www.instagram.com/embed.js', 'https://www.instagram.com/embed.js'];
    }
}
