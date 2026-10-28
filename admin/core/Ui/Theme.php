<?php

declare(strict_types=1);

namespace Cms\Ui;

/**
 * Theme registry and resolution.
 *
 * `data-theme` and `data-radius` are TWO INDEPENDENT AXES on `<html>`, composed
 * freely: 21 palettes across two families, times `modern` (rounded controls and
 * floaters) or `classic` (flat throughout). The radius preference never decides
 * whether the page's *content* rounds — `--r-none` is 0 under both. That is the
 * invariant; the preference is a dial on top of it.
 *
 * BOTH ATTRIBUTES MUST BE SERVER-RENDERED. If JavaScript stamps them after load,
 * every navigation paints the default theme for a frame and then swaps.
 *
 * ── ADDING A THEME ──────────────────────────────────────────────────────────
 *   1. Add a row below with a `family` of 'signature' or 'legacy', plus `bg` and
 *      `chrome` — these feed the first-paint skeleton, so set them to the
 *      colours the CSS block actually resolves to.
 *   2. Add a `:root[data-theme="<key>"]` block to the matching family file under
 *      `www/admin/assets/css/tokens/`, defining the FULL variable set. A theme
 *      that omits a token inherits the default's value for it, which is how a
 *      "mostly right" theme ships with one wrong colour nobody can find.
 *
 * ── STORAGE ─────────────────────────────────────────────────────────────────
 * Both preferences live on the `users` row — `theme_preset` (migration 006) and
 * `radius_pref` (migration 037). One table, no new store, and none is needed:
 * fewer storage layers, not more operations.
 *
 * Migration 006 originally defaulted `theme_preset` to `'silverservers-dark'`, a
 * key no theme here has, so every row resolved to the default below — correct
 * behaviour, and completely invisible, because the fallback IS the default. 037
 * re-points the column and migrates the one known dead value; `resolve()` still
 * validates, so an unrecognised key remains harmless rather than fatal.
 *
 * DESIGN-LINT: RAW-HEX-OK — this class IS the theme colour vocabulary. Every
 * palette's first-paint colours are declared here and mirrored by the token
 * sheets; a colour here is the source, not an invention.
 */
final class Theme
{
    public const DEFAULT_KEY    = 'tuxedo';

    /**
     * FLAT IS THE DEFAULT. `modern` rounds every input, select and button at
     * 6px against form cards that are correctly sharp. `classic` zeroes every
     * tier, floaters included.
     *
     * The `--r-*` ROLES are untouched: the preference scales the tiers, it does
     * not abolish them, so a user who sets `modern` still gets round controls
     * and round modals. Nothing in the stylesheets changes.
     */
    public const DEFAULT_RADIUS = 'classic';

    /** The radius preference axis. Both values are legal on every theme. */
    public const RADII = ['modern', 'classic'];

    /**
     * Every registered theme.
     *
     * `bg` mirrors the theme's `--bg` token. `chrome` mirrors the resolved
     * background of the top nav and the sidebar rail. All three are emitted
     * inline on `<html>` and painted by the first-paint skeleton in head.php, so
     * the blank frame before the render-blocking stylesheets parse already shows
     * the app's structure in-theme instead of flashing white. A stale value here
     * can only mis-tint a sub-second skeleton; it can never affect the loaded UI.
     *
     * @return list<array{key:string,family:string,label:string,kind:string,bg:string,chrome:array{nav:string,rail:string},note:string}>
     */
    public static function all(): array
    {
        return [
            ['key' => 'tuxedo',    'family' => 'signature', 'label' => 'Tuxedo',    'kind' => 'light',
             'bg' => '#edf1f5', 'chrome' => ['nav' => '#0d1218', 'rail' => '#06090c'],
             'note' => 'Light content with deep cool black chrome — sidebar and top nav inverted.'],

            ['key' => 'midnight',  'family' => 'signature', 'label' => 'Midnight',  'kind' => 'dark',
             'bg' => '#06090c', 'chrome' => ['nav' => '#0d1218', 'rail' => '#06090c'],
             'note' => 'Very dark cool black with the locked green accent.'],

            ['key' => 'pearl',     'family' => 'signature', 'label' => 'Pearl',     'kind' => 'light',
             'bg' => '#edf1f5', 'chrome' => ['nav' => '#fafcfd', 'rail' => '#edf1f5'],
             'note' => 'Cool-grey light — the lighter of the two cool light themes.'],

            ['key' => 'deep-blue', 'family' => 'signature', 'label' => 'Deep Blue', 'kind' => 'dark',
             'bg' => '#001921', 'chrome' => ['nav' => '#052530', 'rail' => '#001921'],
             'note' => 'Navy ground, dim teal panels, green accent.'],

            ['key' => 'slate',     'family' => 'signature', 'label' => 'Slate',     'kind' => 'light',
             'bg' => '#c4cbd2', 'chrome' => ['nav' => '#d2d8de', 'rail' => '#c4cbd2'],
             'note' => 'Pronounced cool grey light — a step down from Pearl.'],

            ['key' => 'oled',      'family' => 'signature', 'label' => 'OLED',      'kind' => 'dark',
             'bg' => '#000000', 'chrome' => ['nav' => '#0a0a0a', 'rail' => '#000000'],
             'note' => 'True black for OLED displays, with slightly lifted chrome.'],

            ['key' => 'kawaii',    'family' => 'signature', 'label' => 'Kawaii',    'kind' => 'light',
             'bg' => '#fef5f8', 'chrome' => ['nav' => '#ffffff', 'rail' => '#fef5f8'],
             'note' => 'Soft pinks with a functional yellow accent — pastel but readable.'],

            ['key' => 'ember',     'family' => 'signature', 'label' => 'Ember',     'kind' => 'light',
             'bg' => '#fdf8f0', 'chrome' => ['nav' => '#0d1218', 'rail' => '#06090c'],
             'note' => "Warm off-white content with black chrome and an orange accent — Tuxedo's warm sibling."],

            ['key' => 'pink',            'family' => 'legacy', 'label' => 'Pink',            'kind' => 'light',
             'bg' => '#f4f4f4', 'chrome' => ['nav' => '#5e1129', 'rail' => '#241810'],
             'note' => 'Light content, wine-pink top nav, brown sidebar.'],

            ['key' => 'pink-dark',       'family' => 'legacy', 'label' => 'Pink Dark',       'kind' => 'dark',
             'bg' => '#080504', 'chrome' => ['nav' => '#5e1129', 'rail' => '#241810'],
             'note' => 'Dark content with the same wine-pink chrome.'],

            ['key' => 'orange',          'family' => 'legacy', 'label' => 'Orange',          'kind' => 'light',
             'bg' => '#f4f4f4', 'chrome' => ['nav' => '#6e3208', 'rail' => '#241810'],
             'note' => 'Light content, burnt-orange top nav, brown sidebar.'],

            ['key' => 'orange-dark',     'family' => 'legacy', 'label' => 'Orange Dark',     'kind' => 'dark',
             'bg' => '#080604', 'chrome' => ['nav' => '#6e3208', 'rail' => '#241810'],
             'note' => 'Dark content with the same burnt-orange chrome.'],

            ['key' => 'green',           'family' => 'legacy', 'label' => 'Green',           'kind' => 'light',
             'bg' => '#f4f4f4', 'chrome' => ['nav' => '#3e4f1c', 'rail' => '#241810'],
             'note' => 'Light content, olive-green top nav, brown sidebar.'],

            ['key' => 'green-dark',      'family' => 'legacy', 'label' => 'Green Dark',      'kind' => 'dark',
             'bg' => '#080704', 'chrome' => ['nav' => '#3e4f1c', 'rail' => '#241810'],
             'note' => 'Dark content with the same olive-green chrome.'],

            ['key' => 'blue',            'family' => 'legacy', 'label' => 'Blue',            'kind' => 'light',
             'bg' => '#f4f4f4', 'chrome' => ['nav' => '#1f3b82', 'rail' => '#241810'],
             'note' => 'Light content, deep-blue top nav, brown sidebar.'],

            ['key' => 'blue-dark',       'family' => 'legacy', 'label' => 'Blue Dark',       'kind' => 'dark',
             'bg' => '#070403', 'chrome' => ['nav' => '#1f3b82', 'rail' => '#241810'],
             'note' => 'Dark content with the same deep-blue chrome.'],

            ['key' => 'light-pink',      'family' => 'legacy', 'label' => 'Light Pink',      'kind' => 'light',
             'bg' => '#f4f4f4', 'chrome' => ['nav' => '#8a3a5e', 'rail' => '#241810'],
             'note' => 'Light content, soft-rose top nav, brown sidebar.'],

            ['key' => 'light-pink-dark', 'family' => 'legacy', 'label' => 'Light Pink Dark', 'kind' => 'dark',
             'bg' => '#080504', 'chrome' => ['nav' => '#8a3a5e', 'rail' => '#241810'],
             'note' => 'Dark content with the same soft-rose chrome.'],

            ['key' => 'purple',          'family' => 'legacy', 'label' => 'Purple',          'kind' => 'light',
             'bg' => '#f4f4f4', 'chrome' => ['nav' => '#5a2855', 'rail' => '#241810'],
             'note' => 'Light content, plum top nav, brown sidebar.'],

            ['key' => 'purple-dark',     'family' => 'legacy', 'label' => 'Purple Dark',     'kind' => 'dark',
             'bg' => '#070505', 'chrome' => ['nav' => '#5a2855', 'rail' => '#241810'],
             'note' => 'Dark content with the same plum chrome.'],

            ['key' => 'night',           'family' => 'legacy', 'label' => 'Night',           'kind' => 'dark',
             'bg' => '#060606', 'chrome' => ['nav' => '#1a1a1a', 'rail' => '#060606'],
             'note' => 'Near-black content and chrome, light grey ink.'],
        ];
    }

    public static function exists(string $key): bool
    {
        foreach (self::all() as $t) {
            if ($t['key'] === $key) {
                return true;
            }
        }
        return false;
    }

    /** A theme's definition row, or the default's if the key is unregistered. */
    public static function get(string $key): array
    {
        foreach (self::all() as $t) {
            if ($t['key'] === $key) {
                return $t;
            }
        }
        foreach (self::all() as $t) {
            if ($t['key'] === self::DEFAULT_KEY) {
                return $t;
            }
        }
        // Unreachable: the registry always contains DEFAULT_KEY.
        return ['key' => self::DEFAULT_KEY, 'family' => 'signature', 'label' => self::DEFAULT_KEY,
                'kind' => 'light', 'bg' => '#edf1f5',
                'chrome' => ['nav' => '#edf1f5', 'rail' => '#edf1f5'], 'note' => ''];
    }

    /**
     * Which theme to render for this viewer.
     *
     * There are no per-route overrides. One user, one theme — a second axis of
     * resolution buys nothing here and is a second thing that can disagree with
     * the picker.
     *
     * @param array<string, mixed>|null $user
     */
    public static function resolve(?array $user): string
    {
        $stored = (string) ($user['theme_preset'] ?? '');
        // NOT CHOSEN (NULL since migration 070) or not a palette: the site's
        // own, from Appearance → Branding (`Brand::adminTheme()`), whose fallback
        // is DEFAULT_KEY.
        return self::exists($stored) ? $stored : Brand::adminTheme();
    }

    /** @param array<string, mixed>|null $user */
    public static function resolveRadius(?array $user): string
    {
        $stored = (string) ($user['radius_pref'] ?? '');
        return in_array($stored, self::RADII, true) ? $stored : Brand::adminRadius();
    }

    /**
     * The three colours the first-paint skeleton needs.
     *
     * @return array{page: string, nav: string, rail: string}
     */
    public static function paint(string $key): array
    {
        $t  = self::get($key);
        $bg = $t['bg'];

        return [
            'page' => $bg,
            'nav'  => $t['chrome']['nav']  ?? $bg,
            'rail' => $t['chrome']['rail'] ?? $bg,
        ];
    }

    /** `light` or `dark` — drives `color-scheme`, so native widgets match. */
    public static function scheme(string $key): string
    {
        return self::get($key)['kind'] === 'light' ? 'light' : 'dark';
    }
}
