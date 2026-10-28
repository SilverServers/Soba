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

    /** The page this request asks for; anything that is not a page is page 1. */
    public static function current(): int
    {
        $raw = $_GET['page'] ?? '1';
        return is_string($raw) && ctype_digit($raw) ? max(1, (int) $raw) : 1;
    }

    /**
     * Page `$n` of the list whose first page is `$base`. Page 1 is the list's
     * own address and never `?page=1`: two addresses for one page is the
     * duplicate the canonical exists to prevent.
     */
    public static function url(string $base, int $n): string
    {
        return $n <= 1 ? $base : $base . (str_contains($base, '?') ? '&' : '?') . 'page=' . $n;
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
