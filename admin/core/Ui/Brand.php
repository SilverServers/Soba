<?php

declare(strict_types=1);

namespace Cms\Ui;

use Cms\Models\Media;
use Cms\Models\Option;

/**
 * THE CMS'S OWN IDENTITY, PER SITE — Appearance → Branding: what the admin
 * calls itself, the logo in its bar, the icon in every browser tab, and the
 * look everyone gets.
 *
 * Left blank, every visible name reads the Site title — right for a fresh
 * install, and no help to a site whose admin should wear the agency's name or
 * the client's logo rather than the public site's title.
 *
 *   brand_name     the admin bar, every admin tab's title, the sign-in pages
 *   brand_logo     a media id: beside the name in the admin bar, and the
 *                  sign-in pages' logo unless they have their own
 *   brand_icon     a media id: the favicon of the admin, the sign-in pages and
 *                  the public site (a whole-page theme prints `iconTags()`)
 *   brand_admin_theme   the admin's theme — one of `Ui\Theme`'s palettes —
 *   brand_admin_radius  and corners, for everyone who has not chosen their own:
 *                       migration 070 made a user's own choice nullable so "not
 *                       chosen" can be told from "chose the default". Named
 *                       for what it holds — the key of one of the sanctioned
 *                       themes — and not as a palette of the brand's own, which
 *                       would be a second, parallel colour source.
 *
 * No CMS mark when none is set: no default logo, no default icon. A white-label
 * admin that showed the CMS's own favicon would not be one.
 */
final class Brand
{
    public const NAME_KEY    = 'brand_name';
    public const LOGO_KEY    = 'brand_logo';
    public const ICON_KEY    = 'brand_icon';
    public const ADMIN_THEME_KEY  = 'brand_admin_theme';
    public const ADMIN_RADIUS_KEY = 'brand_admin_radius';

    /** How long a name may be: the admin bar has room for about this much. */
    public const NAME_MAX = 60;

    /** @var array{name: string, logo: int, icon: int, admin_theme: string, admin_radius: string}|null */
    private static ?array $settings = null;

    /**
     * What is stored, each value checked — an unknown theme, a negative id,
     * a name too long — so no caller has to.
     *
     * @return array{name: string, logo: int, icon: int, admin_theme: string, admin_radius: string}
     */
    public static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }
        $defaults = [
            self::NAME_KEY => '', self::LOGO_KEY => '0', self::ICON_KEY => '0',
            self::ADMIN_THEME_KEY => '', self::ADMIN_RADIUS_KEY => '',
        ];
        // A PAGE THAT CANNOT REACH THE DATABASE STILL GETS A LOOK — the
        // built-in one, said in the log. `Ui\Theme::resolve()` asks here for
        // anyone who has not chosen a theme, and a site's own tool that paints
        // itself in the admin's colours calls that before anything else has
        // needed the database (a site's own tool may).
        try {
            $raw = Option::getMany($defaults);
        } catch (\Throwable $e) {
            error_log('CMS brand: the built-in look, because ' . $e->getMessage());
            $raw = $defaults;
        }
        $theme  = (string) $raw[self::ADMIN_THEME_KEY];
        $radius = (string) $raw[self::ADMIN_RADIUS_KEY];

        return self::$settings = [
            'name'    => mb_substr(trim((string) $raw[self::NAME_KEY]), 0, self::NAME_MAX),
            'logo'    => max(0, (int) $raw[self::LOGO_KEY]),
            'icon'    => max(0, (int) $raw[self::ICON_KEY]),
            'admin_theme'  => Theme::exists($theme) ? $theme : Theme::DEFAULT_KEY,
            'admin_radius' => in_array($radius, Theme::RADII, true) ? $radius : Theme::DEFAULT_RADIUS,
        ];
    }

    /** What the admin calls itself: the brand name, else the Site title. */
    public static function name(): string
    {
        $name = self::settings()['name'];
        return $name !== '' ? $name : (string) \Cms\SEO\Head::globalTokens()['site_name'];
    }

    /** The admin bar's logo, or ''. */
    public static function logoUrl(): string
    {
        return self::mediaUrl(self::settings()['logo']);
    }

    /** The favicon's address, or ''. */
    public static function iconUrl(): string
    {
        return self::mediaUrl(self::settings()['icon']);
    }

    /**
     * The `<head>` lines for the site icon — the tab's icon and the one a phone
     * saves to its home screen — or nothing when there is none.
     */
    public static function iconTags(): string
    {
        $url = self::iconUrl();
        if ($url === '') {
            return '';
        }
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<link rel="icon" href="' . $h($url) . '">' . "\n"
             . '    <link rel="apple-touch-icon" href="' . $h($url) . '">';
    }

    /** The admin's theme for someone who has not chosen one. */
    public static function adminTheme(): string
    {
        return self::settings()['admin_theme'];
    }

    /** The admin's corners for someone who has not chosen them. */
    public static function adminRadius(): string
    {
        return self::settings()['admin_radius'];
    }

    /** Forget what was read — after Branding is saved, and in tests. */
    public static function reset(): void
    {
        self::$settings = null;
    }

    private static function mediaUrl(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        try {
            return Media::getUrl($id);
        } catch (\Throwable) {
            return '';
        }
    }
}
