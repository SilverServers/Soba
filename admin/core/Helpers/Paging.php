<?php

declare(strict_types=1);

namespace Cms\Helpers;

use Cms\Models\Option;

/**
 * HOW A PUBLIC LIST IS PAGED — one rule, read by every list the site shows.
 *
 * The type archive, the term archive and the home feed all read it. Each
 * keeping its own `$perPage`, its own way of building page N's address and a
 * canonical pointing at page 1 means a site that shows twelve cannot say so,
 * page 2 tells search engines it is a duplicate of page 1, and three copies of
 * "what is the address of page N" are three places for it to disagree with the
 * `rel` links.
 *
 *   perPage()   "Entries per page" (General → Reading), the option
 *               `posts_per_page`
 *   parameter() the page number's name in the address: `page`, or the one
 *               config.php's `paging_parameter` gives
 *   current()   the page this request asks for
 *   url()       page N of a list: its own address for page 1 — never `?page=1`
 *               — and `?page=N` after that
 *   head()      what the <head> says about it: a canonical that is the page
 *               itself, and `rel="prev"` / `rel="next"`
 */
final class Paging
{
    /** The number shown until the site sets its own. */
    public const DEFAULT_PER_PAGE = 10;

    /** The most a page shows — General's box and a Content Type's own. */
    public const MAX_PER_PAGE = 100;

    /** The page number's name in a list's address, unless config.php gives another. */
    public const PARAMETER = 'page';

    /**
     * The names a list's number cannot take: the questions the paged lists
     * already ask (a sort, a share link, a form's thank-you) and search's.
     * A shop's filters ask by their taxonomy's key, so no taxonomy's key is
     * taken either.
     */
    private const TAKEN = ['sort', 'share', 'sent', 'form', 'q', 'index'];

    /** The name for this request; null until asked. */
    private static ?string $parameter = null;

    /**
     * THE PAGE NUMBER'S NAME IN A PUBLIC LIST'S ADDRESS: `page` (?page=2), or
     * config.php's `paging_parameter` for a site whose own pages number
     * theirs another way (?p=2), so its lists and the CMS's read alike. The
     * admin's own lists keep `page`.
     */
    public static function parameter(): string
    {
        if (self::$parameter === null) {
            $config = defined('ROOT_PATH') ? \Cms\Site\ConfigFile::read(ROOT_PATH . '/config/config.php') : null;
            self::configure(is_array($config) ? trim((string) ($config['paging_parameter'] ?? '')) : '');
        }

        return (string) self::$parameter;
    }

    /**
     * The name for this request, as config.php's `paging_parameter` gives it
     * ('' is `page`), for a site's own front controller or a check. A name
     * that is not a lower-case one of up to 32 letters, digits, `_` and `-`
     * starting with a letter, or that a list already asks, is logged and
     * `page` is used.
     */
    public static function configure(string $name): void
    {
        if ($name !== '' && (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $name) !== 1 || in_array($name, self::TAKEN, true)
            || \Cms\ContentType\TaxonomyRegistry::get($name) !== null)) {
            error_log('CMS: config.php\'s paging_parameter "' . $name . '" is not a name a list\'s page number can take; page is used.');
            $name = '';
        }
        self::$parameter = $name !== '' ? $name : self::PARAMETER;
    }

    /** How many entries a public list shows on one page — 1 to 100. */
    public static function perPage(): int
    {
        $n = (int) (Option::get('posts_per_page', (string) self::DEFAULT_PER_PAGE) ?? self::DEFAULT_PER_PAGE);
        return $n >= 1 && $n <= self::MAX_PER_PAGE ? $n : self::DEFAULT_PER_PAGE;
    }

    /**
     * WHICH ROWS PAGE $page SHOWS, as [limit, offset]. A limit of 0 is no
     * limit: a list of 0 to a page is one page. Page 1 carries $extra more
     * than the rest (a Content Type's first-page extra, migration 090), and
     * page 2 starts after them — so nothing is shown twice or skipped.
     *
     * @return array{0: int, 1: int}
     */
    public static function window(int $page, int $perPage, int $extra = 0): array
    {
        if ($perPage <= 0) {
            return [0, 0];
        }
        $page  = max(1, $page);
        $extra = max(0, $extra);

        return $page === 1
            ? [$perPage + $extra, 0]
            : [$perPage, ($page - 1) * $perPage + $extra];
    }

    /**
     * HOW MANY PAGES $total ENTRIES MAKE, with the same rule as window().
     * Never less than 1: an empty archive still has its page, and only a
     * page past the last is a 404.
     */
    public static function pages(int $total, int $perPage, int $extra = 0): int
    {
        $extra = max(0, $extra);
        if ($perPage <= 0 || $total <= $perPage + $extra) {
            return 1;
        }

        return 1 + (int) ceil(($total - $perPage - $extra) / $perPage);
    }

    /**
     * The page this request asks for; anything that is not a page is page 1.
     * A public list's number is under `parameter()`; an admin list names
     * `page`.
     */
    public static function current(?string $name = null): int
    {
        $raw = $_GET[$name ?? self::parameter()] ?? '1';
        return is_string($raw) && ctype_digit($raw) ? max(1, (int) $raw) : 1;
    }

    /**
     * Page `$n` of the list whose first page is `$base`. Page 1 is the list's
     * own address and never `?page=1`: two addresses for one page is the
     * duplicate the canonical exists to prevent.
     */
    public static function url(string $base, int $n): string
    {
        return $n <= 1 ? $base : $base . (str_contains($base, '?') ? '&' : '?') . self::parameter() . '=' . $n;
    }

    /**
     * The canonical and the neighbours of page `$page` of `$pages`.
     *
     * @return array{canonical: string, prev: ?string, next: ?string}
     */
    public static function head(string $base, int $page, int $pages): array
    {
        return [
            'canonical' => self::url($base, $page),
            'prev'      => $page > 1 ? self::url($base, $page - 1) : null,
            'next'      => $page < $pages ? self::url($base, $page + 1) : null,
        ];
    }
}
