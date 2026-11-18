<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\Models\Media;
use Cms\Models\Option;
use Cms\Ui\Brand;
use Cms\Ui\Theme;

/**
 * THE PUBLIC SITE'S LOOK (Appearance → Customizer): its palette and corners,
 * its two fonts, the logo in its header and its site icon. The owner's, kept
 * as options, and the same for every visitor.
 *
 * ── ONLY WHAT THE DESIGN LANGUAGE OFFERS ────────────────────────────────────
 * A palette is one of the 21 the admin has (`Ui\Theme`), each a full set of
 * colour tokens, so no choice here can give text a background it cannot be
 * read on; there is no free colour. Corners are its two: square or rounded.
 * A font is one of what ships: Inter, which the site serves itself, or the
 * visitor's own system sans-serif or serif, which loads nothing.
 *
 * ── STAMPED, NOT INLINE ─────────────────────────────────────────────────────
 * Each choice is an attribute on `<html>` (`htmlAttributes()`), and the
 * stylesheets do the rest: the palette's family sheet sets its tokens under
 * `[data-theme]`, and `theme.css` sets the fonts under `[data-font-body]` and
 * `[data-font-head]`. A page under a Content-Security-Policy that refuses
 * inline styles needs none. Printed by `base.php`, by a framed theme's own
 * frame through the same calls, and by the not-found page.
 *
 * ── THE SITE ICON FALLS BACK ────────────────────────────────────────────────
 * The public site's own icon when it has one, else Branding's, which was the
 * public site's until there was a choice.
 */
final class Look
{
    public const PALETTE_KEY  = 'look_palette';
    public const RADIUS_KEY   = 'look_radius';
    public const BODY_KEY     = 'look_font_body';
    public const HEAD_KEY     = 'look_font_head';
    public const LOGO_KEY     = 'look_logo';
    public const LOGO_NAME_KEY = 'look_logo_name';
    public const ICON_KEY     = 'look_icon';

    /** The fonts a choice may name: what ships, and the visitor's own. */
    public const FONTS = [
        'inter' => 'Inter',
        'sans'  => 'The visitor\'s own sans-serif',
        'serif' => 'The visitor\'s own serif',
    ];

    /** The corners, in the words the admin's Branding uses. */
    public const CORNERS = ['classic' => 'Square', 'modern' => 'Rounded'];

    /** @var array<string, mixed>|null */
    private static ?array $settings = null;

    /**
     * What is stored, each value checked, so no caller has to: a palette
     * that is not one, a font that does not ship, a file that is not a
     * public picture, each read as the default.
     *
     * @return array{palette: string, radius: string, body: string, head: string, logo: int, logo_name: bool, icon: int}
     */
    public static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }
        $defaults = [
            self::PALETTE_KEY => '', self::RADIUS_KEY => '', self::BODY_KEY => '', self::HEAD_KEY => '',
            self::LOGO_KEY => '0', self::LOGO_NAME_KEY => '1', self::ICON_KEY => '0',
        ];
        try {
            $raw = Option::getMany($defaults);
        } catch (\Throwable $e) {
            error_log('CMS look: the built-in look, because ' . $e->getMessage());
            $raw = $defaults;
        }
        return self::$settings = self::clean($raw);
    }

    /**
     * Values as they are kept, from a form or a store: each one the default
     * when it is not one of the choices.
     *
     * @param  array<string, mixed> $raw by option key
     * @return array{palette: string, radius: string, body: string, head: string, logo: int, logo_name: bool, icon: int}
     */
    public static function clean(array $raw): array
    {
        $palette = (string) ($raw[self::PALETTE_KEY] ?? '');
        $radius  = (string) ($raw[self::RADIUS_KEY] ?? '');
        $body    = (string) ($raw[self::BODY_KEY] ?? '');
        $head    = (string) ($raw[self::HEAD_KEY] ?? '');

        return [
            'palette'   => Theme::exists($palette) ? $palette : Theme::DEFAULT_KEY,
            'radius'    => isset(self::CORNERS[$radius]) ? $radius : Theme::DEFAULT_RADIUS,
            'body'      => isset(self::FONTS[$body]) ? $body : 'inter',
            'head'      => isset(self::FONTS[$head]) ? $head : 'inter',
            'logo'      => max(0, (int) ($raw[self::LOGO_KEY] ?? 0)),
            'logo_name' => (string) ($raw[self::LOGO_NAME_KEY] ?? '1') !== '0',
            'icon'      => max(0, (int) ($raw[self::ICON_KEY] ?? 0)),
        ];
    }

    /** Forget what was read: after a save, and in tests. */
    public static function reset(): void
    {
        self::$settings = null;
    }

    /**
     * Why a file cannot be the logo or the icon, or null: it must be a
     * picture in the library that anyone may see.
     */
    public static function pictureProblem(int $id): ?string
    {
        if ($id === 0) {
            return null;
        }
        $row = Media::find($id);
        if ($row === null) {
            return 'That file is no longer in the library.';
        }
        if (($row['visibility'] ?? 'public') !== 'public') {
            return 'That file is private, so a visitor could not see it. Make it public in the library first.';
        }
        if (!str_starts_with((string) ($row['mime_type'] ?? ''), 'image/')) {
            return 'That file is not a picture.';
        }
        return null;
    }

    // ----------------------------------------------------------------
    // On every public page
    // ----------------------------------------------------------------

    /**
     * The look's attributes for `<html>`: ` data-theme="…" data-radius="…"
     * data-font-body="…" data-font-head="…"`, escaped, with a space first.
     */
    public static function htmlAttributes(): string
    {
        $look = self::settings();
        $at   = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return ' data-theme="' . $at($look['palette']) . '" data-radius="' . $at($look['radius']) . '"'
            . ' data-font-body="' . $at($look['body']) . '" data-font-head="' . $at($look['head']) . '"';
    }

    /**
     * The stylesheet of the palette's family, to link after `tokens-base.css`:
     * its address, or '' for the default palette, whose tokens that sheet
     * already carries.
     */
    public static function paletteSheet(): string
    {
        $palette = self::settings()['palette'];
        return $palette === Theme::DEFAULT_KEY ? '' : self::familySheet((string) Theme::get($palette)['family']);
    }

    /** The address of a family's token sheet on the public side. */
    public static function familySheet(string $family): string
    {
        return \Cms\Helpers\Asset::site('assets/css/tokens/' . ($family === 'legacy' ? 'legacy' : 'signature') . '-styles.css');
    }

    /** The public site icon's `<head>` lines: its own, else Branding's, else nothing. */
    public static function iconTags(): string
    {
        $id  = self::settings()['icon'];
        $url = $id > 0 && self::pictureProblem($id) === null ? Media::getUrl($id) : '';
        if ($url === '') {
            return Brand::iconTags();
        }
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<link rel="icon" href="' . $h($url) . '">' . "\n"
             . '    <link rel="apple-touch-icon" href="' . $h($url) . '">';
    }

    /**
     * The header's name of the site: the logo and the name beside it, or the
     * name alone. The link home either way; with a logo and no name beside
     * it, the picture's alternative text is the name.
     */
    public static function brand(string $siteName, string $class = 'site-header__name'): string
    {
        $look = self::settings();
        $h    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $logo = $look['logo'] > 0 && self::pictureProblem($look['logo']) === null ? Media::getUrl($look['logo']) : '';
        $home = \Cms\Helpers\Url::home();
        if ($logo === '') {
            return '<a href="' . $h($home) . '" class="' . $h($class) . '" data-look-brand><span class="site-header__text">' . $h($siteName) . '</span></a>';
        }
        return '<a href="' . $h($home) . '" class="' . $h($class) . ' site-header__name--logo" data-look-brand>'
            . '<img class="site-header__logo" src="' . $h($logo) . '" alt="' . ($look['logo_name'] ? '' : $h($siteName)) . '">'
            . '<span class="site-header__text"' . ($look['logo_name'] ? '' : ' hidden') . '>' . $h($siteName) . '</span></a>';
    }
}
