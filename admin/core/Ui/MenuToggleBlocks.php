<?php

declare(strict_types=1);

namespace Cms\Ui;

use Cms\Html\Sanitizer;

/**
 * THE PHONE'S TOGGLE BAR — and its designer.
 *
 * On a phone the menu collapses behind a bar, and the owner builds that bar
 * from blocks: the menu toggle, an animated toggle, a spacer, a logo, a search
 * box, an icon link, a block of HTML, and a toggle drawn from two images. Each
 * block sits in the bar's left, centre or right, in the order it was dragged
 * to.
 *
 * Stored per location in `menu_locations.toggle_blocks` (migration 052) as a
 * list of blocks — `type`, `align` and the type's own fields — and read by
 * `MenuRenderer`. `types()` is the one definition the designer, the sanitiser
 * and the renderer share, the way `MenuItemSettings::tabs()` is for items.
 *
 * ── A BAR ALWAYS HAS A TOGGLE ──────────────────────────────────────────────
 * Without the last toggle, a phone would show a bar with no way to open the
 * menu. The designer will not delete the last one, and the sanitiser puts one
 * back if a posted bar has none — a menu nobody on a phone can open is not a
 * style.
 */
final class MenuToggleBlocks
{
    public const ALIGN = ['left', 'center', 'right'];

    /** The most blocks one bar holds. A phone is 375px wide. */
    public const MAX_BLOCKS = 12;

    /** The block types that open the menu. */
    public const TOGGLES = ['menu_toggle', 'menu_toggle_animated', 'menu_toggle_custom'];

    /**
     * Every block type, its name in the designer, its glyph, and its fields in
     * order. A field's `type` is one of: text, url, select, check, number,
     * colour, icon, image, html.
     *
     * @return array<string, array{title: string, glyph: string, fields: list<array<string, mixed>>}>
     */
    public static function types(): array
    {
        $sizes = ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'];

        return [
            'menu_toggle' => [
                'title'  => 'Menu toggle',
                'glyph'  => 'bars',
                'fields' => [
                    ['key' => 'closed_text', 'type' => 'text', 'label' => 'Text while closed', 'default' => 'Menu', 'max' => 30],
                    ['key' => 'open_text', 'type' => 'text', 'label' => 'Text while open', 'default' => 'Menu', 'max' => 30],
                    // THE DEFAULTS: three lines after the word while closed, a
                    // cross while open. Blank, the phone's bar would read "Menu"
                    // and nothing else.
                    ['key' => 'closed_icon', 'type' => 'icon', 'label' => 'Icon while closed', 'default' => 'bars'],
                    ['key' => 'open_icon', 'type' => 'icon', 'label' => 'Icon while open', 'default' => 'x'],
                    ['key' => 'icon_position', 'type' => 'select', 'label' => 'Where the icon goes',
                     'options' => ['before' => 'Before the text', 'after' => 'After the text'], 'default' => 'after'],
                    ['key' => 'text_size', 'type' => 'select', 'label' => 'Text size', 'options' => $sizes, 'default' => 'medium'],
                    ['key' => 'icon_size', 'type' => 'select', 'label' => 'Icon size', 'options' => $sizes, 'default' => 'medium'],
                    ['key' => 'text_colour', 'type' => 'colour', 'label' => 'Text colour', 'default' => ''],
                    ['key' => 'icon_colour', 'type' => 'colour', 'label' => 'Icon colour', 'default' => ''],
                ],
            ],
            'menu_toggle_animated' => [
                'title'  => 'Menu toggle (animated)',
                'glyph'  => 'bars',
                'fields' => [
                    ['key' => 'size', 'type' => 'select', 'label' => 'Size',
                     'options' => ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large', 'x_large' => 'Extra large'],
                     'default' => 'medium'],
                    ['key' => 'colour', 'type' => 'colour', 'label' => 'Colour', 'default' => ''],
                    ['key' => 'label', 'type' => 'text', 'label' => 'Its name, for a screen reader', 'default' => 'Menu', 'max' => 40],
                ],
            ],
            'menu_toggle_custom' => [
                'title'  => 'Menu toggle (images)',
                'glyph'  => 'image',
                'fields' => [
                    ['key' => 'closed_image', 'type' => 'image', 'label' => 'Image while closed', 'default' => 0],
                    ['key' => 'open_image', 'type' => 'image', 'label' => 'Image while open', 'default' => 0],
                    ['key' => 'label', 'type' => 'text', 'label' => 'Its name, for a screen reader', 'default' => 'Menu', 'max' => 40],
                ],
            ],
            'spacer' => [
                'title'  => 'Spacer',
                'glyph'  => 'arrows-left-right',
                'fields' => [
                    ['key' => 'width', 'type' => 'number', 'label' => 'Width (px)', 'min' => 0, 'max' => 200, 'default' => 16],
                ],
            ],
            'logo' => [
                'title'  => 'Logo',
                'glyph'  => 'image',
                'fields' => [
                    ['key' => 'image', 'type' => 'image', 'label' => 'Image', 'default' => 0],
                    ['key' => 'url', 'type' => 'url', 'label' => 'Links to', 'default' => '/'],
                    ['key' => 'new_window', 'type' => 'check', 'label' => 'Open it in a new tab', 'default' => false],
                    ['key' => 'max_height', 'type' => 'number', 'label' => 'Tallest it can be (px)', 'min' => 16, 'max' => 120, 'default' => 32],
                    ['key' => 'offset', 'type' => 'number', 'label' => 'Move it down (px)', 'min' => -20, 'max' => 20, 'default' => 0],
                ],
            ],
            'search' => [
                'title'  => 'Search',
                'glyph'  => 'magnifying-glass',
                'fields' => [
                    ['key' => 'style', 'type' => 'select', 'label' => 'Style',
                     'options' => [
                         'expand_left'  => 'An icon that opens to the left',
                         'expand_right' => 'An icon that opens to the right',
                         'static'       => 'A search box',
                     ], 'default' => 'expand_left'],
                    ['key' => 'placeholder', 'type' => 'text', 'label' => 'Placeholder', 'default' => 'Search', 'max' => 40],
                    ['key' => 'width', 'type' => 'number', 'label' => 'Width when open (px)', 'min' => 80, 'max' => 320, 'default' => 180],
                ],
            ],
            'icon' => [
                'title'  => 'Icon',
                'glyph'  => 'star',
                'fields' => [
                    ['key' => 'icon', 'type' => 'icon', 'label' => 'Icon', 'default' => 'phone'],
                    ['key' => 'url', 'type' => 'url', 'label' => 'Links to', 'default' => ''],
                    ['key' => 'title', 'type' => 'text', 'label' => 'What it is, for a screen reader', 'default' => '', 'max' => 60],
                    ['key' => 'new_window', 'type' => 'check', 'label' => 'Open it in a new tab', 'default' => false],
                    ['key' => 'size', 'type' => 'select', 'label' => 'Size', 'options' => $sizes, 'default' => 'medium'],
                    ['key' => 'colour', 'type' => 'colour', 'label' => 'Colour', 'default' => ''],
                ],
            ],
            'html' => [
                'title'  => 'HTML',
                'glyph'  => 'terminal',
                'fields' => [
                    ['key' => 'html', 'type' => 'html', 'label' => 'HTML', 'default' => '', 'max' => 2000],
                ],
            ],
        ];
    }

    /**
     * THE BAR WITH NOTHING CHOSEN: one menu toggle reading "Menu", on the left —
     * where this theme's phone header has always had it, right after the site's
     * name.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaults(): array
    {
        // AT THE RIGHT.
        return [self::block('menu_toggle', 'right')];
    }

    /**
     * A new block of `$type` with every field at its default.
     *
     * @return array<string, mixed>
     */
    public static function block(string $type, string $align = 'right'): array
    {
        $out = ['type' => $type, 'align' => in_array($align, self::ALIGN, true) ? $align : 'right'];
        foreach (self::types()[$type]['fields'] ?? [] as $field) {
            $out[(string) $field['key']] = $field['default'];
        }

        return $out;
    }

    /**
     * A stored bar read back: every block whole, unknown types gone, and the
     * default bar for anything unreadable — the same rule as every other store
     * on this screen.
     *
     * @return list<array<string, mixed>>
     */
    public static function read(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return self::defaults();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::defaults();
        }

        return self::coerce($decoded);
    }

    /**
     * Clean a posted bar for storage — JSON, or NULL for the default bar.
     */
    public static function sanitise(mixed $raw): ?string
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return null;
        }

        $clean = self::coerce($raw);

        return $clean === self::defaults() ? null : (string) json_encode($clean);
    }

    /**
     * @param  array<mixed, mixed> $raw
     * @return list<array<string, mixed>>
     */
    private static function coerce(array $raw): array
    {
        $types = self::types();
        $out   = [];

        foreach (array_values($raw) as $block) {
            if (count($out) >= self::MAX_BLOCKS) {
                break;
            }
            if (!is_array($block) || !isset($types[(string) ($block['type'] ?? '')])) {
                continue;
            }
            $type  = (string) $block['type'];
            $clean = self::block($type, (string) ($block['align'] ?? 'right'));
            foreach ($types[$type]['fields'] as $field) {
                $key = (string) $field['key'];
                if (array_key_exists($key, $block)) {
                    $clean[$key] = self::clean($field, $block[$key]);
                }
            }
            $out[] = $clean;
        }

        // A BAR ALWAYS HAS A TOGGLE — see the class docblock.
        $hasToggle = false;
        foreach ($out as $block) {
            $hasToggle = $hasToggle || in_array($block['type'], self::TOGGLES, true);
        }
        if (!$hasToggle) {
            array_unshift($out, self::defaults()[0]);
            $out = array_slice($out, 0, self::MAX_BLOCKS);
        }

        return $out;
    }

    /**
     * ONE VALUE, CLEANED FOR ITS FIELD. Every one of these reaches the site's
     * markup — a class name, a custom property, an `href`, an image — so each is
     * the shape it claims or the field's default.
     *
     * @param array<string, mixed> $field
     */
    public static function clean(array $field, mixed $value): mixed
    {
        $default = $field['default'];

        switch ((string) $field['type']) {
            case 'check':
                return !empty($value) && $value !== 'false' && $value !== '0';

            case 'number':
                if (!is_numeric($value)) {
                    return $default;
                }

                return (int) max((int) $field['min'], min((int) $field['max'], (int) round((float) $value)));

            case 'select':
                $value = is_scalar($value) ? (string) $value : '';

                return array_key_exists($value, (array) $field['options']) ? $value : $default;

            case 'colour':
                // A HEX COLOUR OR THE THEME'S OWN. It goes into a `style`
                // attribute as a custom property, so nothing else gets through.
                $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';

                return preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $value) ? $value : '';

            case 'icon':
                $value = is_scalar($value) ? trim((string) $value) : '';

                return $value === '' || in_array($value, Icons::all(), true) ? $value : $default;

            case 'image':
                return is_numeric($value) ? max(0, (int) $value) : 0;

            case 'url':
                return self::url(is_scalar($value) ? (string) $value : '', (string) $default);

            case 'html':
                return Sanitizer::clean(mb_substr(is_scalar($value) ? (string) $value : '', 0, (int) ($field['max'] ?? 2000)));

            default:   // text
                $value = is_scalar($value) ? trim((string) $value) : '';

                return mb_substr($value, 0, (int) ($field['max'] ?? 60));
        }
    }

    /**
     * A LINK SOMEBODY TYPED: a path on this site, a place on the page, or one
     * of the schemes `Sanitizer` allows in content. `javascript:` and its
     * relatives are the reason this exists.
     */
    private static function url(string $url, string $default): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('~^(/(?!/)|#)~', $url)) {
            return mb_substr($url, 0, 500);
        }
        if (preg_match('~^(https?://|mailto:|tel:)~i', $url)) {
            return mb_substr($url, 0, 500);
        }

        return $default;
    }
}
