<?php

declare(strict_types=1);

namespace Cms\Ui;

/**
 * HOW A MENU BEHAVES WHERE IT IS SHOWN.
 *
 * One card per menu location. It sits on the pair, not on the menu: migration
 * 049 made one menu able to occupy the header AND the footer, and those two
 * want different answers — a header opens sub-menus on hover, a footer has
 * none to open.
 *
 * Stored in `menu_locations.settings` (migration 050), read by `MenuRenderer`.
 *
 * ── THE CARD'S TABS ────────────────────────────────────────────────────────
 * `tabs()` lays the card out as tabs — General, Advanced, Sticky and Page
 * overlay — and each setting sits in one of them.
 *
 * ── A SETTING FOR THE HEADER'S BAR IS NOT OFFERED ANYWHERE ELSE ────────────
 * `MenuRenderer::isBar()`. A footer menu is a row of links: nothing opens,
 * nothing animates, nothing collapses on a phone, nothing sticks. Drawing
 * every control for every location would offer a footer's card six settings
 * that the footer's markup never reads. A field marked `'bar' => true` is
 * drawn only where the renderer draws the bar.
 *
 * ── FOUR KINDS OF CONTROL ──────────────────────────────────────────────────
 *   select  — `options`, an allowlist; anything else reads as the default.
 *   check   — a checkbox. Stored `on` / `off`; the card posts an
 *             `off` before the box so an unticked box is a value and not an
 *             absence (an absence reads as the DEFAULT, and a setting whose
 *             default is on could then never be turned off).
 *   number  — a free-text number, bounded: `min`, `max`, `step`,
 *             stored in one canonical form so "1.0" and "1" are one value.
 *   row     — several of the above under one label, as in
 *             "Stick on: Desktop / Mobile". Stores nothing itself; its controls
 *             inherit its `bar` and `showWhen` (see `fields()`).
 *
 * ── WHAT IS DELIBERATELY NOT OFFERED ───────────────────────────────────────
 * **"Enabled"** — a menu in a location is shown there, so a tick box saying
 * "yes really" would be a control with one legal value — and a control that
 * can change nothing is not drawn.
 *
 * **"Theme"** — a whole styling subsystem (fonts, colours, spacing, per-level
 * typography) that is a theme's job, not a location card's. Drawing the
 * selector with one entry in it would be exactly the "control that lies" this
 * file is otherwise arguing against.
 *
 * **Settings that negotiate with a theme the menu does not control** —
 * unbinding another script's events on the same links, prefixing item classes
 * that collide with the theme's, the element to wrap the menu in, which of
 * several menu calls to take over, and a shortcode, block or PHP snippet to
 * put a menu on a page. The CMS's theme calls `MenuRenderer::render()` once
 * per location and nothing else touches the markup, so each would be a
 * control with nothing on the other end of it.
 */
final class MenuLocationSettings
{
    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            // ── General ──
            'event'         => 'hover',
            'effect'        => 'fade_up',
            'effect_speed'  => '200',
            'mobile'        => 'panel',
            'breakpoint'    => '720',
            'orientation'         => 'horizontal',
            'accordion_behaviour' => 'open_parents',
            // ── Advanced ──
            'second_click'  => 'go',
            // ALL OPEN BY DEFAULT: that is what this theme's phone panel does,
            // and a menu saved before the setting existed would otherwise
            // fold.
            'mobile_behaviour' => 'standard',
            'mobile_state'     => 'expand_all',
            'descriptions'  => 'off',
            // ── Sticky ──
            'sticky_enabled'        => 'off',
            'sticky_desktop'        => 'on',
            'sticky_mobile'         => 'off',
            'sticky_opacity'        => '1',
            'sticky_offset'         => '0',
            'sticky_expand'         => 'off',
            'sticky_expand_mobile'  => 'off',
            'sticky_hide'           => 'off',
            'sticky_hide_tolerance' => '10',
            'sticky_hide_offset'    => '0',
            // ── Page overlay ──
            'overlay_desktop' => 'off',
            'overlay_mobile'  => 'off',
        ];
    }

    /**
     * THE CARD: its tabs, each a list of fields.
     *
     * @return array<string, array{title: string, fields: list<array<string, mixed>>}>
     */
    public static function tabs(): array
    {
        return [
            'general' => [
                'title'  => 'General',
                'fields' => [
                    [
                        'key'       => 'event',
                        'label'     => 'Sub-menus open on',
                        'bar'       => true,
                        'options'   => [
                            'hover' => 'Hovering over the item',
                            'click' => 'Clicking the item',
                        ],
                        'help' => 'Hover is what most site navigation does. Click is steadier on a laptop trackpad and is what a touchscreen gets either way.',
                    ],
                    [
                        'key'       => 'effect',
                        'label'     => 'How a sub-menu appears',
                        'bar'       => true,
                        'options'   => [
                            'none'       => 'Immediately',
                            'fade'       => 'Fades in',
                            'fade_up'    => 'Fades in and rises',
                            'slide_down' => 'Slides down',
                        ],
                    ],
                    [
                        'key'       => 'effect_speed',
                        'label'     => 'How quickly',
                        'bar'       => true,
                        'options'   => [
                            '150' => 'Fast',
                            '200' => 'Normal',
                            '300' => 'Slow',
                        ],
                        // A SPEED FOR AN ANIMATION THAT IS NOT PLAYING is a
                        // control that does nothing, so it shows only when
                        // there is an effect.
                        'showWhen' => ['effect' => '!none'],
                    ],
                    [
                        'key'       => 'mobile',
                        'label'     => 'On a phone, the menu becomes',
                        'bar'       => true,
                        'options'   => [
                            'panel'       => 'A panel that drops down',
                            'slide_left'  => 'A drawer from the left',
                            'slide_right' => 'A drawer from the right',
                        ],
                    ],
                    [
                        'key'       => 'breakpoint',
                        'label'     => 'Below this width',
                        'bar'       => true,
                        // ── TWO OPTIONS, NOT FOUR, AND THAT IS A RETREAT ────
                        // This offered 560 / 720 / 960 for about an hour. The
                        // theme's phone block is a hard-coded
                        // `@media (max-width: 720px)`, so the other two moved
                        // the DRAWER'S styling and not the width at which the
                        // menu actually collapses — a control that did half of
                        // what it said, which is the exact fault the rest of
                        // this file argues against.
                        //
                        // Parameterising the theme's own block per breakpoint is
                        // real work on working CSS and is not being done in
                        // passing. Until it is, the control offers the two
                        // answers that are true.
                        'options' => [
                            '720' => 'The theme\'s own width (720px)',
                            '0'   => 'Never — always show the full menu',
                        ],
                        'help' => 'Below this width the menu collapses behind its button. "Never" keeps the full menu at every size, which only suits two or three short items.',
                    ],
                    [
                        'key'     => 'orientation',
                        'label'   => 'Orientation',
                        'bar'     => true,
                        'options' => [
                            'horizontal' => 'Horizontal — a bar',
                            'vertical'   => 'Vertical — a column, sub-menus open beside it',
                            'accordion'  => 'Accordion — a column, sub-menus open beneath their item',
                        ],
                        'help' => 'On a phone every menu is the phone panel, whatever this says.',
                    ],
                    [
                        'key'      => 'accordion_behaviour',
                        'label'    => 'The accordion starts',
                        'bar'      => true,
                        'showWhen' => ['orientation' => 'accordion'],
                        'options'  => [
                            'open_parents'     => 'Open around the current page',
                            'open_all'         => 'With every sub-menu open',
                            'collapse_parents' => 'With every sub-menu closed',
                        ],
                    ],
                ],
            ],
            'advanced' => [
                'title'  => 'Advanced',
                'fields' => [
                    [
                        'key'       => 'second_click',
                        'label'     => 'Clicking an item a second time',
                        'bar'       => true,
                        'options'   => [
                            'go'    => 'Follows the link',
                            'close' => 'Closes the sub-menu',
                        ],
                        // NOT HIDDEN ON HOVER. It applies on a phone too: a tap
                        // on an open item on a phone does one or the other.
                        'help' => 'When an item\'s sub-menu is already open: where sub-menus open on a click, in an accordion, and always on a phone.',
                    ],
                    [
                        'key'     => 'mobile_behaviour',
                        'label'   => 'On a phone, opening a sub-menu',
                        'bar'     => true,
                        'options' => [
                            'standard'  => 'Leaves the others open',
                            'accordion' => 'Closes the others beside it',
                        ],
                    ],
                    [
                        'key'     => 'mobile_state',
                        'label'   => 'On a phone, sub-menus start',
                        'bar'     => true,
                        'options' => [
                            'expand_all'    => 'All open',
                            'collapse_all'  => 'All closed',
                            'expand_active' => 'Open only around the current page',
                        ],
                        'help' => 'A tap on a closed item opens it.',
                    ],
                    [
                        // Per LOCATION, because the header wants a compact bar
                        // and a footer is where a line under each link can earn
                        // its space.
                        'key'   => 'descriptions',
                        'type'  => 'check',
                        'label' => 'Item descriptions',
                        'text'  => "Show each item's description under its label",
                        'help'  => "An item's description is written in its row in the menu structure above.",
                    ],
                ],
            ],
            'sticky' => [
                'title'  => 'Sticky',
                'fields' => [
                    [
                        'key'   => 'sticky_enabled',
                        'type'  => 'check',
                        'label' => 'Sticky',
                        'text'  => 'Stick the menu to the top of the window as the page scrolls',
                        'bar'   => true,
                    ],
                    [
                        'type'     => 'row',
                        'label'    => 'Stick on',
                        'bar'      => true,
                        'showWhen' => ['sticky_enabled' => 'on'],
                        'help'     => 'Only if nothing around the menu sticks already — two sticky things fight over the top of the window.',
                        'fields'   => [
                            ['key' => 'sticky_desktop', 'type' => 'check', 'text' => 'Desktop'],
                            ['key' => 'sticky_mobile',  'type' => 'check', 'text' => 'Phone'],
                        ],
                    ],
                    [
                        'key'      => 'sticky_opacity',
                        'type'     => 'number',
                        'label'    => 'Opacity once stuck',
                        'min'      => 0.2,
                        'max'      => 1,
                        'step'     => 0.1,
                        'bar'      => true,
                        'showWhen' => ['sticky_enabled' => 'on'],
                        'help'     => 'From 0.2 to 1. Below 1 the page shows through the bar.',
                    ],
                    [
                        'key'      => 'sticky_offset',
                        'type'     => 'number',
                        'label'    => 'Gap above it once stuck (px)',
                        'min'      => 0,
                        'max'      => 200,
                        'step'     => 1,
                        'bar'      => true,
                        'showWhen' => ['sticky_enabled' => 'on'],
                        'help'     => 'The distance between the top of the window and the stuck menu.',
                    ],
                    [
                        'type'     => 'row',
                        'label'    => 'Background across the window',
                        'bar'      => true,
                        'showWhen' => ['sticky_enabled' => 'on', 'orientation' => 'horizontal'],
                        'help'     => "Once stuck, the bar's background spans the whole window rather than only the menu.",
                        'fields'   => [
                            ['key' => 'sticky_expand',        'type' => 'check', 'text' => 'Desktop'],
                            ['key' => 'sticky_expand_mobile', 'type' => 'check', 'text' => 'Phone'],
                        ],
                    ],
                    [
                        'type'     => 'row',
                        'label'    => 'Hide until scrolling up',
                        'bar'      => true,
                        'showWhen' => ['sticky_enabled' => 'on', 'orientation' => 'horizontal'],
                        'help'     => 'The stuck bar slides away as the page scrolls down and comes back as soon as it scrolls up. Tolerance is how far the page has to move first; offset is how far down the page this starts.',
                        'fields'   => [
                            ['key' => 'sticky_hide', 'type' => 'check', 'text' => 'On'],
                            [
                                'key'  => 'sticky_hide_tolerance', 'type' => 'number', 'text' => 'Tolerance (px)',
                                'min'  => 0, 'max' => 200, 'step' => 1,
                                'showWhen' => ['sticky_hide' => 'on'],
                            ],
                            [
                                'key'  => 'sticky_hide_offset', 'type' => 'number', 'text' => 'Offset (px)',
                                'min'  => 0, 'max' => 2000, 'step' => 1,
                                'showWhen' => ['sticky_hide' => 'on'],
                            ],
                        ],
                    ],
                ],
            ],
            'overlay' => [
                // Its colour is not a setting here: the theme dims the page with
                // its own default.
                'title'  => 'Page overlay',
                'fields' => [
                    [
                        'type'   => 'row',
                        'label'  => 'Dim the page behind it',
                        'bar'    => true,
                        'help'   => 'While the menu is in use: on a desktop while the pointer or the keyboard is in it or a sub-menu is open, on a phone while its panel is open.',
                        'fields' => [
                            ['key' => 'overlay_desktop', 'type' => 'check', 'text' => 'Desktop'],
                            ['key' => 'overlay_mobile',  'type' => 'check', 'text' => 'Phone'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Every control that STORES something, every tab, in order — what the
     * sanitiser and the gate walk, so neither can disagree with the card about
     * what exists.
     *
     * A row stores nothing itself; its controls are listed in its place, each
     * inheriting the row's `bar` and adding the row's `showWhen` to its own — a
     * control in a hidden row is hidden, and one in a bar-only row is bar-only.
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(): array
    {
        $out = [];
        foreach (self::tabs() as $tab) {
            foreach ($tab['fields'] as $field) {
                if (($field['type'] ?? '') !== 'row') {
                    $out[] = $field;
                    continue;
                }
                foreach ($field['fields'] as $leaf) {
                    if (!empty($field['bar'])) {
                        $leaf['bar'] = true;
                    }
                    $when = (array) ($leaf['showWhen'] ?? []) + (array) ($field['showWhen'] ?? []);
                    if ($when !== []) {
                        $leaf['showWhen'] = $when;
                    }
                    $out[] = $leaf;
                }
            }
        }

        return $out;
    }

    /**
     * Read a stored bag back, merged over the defaults.
     *
     * AN UNREADABLE BAG IS THE DEFAULTS. The other way round, one malformed row
     * changes how a site's navigation behaves with nothing anywhere saying why —
     * the same rule `MenuItemSettings::read()` and `MenuRenderer::audienceOf()`
     * both follow.
     *
     * @return array<string, string>
     */
    public static function read(mixed $raw): array
    {
        $defaults = self::defaults();

        if (!is_string($raw) || $raw === '') {
            return $defaults;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }

        return self::coerce($decoded) + $defaults;
    }

    /** Only what differs from the defaults — what the editor should show as set. */
    public static function stored(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $defaults = self::defaults();
        $out      = [];
        foreach (self::coerce($decoded) as $key => $value) {
            if ($value !== $defaults[$key]) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** Sanitise for storage; null when nothing differs from the defaults. */
    public static function sanitise(mixed $raw): ?string
    {
        if (is_string($raw) && $raw !== '') {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return null;
        }

        $defaults = self::defaults();
        $clean    = self::coerce($raw);
        $out      = [];

        foreach ($defaults as $key => $default) {
            if (array_key_exists($key, $clean) && $clean[$key] !== $default) {
                $out[$key] = $clean[$key];
            }
        }

        return $out === [] ? null : json_encode($out);
    }

    /**
     * The values a field accepts, as the gate samples them: every option of a
     * select, both states of a check, and the ends and default of a number.
     *
     * @param  array<string, mixed> $field
     * @return list<string>
     */
    public static function samples(array $field): array
    {
        return match ($field['type'] ?? 'select') {
            'check'  => ['on', 'off'],
            'number' => [
                self::number((float) $field['min']),
                self::number((float) $field['max']),
                self::defaults()[(string) $field['key']],
            ],
            default  => array_map('strval', array_keys((array) ($field['options'] ?? []))),
        };
    }

    /**
     * AN ALLOWLIST FOR EVERY KEY, derived from `tabs()` so the card and the
     * sanitiser cannot disagree. These go into the renderer's branching and a
     * value it has never heard of falls through to an arm nobody chose.
     *
     * @param  array<mixed, mixed> $raw
     * @return array<string, string>
     */
    private static function coerce(array $raw): array
    {
        $defaults = self::defaults();
        $out      = [];

        foreach (self::fields() as $field) {
            $key = (string) $field['key'];
            if (!array_key_exists($key, $raw)) {
                continue;
            }
            $value     = is_scalar($raw[$key]) ? trim((string) $raw[$key]) : '';
            $out[$key] = self::clean($field, $value, $defaults[$key]);
        }

        return $out;
    }

    /** @param array<string, mixed> $field */
    private static function clean(array $field, string $value, string $default): string
    {
        switch ($field['type'] ?? 'select') {
            case 'check':
                return in_array($value, ['on', 'off'], true) ? $value : $default;

            case 'number':
                // BOUNDED, then snapped to the step, then written one way. This
                // goes into a style attribute, so it is a number or it is the
                // default.
                if (!is_numeric($value)) {
                    return $default;
                }
                $n    = max((float) $field['min'], min((float) $field['max'], (float) $value));
                $step = (float) ($field['step'] ?? 1);
                if ($step > 0) {
                    $n = round($n / $step) * $step;
                }

                return self::number($n);

            default:
                $allowed = array_map('strval', array_keys((array) ($field['options'] ?? [])));

                return in_array($value, $allowed, true) ? $value : $default;
        }
    }

    /** One canonical spelling per number: `1`, `0.5`, `10` — never `1.0`. */
    private static function number(float $n): string
    {
        $s = rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');

        return $s === '-0' ? '0' : $s;
    }
}
