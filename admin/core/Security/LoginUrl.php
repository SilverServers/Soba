<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\ContentType\Registry;
use Cms\ContentType\TaxonomyRegistry;
use Cms\Helpers\Slug;
use Cms\Helpers\Template;
use Cms\Helpers\Url;
use Cms\Models\Option;
use Cms\Models\Page;
use Cms\SEO\Head;
use Cms\SEO\Redirects;
use Cms\Site\Front;

/**
 * THE SIGN-IN ADDRESS — a hidden sign-in page, and a dynamic one: it is a
 * setting, changed from Security → Login protection whenever the owner likes,
 * and it takes effect on the next request. No file is edited and no server
 * rule is involved.
 *
 * ── WHAT MOVES, AND WHAT DOES NOT ───────────────────────────────────────────
 * The SIGN-IN PAGE moves to `/{slug}`. The admin itself stays at `/admin` —
 * but to anyone not signed in it no longer exists:
 *
 *   /admin/login            the not-found page, as if it had never been there
 *   /admin, /admin/…        the "old address" rule: the not-found page, or a
 *                           redirect to wherever the owner chose
 *   /{slug}                 the sign-in page
 *
 * Signed in, nothing changes. Signing out lands on `/{slug}`.
 *
 * ── THE NOT-FOUND PAGE IS THE DEFAULT, NOT A REDIRECT HOME ──────────────────
 * A redirect to the home page is itself an answer — "something is here, and
 * it sent me away" — which is the one thing hiding the door was meant to
 * stop. The public not-found page is what any other address that does not
 * exist gets, so it tells a scanner nothing. A redirect remains one field away
 * for an owner who wants it.
 *
 * ── AN ADDRESS NOTHING ELSE MAY TAKE ────────────────────────────────────────
 * The slug may not collide with a fixed route, a Content Type or taxonomy
 * address, a Page or a redirect — and while it is in force it joins
 * `Slug::reservedFirstSegments()`, so none of those screens can take it from
 * the sign-in page afterwards. Its route is registered before every generated
 * one, so a collision that arrives some other way (an import, a row edited by
 * hand) loses to the sign-in page rather than hiding it.
 *
 * ── LOCKED OUT OF YOUR OWN ADDRESS ──────────────────────────────────────────
 * Forgetting it is the failure this feature invites. `php admin/bin/security.php
 * login-url` prints it, and `… login-url off` puts the sign-in page back at
 * /admin/login. Anyone with the server can recover; nobody without it can.
 */
final class LoginUrl
{
    /** Options keys, so the settings screen and this class cannot disagree. */
    public const ENABLED_KEY  = 'security_login_url_enabled';
    public const SLUG_KEY     = 'security_login_slug';
    public const REDIRECT_KEY = 'security_login_redirect';

    /** Where the sign-in page lives when the feature is off. */
    public const DEFAULT_PATH = '/admin/login';

    /** @var array{enabled: bool, slug: string, redirect: string}|null */
    private static ?array $state = null;

    /** @return array{enabled: bool, slug: string, redirect: string} */
    private static function state(): array
    {
        if (self::$state !== null) {
            return self::$state;
        }

        $raw  = Option::getMany([self::ENABLED_KEY => '0', self::SLUG_KEY => '', self::REDIRECT_KEY => '']);
        $slug = (string) ($raw[self::SLUG_KEY] ?? '');

        return self::$state = [
            // A switch left on with no usable slug stored would move the sign-in
            // page to nowhere. It counts as off instead.
            'enabled'  => ($raw[self::ENABLED_KEY] ?? '0') === '1' && self::wellFormed($slug),
            'slug'     => $slug,
            'redirect' => trim((string) ($raw[self::REDIRECT_KEY] ?? '')),
        ];
    }

    /** Forget what was read this request — after a save. */
    public static function forget(): void
    {
        self::$state = null;
    }

    public static function enabled(): bool
    {
        return self::state()['enabled'];
    }

    /** The stored slug, in force or not — for the settings form. */
    public static function slug(): string
    {
        return self::state()['slug'];
    }

    /** The stored redirect for old addresses — '' means the not-found page. */
    public static function redirect(): string
    {
        return self::state()['redirect'];
    }

    /**
     * The sign-in page's path AS THE ROUTER SEES IT: `/{slug}` or `/admin/login`
     * — the route the page is registered at, and what request paths are
     * compared with (without a trailing slash, as the router trims one).
     * Anything sent to a browser or requested over HTTP is `url()`.
     */
    public static function path(): string
    {
        return self::enabled() ? '/' . self::slug() : self::DEFAULT_PATH;
    }

    /**
     * The sign-in page's full address. Every link to it, and every form that
     * posts to it, is this. Moved, it is a page address and ends as Settings →
     * Permalinks says (`Url::page()`): a front that adds the slash with a 301
     * would turn the sign-in's POST into a GET and lose it.
     */
    public static function url(): string
    {
        return self::enabled() ? Url::page(self::slug()) : Url::admin('login');
    }

    /**
     * A SIGNED-OUT VISITOR AT AN ADMIN ADDRESS. With the feature off, the
     * sign-in page, as always. With it on: the old-address rule — the owner's
     * redirect, or the site's own not-found page. Ends the request.
     */
    public static function turnAway(): never
    {
        if (!self::enabled()) {
            header('Location: ' . self::url());
            exit;
        }

        $redirect = self::redirect();
        if ($redirect !== '') {
            header('Location: ' . Url::resolve($redirect), true, 302);
            exit;
        }

        self::notFound();
    }

    /**
     * The public not-found page, exactly as `www/index.php` renders it for an
     * address that does not exist — so the two cannot be told apart.
     */
    public static function notFound(): never
    {
        Head::set([
            'title'  => 'Page Not Found | ' . Head::globalTokens()['site_name'],
            'robots' => 'noindex, follow',
        ]);
        Template::notFound();
        exit;
    }

    /**
     * Why this slug cannot be the sign-in address, or null when it can.
     * `$slug` is expected already cleaned (`Slug::clean()`).
     */
    public static function problem(string $slug): ?string
    {
        if ($slug === '') {
            return 'Choose an address for the sign-in page.';
        }
        if (!self::wellFormed($slug)) {
            return 'The address may use lowercase letters, numbers and hyphens, 4 to 64 characters.';
        }
        if (in_array($slug, Slug::reservedFirstSegments(false), true)) {
            return '/' . $slug . ' is already one of the site\'s own addresses.';
        }
        // A SITE WITH ITS OWN ROUTER IN FRONT (`Site\Front`): what that router
        // serves, as the site declares it, and its own files and folders —
        // over /about, the sign-in form would take the site's page.
        if (in_array($slug, Front::siteAddresses(), true)) {
            return '/' . $slug . ' is one of the site\'s own pages.';
        }
        if (defined('PUBLIC_PATH') && file_exists(PUBLIC_PATH . '/' . $slug)) {
            return '/' . $slug . ' is one of the site\'s own folders or files.';
        }
        // The web server's own (Apache's stock aliases): it answers there
        // before the site ever sees the request.
        if (in_array($slug, Front::SERVER, true)) {
            return '/' . $slug . ' belongs to the web server on many hosts, and never reaches the site.';
        }
        foreach (Registry::all() as $typeKey => $type) {
            if (trim((string) Registry::publicSlug((string) $typeKey), '/') === $slug) {
                return '/' . $slug . ' is where ' . $type['label'] . ' are published.';
            }
        }
        foreach (TaxonomyRegistry::all() as $tax) {
            $first = explode('/', trim((string) ($tax['public_slug'] ?? ''), '/'))[0];
            if ($first !== '' && $first === $slug) {
                return '/' . $slug . ' is where ' . $tax['label'] . ' are published.';
            }
        }
        if (Page::slugExists($slug)) {
            return 'A page already uses /' . $slug . '.';
        }
        if (Redirects::match('/' . $slug) !== null) {
            return 'A redirect already starts at /' . $slug . '.';
        }

        return null;
    }

    /** Lowercase letters, digits and inner hyphens, 4–64 characters. */
    public static function wellFormed(string $slug): bool
    {
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{2,62})[a-z0-9]$/', $slug) === 1
            && !str_contains($slug, '--');
    }

    /**
     * A RANDOM ADDRESS'S PARTS, printed onto the screen's Randomize button
     * (sign-in-address.js), which makes an address nobody will guess from
     * them: an ordinary word, a hyphen and TAIL characters of ALPHABET, about
     * 9 × 10^15 of them, in the shape `wellFormed()` accepts.
     *
     * The word it starts with: ordinary, so it reads as one.
     */
    public const WORDS = ['office', 'desk', 'studio', 'portal', 'console', 'workroom', 'backstage', 'hub'];

    /** Its random characters: no l, o, 0 or 1, so it reads aloud safely. */
    public const ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789';

    /** How many of them. */
    public const TAIL = 10;
}
