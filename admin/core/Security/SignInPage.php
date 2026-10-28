<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Models\Media;
use Cms\Models\Option;

/**
 * THE SIGN-IN PAGE'S LOOK — the parts a small business uses: a logo in place
 * of the CMS's name, a background, and custom CSS — on every page at the
 * sign-in address (sign in, the way back in, the second step, a code or a
 * link, unlock, and temporary access). Set under Appearance → Branding,
 * Sign-in tab, with the rest of the site's whitelabel (`Ui\Brand`).
 *
 * No font setting: a font fetched from a third party would load on the page
 * where the password is typed. The design system's own fonts stay.
 *
 * ── THE CSS IS THE OWNER'S, BUT NOT A WAY OUT ───────────────────────────────
 * It is printed as typed, in a <style> on those pages only — except that it
 * may not fetch from anywhere else (`@import`, a `url()` with a scheme or `//`)
 * and may not close its own <style>. A stylesheet on the sign-in page that
 * loads from elsewhere is a third party watching who signs in.
 */
final class SignInPage
{
    // `brand_signin_*`: Appearance → Branding's Sign-in tab, beside the Admin
    // tab's `brand_*` (`Ui\Brand`).
    public const LOGO_KEY             = 'brand_signin_logo';
    public const LOGO_WIDTH_KEY       = 'brand_signin_logo_width';
    public const BACKGROUND_KEY       = 'brand_signin_background';
    public const BACKGROUND_IMAGE_KEY = 'brand_signin_background_image';
    public const CSS_KEY              = 'brand_signin_css';

    /** @var array{0: int, 1: int} the logo's width, in pixels */
    public const WIDTH = [40, 400];

    public const DEFAULT_WIDTH = 160;

    public const CSS_MAX = 20000;

    /** @return array{logo: int, logo_width: int, background: string, background_image: int, css: string} */
    public static function settings(): array
    {
        $raw = Option::getMany([
            self::LOGO_KEY => '0', self::LOGO_WIDTH_KEY => (string) self::DEFAULT_WIDTH, self::BACKGROUND_KEY => '',
            self::BACKGROUND_IMAGE_KEY => '0', self::CSS_KEY => '',
        ]);

        return [
            'logo'             => max(0, (int) $raw[self::LOGO_KEY]),
            'logo_width'       => max(self::WIDTH[0], min(self::WIDTH[1], (int) $raw[self::LOGO_WIDTH_KEY])),
            'background'       => self::colour((string) $raw[self::BACKGROUND_KEY]) ?? '',
            'background_image' => max(0, (int) $raw[self::BACKGROUND_IMAGE_KEY]),
            'css'              => (string) $raw[self::CSS_KEY],
        ];
    }

    /** A colour as `#rrggbb` (or `#rgb`), lower-cased — or null when it is not one. */
    public static function colour(string $typed): ?string
    {
        $typed = strtolower(trim($typed));

        return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $typed) === 1 ? $typed : null;
    }

    /**
     * What is wrong with custom CSS, or null. Pure: the checks hand it the
     * text.
     */
    public static function cssProblem(string $css): ?string
    {
        if (mb_strlen($css) > self::CSS_MAX) {
            return 'The CSS can be at most ' . number_format(self::CSS_MAX) . ' characters.';
        }
        // Read it as a browser would: comments gone, escapes DECODED — `@\69mport`
        // is an @import, and `u\72l(` a url(.
        $flat = (string) preg_replace('/\/\*.*?\*\//s', '', $css);
        $flat = (string) preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?/', static fn (array $m): string => (string) mb_chr((int) hexdec($m[1])), $flat);
        $flat = strtolower((string) preg_replace('/\\\\(.)/su', '$1', $flat));
        if (str_contains($flat, '</') || str_contains($flat, '<!--')) {
            return 'The CSS may not contain HTML.';
        }
        if (str_contains($flat, '@import')) {
            return 'The CSS may not @import: nothing on the sign-in page loads from elsewhere.';
        }
        // Any address with a scheme, or one beginning `//`, wherever it is —
        // url(), image-set(), a string — is another site.
        if (str_contains($flat, '://') || preg_match('#(\(|["\'\s,])//#', $flat) === 1) {
            return 'An address in the CSS must be on this site — a path beginning with /, not another site.';
        }
        if (str_contains($flat, 'expression(') || str_contains($flat, 'javascript:') || str_contains($flat, 'behavior:')
            || str_contains($flat, '-moz-binding')) {
            return 'The CSS may not run script.';
        }

        return null;
    }

    /**
     * The brand at the top of each page: this tab's logo, else the Admin tab's
     * (`Ui\Brand`), else the name — `$site`, which the pages pass as
     * `Brand::name()`.
     */
    public static function brand(string $site): string
    {
        $s   = self::settings();
        $url = $s['logo'] > 0 ? self::mediaUrl($s['logo']) : \Cms\Ui\Brand::logoUrl();
        $in  = $url !== ''
            ? '<img class="auth__logo" src="' . self::esc($url) . '" alt="' . self::esc($site) . '" width="' . $s['logo_width'] . '">'
            : self::esc($site);

        return '<div class="auth__brand">' . $in . '</div>';
    }

    /** The background and the custom CSS, as one <style> — or nothing. For the <head> of those pages. */
    public static function style(): string
    {
        $s     = self::settings();
        $rules = [];
        // THE OWNER'S OWN COLOUR, not a token — the one place the admin's
        // palette gives way, because the sign-in page is the site's front
        // door. `colour()` let only a hex through, so nothing else reaches the
        // rule.
        if ($s['background'] !== '') {
            $rules[] = 'background-color: ' . $s['background'] . ';';
        }
        $image = $s['background_image'] > 0 ? self::mediaUrl($s['background_image']) : '';
        if ($image !== '') {
            $rules[] = 'background-image: url("' . str_replace(['"', '\\', "\n", "\r"], '', $image) . '");';
            $rules[] = 'background-size: cover; background-position: center; background-repeat: no-repeat;';
        }
        $css = trim($s['css']);
        if ($css !== '' && self::cssProblem($css) !== null) {
            // Stored before a rule it now breaks (or written to the database
            // by hand): not printed, rather than printed with its way out.
            $css = '';
        }
        if ($rules === [] && $css === '') {
            return '';
        }

        return '<style>' . ($rules !== [] ? 'main.auth { ' . implode(' ', $rules) . ' }' : '')
            . ($css !== '' ? "\n" . $css : '') . '</style>';
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function mediaUrl(int $id): string
    {
        try {
            return (string) Media::getUrl($id);
        } catch (\Throwable) {
            return '';
        }
    }
}
