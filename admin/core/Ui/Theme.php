<?php

declare(strict_types=1);

namespace Cms\Ui;

/**
 * The admin's themes, and which one a page wears.
 *
 * THE CMS SHIPS THREE: Tuxedo, the default, and Midnight and Pearl, its
 * full-dark and full-light versions (`tokens/signature-styles.css`). A site
 * adds its own as one stylesheet in `www/site/admin-themes/<key>.css`, the
 * site's folder, which no package ships and no update touches. Nothing
 * registers it: the file is the theme.
 *
 * A SITE'S THEME is written the way Tuxedo's blocks are: a
 * `:root[data-theme="<key>"]` block with the full set of tokens, and, for
 * chrome of its own, a block scoped to `.nav-top` and one to the sidebar
 * (`.nav-rail`, `.sb`, `.entity-rail-col`, `.nav-drawer`), each with the full
 * set Tuxedo's have. A comment line `Name: <name>` names it; without one it
 * is named after its file. Whether it is light or dark, and the three colours
 * the first-paint skeleton needs, are read from its own blocks, so there is
 * nothing to keep in step with them. `php admin/bin/admin-theme.php check
 * <file>` checks a new one.
 *
 * WHICH A SITE OFFERS (`offered()`): every one, Tuxedo first, unless
 * config.php names them — `'admin_themes' => ['clientname', 'tuxedo']` —
 * when it is those, in that order. The first offered is the default.
 *
 * WHO WEARS WHICH: a user's own choice (`users.theme_preset`, migration 006),
 * set on their account; the pages at the sign-in address wear the one
 * Settings → Branding names (`Branding::signInTheme()`). Anything else, or a
 * theme no longer there or no longer offered, is the default.
 *
 * `data-theme` and `data-radius` are two independent attributes on `<html>`,
 * and BOTH MUST BE SERVER-RENDERED: if JavaScript stamps them after load,
 * every navigation paints the default for a frame and then swaps. The radius
 * is `classic`, square, unless a user's row says otherwise; no screen offers
 * it.
 *
 * DESIGN-LINT: RAW-HEX-OK — this class IS the theme colour vocabulary. The
 * shipped themes' first-paint colours are declared here and mirrored by the
 * token sheet; a colour here is the source, not an invention.
 */
final class Theme
{
    /**
     * FLAT IS THE DEFAULT. `modern` rounds every input, select and button at
     * 6px against form cards that are correctly sharp. `classic` zeroes every
     * tier, floaters included.
     */
    public const DEFAULT_RADIUS = 'classic';

    /** The radius axis. Both values are legal on every theme. */
    public const RADII = ['modern', 'classic'];

    /** Where a site keeps its own admin themes, under its web root. */
    public const SITE_DIR = 'site/admin-themes';

    /** A site theme's key: its file name, plain enough for an attribute and a path. */
    private const KEY = '/^[a-z0-9][a-z0-9-]{0,40}$/';

    /** @var list<array<string, mixed>>|null */
    private static ?array $site = null;

    /** @var list<string>|null config.php's 'admin_themes', once read */
    private static ?array $named = null;

    /**
     * Every theme a page may wear: the three the CMS ships, then the site's own.
     *
     * `bg` is the theme's `--bg`; `chrome` the top bar's and the sidebar's
     * backgrounds. All three are painted by the first-paint skeleton in
     * head.php, so the blank frame before the stylesheets parse already shows
     * the admin's structure in its colours. `href` is a site theme's
     * stylesheet, null for the shipped ones.
     *
     * @return list<array{key:string,label:string,kind:string,bg:string,chrome:array{nav:string,rail:string},href:?string}>
     */
    public static function all(): array
    {
        return array_merge([
            ['key' => 'tuxedo',   'label' => 'Tuxedo',   'kind' => 'light',
             'bg' => '#edf1f5', 'chrome' => ['nav' => '#0d1218', 'rail' => '#06090c'], 'href' => null],
            ['key' => 'midnight', 'label' => 'Midnight', 'kind' => 'dark',
             'bg' => '#06090c', 'chrome' => ['nav' => '#0d1218', 'rail' => '#06090c'], 'href' => null],
            ['key' => 'pearl',    'label' => 'Pearl',    'kind' => 'light',
             'bg' => '#edf1f5', 'chrome' => ['nav' => '#fafcfd', 'rail' => '#edf1f5'], 'href' => null],
        ], self::site());
    }

    /**
     * The themes this site offers, in its order: the ones config.php names
     * (`'admin_themes'`), passing over a key that names no theme, or, without
     * the key or with none that names one, every theme. The first is the
     * default.
     *
     * @return list<array{key:string,label:string,kind:string,bg:string,chrome:array{nav:string,rail:string},href:?string}>
     */
    public static function offered(): array
    {
        $all = self::all();
        $out = [];
        foreach (self::named() as $key) {
            foreach ($all as $t) {
                if ($t['key'] === $key && !in_array($t, $out, true)) {
                    $out[] = $t;
                }
            }
        }

        return $out !== [] ? $out : $all;
    }

    /** The theme an account wears until it chooses one: the first the site offers. */
    public static function defaultKey(): string
    {
        return (string) self::offered()[0]['key'];
    }

    /** Whether the site offers this theme: the only ones an account or the sign-in pages may wear. */
    public static function offers(string $key): bool
    {
        foreach (self::offered() as $t) {
            if ($t['key'] === $key) {
                return true;
            }
        }
        return false;
    }

    /** A theme's row, or the default's when the key names none. */
    public static function get(string $key): array
    {
        foreach (self::all() as $t) {
            if ($t['key'] === $key) {
                return $t;
            }
        }
        return self::offered()[0];
    }

    /**
     * Which theme a signed-in user's pages wear: their own choice while the
     * site offers it, else the default.
     *
     * @param array<string, mixed>|null $user
     */
    public static function resolve(?array $user): string
    {
        $stored = (string) ($user['theme_preset'] ?? '');
        return self::offers($stored) ? $stored : self::defaultKey();
    }

    /** @param array<string, mixed>|null $user */
    public static function resolveRadius(?array $user): string
    {
        $stored = (string) ($user['radius_pref'] ?? '');
        return in_array($stored, self::RADII, true) ? $stored : self::DEFAULT_RADIUS;
    }

    /**
     * The three colours the first-paint skeleton needs.
     *
     * @return array{page: string, nav: string, rail: string}
     */
    public static function paint(string $key): array
    {
        $t = self::get($key);
        return ['page' => $t['bg'], 'nav' => $t['chrome']['nav'], 'rail' => $t['chrome']['rail']];
    }

    /** `light` or `dark`: drives `color-scheme`, so native widgets match. */
    public static function scheme(string $key): string
    {
        return self::get($key)['kind'] === 'dark' ? 'dark' : 'light';
    }

    /** A site theme's stylesheet address, or null for a theme the CMS ships. */
    public static function stylesheet(string $key): ?string
    {
        return self::get($key)['key'] === $key ? self::get($key)['href'] : null;
    }

    /** Forget the site's themes and config.php's list read this request: after a test writes one. */
    public static function reset(): void
    {
        self::$site  = null;
        self::$named = null;
    }

    /**
     * config.php's `'admin_themes'`: the keys it lists, or [] without it. A
     * value that is not a list of names counts as none.
     *
     * @return list<string>
     */
    public static function named(): array
    {
        if (self::$named === null) {
            $config = defined('ROOT_PATH') ? \Cms\Site\ConfigFile::read(ROOT_PATH . '/config/config.php') : null;
            $value  = is_array($config) ? ($config['admin_themes'] ?? []) : [];
            self::$named = is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
        }

        return self::$named;
    }

    /** The list `named()` gives, set by a test; null reads config.php again. */
    public static function configure(?array $keys): void
    {
        self::$named = $keys === null ? null : array_values(array_filter($keys, 'is_string'));
    }

    /**
     * The site's own themes: each `www/site/admin-themes/<key>.css` whose key is
     * plain, is not a shipped theme's, and which holds its
     * `:root[data-theme="<key>"]` block. Sorted by name.
     *
     * @return list<array<string, mixed>>
     */
    private static function site(): array
    {
        if (self::$site !== null) {
            return self::$site;
        }
        $themes = [];
        $files  = defined('PUBLIC_PATH') ? (glob(PUBLIC_PATH . '/' . self::SITE_DIR . '/*.css') ?: []) : [];
        foreach ($files as $file) {
            $key = basename($file, '.css');
            if (preg_match(self::KEY, $key) !== 1 || in_array($key, ['tuxedo', 'midnight', 'pearl'], true)
                || !is_file($file) || filesize($file) > 512 * 1024) {
                continue;
            }
            $theme = self::read($key, (string) file_get_contents($file));
            if ($theme !== null) {
                $themes[] = $theme;
            }
        }
        usort($themes, static fn (array $a, array $b): int => strcasecmp((string) $a['label'], (string) $b['label']));

        return self::$site = $themes;
    }

    /**
     * A site theme's row from its stylesheet, or null when it has no
     * `:root[data-theme="<key>"]` block. Public for `admin/bin/admin-theme.php`,
     * which reads a file the same way the admin will.
     *
     * @return array{key:string,label:string,kind:string,bg:string,chrome:array{nav:string,rail:string},href:string}|null
     */
    public static function read(string $key, string $css): ?array
    {
        // ITS NAME from its comment line `Name: …`, then every comment out of
        // the way, so no comment is read as part of a selector.
        $name = preg_match('/^\s*(?:\/\*+|\*+)?\s*Name:\s*(.+?)\s*(?:\*\/)?\s*$/m', $css, $m) === 1 ? mb_substr(trim($m[1], " */"), 0, 60) : '';
        $css  = (string) preg_replace('/\/\*.*?\*\//s', '', $css);
        $root = self::block($css, ':root[data-theme="' . $key . '"]');
        if ($root === null) {
            return null;
        }
        $bg   = self::hex($root['--bg'] ?? '') ?? '#edf1f5';
        $nav  = self::block($css, '.nav-top', $key);
        $rail = self::block($css, '.sb', $key) ?? self::block($css, '.nav-rail', $key);

        return [
            'key'    => $key,
            'label'  => $name !== '' ? $name : ucwords(str_replace('-', ' ', $key)),
            'kind'   => trim((string) ($root['--color-scheme'] ?? '')) === 'dark' ? 'dark' : 'light',
            'bg'     => $bg,
            'chrome' => [
                'nav'  => self::hex($nav['--bg'] ?? $nav['--surface'] ?? '') ?? $bg,
                'rail' => self::hex($rail['--bg'] ?? '') ?? $bg,
            ],
            'href'   => \Cms\Helpers\Asset::site(self::SITE_DIR . '/' . $key . '.css'),
        ];
    }

    /**
     * The custom properties of the first rule whose selector list holds
     * `$selector` — scoped under the theme's `:root[data-theme]` when `$key`
     * is given — or null when there is none.
     *
     * @return array<string, string>|null
     */
    private static function block(string $css, string $selector, ?string $key = null): ?array
    {
        $want = $key === null ? $selector : ':root[data-theme="' . $key . '"] ' . $selector;
        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER) === false) {
            return null;
        }
        foreach ($rules as $rule) {
            $selectors = array_map(static fn (string $s): string => (string) preg_replace('/\s+/', ' ', trim($s)), explode(',', $rule[1]));
            if (!in_array($want, $selectors, true)) {
                continue;
            }
            preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $rule[2], $props, PREG_SET_ORDER);
            $out = [];
            foreach ($props as $p) {
                $out[strtolower($p[1])] = trim($p[2]);
            }
            return $out;
        }
        return null;
    }

    /**
     * Whether a colour is dark, so what sits on it should be light: its
     * relative luminance is below the point where white and black read
     * equally well on it. A value that is not a colour counts as light.
     */
    public static function isDark(string $colour): bool
    {
        $l = self::luminance($colour);
        return $l !== null && $l < 0.179;
    }

    /** The contrast ratio of two colours (WCAG 2), or null when either is not a `#hex` colour. */
    public static function contrast(string $a, string $b): ?float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        if ($la === null || $lb === null) {
            return null;
        }
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** WCAG 2's relative luminance of a `#hex` colour, or null. */
    private static function luminance(string $colour): ?float
    {
        $hex = self::hex($colour);
        if ($hex === null) {
            return null;
        }
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $c = array_map(static function (string $pair): float {
            $v = hexdec($pair) / 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split($hex, 2));

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    /** A `#rgb` or `#rrggbb` colour, lower-cased, or null. */
    private static function hex(string $value): ?string
    {
        $value = strtolower(trim($value));
        return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $value) === 1 ? $value : null;
    }
}
