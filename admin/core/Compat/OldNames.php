<?php

declare(strict_types=1);

namespace Cms\Compat;

/**
 * THE CMS'S OLD NAMES, KEPT WORKING (0.7.x).
 *
 * Until 0.7.0 this codebase carried a working title, "Imprint", in its PHP
 * namespace, its CSS classes, its session keys and a dozen other names. 0.7.0
 * renamed every one to "cms". An installed site still holds the old names in
 * files an update never touches — its own config seeds (`admin/config/menus.php`
 * and `taxonomies.php` say `use Imprint\…`), its theme, its own code — and in
 * the sessions and cookies of the people signed in to it. This class is the one
 * place that keeps those working. Everything that answers to an old name calls
 * in here, so removing the old names is removing this directory and its callers.
 *
 * Planned removal: 0.8.0, whose updater refuses a site whose own files still
 * use an old name (and names each one). Until then `update.php check` lists
 * them as a note.
 */
final class OldNames
{
    /** The PHP namespace, old and new, each with its trailing separator. */
    public const OLD_NAMESPACE = 'Imprint\\';
    public const NEW_NAMESPACE = 'Cms\\';

    /** Session keys before 0.7.0 => now (Auth, TwoStep, Passwordless, Sessions, TwoStepController). */
    public const SESSION_KEYS = [
        'imprint_user'           => 'cms_user',
        'imprint_seen'           => 'cms_seen',
        'imprint_ver'            => 'cms_ver',
        'imprint_tracked'        => 'cms_tracked',
        'imprint_two_step'       => 'cms_two_step',
        'imprint_two_step_setup' => 'cms_two_step_setup',
        'imprint_confirmed_at'   => 'cms_confirmed_at',
        'imprint_passwordless'   => 'cms_passwordless',
    ];

    /** The ownership list's class for the CMS's own files before 0.7.0 ('core' now). */
    public const OWNERSHIP_CLASS = 'imprint';

    /** The router layout's front controller before 0.7.0 (its `@front` is www/cms.php now). */
    public const ROUTER_FRONT = 'www/imprint.php';

    /** The trusted-browser cookie before 0.7.0 (Security\TrustedBrowsers::COOKIE now). */
    public const TRUSTED_COOKIE = 'imprint_trusted';

    /**
     * NOT REMOVED IN 0.8.0. The marker an export made before 0.7.0 carries
     * (`cms_export` now): Content\Import accepts either, for good — an
     * export is a file people keep.
     */
    public const EXPORT_MARKER = 'imprint_export';

    private static bool $registered = false;

    /**
     * Make every `Imprint\X` the same class as `Cms\X`. Run once, by composer's
     * `files` autoload (`core/Compat/autoload.php`), before any class loads.
     *
     * Prepended, it sees every class first:
     *   Cms\X       loaded from core/ (PSR-4), then aliased as Imprint\X at
     *               once — so `instanceof`, a type hint or a `catch` naming the
     *               old class holds for any object, none of which autoload
     *   Imprint\X   on demand: Cms\X is loaded (and so aliased)
     * Anything else is left to the next autoloader.
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        spl_autoload_register(static function (string $class): void {
            if (str_starts_with($class, self::NEW_NAMESPACE)) {
                $file = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen(self::NEW_NAMESPACE))) . '.php';
                if (is_file($file)) {
                    require_once $file;
                    self::alias($class);
                }
            } elseif (str_starts_with($class, self::OLD_NAMESPACE)) {
                $new = self::NEW_NAMESPACE . substr($class, strlen(self::OLD_NAMESPACE));
                if (self::declared($new, true)) {
                    self::alias($new);
                }
            }
        }, true, true);
    }

    /**
     * A session started before 0.7.0 keeps its sign-in: each key moves to
     * its name now (one already there wins). Called right after every
     * session_start() the CMS makes, and by Auth::check() for a site's own
     * code that started the session itself. In a session opened read-only
     * the move lasts for the request; in any other, PHP saves it.
     */
    public static function carrySession(): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return;
        }
        foreach (self::SESSION_KEYS as $old => $new) {
            if (array_key_exists($old, $_SESSION)) {
                if (!array_key_exists($new, $_SESSION)) {
                    $_SESSION[$new] = $_SESSION[$old];
                }
                unset($_SESSION[$old]);
            }
        }
    }

    /**
     * A manifest or baseline written before 0.7.0 lists the router layout's
     * front controller as www/imprint.php. Read it under the layout's name
     * now (`$front`), so the file it hashed is compared as the same file —
     * otherwise the update would see one file deleted and another added.
     */
    public static function manifest(\Cms\Update\Manifest $manifest, string $front): \Cms\Update\Manifest
    {
        return $manifest->layout() === 'router' && $front !== '' && $front !== self::ROUTER_FRONT
            && array_key_exists(self::ROUTER_FRONT, $manifest->files())
            ? $manifest->renamed([self::ROUTER_FRONT => $front])
            : $manifest;
    }

    /**
     * An install from before 0.7.0 that never named its front controller
     * (no `front_controller` in config.php) still has it at www/imprint.php,
     * and its own rewrite rules hand requests to that file. That is its name
     * for the front controller — updated in place — while the file is there
     * and the layout's name now (`$front`) is not.
     */
    public static function routerFront(string $site, string $front): ?string
    {
        return is_file($site . '/' . self::ROUTER_FRONT) && !file_exists($site . '/' . $front) ? self::ROUTER_FRONT : null;
    }

    /**
     * The site's OWN files that still use an old name — `Imprint\…`,
     * `imprint-form…`/`-gallery…`/`-alert…`, `ImprintMedia`,
     * `data-imprint-form` — relative to the site, at most `$max` of them. They
     * keep working through 0.7.x; `update.php check` lists them so they can be
     * changed before 0.8.0. Read-only: PHP, CSS, JS and HTML under 512 KB,
     * outside the CMS's own paths, its backups and uploads.
     *
     * @return list<string>
     */
    public static function inSiteFiles(string $site, \Cms\Update\Ownership $ownership, int $max = 50): array
    {
        $site  = rtrim($site, '/');
        $found = [];
        $skip  = '#^(admin/updates|admin/logs|www/uploads|\.git|node_modules|vendor)(/|$)#';
        $seen  = 0;
        $walk  = static function (string $dir) use (&$walk, &$found, &$seen, $site, $ownership, $skip, $max): void {
            foreach (@scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..' || count($found) >= $max || ++$seen > 50000) {
                    continue;
                }
                $path = $dir . '/' . $name;
                $rel  = substr($path, strlen($site) + 1);
                if (preg_match($skip, $rel) === 1 || is_link($path)) {
                    continue;
                }
                if (is_dir($path)) {
                    if ($ownership->classify($rel . '/x') !== 'core') {
                        $walk($path);
                    }
                } elseif (preg_match('/\.(php|css|js|html?)$/', $name) === 1 && $ownership->classify($rel) !== 'core'
                    && (int) @filesize($path) < 524288
                    && preg_match('/Imprint\\\\|imprint-(?:form|gallery|alert)|ImprintMedia|data-imprint-form/', (string) @file_get_contents($path)) === 1) {
                    $found[] = $rel;
                }
            }
        };
        $walk($site);
        sort($found, SORT_STRING);

        return $found;
    }

    /** A class name written either way, as the CMS names it now. */
    public static function className(string $class): string
    {
        $class = ltrim($class, '\\');

        return str_starts_with($class, self::OLD_NAMESPACE)
            ? self::NEW_NAMESPACE . substr($class, strlen(self::OLD_NAMESPACE))
            : $class;
    }

    /**
     * THE OLD CLASS NAMES BESIDE THE NEW, in markup the CMS renders for a
     * site's pages: in each `class="…"`, every `cms-form…`, `cms-gallery…` and
     * `cms-alert…` gains its `imprint-…` twin at the end, and `data-cms-form`
     * gains `data-imprint-form` — so a site stylesheet or script aimed at the
     * old names still finds them. The attribute's own text is kept as it was.
     */
    public static function withOldClasses(string $html): string
    {
        $html = (string) preg_replace_callback('/\bclass="([^"]*)"/', static function (array $m): string {
            $old = [];
            foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $token) {
                if (preg_match('/^cms-(?:form|gallery|alert)(?![a-z])/', $token) === 1) {
                    $old[] = 'imprint-' . substr($token, 4);
                }
            }

            return $old === [] ? $m[0] : 'class="' . $m[1] . ' ' . implode(' ', $old) . '"';
        }, $html);

        return (string) preg_replace('/(\sdata-cms-form)(?=[\s>\/=])/', '$1 data-imprint-form', $html);
    }

    private static function alias(string $new): void
    {
        $old = self::OLD_NAMESPACE . substr($new, strlen(self::NEW_NAMESPACE));
        if (self::declared($new, false) && !self::declared($old, false)) {
            class_alias($new, $old, false);
        }
    }

    private static function declared(string $name, bool $autoload): bool
    {
        return class_exists($name, $autoload) || interface_exists($name, $autoload)
            || trait_exists($name, $autoload) || enum_exists($name, $autoload);
    }
}
