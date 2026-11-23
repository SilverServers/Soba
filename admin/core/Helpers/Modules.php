<?php

declare(strict_types=1);

namespace Cms\Helpers;

/**
 * MODULE SWITCHES — which optional parts of the CMS this install runs.
 * Set in config.php, every key optional,
 * every module ON unless switched off, but the assistant, which is OFF unless
 * switched on (`OFF_UNLESS_ON`):
 *
 *     'modules' => ['pages' => false, 'commerce' => false, …],
 *
 * A switch is read as yes or no (`reading()`): false, 0, and "false", "no",
 * "off", "0" or "" in any case switch a module off; true, 1, "true", "yes",
 * "on" and "1" switch it on. Anything else leaves the module as it is with no
 * switch written, and System health and an update's check say which
 * (`notes()`).
 *
 * A switched-off module is switched off, not deleted. It registers no routes
 * (its addresses answer 404, never 405 or a screen), and it shows nowhere in
 * the admin: no sidebar row, no "+ New" entry, no dashboard card or alert, no
 * field type in the form builder. Its tables and data stay where they are, so
 * switching it back on restores it as it was.
 *
 * Appearance holds Menus alone, so switching `menus` off takes the whole
 * group.
 */
final class Modules
{
    /** @var array<string, string> every switchable module => what switching it off removes */
    public const ALL = [
        'pages'        => 'static pages: their admin screens, the public page address (/{slug}), the pages sitemap and export',
        'menus'        => 'Appearance → Menus and the menu API',
        'search'       => 'the public site search (/search)',
        'commerce'     => 'the shop: cart, checkout, order receipts, orders, discount codes, shop settings',
        'appointments' => 'the Appointment field type in the form builder',
        'assistant'    => 'the Assistant chat in the admin, and its spend limit in Settings → General',
        'forms'        => 'forms: their admin screens, the inbox and the public submission address',
    ];

    /** @var list<string> the modules that are off until config.php switches them on */
    public const OFF_UNLESS_ON = ['assistant'];

    /** @var array<string, bool>|null */
    private static ?array $switches = null;

    /** Called once by the front controller with config.php's `modules`. */
    public static function configure(array $switches): void
    {
        self::$switches = [];
        foreach (array_keys(self::ALL) as $module) {
            $unwritten = self::unwritten($module);
            self::$switches[$module] = self::reading($switches[$module] ?? $unwritten) ?? $unwritten;
        }
    }

    /** Whether a module is on where config.php writes no switch for it. */
    private static function unwritten(string $module): bool
    {
        return !in_array($module, self::OFF_UNLESS_ON, true);
    }

    /**
     * A switch as config.php has it, read as yes or no: a bool, 1 or 0, or one
     * of the words above in any case. Anything else is null: neither.
     */
    public static function reading(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return is_int($value) || is_string($value)
            ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;
    }

    /**
     * WHAT TO SAY ABOUT THE SWITCHES AS WRITTEN: each one that is neither yes
     * nor no, which leaves its module on; and, for an update (`$sinceCast`),
     * each written as a word that switches its module off where releases
     * before 0.7.9 read any text as on ("false", "no", "off").
     *
     * @param  array<array-key, mixed> $switches config.php's `modules`
     * @return list<string>
     */
    public static function notes(array $switches, bool $sinceCast = false): array
    {
        $notes = [];
        foreach (array_keys(self::ALL) as $module) {
            if (!array_key_exists($module, $switches) || $switches[$module] === null) {
                continue;
            }
            $value   = $switches[$module];
            $written = "'modules' => ['{$module}' => " . (is_scalar($value) ? var_export($value, true) : gettype($value)) . ']';
            $reading = self::reading($value);
            if ($reading === null) {
                $notes[] = "{$written} is neither yes nor no, so {$module} stays " . (self::unwritten($module) ? 'on' : 'off') . ': write true or false.';
            } elseif ($sinceCast && $reading === false && (bool) $value) {
                $notes[] = "{$written} switches {$module} off from this release; until now any text there left it on.";
            }
        }

        return $notes;
    }

    /**
     * WHICH MODULE OWNS A ROUTE, by the address it is registered at (admin
     * routes are registered at `/admin/…`, whatever the install calls its
     * admin) or by its controller. A route no module owns is the CMS's own
     * and always registered. A route two modules own needs both: the menu
     * builder's list of pages belongs to `menus` and to `pages`.
     */
    private const ROUTE_PREFIXES = [
        '/admin/pages'                    => 'pages',
        '/api/v1/export/pages'            => 'pages',
        '/api/v1/menu-sources/pages'      => 'pages',
        '/sitemap-pages.xml'              => 'pages',
        '/admin/appearance/menus'         => 'menus',
        '/api/v1/menus'                   => 'menus',
        '/api/v1/menu-sources'            => 'menus',
        '/search'                         => 'search',
        '/cart'                           => 'commerce',
        '/checkout'                       => 'commerce',
        '/order'                          => 'commerce',
        '/account'                        => 'commerce',
        '/feed/products.xml'              => 'commerce',
        '/api/v1/products'                => 'commerce',
        '/api/v1/orders'                  => 'commerce',
        '/api/v1/stock'                   => 'commerce',
        '/admin/settings/api'             => 'commerce',
        '/admin/orders'                   => 'commerce',
        '/admin/coupons'                  => 'commerce',
        '/admin/settings/commerce'        => 'commerce',
        '/admin/stock'                    => 'commerce',
        '/admin/customers'                => 'commerce',
        '/admin/reports'                  => 'commerce',
        '/api/v1/export/products'         => 'commerce',
        '/admin/assistant'                => 'assistant',
        '/admin/forms'                    => 'forms',
        '/forms'                          => 'forms',
    ];

    private const ROUTE_HANDLERS = [
        'Cms\\Controllers\\Public\\PageController'     => 'pages',   // the public /{slug} catch-all
        'Cms\\Controllers\\Admin\\PageController'      => 'pages',
        'Cms\\Controllers\\Admin\\MenuController'      => 'menus',
        'Cms\\Controllers\\Api\\MenuController'        => 'menus',
        'Cms\\Controllers\\Public\\SearchController'   => 'search',
        'Cms\\Controllers\\Public\\ShopController'     => 'commerce',
        'Cms\\Controllers\\Public\\AccountController'  => 'commerce',
        'Cms\\Controllers\\Public\\FeedController'     => 'commerce',
        'Cms\\Controllers\\Api\\ShopApiController'     => 'commerce',
        'Cms\\Controllers\\Admin\\ApiSettingsController' => 'commerce',
        'Cms\\Controllers\\Admin\\OrderController'     => 'commerce',
        'Cms\\Controllers\\Admin\\CouponController'    => 'commerce',
        'Cms\\Controllers\\Admin\\StockController'     => 'commerce',
        'Cms\\Controllers\\Admin\\CustomerController'  => 'commerce',
        'Cms\\Controllers\\Admin\\ReportController'    => 'commerce',
        'Cms\\Controllers\\Admin\\AssistantController' => 'assistant',
        'Cms\\Controllers\\Admin\\FormController'      => 'forms',
        'Cms\\Controllers\\Public\\FormController'     => 'forms',
    ];

    /**
     * May this route be registered? False when a module that owns it is
     * switched off. Prefixes match whole path segments: `/search` owns
     * `/search` and `/search/…`, never `/searching`.
     *
     * @param array<int, mixed> $handler [class, method, …]
     */
    public static function allowsRoute(string $pattern, array $handler): bool
    {
        $owners = [];
        $class  = ltrim((string) ($handler[0] ?? ''), '\\');
        if (isset(self::ROUTE_HANDLERS[$class])) {
            $owners[] = self::ROUTE_HANDLERS[$class];
        }
        foreach (self::ROUTE_PREFIXES as $prefix => $module) {
            if ($pattern === $prefix || str_starts_with($pattern, $prefix . '/')) {
                $owners[] = $module;
            }
        }
        foreach ($owners as $module) {
            if (!self::enabled($module)) {
                return false;
            }
        }

        return true;
    }

    public static function enabled(string $module): bool
    {
        if (self::$switches === null) {
            // Outside the front controller (a CLI script): read config.php directly.
            $config = defined('ROOT_PATH') ? \Cms\Site\ConfigFile::read(ROOT_PATH . '/config/config.php') : null;
            self::configure(is_array($config) && is_array($config['modules'] ?? null) ? $config['modules'] : []);
        }

        return self::$switches[$module] ?? true;
    }
}
