<?php

declare(strict_types=1);

namespace Cms\Helpers;

use RuntimeException;

/**
 * Which file renders a public page: the active theme's or the site's, if
 * either has one, else the CMS's.
 *
 * ── WHY ─────────────────────────────────────────────────────────────────────
 * `admin/templates/` is the CMS's — an update replaces it wholesale — so a site
 * that changed how its articles look there would have changed the CMS, and the
 * next update would either overwrite the site's look or refuse to run until
 * someone sorted it out. A site's look is the site's, so it lives in the site's
 * own directory, `admin/site/templates/`, which no package ships and no update
 * touches (`admin/core/Update/ownership.php`).
 *
 * ── HOW A NAME IS FOUND ─────────────────────────────────────────────────────
 * Callers pass candidates from most to least specific. For each name in turn,
 * the active theme's directory is checked (`Helpers\Theme`, chosen under
 * Appearance → Themes — a FRAMED theme's; a whole-page theme's files are whole
 * pages, never these bodies), then the site's, then the CMS's — so a site can
 * override one Content Type's page without taking over every other one:
 *
 *   Template::locate('post-article.php', 'post.php')
 *     admin/site/themes/{active}/post-article.php
 *     admin/site/templates/post-article.php
 *     admin/templates/post-article.php
 *     admin/site/themes/{active}/post.php
 *     admin/site/templates/post.php
 *     admin/templates/post.php
 *
 * `forType()` builds that list. The pages that take one: a single entry
 * (`post-{type}`), a type's archive (`archive-{type}`), a term archive
 * (`term-{taxonomy}`) and a not-found page under a type's address
 * (`404-{type}`). `base.php` is the page's outer shell — header, footer,
 * `<head>` — so a site that overrides it puts every CMS page in its own
 * chrome.
 *
 * A template in the site's directory is `require`d in the same scope as
 * the CMS's would have been, so it sees the same variables.
 */
final class Template
{
    /** The site's templates. Never shipped, never replaced by an update. */
    public static function siteDir(): string
    {
        return ROOT_PATH . '/site/templates';
    }

    /** The CMS's own templates: the default public theme. */
    public static function coreDir(): string
    {
        return ROOT_PATH . '/templates';
    }

    /**
     * The first candidate that exists: the theme's copy, then the site's, then
     * the CMS's.
     *
     * Throws when none does. The CMS always ships the last, generic name, so
     * reaching the end means the install is missing a file — say which, rather
     * than `require` an empty string and fatal with no clue.
     */
    public static function locate(string ...$candidates): string
    {
        $dirs = array_values(array_filter([Theme::framedDir(), self::siteDir(), self::coreDir()], 'is_string'));
        foreach ($candidates as $name) {
            if (!self::isName($name)) {
                continue;
            }
            foreach ($dirs as $dir) {
                if (is_file($dir . '/' . $name)) {
                    return $dir . '/' . $name;
                }
            }
        }

        throw new RuntimeException('No template found for: ' . implode(', ', $candidates));
    }

    /**
     * A PUBLIC PAGE THAT DOES NOT EXIST, answered the same way everywhere: the
     * whole-page theme's `404` if it has one (`Theme::renderNotFound()`), else
     * `404-{key}.php` / `404.php` by the lookup above. Every public controller
     * and the router's own not-found go through here, and must — the hidden
     * sign-in page's 404 is only hidden while it cannot be told apart from
     * the router's (`Security\LoginUrl::notFound()`).
     *
     * The template is handed nothing: a page that is not there has no rows.
     */
    public static function notFound(?string $key = null): void
    {
        http_response_code(404);
        if (Theme::renderNotFound()) {
            return;
        }
        require self::locate(...self::forType('404', $key));
    }

    /**
     * `['post-article.php', 'post.php']` for `forType('post', 'article')`.
     *
     * A key that is not a plain lowercase key contributes nothing, so a value
     * that reached here unchecked cannot name a file outside the directories.
     *
     * @return list<string>
     */
    public static function forType(string $base, ?string $key): array
    {
        $names = [];
        if ($key !== null && preg_match('/^[a-z][a-z0-9_]{0,49}$/', $key) === 1) {
            $names[] = $base . '-' . $key . '.php';
        }
        $names[] = $base . '.php';

        return $names;
    }

    /** A relative `.php` name made of plain segments: no `..`, no leading slash. */
    private static function isName(string $name): bool
    {
        return preg_match('#^[a-z0-9][a-z0-9_-]*(/[a-z0-9][a-z0-9_-]*)*\.php$#', $name) === 1;
    }
}
