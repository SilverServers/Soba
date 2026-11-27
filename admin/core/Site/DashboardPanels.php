<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\Auth\Auth;

/**
 * A SITE'S OWN DASHBOARD PANELS: this week's events, a switch staff flip, the
 * table to fill in, the first thing an editor sees on signing in.
 *
 * DECLARED IN CODE, in the site's own `admin/config/dashboard.php`, which no
 * package ships and no update touches, because a panel is the site's code:
 *
 *     \Cms\Site\DashboardPanels::register('this-week', [
 *         'title'    => 'This week',
 *         'role'     => 'editor',      // the least role that sees it: every role unless named
 *         'width'    => 'half',        // 'full' (the default), 'half' or 'third'
 *         'render'   => static fn (array $user): string => '<p>…</p>',
 *         // or 'template' => 'dashboard/this-week.php', in admin/site/, given $user
 *     ]);
 *     \Cms\Site\DashboardPanels::instead();   // in place of the CMS's own panels
 *
 * A panel's body is HTML: what its callable returns, or what its template
 * prints. It is the site's own code and is printed as it is, so escaping what
 * it prints from the database is its job, as a theme template's is. The
 * panels are drawn first, above the CMS's own (unread form entries, next
 * steps, each type's count, recently edited), in the order declared; with
 * `instead()`, in their place, for anyone who sees at least one, so an
 * account the site gives no panel still has the CMS's.
 *
 * A row that is not one (a bad key, no title, an unknown role or width,
 * neither a callable nor a template, a template outside admin/site/) is said
 * in the log and left out, and a panel that fails as it is drawn is said in
 * the log and drawn as a sentence saying so, so a fault never takes the
 * dashboard down.
 */
final class DashboardPanels
{
    /** Who sees a panel whose row names no role: everyone signed in. */
    public const ROLE = 'author';

    /** A panel's share of the row. */
    public const WIDTHS = ['full', 'half', 'third'];

    private const KEY = '/^[a-z0-9][a-z0-9-]{0,40}$/';

    /** A template's path in admin/site/: folders and a name, ending .php, nothing climbing out. */
    private const TEMPLATE = '~^[a-z0-9_-]+(?:/[a-z0-9_-]+)*\.php$~i';

    /** @var array<string, array<string, mixed>> */
    private static array $panels = [];

    private static bool $instead = false;

    private static bool $loaded = false;

    /**
     * Declare a panel. Its definition is checked here, once: a row that is
     * not one is logged and left out.
     *
     * @param array<string, mixed> $definition
     */
    public static function register(string $key, array $definition): void
    {
        $problem = self::problem($key, $definition);
        if ($problem !== null) {
            error_log('Dashboard panels: "' . $key . '" left out: ' . $problem);
            return;
        }
        self::$panels[$key] = [
            'key'      => $key,
            'title'    => trim((string) $definition['title']),
            'role'     => (string) ($definition['role'] ?? self::ROLE),
            'width'    => (string) ($definition['width'] ?? 'full'),
            'render'   => $definition['render'] ?? null,
            'template' => isset($definition['template']) ? (string) $definition['template'] : null,
        ];
    }

    /** The site's panels drawn in place of the CMS's own, for anyone who sees one. */
    public static function instead(bool $instead = true): void
    {
        self::$instead = $instead;
    }

    /**
     * The panels `$user` sees, in the order declared.
     *
     * @param array<string, mixed> $user
     * @return list<array<string, mixed>>
     */
    public static function forUser(array $user): array
    {
        self::load();

        return array_values(array_filter(self::$panels, static fn (array $p): bool =>
            (Auth::ROLE_LEVELS[(string) ($user['role'] ?? '')] ?? 0) >= (Auth::ROLE_LEVELS[$p['role']] ?? PHP_INT_MAX)));
    }

    /**
     * Whether the CMS's own panels are drawn for `$user`: always, unless the
     * site asks for its panels in their place and `$user` sees one.
     *
     * @param array<string, mixed> $user
     */
    public static function ownShown(array $user): bool
    {
        self::load();

        return !self::$instead || self::forUser($user) === [];
    }

    /**
     * A panel's body, drawn: its callable's HTML or its template's output.
     * One that fails is logged and says so in a sentence.
     *
     * @param array<string, mixed> $panel
     * @param array<string, mixed> $user
     */
    public static function draw(array $panel, array $user): string
    {
        $level = ob_get_level();
        try {
            if ($panel['render'] !== null) {
                $html = ($panel['render'])($user);
                if (!is_string($html)) {
                    throw new \UnexpectedValueException('its callable gave no HTML');
                }
                return $html;
            }
            $file = ROOT_PATH . '/site/' . $panel['template'];
            if (!is_file($file)) {
                throw new \RuntimeException('there is no admin/site/' . $panel['template']);
            }
            ob_start();
            (static function (string $file, array $user): void {
                require $file;
            })($file, $user);
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            error_log('Dashboard panels: "' . $panel['key'] . '" could not be drawn: ' . $e->getMessage());
            return '<p class="form-help">This panel could not be drawn. The reason is in the error log.</p>';
        }
    }

    /** Forget every declaration, for a script that declares its own. */
    public static function reset(): void
    {
        self::$panels  = [];
        self::$instead = false;
        self::$loaded  = true;
    }

    /** Read the site's list once, quietly (`ConfigFile::read()`). */
    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        if (defined('ROOT_PATH') && is_file(ROOT_PATH . '/config/dashboard.php')) {
            ConfigFile::read(ROOT_PATH . '/config/dashboard.php');
        }
    }

    /** @param array<string, mixed> $definition */
    private static function problem(string $key, array $definition): ?string
    {
        return match (true) {
            preg_match(self::KEY, $key) !== 1                                     => 'its key is not lower-case letters, digits and dashes',
            trim((string) ($definition['title'] ?? '')) === ''                    => 'it has no title',
            !isset(Auth::ROLE_LEVELS[(string) ($definition['role'] ?? self::ROLE)]) => 'its role is not one of ' . implode(', ', array_keys(Auth::ROLE_LEVELS)),
            !in_array((string) ($definition['width'] ?? 'full'), self::WIDTHS, true) => 'its width is not one of ' . implode(', ', self::WIDTHS),
            isset($definition['render']) === isset($definition['template'])         => 'it names neither a callable nor a template, or both',
            isset($definition['render']) && !is_callable($definition['render'])      => 'its render is not a callable',
            isset($definition['template']) && (preg_match(self::TEMPLATE, (string) $definition['template']) !== 1
                || str_contains((string) $definition['template'], '..'))           => 'its template is not a path in admin/site/ ending .php',
            default                                                                => null,
        };
    }
}
