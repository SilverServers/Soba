<?php

declare(strict_types=1);

namespace Cms\Helpers;

use Cms\Models\Option;
use Cms\Update\Release;

/**
 * A SITE'S THEME — its own templates and its own chrome, set when the site is
 * built and kept where no update can reach it. The admin does not choose,
 * add or delete themes.
 *
 * A site's `admin/site/templates/` alone is a developer's override, with no
 * way for a site with its own chrome to render a whole page; a theme adds
 * that.
 *
 * ── WHAT A THEME IS ─────────────────────────────────────────────────────────
 * A directory holding `theme.json` — `name` (required), `description`,
 * `version`, `author`, `mode` — its templates, and an `assets/` directory for
 * what a browser fetches (`theme.css` there is loaded on every framed page).
 * Where they are found:
 *
 *   built in            "Default": `admin/templates/`, what the CMS ships
 *   admin/site/themes/  one directory per theme — site-owned, never shipped,
 *                       never touched by an update (`Update/ownership.php`)
 *   config theme_path   one more directory, wherever the site keeps it —
 *                       e.g. `includes/cms-theme/`, unmoved
 *
 * The active one is the option `site_theme`; a theme that is missing or broken
 * leaves the site on Default rather than on nothing.
 *
 * THE `theme_path` THEME KEEPS WORKING THE WAY IT DID. Before there was a
 * choice, naming a directory there WAS turning the theme on, and the directory
 * has no `theme.json` — so it needs none (it is read as a whole-page theme
 * named after its folder), and it stays the active theme. An update must not
 * quietly put a site back on Default.
 *
 * ── TWO WAYS TO RENDER (`mode`) ─────────────────────────────────────────────
 *   framed      (the default) the theme's templates are found first, by the
 *               same names as the CMS's own (`post-{type}.php`, `archive.php`,
 *               `base.php` — the frame itself, so a theme brings its own
 *               header and footer); anything it lacks is the CMS's.
 *               `Template::locate()` does the looking. A theme of nothing but
 *               `assets/theme.css` restyles the CMS's own pages.
 *   whole-page  for a site with chrome of its own around the CMS's pages. A
 *               template for a kind of page — `single`, `archive`, `term`,
 *               `404`, or `password` (an entry's password gate), as
 *               `{key}/{kind}.php` or `{kind}.php` — renders the
 *               ENTIRE page: the controller hands it the rows it loaded and the
 *               SEO it computed, and neither the CMS's template nor `base.php`
 *               is used. Pages it has no template for are rendered framed — by
 *               the CMS's own templates: a whole-page theme's folder is never
 *               searched for those (`framedDir()`), because its `404.php` and
 *               `archive.php` are whole pages, not the bodies those names mean
 *               to `Template::locate()`.
 *
 * ── HOOK FILES ──────────────────────────────────────────────────────────────
 * A theme may also carry files that are not pages — `form-messages.php`
 * (`Forms\Guard`), and those a site's own code reads. `file()` finds them in
 * the active theme, then in `admin/site/templates/`.
 */
final class Theme
{
    /** The option holding the active theme's key. */
    public const OPTION = 'site_theme';

    /** The built-in theme. */
    public const DEFAULT_KEY = 'default';

    /** How a theme renders. */
    public const MODES = ['framed', 'whole-page'];

    /**
     * What `/assets/theme/…` will serve out of a theme's `assets/`, and as
     * what. A theme's PHP already runs on the server, so its scripts are no
     * new trust; the list is here so a stray `.php`, `.json` or dotfile in
     * that directory is never sent as text.
     */
    public const ASSET_TYPES = [
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'text/javascript; charset=utf-8',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'avif'  => 'image/avif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    /** @var array<string, array<string, mixed>>|null every theme, for this request */
    private static ?array $all = null;

    /** The active theme's key, for this request — `Template::locate()` asks per card. */
    private static ?string $activeKey = null;

    /** `configure()`'s path, when the front controller of a site set one. */
    private static ?string $configured = null;

    /**
     * A site's own front controller calls this with config.php's
     * `theme_path`; kept so it goes on working. Read from config.php when
     * nothing called it.
     */
    public static function configure(?string $path): void
    {
        $path = rtrim(trim((string) $path), '/');
        self::$configured = ($path !== '' && is_dir($path)) ? $path : '';
        self::reset();
    }

    /**
     * Every theme there is, the built-in one first.
     *
     * @return array<string, array<string, mixed>> key => theme
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $themes = [self::DEFAULT_KEY => [
            'key'         => self::DEFAULT_KEY,
            'name'        => 'Default',
            'description' => 'The templates and frame the CMS ships with.',
            'version'     => Release::version(),
            'author'      => '',
            'mode'        => 'framed',
            'dir'         => null,
            'where'       => 'Built in',
            'configured'  => false,
            'problem'     => null,
        ]];

        $configured = self::configuredPath();
        $dirs       = glob(self::sitesDir() . '/*', GLOB_ONLYDIR) ?: [];
        if ($configured !== null && !in_array($configured, $dirs, true)) {
            $dirs[] = $configured;
        }

        foreach ($dirs as $dir) {
            $theme = self::read($dir, $dir === $configured);
            if (!isset($themes[$theme['key']])) {
                $themes[$theme['key']] = $theme;
            }
        }

        return self::$all = $themes;
    }

    /**
     * The theme in force: the one chosen on the screen, if it is there and
     * sound; before anyone has chosen, the `theme_path` theme; else Default.
     *
     * @return array<string, mixed>
     */
    public static function active(): array
    {
        $all = self::all();
        if (self::$activeKey === null) {
            $chosen = Option::get(self::OPTION);
            if ($chosen === null) {
                foreach ($all as $key => $theme) {
                    if ($theme['configured'] === true) {
                        $chosen = (string) $key;
                    }
                }
            }
            $theme = $all[(string) $chosen] ?? null;
            self::$activeKey = ($theme !== null && $theme['problem'] === null) ? (string) $chosen : self::DEFAULT_KEY;
        }

        return $all[self::$activeKey];
    }

    /** The active theme's directory, or null when Default is active. */
    public static function dir(): ?string
    {
        $dir = self::active()['dir'];
        return is_string($dir) ? $dir : null;
    }

    /**
     * The folder `Template::locate()` searches first: the active theme's, when
     * it is a framed theme; null for Default and for a whole-page theme.
     */
    public static function framedDir(): ?string
    {
        $theme = self::active();
        return ($theme['mode'] === 'framed' && is_string($theme['dir'])) ? $theme['dir'] : null;
    }

    /**
     * The stylesheet the CMS's own frame links for the active theme — a framed
     * theme's `assets/theme.css` — or null. A whole-page theme links its own,
     * in its own pages (`asset()`).
     */
    public static function stylesheet(): ?string
    {
        return self::active()['mode'] === 'framed' ? self::asset('theme.css') : null;
    }

    /** A site theme's name for `dir()`, kept so that theme code reads the same. */
    public static function path(): ?string
    {
        return self::dir();
    }

    /**
     * The active theme's WHOLE-PAGE template for a kind of page, most specific
     * first, or null — always null for a framed theme.
     */
    public static function template(string $kind, ?string $key = null): ?string
    {
        $theme = self::active();
        if ($theme['mode'] !== 'whole-page' || !is_string($theme['dir'])
            || preg_match('/^[a-z0-9_-]+$/', $kind) !== 1) {
            return null;
        }

        $candidates = [];
        if ($key !== null && preg_match('/^[a-z0-9_-]+$/', $key) === 1) {
            $candidates[] = $theme['dir'] . '/' . $key . '/' . $kind . '.php';
        }
        $candidates[] = $theme['dir'] . '/' . $kind . '.php';

        foreach ($candidates as $file) {
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Render a whole-page template with $vars as its local variables, in a
     * scope of its own — whatever it sets up (a site's globals, say) stays
     * there.
     *
     * @param array<string, mixed> $vars
     */
    public static function render(string $file, array $vars): void
    {
        (static function (string $__themeFile, array $__themeVars): void {
            extract($__themeVars, EXTR_SKIP);
            require $__themeFile;
        })($file, $vars);
    }

    /**
     * Answer a public 404 with the whole-page theme's `404` template, if it has
     * one. False when there is none, so the caller renders its own.
     */
    public static function renderNotFound(): bool
    {
        $file = self::template('404');
        if ($file === null) {
            return false;
        }
        if (!headers_sent()) {
            http_response_code(404);
        }
        self::render($file, [
            'requestPath' => (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'),
        ]);

        return true;
    }

    /** A file the active theme carries that is not a page (`form-messages.php`), or null. */
    public static function file(string $name): ?string
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]*\.php$/', $name) !== 1) {
            return null;
        }
        foreach ([self::dir(), Template::siteDir()] as $dir) {
            if (is_string($dir) && is_file($dir . '/' . $name)) {
                return $dir . '/' . $name;
            }
        }

        return null;
    }

    /**
     * The address of a file in the active theme's `assets/`, versioned like
     * every other stylesheet here — or null when it has no such file.
     */
    public static function asset(string $path): ?string
    {
        $theme = self::active();
        $file  = self::assetFile((string) $theme['key'], $path);
        if ($file === null) {
            return null;
        }

        return Url::public('assets/theme/' . $theme['key'] . '/' . ltrim($path, '/')) . '?v=' . (int) @filemtime($file);
    }

    /**
     * The file `/assets/theme/{key}/{path}` names, or null: a sound theme, a
     * path of plain segments inside its `assets/`, an extension on the list,
     * and — `realpath` — not a link out of the directory.
     */
    public static function assetFile(string $key, string $path): ?string
    {
        $theme = self::all()[$key] ?? null;
        if ($theme === null || $theme['problem'] !== null || !is_string($theme['dir'])
            || preg_match('#^[a-zA-Z0-9_-][a-zA-Z0-9_.-]*(/[a-zA-Z0-9_-][a-zA-Z0-9_.-]*)*$#', $path) !== 1
            || str_contains($path, '..')
            || !isset(self::ASSET_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))])) {
            return null;
        }

        $root = realpath($theme['dir'] . '/assets');
        $file = realpath($theme['dir'] . '/assets/' . $path);

        return ($root !== false && $file !== false && is_file($file) && str_starts_with($file, $root . '/'))
            ? $file
            : null;
    }

    /** The site's theme directories: `admin/site/themes/`. */
    public static function sitesDir(): string
    {
        return ROOT_PATH . '/site/themes';
    }

    /** Forget what was read this request — after a theme is activated. */
    public static function reset(): void
    {
        self::$all       = null;
        self::$activeKey = null;
    }

    /**
     * One theme directory, read. A theme that cannot be used is still listed —
     * with what is wrong — so nobody wonders why a folder they made is not on
     * the screen.
     *
     * @return array<string, mixed>
     */
    private static function read(string $dir, bool $configured): array
    {
        $dir     = rtrim($dir, '/');
        $base    = strtolower(basename($dir));
        $key     = preg_match('/^[a-z0-9][a-z0-9_-]{0,49}$/', $base) === 1 ? $base : 'theme-' . substr(sha1($dir), 0, 8);
        $problem = null;
        $data    = [];

        $file = $dir . '/theme.json';
        if (!is_file($file)) {
            // The `theme_path` directory predates theme.json and needs none.
            $data = $configured ? ['name' => basename($dir), 'mode' => 'whole-page'] : [];
            if (!$configured) {
                $problem = 'It has no theme.json.';
            }
        } else {
            $data = json_decode((string) @file_get_contents($file), true);
            if (!is_array($data)) {
                $problem = 'Its theme.json is not valid JSON.';
                $data    = [];
            } elseif (trim((string) ($data['name'] ?? '')) === '') {
                $problem = 'Its theme.json has no name.';
            } elseif (isset($data['mode']) && !in_array($data['mode'], self::MODES, true)) {
                $problem = 'Its theme.json gives a mode other than "framed" or "whole-page".';
            }
        }
        if ($key === self::DEFAULT_KEY) {
            $problem = 'Its folder is named "default", which is the built-in theme\'s name.';
        }

        $str = static fn (string $k): string => mb_substr(trim((string) ($data[$k] ?? '')), 0, 200);
        $mode = (string) ($data['mode'] ?? 'framed');

        return [
            'key'         => $key,
            'name'        => $str('name') !== '' ? $str('name') : basename($dir),
            'description' => $str('description'),
            'version'     => $str('version'),
            'author'      => $str('author'),
            'mode'        => in_array($mode, self::MODES, true) ? $mode : 'framed',
            'dir'         => $dir,
            'where'       => self::relative($dir),
            'configured'  => $configured,
            'problem'     => $problem,
        ];
    }

    /** A directory as the site's own path (from its project root), for the screen. */
    private static function relative(string $dir): string
    {
        $root = dirname(ROOT_PATH);
        return str_starts_with($dir, $root . '/') ? substr($dir, strlen($root) + 1) : $dir;
    }

    /** `configure()`'s path, else config.php's `theme_path`, when it names a directory. */
    private static function configuredPath(): ?string
    {
        if (self::$configured === null) {
            $config = defined('ROOT_PATH') ? \Cms\Site\ConfigFile::read(ROOT_PATH . '/config/config.php') : null;
            $path   = is_array($config) ? rtrim(trim((string) ($config['theme_path'] ?? '')), '/') : '';
            self::$configured = ($path !== '' && is_dir($path)) ? $path : '';
        }

        return self::$configured !== '' ? self::$configured : null;
    }
}
