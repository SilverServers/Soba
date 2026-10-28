<?php

declare(strict_types=1);

namespace Cms\Ui;

use Cms\Html\Sanitizer;

/**
 * WHAT A MENU ITEM CAN BE TOLD — one definition, read by all three consumers.
 *
 * Each item on Appearance → Menus has a dialog of its own, and this is its
 * control set.
 *
 * ── ONE TABLE, THREE READERS, AND THAT IS THE WHOLE POINT ──────────────────
 * The dialog renders from `tabs()`. `MenuItem::replaceAll()` sanitises against
 * `defaults()`. `MenuRenderer` reads through `read()`. A key added here appears
 * in all three at once.
 *
 * The same rule computed twice drifts, and a display setting is exactly the
 * shape that rots: the template offers `hide_on_mobile`, the sanitiser has
 * never heard of it, and the control silently does nothing for a year.
 *
 * ── WHAT IS NOT IN HERE ────────────────────────────────────────────────────
 * `sub_menu_type`, `mega_columns` and `visibility_rules` are REAL COLUMNS from
 * migration 022 with live readers and writers. The dialog's "Sub-menu display
 * mode" and "Panel columns" drive those columns directly. Moving working data
 * into a new store to tidy it is how working features break.
 *
 * ── AND THERE ARE NO TIERS ─────────────────────────────────────────────────
 * No upsell panels and no greyed-out controls: if a control is drawn, it
 * works, and no tab is marked apart from the others.
 */
final class MenuItemSettings
{
    /**
     * EVERY KEY AND ITS DEFAULT. The allowlist, in the literal sense: a key that
     * is not here is dropped on save rather than stored, so a stale control on
     * an old cached page cannot write a setting nothing reads.
     *
     * @return array<string, string|bool>
     */
    public static function defaults(): array
    {
        return [
            // ── the item itself ──
            'hide_text'               => false,
            'hide_arrow'              => false,
            'disable_link'            => false,
            'hide_on_mobile'          => false,
            'hide_on_desktop'         => false,
            'close_after_click'       => false,
            'item_align'              => 'default',
            'sticky_visibility'       => 'always',
            // ── its sub-menu ──
            'align'                   => 'left',
            'hide_sub_menu_on_mobile' => false,
            'collapse_children'       => false,
            // ── its icon ──
            'icon'                    => '',
            'icon_position'           => 'left',
            // A custom icon: a picture as the icon, `icon` then being
            // `custom`. Mobile sizes blank mean the same as the desktop's.
            'custom_icon'             => '',
            'custom_icon_hover'       => '',
            'custom_icon_width'       => '20',
            'custom_icon_height'      => '20',
            'custom_icon_width_mobile'  => '',
            'custom_icon_height_mobile' => '',
            'custom_icon_align'       => 'middle',
            // ── its own styling — the Styling tab, as one JSON object ──
            'styles'                  => '',
            // ── what it is replaced with — the Replacements tab ──
            'replace_type'                    => 'disabled',
            'replace_logo'                    => '',
            'replace_logo_width'              => '100',
            'replace_logo_height'             => '40',
            'replace_search_style'            => 'expand_left',
            'replace_search_height'           => '32',
            'replace_search_width'            => '180',
            'replace_search_text'             => '',
            'replace_search_icon_colour'      => '',
            'replace_search_icon_colour_open' => '',
            'replace_search_icon'             => 'library',
            'replace_search_label'            => 'Search',
            'replace_search_bg'               => '',
            'replace_search_bg_open'          => '',
            'replace_search_radius'           => '2',
            'replace_search_placeholder'      => 'Search',
            'replace_search_offset'           => '0',
            'replace_html_mode'               => 'inner',
            'replace_html'                    => '',
            // ── the picture it shows in its panel — the Image swap tab ──
            // An attachment id as digits, '' for none.
            'image_swap'              => '',
            'image_swap_size'         => 'thumbnail',
            // ── its badge ──
            // STYLE ONE BY DEFAULT, not "Disabled". A badge is the text alone —
            // type a word and it appears — and a badge stored with no style is
            // text with no style. Defaulting to disabled would switch every one
            // of them off.
            'badge_style'             => 'one',
            'badge_text'              => '',
            'badge_hide_on_mobile'    => false,
            'badge_hide_on_desktop'   => false,
            // ── its width in its parent's panel ──
            // How many of the parent panel's columns this item's tile spans. A
            // string because every select's vocabulary here is compared as
            // strings.
            'span'                    => '1',
        ];
    }

    /**
     * THE DIALOG, TAB BY TAB.
     *
     * Each control names the key it writes, so the template cannot render a
     * control for a key the sanitiser will throw away — the two are the same
     * list.
     *
     * `depth` on a control means "only meaningful at this depth": item alignment
     * and sub-menu alignment mean nothing below the top level, and
     * collapse-children only applies INSIDE a mega panel. A control that does
     * nothing where it is drawn is a fault, so the dialog hides it and says why.
     *
     * @return array<string, array{title: string, groups: list<array{title: string, fields: list<array<string, mixed>>}>}>
     */
    public static function tabs(): array
    {
        return [
            'submenu' => [
                'title'  => 'Sub-menu',
                'glyph'  => 'table-columns',
                'groups' => [[
                    'title'  => '',
                    'fields' => [
                        [
                            // THE EXISTING COLUMN, not a settings key. Migration
                            // 022 declared `sub_menu_type` and `MenuRenderer`
                            // branches on it.
                            'key'   => 'sub_menu_type',
                            'store' => 'column',
                            'type'  => 'select',
                            'label' => 'How the sub-menu opens',
                            'options' => [
                                'standard' => 'A dropdown list',
                                'mega'     => 'A panel in columns',
                                'tabbed'   => 'A panel with tabs down the side',
                            ],
                            'help'  => 'Only does anything when this item has items under it.',
                            'depth' => 'top',
                        ],
                        [
                            'key'   => 'mega_columns',
                            'store' => 'column',
                            'type'  => 'select',
                            'label' => 'Columns in the panel',
                            'options' => self::columnChoices(),
                            'help'  => 'The panel is a grid this many columns wide. Each item under this one takes one column unless you widen it below.',
                            'showWhen' => ['sub_menu_type' => 'mega'],
                            'depth' => 'top',
                        ],
                        [
                            'key'   => 'align',
                            'type'  => 'select',
                            'label' => 'Which edge it opens from',
                            'options' => [
                                'left'  => 'The left edge of this item',
                                'right' => 'The right edge of this item',
                            ],
                            'help'  => 'A sub-menu aligned right expands leftwards, so the last item in a menu bar does not run off the screen.',
                            'depth' => 'top',
                        ],
                        [
                            // SET HERE FOR ONE ITEM, or as a tile on its parent's
                            // panel layout, which writes the same key. Only drawn
                            // when the parent's sub-menu IS a panel: a width in a
                            // dropdown list is a control that does nothing.
                            'key'   => 'span',
                            'type'  => 'select',
                            'label' => "Width in its parent's panel",
                            'options' => self::columnChoices(),
                            'help'  => "Out of the parent panel's columns.",
                            'depth' => 'nested',
                            'needs' => 'mega-parent',
                        ],
                        [
                            'key'   => 'hide_sub_menu_on_mobile',
                            'type'  => 'check',
                            'label' => 'Hide this sub-menu on a phone',
                            'help'  => 'The item still appears; what is under it does not.',
                        ],
                        [
                            'key'   => 'collapse_children',
                            'type'  => 'check',
                            'label' => 'Collapse the items under this one',
                            'help'  => 'Only applies to an item shown inside a panel.',
                            'depth' => 'nested',
                        ],
                    ],
                ]],
            ],

            'settings' => [
                'title'  => 'Settings',
                'glyph'  => 'sliders',
                'groups' => [
                    [
                        'title'  => 'This item',
                        'fields' => [
                            ['key' => 'hide_text',   'type' => 'check',
                             'label' => 'Hide the text',
                             'help'  => 'For an item that is only an icon. Give it an icon first, or it disappears entirely.'],
                            ['key' => 'hide_arrow',  'type' => 'check',
                             'label' => 'Hide the arrow',
                             'help'  => 'The mark that says this item has a sub-menu.'],
                            ['key' => 'disable_link', 'type' => 'check',
                             'label' => 'Do not make it a link',
                             'help'  => 'For a heading that only opens its sub-menu. It stops being reachable by keyboard as a link, so do not use it on an item with nothing under it.'],
                            ['key' => 'hide_on_mobile',  'type' => 'check',
                             'label' => 'Hide it on a phone'],
                            ['key' => 'hide_on_desktop', 'type' => 'check',
                             'label' => 'Hide it on a desktop'],
                            ['key' => 'close_after_click', 'type' => 'check',
                             'label' => 'Close the menu when it is clicked',
                             'help'  => 'For a link to a place on the same page, such as #about.'],
                            [
                                'key'   => 'item_align',
                                'type'  => 'select',
                                'label' => 'Where it sits in the bar',
                                'options' => [
                                    'default' => 'With the others',
                                    'left'    => 'Pushed to the left',
                                    'right'   => 'Pushed to the right',
                                ],
                                'help'  => 'Items pushed right appear in reverse order at the end of the bar.',
                                'depth' => 'top',
                            ],
                            [
                                // IN THE SETTINGS TAB: the Icon tab is the
                                // picker and nothing else.
                                'key'   => 'icon_position',
                                'type'  => 'select',
                                'label' => 'Where the icon goes',
                                'options' => ['left' => 'Before the text', 'right' => 'After the text', 'top' => 'Above the text'],
                                'showWhen' => ['icon' => '*'],
                            ],
                            [
                                // The dialog cannot see the location's Sticky tab,
                                // so the help line says where it takes effect.
                                'key'   => 'sticky_visibility',
                                'type'  => 'select',
                                'label' => 'Once the menu is stuck',
                                'options' => [
                                    'always' => 'Always shown',
                                    'show'   => 'Shown only once stuck',
                                    'hide'   => 'Hidden once stuck',
                                ],
                                'help'  => "Only where the menu's location is set to stick, on the Sticky tab of its card below.",
                            ],
                        ],
                    ],
                ],
            ],

            'icon' => [
                'title'  => 'Icon',
                'glyph'  => 'star',
                // TWO SUB-TABS: the library, and a custom icon.
                'subtabs' => true,
                'groups' => [[
                    'title'  => 'Icons',
                    'fields' => [
                        [
                            // FROM THIS ADMIN'S OWN ICON SET, not a font fetched
                            // from anywhere: EVERY glyph of the library in
                            // `Icons::all()`, and the sanitiser refuses
                            // anything outside it.
                            'key'   => 'icon',
                            'type'  => 'icon',
                            'label' => 'Menu item icon',
                        ],
                    ],
                ], [
                    'title'  => 'Custom icon',
                    'fields' => [
                        ['key' => 'custom_icon', 'type' => 'image', 'label' => 'Icon',
                         'help' => 'A picture of your own in place of a glyph. Choosing one replaces the icon picked on the Icons tab.'],
                        ['key' => 'custom_icon_hover', 'type' => 'image', 'label' => 'Icon (hover)',
                         'help' => 'Shown instead while the item is pointed at.', 'showWhen' => ['custom_icon' => '*']],
                        ['key' => 'custom_icon_width', 'type' => 'px', 'label' => 'Width', 'min' => 8, 'max' => 160,
                         'showWhen' => ['custom_icon' => '*']],
                        ['key' => 'custom_icon_height', 'type' => 'px', 'label' => 'Height', 'min' => 8, 'max' => 160,
                         'showWhen' => ['custom_icon' => '*']],
                        ['key' => 'custom_icon_width_mobile', 'type' => 'px', 'label' => 'Width on a phone', 'min' => 8, 'max' => 160,
                         'help' => "Blank: the same as a desktop's.", 'showWhen' => ['custom_icon' => '*']],
                        ['key' => 'custom_icon_height_mobile', 'type' => 'px', 'label' => 'Height on a phone', 'min' => 8, 'max' => 160,
                         'help' => "Blank: the same as a desktop's.", 'showWhen' => ['custom_icon' => '*']],
                        ['key' => 'custom_icon_align', 'type' => 'select', 'label' => 'Vertical align',
                         'options' => [
                             'middle' => 'Middle', 'top' => 'Top', 'bottom' => 'Bottom',
                             'text-top' => 'Text top', 'text-bottom' => 'Text bottom',
                             'super' => 'Super', 'sub' => 'Sub',
                         ],
                         'showWhen' => ['custom_icon' => '*']],
                    ],
                ]],
            ],

            'styling' => [
                'title'  => 'Styling',
                'glyph'  => 'droplet',
                'groups' => [[
                    'title'  => '',
                    'fields' => [
                        ['key' => 'styles', 'type' => 'styles', 'label' => 'Styling'],
                    ],
                ]],
            ],

            'replacements' => [
                // A logo, a search box, HTML, or this shop's basket — its total,
                // or how many things are in it.
                'title'  => 'Replacements',
                'glyph'  => 'repeat',
                'groups' => [[
                    'title'  => '',
                    'fields' => [
                        [
                            'key'   => 'replace_type',
                            'type'  => 'select',
                            'label' => 'Replace this item with',
                            'options' => [
                                'disabled'     => 'Nothing — it stays a link',
                                'logo'         => 'A logo',
                                'search'       => 'A search box',
                                'html'         => 'HTML',
                                'basket_total' => "The basket's total",
                                'basket_count' => 'How many things are in the basket',
                            ],
                            'help'  => 'The basket answers only on a site that sells something.',
                        ],
                        // ── a logo ──
                        ['key' => 'replace_logo', 'type' => 'image', 'label' => 'Logo', 'showWhen' => ['replace_type' => 'logo'],
                         'help' => "It links where the item links; an item going nowhere links home. To leave it out of the phone's menu, tick \"Hide it on a phone\" on the Settings tab and put a logo on the phone's toggle bar instead."],
                        ['key' => 'replace_logo_width', 'type' => 'px', 'label' => 'Width', 'min' => 16, 'max' => 400, 'showWhen' => ['replace_type' => 'logo']],
                        ['key' => 'replace_logo_height', 'type' => 'px', 'label' => 'Height', 'min' => 16, 'max' => 200, 'showWhen' => ['replace_type' => 'logo'],
                         'help' => 'Smaller than the menu bar is tall.'],
                        // ── a search box ──
                        ['key' => 'replace_search_style', 'type' => 'select', 'label' => 'Style', 'showWhen' => ['replace_type' => 'search'],
                         'options' => ['expand_left' => 'An icon that opens to the left', 'expand_right' => 'An icon that opens to the right', 'static' => 'A search box']],
                        ['key' => 'replace_search_height', 'type' => 'px', 'label' => 'Height', 'min' => 20, 'max' => 80, 'showWhen' => ['replace_type' => 'search']],
                        ['key' => 'replace_search_width', 'type' => 'px', 'label' => 'Width when open', 'min' => 80, 'max' => 400, 'showWhen' => ['replace_type' => 'search']],
                        ['key' => 'replace_search_text', 'type' => 'colour', 'label' => 'Text colour', 'showWhen' => ['replace_type' => 'search']],
                        ['key' => 'replace_search_icon_colour', 'type' => 'colour', 'label' => 'Icon colour, closed', 'showWhen' => ['replace_type' => 'search']],
                        ['key' => 'replace_search_icon_colour_open', 'type' => 'colour', 'label' => 'Icon colour, open', 'showWhen' => ['replace_type' => 'search']],
                        ['key' => 'replace_search_icon', 'type' => 'select', 'label' => 'Search icon', 'showWhen' => ['replace_type' => 'search'],
                         'options' => ['library' => 'A magnifying glass', 'item' => "This item's own icon"]],
                        ['key' => 'replace_search_label', 'type' => 'text', 'label' => "The icon's name, for a screen reader", 'maxlength' => 40, 'showWhen' => ['replace_type' => 'search']],
                        ['key' => 'replace_search_bg', 'type' => 'colour', 'label' => 'Background, closed', 'showWhen' => ['replace_type' => 'search']],
                        ['key' => 'replace_search_bg_open', 'type' => 'colour', 'label' => 'Background, open', 'showWhen' => ['replace_type' => 'search']],
                        ['key' => 'replace_search_radius', 'type' => 'px', 'label' => 'Corner radius', 'min' => 0, 'max' => 40, 'showWhen' => ['replace_type' => 'search']],
                        ['key' => 'replace_search_placeholder', 'type' => 'text', 'label' => 'Placeholder', 'maxlength' => 40, 'showWhen' => ['replace_type' => 'search']],
                        ['key' => 'replace_search_offset', 'type' => 'px', 'label' => 'Move it down', 'min' => -20, 'max' => 20, 'showWhen' => ['replace_type' => 'search']],
                        // ── HTML ──
                        ['key' => 'replace_html_mode', 'type' => 'select', 'label' => 'Mode', 'showWhen' => ['replace_type' => 'html'],
                         'options' => ['inner' => "Replace the item's text", 'outer' => 'Replace the whole item', 'href' => "Replace the item's address"]],
                        ['key' => 'replace_html', 'type' => 'html', 'label' => 'HTML', 'showWhen' => ['replace_type' => 'html'],
                         'help' => 'Cleaned as page content is — no scripts, no styles, no forms. For "the item\'s address", a path, an anchor or a web, mail or phone address.'],
                    ],
                ]],
            ],

            'badge' => [
                'title'  => 'Badge',
                'glyph'  => 'tag',
                'groups' => [[
                    'title'  => '',
                    'fields' => [
                        [
                            // Four styles. The THEME draws them from its own
                            // tokens, and none is an alert colour — a red "New"
                            // is decoration borrowing the colour that means
                            // something went wrong.
                            'key'   => 'badge_style',
                            'type'  => 'select',
                            'label' => 'Badge style',
                            'options' => [
                                'disabled' => 'No badge',
                                'one'      => 'Style 1 — quiet',
                                'two'      => 'Style 2 — solid',
                                'three'    => 'Style 3 — soft',
                                'four'     => 'Style 4 — dark',
                            ],
                        ],
                        [
                            'key'   => 'badge_text',
                            'type'  => 'text',
                            'label' => 'Badge text',
                            'help'  => 'A short word on the item — "New", "Sale". Leave it blank for none.',
                            'maxlength' => 24,
                            'showWhen'  => ['badge_style' => '!disabled'],
                        ],
                        [
                            'key'   => 'badge_hide_on_mobile',
                            'type'  => 'check',
                            'label' => 'Hide the badge on a phone',
                            'showWhen' => ['badge_style' => '!disabled'],
                        ],
                        [
                            'key'   => 'badge_hide_on_desktop',
                            'type'  => 'check',
                            'label' => 'Hide the badge on a desktop',
                            'showWhen' => ['badge_style' => '!disabled'],
                        ],
                    ],
                ]],
            ],
            'image_swap' => [
                // Its picture is drawn in the PANEL — so it is offered only on a
                // panel's own item (the picture the panel opens with) and on the
                // items in one (the picture pointing at them brings up).
                'title'     => 'Image swap',
                'glyph'  => 'images',
                'needsNote' => 'Image swap shows its picture in a mega panel — set one on a panel\'s own item, or on the items in one. This item is neither.',
                'groups'    => [[
                    'title'  => '',
                    'fields' => [
                        [
                            'key'   => 'image_swap',
                            'type'  => 'image',
                            'label' => 'Image',
                            'needs' => 'panel',
                            'help'  => "Shown in the panel's picture column while this item is pointed at. On a panel's own item, it is the picture the panel opens with.",
                        ],
                        [
                            'key'   => 'image_swap_size',
                            'type'  => 'select',
                            'label' => 'Size',
                            'needs' => 'panel',
                            'options' => [
                                'thumbnail' => 'Thumbnail (150px wide)',
                                'medium'    => 'Medium (300px wide)',
                                'large'     => 'Large (480px wide)',
                                'full'      => 'As large as it is',
                            ],
                            'showWhen' => ['image_swap' => '*'],
                        ],
                    ],
                ]],
            ],
        ];
    }

    /**
     * Every attachment an item's settings name — its styling's background
     * image and every picture field — so the editor can show the picture, not
     * the number.
     *
     * @return list<int>
     */
    public static function mediaIds(mixed $raw): array
    {
        $bag = self::read($raw);
        $ids = MenuItemStyles::mediaIds($bag['styles']);
        foreach (self::kinds() as $key => $kind) {
            if ($kind === 'image' && (string) $bag[$key] !== '') {
                $ids[] = (int) $bag[$key];
            }
        }

        return $ids;
    }

    /** @return array<string, string> key => field type, for the fields that need their type to be cleaned */
    private static function kinds(): array
    {
        static $kinds = null;
        if ($kinds === null) {
            $kinds = [];
            foreach (self::tabs() as $tab) {
                foreach ($tab['groups'] as $group) {
                    foreach ($group['fields'] as $field) {
                        $kinds[(string) $field['key']] = (string) ($field['type'] ?? 'check');
                    }
                }
            }
        }

        return $kinds;
    }

    /** @return array<string, mixed> */
    private static function fieldDef(string $key): array
    {
        foreach (self::tabs() as $tab) {
            foreach ($tab['groups'] as $group) {
                foreach ($group['fields'] as $field) {
                    if ((string) $field['key'] === $key) {
                        return $field;
                    }
                }
            }
        }

        return [];
    }

    /**
     * `1` … `MenuItem::MAX_PANEL_COLUMNS` as select options — generated, so the
     * panel's column count and an item's span can never offer different ranges.
     *
     * @return array<string, string>
     */
    public static function columnChoices(): array
    {
        $out = [];
        for ($i = 1; $i <= \Cms\Models\MenuItem::MAX_PANEL_COLUMNS; $i++) {
            $out[(string) $i] = $i === 1 ? '1 column' : $i . ' columns';
        }

        return $out;
    }

    /**
     * Read a stored bag back, merged over the defaults.
     *
     * AN UNREADABLE BAG IS THE DEFAULTS, not an error and not a blank item. A
     * malformed row must not be able to remove a link from a site's navigation
     * with nothing anywhere saying why — the same rule `MenuRenderer::audienceOf()`
     * already follows for `visibility_rules`.
     *
     * @return array<string, string|bool>
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

    /**
     * ONLY WHAT WAS ACTUALLY SET — the sparse bag, coerced but not merged.
     *
     * `read()` merges over the defaults, which is right for the RENDERER: it
     * wants a complete answer for every key. It is wrong for the EDITOR, and the
     * difference cost two assertions: handed a merged bag, the row recorded
     * `align: "left"` and `badge_text: ""` as though somebody had chosen them,
     * so "the item has 13 settings" was true of an item with none, and
     * "cancel wrote nothing" failed on a key that had been there all along.
     *
     * The dialog fills its blanks from `defaults()` instead, which it is handed
     * separately.
     *
     * @return array<string, string|bool>
     */
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

    /**
     * Sanitise a posted bag for storage. Returns null when nothing differs from
     * the defaults.
     *
     * NULL RATHER THAN `'{}'`, and rather than the full bag: a row of JSON on
     * every item in every menu restating what the renderer already assumes is a
     * fact nobody asked for, and it makes a diff of a menu unreadable.
     */
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
     * Force every value to the type and the vocabulary its key declares.
     *
     * AN ALLOWLIST FOR EVERY SELECT, because these go straight into the
     * renderer's branching and a value it has never heard of falls through to
     * some arm nobody chose. Same rule `MenuItem::replaceAll()` already applies
     * to `sub_menu_type`.
     *
     * @param  array<mixed, mixed> $raw
     * @return array<string, string|bool>
     */
    private static function coerce(array $raw): array
    {
        $choices = self::choices();
        $out     = [];

        foreach (self::defaults() as $key => $default) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }
            $value = $raw[$key];

            // A PICTURE IS AN ATTACHMENT NUMBER, and a size in pixels is a
            // bounded whole number — each field says which it is.
            $kind = self::kinds()[$key] ?? '';
            if ($kind === 'image') {
                $out[$key] = is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0
                    ? (string) (int) $value : '';
                continue;
            }
            // A COLOUR IS HEX OR THE THEME'S OWN; HTML IS CLEANED as page
            // content is, and no longer than a menu item has any use for.
            if ($kind === 'colour') {
                $value     = is_scalar($value) ? strtolower(trim((string) $value)) : '';
                $out[$key] = preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $value) ? $value : '';
                continue;
            }
            if ($kind === 'html') {
                $out[$key] = Sanitizer::clean(mb_substr(is_scalar($value) ? (string) $value : '', 0, 2000));
                continue;
            }
            if ($kind === 'px') {
                $field = self::fieldDef($key);
                $out[$key] = is_numeric($value)
                    ? (string) max((int) $field['min'], min((int) $field['max'], (int) round((float) $value)))
                    : (string) $default;
                continue;
            }

            // ITS STYLING IS ITS OWN SHAPE — an object of ticked rows, arriving
            // as JSON from the dialog or as an array — and `MenuItemStyles`
            // cleans every row. Before the scalar cast below, which would
            // turn an array into nothing.
            if ($key === 'styles') {
                $out[$key] = MenuItemStyles::sanitise($value);
                continue;
            }

            if (is_bool($default)) {
                $out[$key] = !empty($value) && $value !== 'false' && $value !== '0';
                continue;
            }

            $value = is_scalar($value) ? (string) $value : '';

            // AN ICON IS A GLYPH IN THE SET OR IT IS NOTHING. A slug with no file
            // renders an empty box where a mark should be, and logs a miss on
            // every page of the site that draws the menu.
            if ($key === 'icon') {
                $value     = trim($value);
                // …or `custom`: "the picture on the Custom icon tab".
                $out[$key] = $value === '' || $value === 'custom' || in_array($value, Icons::all(), true) ? $value : '';
                continue;
            }

            if (isset($choices[$key])) {
                $out[$key] = in_array($value, $choices[$key], true) ? $value : (string) $default;
                continue;
            }

            // A FREE-TEXT KEY IS TRIMMED AND CAPPED, never trusted whole. It is
            // escaped at output like everything else, but a 4MB badge is a
            // denial of service on the menu table rather than a badge.
            $out[$key] = mb_substr(trim($value), 0, 64);
        }

        return $out;
    }

    /**
     * The legal values for every select, derived from `tabs()` so the dialog and
     * the allowlist cannot disagree.
     *
     * @return array<string, list<string>>
     */
    private static function choices(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = [];
        foreach (self::tabs() as $tab) {
            foreach ($tab['groups'] as $group) {
                foreach ($group['fields'] as $field) {
                    if (($field['type'] ?? '') !== 'select') {
                        continue;
                    }
                    // Only the keys this class actually stores. `sub_menu_type`
                    // and `mega_columns` are real columns and are sanitised by
                    // `MenuItem::replaceAll()`, where they are written.
                    if (($field['store'] ?? '') === 'column') {
                        continue;
                    }
                    $cache[(string) $field['key']] = array_map(
                        'strval',
                        array_keys((array) ($field['options'] ?? []))
                    );
                }
            }
        }

        return $cache;
    }
}
