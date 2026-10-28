<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Database\Database;
use Cms\Models\Option;

/**
 * SOMEONE LOOKING FOR USERNAMES — the Activity log's Enumeration tab.
 *
 * ── THERE IS NOTHING HERE TO FIND ───────────────────────────────────────────
 * WordPress gives its logins away in places every scanner knows: `?author=N`,
 * which redirects to `/author/<login>/`; the author archives themselves; the
 * REST API's `/wp/v2/users`; the core users sitemap and Yoast's author sitemap;
 * oEmbed's `author_name`. The CMS has none of them: those addresses are the
 * site's not-found page, and `?author=` is a stray parameter like any other.
 *
 * ── SO THIS ONLY WRITES IT DOWN ─────────────────────────────────────────────
 * A request for one of them is a script looking — worth an owner knowing, as
 * the same address is likely at the sign-in page next. It is logged
 * (`enumeration`: the address, the browser, what was asked) and answered
 * exactly as before, so being noticed teaches the script nothing.
 *
 * ── ONE LINE PER ADDRESS AN HOUR ────────────────────────────────────────────
 * A scanner asks for `?author=1` to `?author=50` in a second. Fifty lines would
 * say one thing fifty times — and would let anyone fill the log by asking. So
 * an address is written down once in `QUIET_MINUTES`, with the first thing it
 * asked for.
 */
final class Enumeration
{
    /** Write probes down at all. On by default. */
    public const LOG_KEY = 'security_enumeration_log';

    public const QUIET_MINUTES = 60;

    /** What each kind of probe is called on the screens. */
    public const KINDS = [
        'author'        => 'Author number',
        'author-page'   => 'Author page',
        'users-api'     => 'Users API',
        'users-sitemap' => 'Users sitemap',
        'oembed'        => 'oEmbed',
    ];

    public static function logging(): bool
    {
        return Option::get(self::LOG_KEY, '1') === '1';
    }

    /**
     * WHICH PLACE a request is looking in for usernames — a `KINDS` key — or
     * null for an ordinary request. Pure: no request, disk or database.
     *
     * @param string               $path   the site-relative path (`www/index.php`'s `$requestPath`)
     * @param array<string, mixed> $query  the query string, parsed
     */
    public static function probe(string $path, array $query): ?string
    {
        $path = '/' . ltrim($path, '/');
        $rest = is_string($query['rest_route'] ?? null) ? '/' . ltrim((string) $query['rest_route'], '/') : '';

        return match (true) {
            array_key_exists('author', $query)                                             => 'author',
            preg_match('#^/author/[^/]+#i', $path) === 1                                   => 'author-page',
            preg_match('#^/wp-json/wp/v2/users(?:/|$)#i', $path) === 1,
            preg_match('#^/wp/v2/users(?:/|$)#i', $rest) === 1                             => 'users-api',
            preg_match('#^/(?:wp-sitemap-users-\d+|author-sitemap\d*)\.xml$#i', $path) === 1 => 'users-sitemap',
            preg_match('#^/wp-json/oembed/1\.0/embed(?:/|$)#i', $path) === 1,
            preg_match('#^/oembed/1\.0/embed(?:/|$)#i', $rest) === 1                       => 'oembed',
            default                                                                        => null,
        };
    }

    /**
     * Called for every request that reaches the router (`www/index.php`):
     * an ordinary one costs a few string tests and nothing else. A probe is
     * logged — unless logging is off, or this address was written down in the
     * last `QUIET_MINUTES`.
     *
     * @param array<string, mixed> $query
     */
    public static function watch(string $path, array $query): void
    {
        $kind = self::probe($path, $query);
        if ($kind === null || !self::logging()) {
            return;
        }

        $stmt = Database::getInstance()->prepare(
            'SELECT 1 FROM security_log
              WHERE event = :event AND ip LIKE :ip
                AND created_at > UTC_TIMESTAMP() - INTERVAL ' . (int) self::QUIET_MINUTES . ' MINUTE
              LIMIT 1'
        );
        $stmt->execute([':event' => SecurityLog::ENUMERATION, ':ip' => ClientIp::likePattern(ClientIp::key())]);
        if ($stmt->fetchColumn() !== false) {
            return;
        }

        // The NAME a script guessed, from `/author/<name>` — the same reason
        // the failed sign-ins keep the name typed: it shows the word list.
        $name = null;
        if ($kind === 'author-page' && preg_match('#^/author/([^/]+)#i', '/' . ltrim($path, '/'), $m) === 1) {
            $name = mb_substr(trim(rawurldecode($m[1])), 0, 60);
        }

        SecurityLog::record(
            SecurityLog::ENUMERATION,
            $name !== '' ? $name : null,
            null,
            $kind . ' · ' . (string) ($_SERVER['REQUEST_URI'] ?? $path)
        );
    }

    /**
     * A logged row's kind and what was asked, split back out of its `detail`.
     *
     * @return array{0: string, 1: string}  kind ('' if unknown), the address asked for
     */
    public static function parse(string $detail): array
    {
        [$kind, $asked] = array_pad(explode(' · ', $detail, 2), 2, '');

        return [isset(self::KINDS[$kind]) ? $kind : '', $asked];
    }
}
