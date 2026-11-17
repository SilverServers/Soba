<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\ContentType\Registries;
use Cms\ContentType\Registry;
use Cms\ContentType\TaxonomyRegistry;
use Cms\Database\Database;
use Cms\SEO\Redirects;
use Cms\Security\LoginUrl;

/**
 * A SITE WHOSE OWN ROUTER SITS IN FRONT OF THE CMS — the integration.
 *
 * A site may keep its own `www/index.php` and run the CMS beside it:
 * the CMS's front controller under a name of the site's
 * (config.php `front_controller`, e.g. `cms.php`), and the site's router
 * handing it the requests that are the CMS's. WHICH requests those are is one
 * question, put to the CMS on every request — never a list the site keeps,
 * which falls behind the moment the CMS adds or moves an address, and which
 * locks people out when it does:
 *
 *     require __DIR__ . '/../admin/vendor/autoload.php';
 *     if (\Cms\Site\Front::owns($_SERVER['REQUEST_URI'] ?? '/')) {
 *         require __DIR__ . '/cms.php';
 *         exit;
 *     }
 *
 * ASK IT FIRST: before the site's own rules, and above all before one that
 * redirects — a trailing slash added with a 301 turns a POST into a GET. The
 * web server then needs only to send every address that is not a file to the
 * site's own `index.php`; it keeps no list of the CMS's either.
 *
 * WHAT THE CMS OWNS:
 *
 *   /admin, /admin/…        the admin, a site's tools (/admin/tools/…) included
 *   /api/v1/…               the API
 *   /files/…                private files
 *   /forms/…                form posts
 *   /assets/theme/…         a framed theme's files
 *   the sign-in page        wherever Security → Login protection has moved it
 *   a redirect's address    any address a rule in Tools → Redirects sends on,
 *                           as the front controller follows it
 *                           (`Redirects::followed()`), the site's own included:
 *                           the owner made the rule for that address
 *   /{type}, /{type}/…      every Content Type published at an address
 *   /{taxonomy}/…           every taxonomy published at an address
 *
 * Nothing else: the home page, Pages, search, the shop, sitemaps and
 * robots.txt are a site with its own router's to serve.
 *
 * THE SITE'S OWN ADDRESSES — `admin/config/site-addresses.php`, the site's
 * file (no update touches it), returns the first segments its router serves:
 *
 *     <?php
 *     return ['about', 'contact', 'services', 'team'];
 *
 * They are what the CMS must not take. The sign-in page cannot be moved onto
 * one (`LoginUrl::problem()`), or `/about` would answer with a sign-in form,
 * and the Content Types and Taxonomies screens will not publish at one. A
 * type or taxonomy at one that arrived some other way, an import or a row
 * written by hand, still never takes it from the site here. Only a redirect
 * rule does, because the owner made it.
 *
 * DURING AN UPDATE, while `admin/bin/update.php`'s flag is up, the database may
 * be mid-migration and the CMS's files half one release and half the next, so
 * neither is read: the home page and the site's own addresses stay the site's,
 * and every other address is the CMS's, whose front controller answers 503
 * until the update is done. A site that lists none of its addresses keeps only
 * its home page for that minute. Its `index.php` has loaded the CMS's
 * autoloader to ask; one that wants nothing of the CMS loaded mid-update looks
 * for `admin/updates/.maintenance` before it does.
 *
 * A FAULT IS NOT THE WHOLE SITE. A config.php that is missing, a database that
 * does not answer, a registry that cannot load: `owns()` never throws. The
 * fault is logged, the fixed addresses above are still the CMS's (its front
 * controller then answers with its error page), and every other request goes
 * to the site's router, so the site's own pages carry on.
 *
 * It sets up what it reads — config.php, the database (keeping the site's own
 * time zone: `Database::init()` pins PHP to UTC) and the registries — and uses
 * whatever the site has set up already as it is.
 */
final class Front
{
    /** The CMS's fixed addresses: each one, and everything under it. */
    public const PREFIXES = ['/admin', '/api/v1', '/files', '/forms', '/assets/theme'];

    /**
     * The web server's own: Apache's stock aliases, which answer before any
     * site sees the request — a stock `/icons/` alias takes a site's own icons
     * folder away.
     */
    public const SERVER = ['icons', 'cgi-bin', 'phpmyadmin'];

    private static bool $settled = false;

    private static bool $booted = false;

    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    /** @var list<string>|null */
    private static ?array $siteAddresses = null;

    /**
     * Does the CMS answer this request? `$uri` is the request's address
     * (REQUEST_URI) or its path. Never throws.
     */
    public static function owns(string $uri): bool
    {
        try {
            self::settle();
            $path = self::path($uri);
            if (self::fixed($path)) {
                return true;
            }
            if (is_file(ROOT_PATH . '/updates/.maintenance')) {
                return $path !== '/' && !self::isSiteAddress($path);
            }

            self::boot();
            if (LoginUrl::enabled() && $path === LoginUrl::path()) {
                return true;
            }
            if (Redirects::followed($path) !== null) {
                return true;
            }
            if (self::isSiteAddress($path)) {
                return false;
            }
            foreach (Registry::all() as $type) {
                $at = trim((string) ($type['public_slug'] ?? ''), '/');
                if ($at !== '' && ($path === '/' . $at || str_starts_with($path, '/' . $at . '/'))) {
                    return true;
                }
            }
            foreach (TaxonomyRegistry::all() as $taxonomy) {
                $at = trim((string) ($taxonomy['public_slug'] ?? ''), '/');
                if ($at !== '' && str_starts_with($path, '/' . $at . '/')) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            error_log('CMS front: ' . $e->getMessage() . ' (only its fixed addresses were answered as the CMS\'s)');

            return self::fixed(self::path($uri));
        }
    }

    /**
     * The first segments the site's own router serves, from
     * `admin/config/site-addresses.php`; none when the site keeps no such file.
     *
     * @return list<string>
     */
    public static function siteAddresses(): array
    {
        if (self::$siteAddresses !== null) {
            return self::$siteAddresses;
        }
        $file = defined('ROOT_PATH') ? ROOT_PATH . '/config/site-addresses.php' : '';
        $list = [];
        if ($file !== '' && is_file($file)) {
            // READ QUIETLY: it is read on every request of the site, and a blank
            // line before its `<?php` would be printed before every page.
            ob_start();
            try {
                $list = (static fn (string $__f): mixed => require $__f)($file);
            } finally {
                ob_end_clean();
            }
        }

        $first = [];
        foreach (is_array($list) ? $list : [] as $address) {
            $address = is_string($address) ? strtolower(trim($address, " /")) : '';
            if ($address !== '') {
                $first[] = explode('/', $address)[0];
            }
        }

        return self::$siteAddresses = array_values(array_unique($first));
    }

    /** Is the path one of the CMS's fixed addresses, or under one? */
    private static function fixed(string $path): bool
    {
        foreach (self::PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /** Is the path under one of the site's own first segments? */
    private static function isSiteAddress(string $path): bool
    {
        $first = strtolower(explode('/', ltrim($path, '/'))[0]);

        return $first !== '' && in_array($first, self::siteAddresses(), true);
    }

    /** The request's path as the router sees it: no query, no BASE_PATH, no trailing slash. */
    private static function path(string $uri): string
    {
        $path = (string) strtok($uri, '?');
        $base = defined('BASE_PATH') ? rtrim((string) BASE_PATH, '/') : '';
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base));
        }

        return '/' . trim($path, '/');
    }

    /** The CMS's folders and the site's address, from config.php alone: nothing from the database. */
    private static function settle(): void
    {
        if (self::$settled) {
            return;
        }
        self::$settled = true;

        defined('ROOT_PATH')   || define('ROOT_PATH', dirname(__DIR__, 2));
        defined('PUBLIC_PATH') || define('PUBLIC_PATH', dirname(ROOT_PATH) . '/www');
        if (!defined('BASE_URL')) {
            define('BASE_URL', rtrim((string) (self::config()['base_url'] ?? ''), '/'));
            defined('BASE_PATH') || define('BASE_PATH', (string) (parse_url(BASE_URL, PHP_URL_PATH) ?? ''));
        }
    }

    /** The database and the registries, once a request. */
    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        if (!Database::initialized()) {
            $zone = date_default_timezone_get();
            Database::init(self::config());
            date_default_timezone_set($zone);
        }
        Registries::load();
    }

    /**
     * config.php, read once. A missing one is said as such, without the warning
     * a `require` of it would write first.
     *
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        if (self::$config === null) {
            $file = ROOT_PATH . '/config/config.php';
            if (!is_file($file)) {
                throw new \RuntimeException($file . ' is missing');
            }
            $config       = require $file;
            self::$config = is_array($config) ? $config : [];
        }

        return self::$config;
    }
}
