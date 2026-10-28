<?php

declare(strict_types=1);

namespace Cms\Ui;

use Cms\Models\Media;

/**
 * ONE ITEM'S OWN STYLING — the menu item dialog's Styling tab: styling for
 * this item only, over what the menu's theme sets. Every row has its own tick
 * box, and only a ticked row is applied; `rows()` is the list, in order.
 *
 * ── STORED AS ONE KEY IN THE ITEM'S BAG ────────────────────────────────────
 * `MenuItemSettings`' `styles` holds these as one JSON object of the ticked
 * rows only — forty-odd keys in the flat bag would bury the twenty that are
 * switches, and "which rows are ticked" is exactly "which keys are present".
 *
 * ── DRAWN AS A CLASS AND A CUSTOM PROPERTY PER ROW ─────────────────────────
 * The CMS has no build step, so a ticked row puts a class on the item
 * (`menu__item--st-size`) and its value in a custom property
 * (`--st-size: 18px`); the theme owns one rule per row that reads it. A row
 * that is not ticked emits nothing, so it cannot override the theme with a
 * fallback nobody chose. Every value is a hex colour, a bounded whole number
 * of pixels, an allowlisted keyword or an attachment id — nothing typed
 * reaches the stylesheet as typed.
 */
final class MenuItemStyles
{
    /**
     * The rows, in order. `type`: colour, colour2 (a colour and an optional
     * second for a gradient), px, select, image.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        $weights = ['300' => 'Light (300)', '400' => 'Normal (400)', '700' => 'Bold (700)'];
        $decor   = ['none' => 'None', 'underline' => 'Underline'];
        $px      = static fn (string $key, string $label, int $min, int $max, int $default = 0, string $note = ''): array =>
            ['key' => $key, 'label' => $label, 'type' => 'px', 'min' => $min, 'max' => $max, 'default' => $default, 'note' => $note];
        $notTop  = 'Not recommended for a top-level item.';

        return [
            ['key' => 'bg', 'label' => 'Background colour', 'type' => 'colour2'],
            ['key' => 'bg_hover', 'label' => 'Background colour (hover)', 'type' => 'colour2'],
            ['key' => 'color', 'label' => 'Text colour', 'type' => 'colour'],
            ['key' => 'color_hover', 'label' => 'Text colour (hover)', 'type' => 'colour'],
            ['key' => 'weight', 'label' => 'Font weight', 'type' => 'select', 'options' => $weights, 'default' => '700'],
            ['key' => 'weight_hover', 'label' => 'Font weight (hover)', 'type' => 'select', 'options' => $weights, 'default' => '700'],
            $px('size', 'Font size', 8, 48, 16),
            ['key' => 'align', 'label' => 'Text align', 'type' => 'select',
             'options' => ['left' => 'Left', 'center' => 'Centre', 'right' => 'Right'], 'default' => 'left'],
            ['key' => 'transform', 'label' => 'Text transform', 'type' => 'select',
             'options' => ['none' => 'Normal', 'capitalize' => 'Capitalise', 'uppercase' => 'Uppercase', 'lowercase' => 'Lowercase'],
             'default' => 'uppercase'],
            ['key' => 'decoration', 'label' => 'Text decoration', 'type' => 'select', 'options' => $decor, 'default' => 'underline'],
            ['key' => 'decoration_hover', 'label' => 'Text decoration (hover)', 'type' => 'select', 'options' => $decor, 'default' => 'underline'],
            ['key' => 'border_color', 'label' => 'Border colour', 'type' => 'colour'],
            ['key' => 'border_color_hover', 'label' => 'Border colour (hover)', 'type' => 'colour'],
            $px('border_top', 'Border width (top)', 0, 20, 1),
            $px('border_right', 'Border width (right)', 0, 20, 1),
            $px('border_bottom', 'Border width (bottom)', 0, 20, 1),
            $px('border_left', 'Border width (left)', 0, 20, 1),
            $px('radius_tl', 'Border radius (top left)', 0, 50, 4),
            $px('radius_tr', 'Border radius (top right)', 0, 50, 4),
            $px('radius_br', 'Border radius (bottom right)', 0, 50, 4),
            $px('radius_bl', 'Border radius (bottom left)', 0, 50, 4),
            $px('icon_size', 'Icon size', 8, 64, 16),
            ['key' => 'icon_color', 'label' => 'Icon colour', 'type' => 'colour'],
            ['key' => 'icon_color_hover', 'label' => 'Icon colour (hover)', 'type' => 'colour'],
            $px('padding_left', 'Padding (left)', 0, 60, 8),
            $px('padding_right', 'Padding (right)', 0, 60, 8),
            $px('padding_top', 'Padding (top)', 0, 60, 4, $notTop),
            $px('padding_bottom', 'Padding (bottom)', 0, 60, 4, $notTop),
            $px('margin_left', 'Margin (left)', -40, 60, 0),
            $px('margin_right', 'Margin (right)', -40, 60, 0),
            $px('margin_top', 'Margin (top)', -40, 60, 0, $notTop),
            $px('margin_bottom', 'Margin (bottom)', -40, 60, 0, $notTop),
            $px('height', 'Height', 16, 120, 40),
            $px('sub_width', 'Sub-menu width', 120, 1200, 240),
            $px('sub_x', 'Sub-menu horizontal offset', -400, 400, 0),
            $px('sub_y', 'Sub-menu vertical offset', -100, 100, 0),
            ['key' => 'sub_bg', 'label' => 'Sub-menu background colour', 'type' => 'colour2'],
            ['key' => 'sub_image', 'label' => 'Sub-menu background image', 'type' => 'image'],
            // THREE ROWS THAT SHAPE THE IMAGE ROW ABOVE (`with`): they carry a
            // value its rule reads, and do nothing without it — so they add no
            // class of their own, and say so under the control.
            ['key' => 'sub_image_size', 'label' => 'Sub-menu background image size', 'type' => 'select', 'with' => 'sub_image',
             'options' => ['auto' => 'Auto', 'cover' => 'Cover', 'contain' => 'Contain'], 'default' => 'cover',
             'note' => 'With a sub-menu background image.'],
            ['key' => 'sub_image_repeat', 'label' => 'Sub-menu background image repeat', 'type' => 'select', 'with' => 'sub_image',
             'options' => ['no-repeat' => 'No repeat', 'repeat' => 'Repeat', 'repeat-x' => 'Repeat across', 'repeat-y' => 'Repeat down'],
             'default' => 'no-repeat', 'note' => 'With a sub-menu background image.'],
            ['key' => 'sub_image_position', 'label' => 'Sub-menu background image position', 'type' => 'select',
             'with' => 'sub_image', 'note' => 'With a sub-menu background image.',
             'options' => [
                 'left top' => 'Left top', 'left center' => 'Left centre', 'left bottom' => 'Left bottom',
                 'right top' => 'Right top', 'right center' => 'Right centre', 'right bottom' => 'Right bottom',
                 'center top' => 'Centre top', 'center center' => 'Centre', 'center bottom' => 'Centre bottom',
             ],
             'default' => 'right bottom'],
        ];
    }

    /** @return array<string, array<string, mixed>> key => row */
    private static function byKey(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::rows() as $row) {
                $map[(string) $row['key']] = $row;
            }
        }

        return $map;
    }

    /**
     * A stored value read back: the ticked rows, cleaned. Anything unreadable
     * is no styling at all.
     *
     * @return array<string, int|string>
     */
    public static function read(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : json_decode($raw, true);
        }

        return is_array($raw) ? self::coerce($raw) : [];
    }

    /** Clean for storage: canonical JSON of the ticked rows, or '' for none. */
    public static function sanitise(mixed $raw): string
    {
        $clean = self::read($raw);

        return $clean === [] ? '' : (string) json_encode($clean);
    }

    /**
     * @param  array<mixed, mixed> $raw
     * @return array<string, int|string>
     */
    private static function coerce(array $raw): array
    {
        $out = [];
        foreach (self::byKey() as $key => $row) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }
            $value = $raw[$key];

            switch ((string) $row['type']) {
                case 'colour':
                case 'colour2':
                    $hex = self::hex($value);
                    if ($hex === '') {
                        break;
                    }
                    $out[$key] = $hex;
                    // The second colour of a gradient, kept only when it differs.
                    if ($row['type'] === 'colour2') {
                        $to = self::hex($raw[$key . '_to'] ?? '');
                        if ($to !== '' && $to !== $hex) {
                            $out[$key . '_to'] = $to;
                        }
                    }
                    break;

                case 'px':
                    if (is_numeric($value)) {
                        $out[$key] = (int) max((int) $row['min'], min((int) $row['max'], (int) round((float) $value)));
                    }
                    break;

                case 'select':
                    $value = is_scalar($value) ? (string) $value : '';
                    if (array_key_exists($value, (array) $row['options'])) {
                        $out[$key] = $value;
                    }
                    break;

                case 'image':
                    if (is_numeric($value) && (int) $value > 0) {
                        $out[$key] = (int) $value;
                    }
                    break;
            }
        }

        return $out;
    }

    private static function hex(mixed $value): string
    {
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        return preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $value) ? $value : '';
    }

    /**
     * THE CLASSES AND THE STYLE ATTRIBUTE for an item's ticked rows. Appends to
     * `$classes`; returns ` style="…"` or ''.
     *
     * @param array<string, int|string> $styles  from `read()`
     * @param list<string>              $classes
     */
    public static function apply(array $styles, array &$classes): string
    {
        $vars = [];
        foreach ($styles as $key => $value) {
            $row = self::byKey()[$key] ?? null;
            if ($row === null) {
                continue;   // a `_to` — carried by its own row below
            }
            $name = str_replace('_', '-', $key);
            if (!isset($row['with'])) {
                $classes[] = 'menu__item--st-' . $name;
            }

            switch ((string) $row['type']) {
                case 'colour2':
                    $vars['--st-' . $name]         = (string) $value;
                    $vars['--st-' . $name . '-to'] = (string) ($styles[$key . '_to'] ?? $value);
                    break;
                case 'px':
                    $vars['--st-' . $name] = (int) $value . 'px';
                    break;
                case 'image':
                    $url = Media::getUrl((int) $value);
                    if ($url === '') {
                        array_pop($classes);   // no picture, no rule
                        break;
                    }
                    // A CSS STRING: the address is this site's own upload path,
                    // and the quote and backslash are escaped all the same.
                    $vars['--st-' . $name] = 'url("' . addcslashes($url, "\"\\\n\r") . '")';
                    break;
                case 'select':
                    // Text align moves a flex row's content, so it travels as the
                    // `justify-content` value that does that.
                    $vars['--st-' . $name] = $key === 'align'
                        ? ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'][(string) $value]
                        : (string) $value;
                    break;
                default:
                    $vars['--st-' . $name] = (string) $value;
            }
        }

        if ($vars === []) {
            return '';
        }
        $css = '';
        foreach ($vars as $prop => $value) {
            $css .= $prop . ':' . $value . ';';
        }

        return ' style="' . htmlspecialchars($css) . '"';
    }

    /** Attachment ids the styling names — for the editor's picture map. @return list<int> */
    public static function mediaIds(mixed $raw): array
    {
        $ids = [];
        foreach (self::read($raw) as $key => $value) {
            if ((self::byKey()[$key]['type'] ?? '') === 'image') {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }
}
