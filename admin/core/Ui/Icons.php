<?php

declare(strict_types=1);

namespace Cms\Ui;

/**
 * Icons.
 *
 * An icon is **inline SVG**, with `width` and `height` as ATTRIBUTES on the tag.
 *
 * Not a CSS `mask`, not an `<img>`, not an icon font. A mask — even from an
 * inlined `data:` URI — is an *image resource*: the browser decodes it
 * asynchronously, and an element whose mask is not ready yet is masked OUT, i.e.
 * invisible. Every masked icon is briefly blank and then appears, on every load.
 * That is not a race you win by loading sooner; the decode is always there.
 * Inline SVG is DOM — it paints with the text beside it.
 *
 * The width/height ATTRIBUTES matter separately: with them the glyph draws
 * correctly even before any stylesheet applies. An `<svg>` with no intrinsic
 * size defaults to 300x150 and flashes something far worse than nothing.
 *
 * ── WHY THIS READS THE FILE INSTEAD OF A BAKED TABLE ────────────────────────
 * A table baked from the slugs in use, by a generator, renders NOTHING for a
 * slug the generator has not seen. The CMS has no build step, and a generated
 * file that must be re-run by hand is a registry that goes stale. So the
 * geometry is read from the SVG on disk, once per slug per request, and
 * memoised. There is nothing to regenerate and nothing that can drift: if the
 * file is in `www/admin/assets/icon/`, the glyph renders.
 *
 * ── AND WHY AN UNKNOWN SLUG IS LOUD ─────────────────────────────────────────
 * An unknown slug must render nothing rather than a broken box — and that is a
 * trap: a missing glyph becomes an invisible control, which nobody reports
 * because it does not look like a bug. So a miss renders nothing AND writes a
 * named line to the error log.
 */
final class Icons
{
    /** @var array<string, array{vb: string, body: string}|false> slug => geometry, or false for a known miss */
    private static array $cache = [];

    /** @var list<string> every slug this request failed to resolve, for assertions */
    private static array $misses = [];

    /**
     * Inline SVG for `$slug`, or `''` if there is no such icon.
     *
     *     <?= Icons::render('folder') ?>
     *     <?= Icons::render('bell', 'icon icon--sm') ?>
     *     <?= Icons::render('grip', 'icon', $tooltipAttrs) ?>   // glyph-only elements only
     *
     * `$attrs` is raw markup and is NEVER escaped — it is authored at the call
     * site, never built from user input. Do not pass anything through it that
     * came from a request.
     */
    public static function render(string $slug, string $cls = 'icon', string $attrs = '', int $size = 0): string
    {
        $g = self::geometry($slug);
        if ($g === null) {
            return '';
        }

        if ($size <= 0) {
            // Mirror the CSS sizes, so the pre-stylesheet paint is already right.
            $size = 14;
            if (str_contains($cls, 'icon--sm')) { $size = 12; }
            if (str_contains($cls, 'icon--xs')) { $size = 10; }
            if (str_contains($cls, 'icon--lg')) { $size = 16; }
        }

        return '<svg class="' . View::escape($cls) . '"'
            . ' width="' . $size . '" height="' . $size . '"'
            . ' viewBox="' . View::escape($g['vb']) . '"'
            . ' fill="currentColor" aria-hidden="true"'
            . ($attrs !== '' ? ' ' . $attrs : '')
            . '>' . $g['body'] . '</svg>';
    }

    /** Is there geometry for this slug? Used by the registry assertions. */
    public static function exists(string $slug): bool
    {
        return self::geometry($slug) !== null;
    }

    /**
     * EVERY GLYPH IN THE SET, sorted.
     *
     * The menu item dialog offers all of them, and `MenuItemSettings` refuses
     * anything else.
     * Read from the directory, like `render()`, so there is no second list to
     * fall behind the files.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        static $all = null;
        if ($all !== null) {
            return $all;
        }

        $all = [];
        foreach (glob(self::directory() . '/*.svg') ?: [] as $file) {
            $slug = basename($file, '.svg');
            if (preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*$~', $slug)) {
                $all[] = $slug;
            }
        }
        sort($all, SORT_STRING);

        return $all;
    }

    /**
     * A slug as words, for a tooltip and a search: `alarm-clock-solid` reads
     * "Alarm clock (solid)", and `-brands` — a file-naming convention, not part
     * of the mark — is dropped.
     */
    public static function label(string $slug): string
    {
        $style = '';
        if (str_ends_with($slug, '-solid')) {
            $slug  = substr($slug, 0, -6);
            $style = ' (solid)';
        } elseif (str_ends_with($slug, '-brands')) {
            $slug = substr($slug, 0, -7);
        }

        return ucfirst(str_replace('-', ' ', $slug)) . $style;
    }

    /**
     * Assert a glyph registry is TOTAL.
     *
     * A map that has gone stale is *invisible*: `render()` returns '' for a slug
     * it cannot resolve, so a missing entry looks like "this row has no state"
     * rather than like a bug. Every map from a value to an icon slug goes
     * through here.
     *
     * @param array<string, string> $map key => slug
     * @return list<string> human-readable failures; empty means total
     */
    public static function assertTotal(array $map, string $registryName): array
    {
        $bad = [];
        foreach ($map as $key => $slug) {
            if (!self::exists($slug)) {
                $bad[] = sprintf(
                    '%s maps "%s" to "%s", which has no SVG in %s',
                    $registryName,
                    $key,
                    $slug,
                    self::directory()
                );
            }
        }
        return $bad;
    }

    /** Slugs this request asked for and could not resolve. */
    public static function misses(): array
    {
        return array_values(array_unique(self::$misses));
    }

    /**
     * Geometry for a slug, or null.
     *
     * The slug is a FILESYSTEM PATH SEGMENT, so it is validated against a strict
     * pattern before it ever reaches the disk — not sanitised, rejected. Every
     * legitimate slug in the set is lowercase letters, digits and hyphens.
     *
     * @return array{vb: string, body: string}|null
     */
    private static function geometry(string $slug): ?array
    {
        if (array_key_exists($slug, self::$cache)) {
            return self::$cache[$slug] === false ? null : self::$cache[$slug];
        }

        if (!preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*$~', $slug)) {
            return self::miss($slug);
        }

        $file = self::directory() . '/' . $slug . '.svg';
        $raw  = @file_get_contents($file);
        if ($raw === false) {
            return self::miss($slug);
        }

        // The set is uniform Font Awesome output: one <svg> with a viewBox, an
        // attribution comment, and the path data. Take the viewBox and whatever
        // is between the tags; drop the comment so it is not repeated on every
        // glyph of every page. The licence attribution stays in the SVG files on
        // disk and in the README's third-party section, which is how CC BY 4.0 is
        // satisfied.
        if (!preg_match('~<svg\b[^>]*\bviewBox="([^"]+)"[^>]*>(.*)</svg>~is', $raw, $m)) {
            return self::miss($slug);
        }

        $body = preg_replace('~<!--.*?-->~s', '', $m[2]) ?? '';

        $geom = ['vb' => $m[1], 'body' => trim($body)];
        self::$cache[$slug] = $geom;
        return $geom;
    }

    /** Record and remember a miss, so it is reported once and asserted on. */
    private static function miss(string $slug): null
    {
        self::$cache[$slug]  = false;
        self::$misses[]      = $slug;

        // Named, so the failure says exactly what to add rather than merely that
        // something is off.
        error_log('icon: no glyph for slug "' . $slug . '"');

        return null;
    }

    /** Where the SVGs live: `www/{admin_slug}/assets/icon`. */
    private static function directory(): string
    {
        return PUBLIC_PATH . '/' . trim(ADMIN_URL_SLUG, '/') . '/assets/icon';
    }
}
