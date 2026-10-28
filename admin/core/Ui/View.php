<?php

declare(strict_types=1);

namespace Cms\Ui;

use RuntimeException;

/**
 * View — escape() and partial().
 *
 * The admin's single source of truth for escaping, and the renderer for the
 * shared fragments the shell is built from.
 */
final class View
{
    /**
     * HTML-escape for output. THE single escaping function for the admin.
     *
     * Two details that are easy to get wrong and expensive to find:
     *
     * - `ENT_QUOTES` escapes BOTH quote kinds. An unescaped single quote breaks
     *   out of a single-quoted attribute, which is a live XSS hole that no
     *   amount of double-quote escaping closes.
     * - `ENT_SUBSTITUTE` replaces invalid UTF-8 with U+FFFD. PHP's default is to
     *   return an EMPTY STRING, so one bad byte silently blanks a whole field
     *   instead of showing something.
     */
    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Render a shared fragment from `admin/templates/partials/` with `$data` in
     * scope, and return its markup.
     *
     * ── THE COLLISION THIS AVOIDS ───────────────────────────────────────────
     * `extract()` refuses to overwrite an existing local. So if this method's
     * own parameters were named `$name` and `$data`, a fragment could never
     * receive a key called `name` or `data` — it would silently get the
     * renderer's value instead. A chip-picker taking a `name` parameter would
     * render the literal string "chip_picker" as its form field's name,
     * everywhere it was used.
     *
     * Hence the deliberately unlikely `$__partial` / `$__data`, and
     * `EXTR_SKIP` is NOT used — a collision here would be a bug, so let the
     * fragment's own keys win.
     *
     * @param array<string, mixed> $__data
     */
    public static function partial(string $__partial, array $__data = []): string
    {
        if (!preg_match('~^[a-z0-9]+(?:[-/][a-z0-9]+)*$~', $__partial)) {
            throw new RuntimeException('View::partial(): illegal fragment name "' . $__partial . '"');
        }

        $__file = ROOT_PATH . '/admin/templates/partials/' . $__partial . '.php';
        if (!is_file($__file)) {
            throw new RuntimeException('View::partial(): no such fragment ' . $__file);
        }

        extract($__data);
        unset($__data);

        ob_start();
        require $__file;
        return (string) ob_get_clean();
    }
}
