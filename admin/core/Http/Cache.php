<?php

declare(strict_types=1);

namespace Cms\Http;

/**
 * HOW LONG AN ANSWER MAY BE KEPT, said both ways HTTP has: `Cache-Control`,
 * and an `Expires` that agrees with it. Nothing else in the CMS sends either.
 *
 * Browsers and caches go by `Cache-Control`, which takes precedence over
 * `Expires` under HTTP/1.1 (RFC 9111 §5.3), so the `Expires` changes nothing
 * for them. It is for the web server. Apache's `mod_expires`, which many hosts
 * switch on for a whole site, gives every successful answer that has no
 * `Expires` a `Cache-Control: max-age` and an `Expires` of its own, whatever
 * `Cache-Control` the answer already has. Behind PHP-FPM the answer then
 * carries both: a form token's `no-store` beside a day's `max-age`. An answer
 * that has an `Expires` is left as it is.
 */
final class Cache
{
    /**
     * Long past: the date PHP's session cache limiter sends, so an answer with
     * a session has the same `Expires` whichever of the two sent it last.
     */
    public const PAST = 'Thu, 19 Nov 1981 08:52:00 GMT';

    /**
     * Kept by nothing, the browser included. `$private` also says `private`,
     * for an answer that is one person's own: a private file, a page opened by
     * its share link.
     */
    public static function noStore(bool $private = false): void
    {
        self::send($private ? 'no-store, private' : 'no-store', self::PAST);
    }

    /** Kept by any browser or cache for `$seconds`. */
    public static function publicFor(int $seconds): void
    {
        self::send('public, max-age=' . $seconds, gmdate('D, d M Y H:i:s', time() + $seconds) . ' GMT');
    }

    private static function send(string $cacheControl, string $expires): void
    {
        header('Cache-Control: ' . $cacheControl);
        header('Expires: ' . $expires);
    }
}
