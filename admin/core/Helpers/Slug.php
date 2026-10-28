<?php

declare(strict_types=1);

namespace Cms\Helpers;

/**
 * A URL slug the way every screen stores one: lowercase letters, digits and
 * single hyphens, none at either end.
 *
 * ── ONE COPY ────────────────────────────────────────────────────────────────
 * ContentTypeController, PageController and the term screen all ask here. A
 * screen that stores whatever was typed, spaces and capitals included, turns a
 * hand-typed "Club News" into an archive address that nothing can reach.
 */
final class Slug
{
    public static function clean(string $raw): string
    {
        $slug = strtolower(trim($raw));
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug) ?? $slug;
        $slug = preg_replace('/[\s-]+/', '-', $slug) ?? $slug;
        return trim($slug, '-');
    }

    /**
     * A public ADDRESS that may be nested — a taxonomy's `articles/category`,
     * under the articles' own address. Each segment is cleaned as
     * a slug and empty ones are dropped, so ` /Articles//Category/ ` is
     * `articles/category`. The one-segment form comes back unchanged.
     */
    public static function cleanPath(string $raw): string
    {
        $parts = array_map([self::class, 'clean'], explode('/', $raw));

        return implode('/', array_values(array_filter($parts, static fn (string $p): bool => $p !== '')));
    }

    /**
     * FIRST SEGMENTS THE ROUTER ALREADY OWNS. A public address starting with
     * one of these either never gets a request — a fixed route wins — or
     * registers a route the router refuses, and a router that refuses a route
     * answers no request at all.
     *
     * AND THE SIGN-IN PAGE'S, while it has moved (`Security\LoginUrl`): a page
     * or a Content Type published there would be shadowed by it — silently, to
     * everyone but the person who can no longer find their sign-in page.
     * `LoginUrl::problem()` asks without it, so re-saving the address it
     * already has is not refused as taken by itself.
     *
     * @return list<string>
     */
    public static function reservedFirstSegments(bool $withSignIn = true): array
    {
        $reserved = ['admin', ...self::PUBLIC_SEGMENTS];
        if ($withSignIn && \Cms\Security\LoginUrl::enabled()) {
            $reserved[] = \Cms\Security\LoginUrl::slug();
        }

        return array_values(array_unique($reserved));
    }

    /** The site's own first segments: fixed public routes, and the folders under www/. */
    public const PUBLIC_SEGMENTS = ['api', 'assets', 'uploads', 'cart', 'checkout', 'files', 'forms', 'order', 'search'];
}
