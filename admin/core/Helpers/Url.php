<?php

declare(strict_types=1);

namespace Cms\Helpers;

final class Url
{
    /**
     * THE FRONT PAGE'S OWN PAGE: the published Page whose words `/` shows
     * (`Public\HomeController`). It has one address, `/`. pagePath() writes it
     * so, which puts `/` in the sitemap, the canonicals, the menus and the
     * breadcrumbs, and settle() answers a request for `/home` with a 301 to
     * `/`. Only where the CMS serves `/` itself (`SERVES_FRONT_PAGE`): behind a
     * site's own router, `/` is the site's page.
     */
    public const FRONT_PAGE = 'home';

    public static function admin(string $path = ''): string
    {
        $base = defined('ADMIN_URL') ? ADMIN_URL : '/admin';
        if ($path === '') {
            return $base;
        }
        return $base . '/' . ltrim($path, '/');
    }

    public static function public(string $path = ''): string
    {
        $base = defined('BASE_URL') ? BASE_URL : '';
        if ($path === '') {
            return $base ?: '/';
        }
        return $base . '/' . ltrim($path, '/');
    }

    /** The trailing-slash setting for this request — read once; see page(). */
    private static ?bool $slash = null;

    /**
     * THE ADDRESS OF A PAGE ON THIS SITE — an entry, a type's archive, a term's
     * archive, a Page, search.
     *
     * An address glued together where it is needed — `BASE_URL . '/' . $slug`
     * in a controller, the sitemap, the menus, the listing card — cannot end in
     * a slash, and a site whose every canonical does could not be moved onto
     * this without changing every address it has. So this is the one place a
     * page's address is made, and the one place the Permalinks setting "End
     * addresses with a slash" is read.
     *
     * The home page is `/` either way, and so is the front page's own Page
     * (FRONT_PAGE). A path whose last segment has a dot in
     * it names a file (`/feed.xml`), which never takes a slash. A query string
     * is the caller's to add after — `Paging::url()` does — so it lands after
     * the slash, where the address that owns it ends.
     */
    public static function page(string $path = ''): string
    {
        return (defined('BASE_URL') ? BASE_URL : '') . self::pagePath($path);
    }

    /**
     * THE SAME ADDRESS AS A PATH, with no scheme or host: for what must go to
     * whichever host the visitor is on. A site that answers on both `www.`
     * and its bare name posts a form, and loads a script, on the name the
     * page was read on, which a Content-Security-Policy of `'self'` requires.
     */
    public static function path(string $path = ''): string
    {
        return rtrim(defined('BASE_PATH') ? (string) BASE_PATH : '', '/') . self::pagePath($path);
    }

    /**
     * page() without the host: `/articles/foo/` or `/articles/foo`. For what is
     * compared or stored as a path — a redirect's target, the request itself.
     */
    public static function pagePath(string $path): string
    {
        $path = trim($path, '/');
        if ($path === '' || ($path === self::FRONT_PAGE && self::servesFrontPage())) {
            return '/';
        }
        $last = substr($path, (int) strrpos('/' . $path, '/'));
        return '/' . $path . (self::trailingSlash() && !str_contains($last, '.') ? '/' : '');
    }

    /**
     * Does the CMS serve `/` here? Its front controller says (`www/index.php`
     * defines SERVES_FRONT_PAGE). A tool run without one is taken to, as on a
     * standalone install.
     */
    private static function servesFrontPage(): bool
    {
        return !defined('SERVES_FRONT_PAGE') || SERVES_FRONT_PAGE;
    }

    /** Does this site end its addresses with a slash? Settings → Permalinks. */
    public static function trailingSlash(): bool
    {
        if (self::$slash === null) {
            try {
                self::$slash = \Cms\Models\Option::get('permalink_trailing_slash', '0') === '1';
            } catch (\Throwable) {
                // No database (a CLI tool that never connected): the default.
                self::$slash = false;
            }
        }
        return self::$slash;
    }

    /**
     * SEND THE OTHER ENDING TO THIS ONE — the one way an address can differ
     * here.
     *
     * The router trims a trailing slash before it matches, so `/about` and
     * `/about/` would both answer 200: one page at two addresses, which a search
     * engine counts as two. `page()` writes every link and canonical in the
     * form Settings → Permalinks chose; this 301s a GET or HEAD for the other
     * form to it, query and all.
     *
     * A PAGE'S CONTROLLER CALLS IT, once the page is known to EXIST — never the
     * router. The Page route matches any single segment, so deciding there
     * would 301 every mistyped address to its twin and only then 404 it; a 404
     * stays a 404, which is also what the update's smoke test asks for.
     *
     * IT CANNOT LOOP. The request is compared with `pagePath()` of ITSELF —
     * not with a slug out of the database, which could differ from what was
     * asked in case or percent-encoding and bounce the visitor between the
     * two — and the target, requested, is equal to its own `pagePath()`.
     */
    public static function settle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if ($method !== 'GET' && $method !== 'HEAD') {
            return;   // a password gate's POST answers where it was sent
        }
        $uri   = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $cut   = strpos($uri, '?');
        $asked = $cut === false ? $uri : substr($uri, 0, $cut);
        $base  = defined('BASE_PATH') ? rtrim((string) BASE_PATH, '/') : '';
        if ($base !== '' && str_starts_with($asked, $base)) {
            $asked = substr($asked, strlen($base));
        }
        if ($asked === '' || $asked === self::pagePath($asked)) {
            return;
        }
        http_response_code(301);
        header('Location: ' . self::page($asked) . ($cut === false ? '' : substr($uri, $cut)));
        exit;
    }

    /**
     * SET THE ENDING FOR THIS REQUEST from outside the CMS — a site's own
     * seam (its router in front) calls this on every request, with its own config's
     * `trailing_slash`, before it hands one of the CMS's pages to the front
     * controller. Kept so that seam goes on working through an update; Settings
     * → Permalinks (`permalink_trailing_slash`) decides everywhere else.
     */
    public static function configureTrailingSlash(bool $slash): void
    {
        self::$slash = $slash;
    }

    /** Forget the setting — for the screen that has just changed it. */
    public static function resetSettings(): void
    {
        self::$slash = null;
    }

    /**
     * Turn a link an editor typed into one that works.
     *
     * Button and menu targets are now content — someone types them into an admin
     * field — so they arrive as anything from `/pricing` to
     * `https://example.com/x` to `mailto:sales@…`. Absolute URLs and schemes are
     * left alone; everything else is treated as a site path and gets BASE_URL.
     *
     * `javascript:` and `data:` are rejected to a harmless `#`, because these
     * values reach an `href`. Escaping alone would not stop them — the string is
     * already valid markup by the time it is printed.
     */
    public static function resolve(string $link): string
    {
        $link = trim($link);

        if ($link === '') {
            return self::public();
        }

        if (preg_match('#^(javascript|data|vbscript):#i', $link) === 1) {
            return '#';
        }

        // A scheme, a protocol-relative URL, an anchor or a query: use as given.
        if (preg_match('#^([a-z][a-z0-9+.-]*:|//|\#|\?)#i', $link) === 1) {
            return $link;
        }

        return self::public($link);
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return \Cms\Helpers\Url::admin($path);
    }
}
