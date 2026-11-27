<?php

declare(strict_types=1);

namespace Cms\Helpers;

/**
 * THE SITE'S PLACES: spots on the
 * site's own pages that show a gallery, a form or a picture the admin
 * chooses, in the site's own design. The site lists them in `places.php` — in
 * the active theme, or in `admin/site/templates/` (`Theme::file()`):
 *
 *     return [
 *         'portfolio'    => ['name' => 'The portfolio page', 'url' => '/portfolio/'],
 *         'contact'      => ['name' => 'The contact page', 'url' => '/contact/', 'kind' => 'form'],
 *         'about-top'    => ['name' => 'The about page, at the top', 'url' => '/about/', 'kind' => 'picture'],
 *     ];
 *
 * A place's kind says what it holds: 'gallery' (the default), 'form' or
 * 'picture'. Each screen offers its own kind only — a form's "Shown on" lists
 * the form places. A place shows one form at most (`forms.place_key`,
 * unique), and the site's template for that page asks which:
 * `Places::keyForUrl($path, 'form')`, then `Form::byPlace($key)`. A picture
 * place shows the one picture chosen for it on Files and media → Picture
 * places (`Models\PicturePlace`); its template asks `Places::picture($key)`,
 * gets what `Media::image()` gives, and draws its own file on null. Without a
 * places.php there is nowhere to place anything, and the admin shows no
 * field. A places.php that fails is logged, and `error()` says why.
 *
 * A page that depends on one record names it in a column the admin shows and
 * can change — never in a title or an id written into a template, which an
 * owner renaming the form would silently break.
 */
final class Places
{
    /** @var array<string, array{name: string, url?: string, kind: string}>|null */
    private static ?array $places = null;
    private static bool $loaded = false;
    private static ?string $error = null;

    /** Does this install have any places? */
    public static function declared(): bool
    {
        return self::load() !== null;
    }

    /**
     * Every place, or those of one kind ('gallery', 'form', 'picture').
     *
     * @return array<string, array{name: string, url?: string, kind: string}>
     */
    public static function all(?string $kind = null): array
    {
        $all = self::load() ?? [];
        return $kind === null ? $all : array_filter($all, static fn (array $p): bool => $p['kind'] === $kind);
    }

    /** The key of the place on a page (its address), optionally of one kind, or null. */
    public static function keyForUrl(string $url, ?string $kind = null): ?string
    {
        foreach (self::all($kind) as $key => $place) {
            if (($place['url'] ?? null) === $url) {
                return (string) $key;
            }
        }
        return null;
    }

    /** @return array{name: string, url?: string, kind: string}|null */
    public static function find(?string $key): ?array
    {
        return $key !== null && $key !== '' ? (self::all()[$key] ?? null) : null;
    }

    /**
     * THE PICTURE A PICTURE PLACE SHOWS, as `Media::image()` gives it, or null
     * when none is chosen, the picture has left the library, or the key names
     * no picture place: then the site draws its own file there.
     *
     * @return array{url: string, width: ?int, height: ?int, alt: string, variants: array<int, string>}|null
     */
    public static function picture(string $key): ?array
    {
        if ((self::find($key)['kind'] ?? null) !== 'picture') {
            return null;
        }

        return \Cms\Models\Media::image(\Cms\Models\PicturePlace::chosen($key));
    }

    /** Why the list could not be read, or null. */
    public static function error(): ?string
    {
        self::load();
        return self::$error;
    }

    /** Forget the list — in tests. */
    public static function reset(): void
    {
        self::$places = null;
        self::$loaded = false;
        self::$error  = null;
    }

    /** @return array<string, array{name: string, url?: string, kind: string}>|null */
    private static function load(): ?array
    {
        if (self::$loaded) {
            return self::$places;
        }
        self::$loaded = true;

        $file = Theme::file('places.php');
        if ($file === null) {
            return null;
        }
        try {
            $raw = (static fn (string $__file): mixed => require $__file)($file);
        } catch (\Throwable $e) {
            error_log('Places: ' . $file . ' failed: ' . $e->getMessage());
            self::$error = 'The list of places could not be read (' . $e->getMessage() . ').';
            return self::$places = [];
        }
        if (!is_array($raw)) {
            return null;
        }

        $places = [];
        foreach ($raw as $key => $p) {
            if (!is_string($key) || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $key) !== 1
                || !is_array($p) || trim((string) ($p['name'] ?? '')) === '') {
                continue;
            }
            $places[$key] = array_filter([
                'name' => trim((string) $p['name']),
                'url'  => isset($p['url']) ? (string) $p['url'] : null,
                'kind' => in_array($p['kind'] ?? 'gallery', ['form', 'picture'], true) ? (string) $p['kind'] : 'gallery',
            ], static fn ($v): bool => $v !== null && $v !== '');
        }
        return self::$places = $places;
    }
}
