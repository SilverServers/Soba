<?php

declare(strict_types=1);

namespace Cms\Helpers;

/**
 * Versioned URLs for CSS and JavaScript.
 *
 * **Why this exists.** Every stylesheet and script in the admin was linked at a
 * bare path, so a browser that had the old file kept it. That is not a theoretical
 * problem — the session that added this changed all three admin stylesheets and
 * added three scripts, which means every returning user would have got last week's
 * CSS applied to this week's markup: field labels still uppercase, the editor
 * unstyled, the sidebar toggle a button that did nothing. The failure looks like a
 * broken deploy and is unreproducible for whoever shipped it, because their own
 * cache is warm with the right file.
 *
 * The version is the file's own mtime. Nothing to remember on deploy, nothing to
 * bump by hand, and a file that did not change keeps its URL and stays cached.
 *
 * `filemtime()` is stat-only and the result is cached by PHP for the request, so
 * the cost is negligible next to the request that is about to fetch the file.
 */
final class Asset
{
    /** `<link href="…/assets/css/admin-base.css?v=1755740000">` for the admin. */
    public static function admin(string $relative): string
    {
        $relative = ltrim($relative, '/');

        return Url::admin('assets/' . $relative)
            . self::version(PUBLIC_PATH . '/' . trim(ADMIN_URL_SLUG, '/') . '/assets/' . $relative);
    }

    /** The same for a file under `www/`, such as the public theme's stylesheet. */
    public static function site(string $relative): string
    {
        $relative = ltrim($relative, '/');

        return Url::public($relative) . self::version(PUBLIC_PATH . '/' . $relative);
    }

    /**
     * `?v=<mtime>`, or nothing at all when the file cannot be read.
     *
     * A missing file is already a broken page; appending `?v=0` or `?v=false`
     * would only make the cache key wrong as well.
     */
    private static function version(string $path): string
    {
        $mtime = @filemtime($path);

        return $mtime === false ? '' : '?v=' . $mtime;
    }
}
