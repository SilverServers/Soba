<?php

declare(strict_types=1);

namespace Cms\Ui;

use Cms\Models\Media;
use Cms\Models\Option;

/**
 * SETTINGS → BRANDING: the site's logo on the admin's top bar and on the pages
 * at the sign-in address, and the theme those sign-in pages wear.
 *
 * The logo comes in two versions, one for light backgrounds and one for dark.
 * Each place shows the version that reads on its own background: the top bar
 * by its colour under the theme its viewer wears, a sign-in page by the
 * background of the sign-in theme. With one version set, it is used
 * everywhere; with none, each place shows the Site title alone.
 *
 * Kept as options, each read back checked, so no caller has to:
 *
 *   branding_logo_light    a media id: the logo for light backgrounds
 *   branding_logo_dark     a media id: the logo for dark backgrounds
 *   branding_signin_theme  a theme's key (`Ui\Theme`): the sign-in pages' look
 */
final class Branding
{
    public const LOGO_LIGHT_KEY   = 'branding_logo_light';
    public const LOGO_DARK_KEY    = 'branding_logo_dark';
    public const SIGNIN_THEME_KEY = 'branding_signin_theme';

    /** @var array{logo_light: int, logo_dark: int, signin_theme: string}|null */
    private static ?array $settings = null;

    /** @return array{logo_light: int, logo_dark: int, signin_theme: string} */
    public static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }
        $defaults = [self::LOGO_LIGHT_KEY => '0', self::LOGO_DARK_KEY => '0', self::SIGNIN_THEME_KEY => ''];
        // A PAGE THAT CANNOT REACH THE DATABASE (the sign-in page of a site
        // whose database is down) still draws, with the default look.
        try {
            $raw = Option::getMany($defaults);
        } catch (\Throwable $e) {
            error_log('CMS branding: the default look, because ' . $e->getMessage());
            $raw = $defaults;
        }
        $theme = (string) $raw[self::SIGNIN_THEME_KEY];

        return self::$settings = [
            'logo_light'   => max(0, (int) $raw[self::LOGO_LIGHT_KEY]),
            'logo_dark'    => max(0, (int) $raw[self::LOGO_DARK_KEY]),
            'signin_theme' => Theme::offers($theme) ? $theme : Theme::defaultKey(),
        ];
    }

    /** The theme the pages at the sign-in address wear. */
    public static function signInTheme(): string
    {
        return self::settings()['signin_theme'];
    }

    /**
     * The logo for a background, as an address, or '' when none is set: the
     * version for that background, else the other one.
     */
    public static function logoFor(bool $dark): string
    {
        $s     = self::settings();
        $first = $dark ? $s['logo_dark'] : $s['logo_light'];
        $other = $dark ? $s['logo_light'] : $s['logo_dark'];
        foreach ([$first, $other] as $id) {
            $url = $id > 0 ? self::url($id) : '';
            if ($url !== '') {
                return $url;
            }
        }
        return '';
    }

    /** The top bar's logo under a theme, or ''. */
    public static function barLogo(string $theme): string
    {
        return self::logoFor(Theme::isDark(Theme::paint($theme)['nav']));
    }

    /**
     * The name at the top of each sign-in page: the logo in the version for
     * the sign-in theme's background, its words the Site title; else the Site
     * title itself.
     */
    public static function signInBrand(): string
    {
        $site = (string) \Cms\SEO\Head::globalTokens()['site_name'];
        $logo = self::logoFor(Theme::isDark(Theme::paint(self::signInTheme())['page']));
        $h    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div class="auth__brand">'
            . ($logo !== '' ? '<img class="auth__logo" src="' . $h($logo) . '" alt="' . $h($site) . '">' : $h($site))
            . '</div>';
    }

    /**
     * Why a file cannot be a logo, or null: it must be a picture in the
     * library that anyone may see, since the sign-in page shows it to a
     * visitor who is not signed in.
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

    /** Forget what was read: after a save, and in tests. */
    public static function reset(): void
    {
        self::$settings = null;
    }

    private static function url(int $id): string
    {
        if (self::pictureProblem($id) !== null) {
            return '';
        }
        try {
            return (string) Media::getUrl($id);
        } catch (\Throwable) {
            return '';
        }
    }
}
