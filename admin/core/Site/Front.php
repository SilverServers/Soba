<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\ContentType\Registries;
use Cms\ContentType\Registry;
use Cms\ContentType\TaxonomyRegistry;
use Cms\Database\Database;
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
 * They are what the CMS must not take: the sign-in page cannot be moved onto
 * one (`LoginUrl::problem()`), or `/about` would answer with a sign-in form.
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

    private static bool $booted = false;

    /** @var list<string>|null */
    private static ?array $siteAddresses = null;

    /** Does the CMS answer this request? `$uri` is the request's address (REQUEST_URI) or its path. */
    public static function owns(string $uri): bool
    {
        self::boot();
        $path = self::path($uri);

        foreach (self::PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        if (LoginUrl::enabled() && $path === LoginUrl::path()) {
            return true;
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
        $list = ($file !== '' && is_file($file)) ? (static fn (string $__f): mixed => require $__f)($file) : [];

        $first = [];
        foreach (is_array($list) ? $list : [] as $address) {
            $address = is_string($address) ? strtolower(trim($address, " /")) : '';
            if ($address !== '') {
                $first[] = explode('/', $address)[0];
            }
        }

        return self::$siteAddresses = array_values(array_unique($first));
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

    /** Ready to answer, once a request. */
    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        defined('ROOT_PATH')   || define('ROOT_PATH', dirname(__DIR__, 2));
        defined('PUBLIC_PATH') || define('PUBLIC_PATH', dirname(ROOT_PATH) . '/www');
        if (!defined('BASE_URL') || !Database::initialized()) {
            $config = require ROOT_PATH . '/config/config.php';
            if (!defined('BASE_URL')) {
                define('BASE_URL', rtrim((string) $config['base_url'], '/'));
                defined('BASE_PATH') || define('BASE_PATH', (string) (parse_url(BASE_URL, PHP_URL_PATH) ?? ''));
            }
            if (!Database::initialized()) {
                $zone = date_default_timezone_get();
                Database::init($config);
                date_default_timezone_set($zone);
            }
        }
        Registries::load();
    }
}
