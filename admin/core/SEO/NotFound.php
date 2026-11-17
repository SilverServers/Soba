<?php

declare(strict_types=1);

namespace Cms\SEO;

use Cms\Database\Database;
use PDO;

/**
 * THE ADDRESSES VISITORS ASKED FOR THAT WERE NOT FOUND, for Tools →
 * Redirects to list with Add redirect beside each.
 *
 * Every public 404 (`Helpers\Template::notFound()`) counts its address once:
 * how often it was asked for, when first and last, and the page that linked
 * to it the last time, when the browser said. The address is kept as the
 * redirects read it (`www/index.php`): no query, no ending slash, the site's
 * own folder taken off, still encoded, so a redirect added from a row
 * matches the address that was asked for.
 *
 * Not counted: anything under the admin's address, the front page and the
 * sign-in page (no redirect may take either), a request that is not a GET,
 * a page drawn for a static copy, and the updater's own smoke test, which
 * asks for a page that is not there on purpose. No visitor's address or browser is
 * kept, only the address they asked for.
 *
 * The newest thousand are kept, so a scanner trying a thousand addresses
 * cannot grow the table without end. An address a redirect now answers is
 * left off the list, and dropped when a redirect for it is added.
 */
final class NotFound
{
    /** The rows kept, newest first. */
    public const KEEP = 1000;

    /** The longest address or referrer kept, as the columns hold. */
    private const MAX = 500;

    /**
     * Count this request's address as not found. Never throws: a page that
     * is not there must still be answered when the count cannot be written.
     */
    public static function record(): void
    {
        try {
            if (defined('CMS_EXPORT') || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
                || \Cms\Update\Updater::isProbe($_SERVER)) {
                return;
            }
            $path = self::path((string) ($_SERVER['REQUEST_URI'] ?? '/'));
            if ($path === null) {
                return;
            }
            $referrer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
            $referrer = $referrer !== '' ? mb_substr($referrer, 0, self::MAX) : null;
            $now      = gmdate('Y-m-d H:i:s');

            $pdo  = Database::getInstance();
            $stmt = $pdo->prepare(
                'INSERT INTO not_found_log (path, hits, referrer, first_seen, last_seen)
                 VALUES (:path, 1, :referrer, :first, :last)
                 ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen),
                                         referrer = COALESCE(VALUES(referrer), referrer)'
            );
            $stmt->execute([':path' => $path, ':referrer' => $referrer, ':first' => $now, ':last' => $now]);

            // A NEW ADDRESS (one row affected, not two) may take the table
            // past what is kept: the oldest go.
            if ($stmt->rowCount() === 1) {
                self::prune($pdo);
            }
        } catch (\Throwable $e) {
            error_log('CMS not-found log: ' . $e->getMessage());
        }
    }

    /**
     * The address a request asked for, as the redirects read it, or null for
     * one that is not the public site's (the admin, the sign-in page).
     */
    public static function path(string $uri): ?string
    {
        $path = (string) (strtok($uri, '?') ?: '/');
        $base = defined('BASE_PATH') ? rtrim((string) BASE_PATH, '/') : '';
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1 || mb_strlen($path) > self::MAX || !mb_check_encoding($path, 'UTF-8')) {
            return null;
        }
        $slugs = array_unique(['admin', defined('ADMIN_URL_SLUG') ? (string) ADMIN_URL_SLUG : 'admin']);
        foreach ($slugs as $slug) {
            if ($path === '/' . $slug || str_starts_with($path, '/' . $slug . '/')) {
                return null;
            }
        }
        // Nor the front page, or the sign-in page: no redirect may take either.
        return $path === '/' || $path === \Cms\Security\LoginUrl::path() ? null : $path;
    }

    /**
     * The addresses to list, most asked-for first: each with its count, when
     * it was last asked for and what linked to it. An address a redirect now
     * answers (a `/*` rule added since, say) is left off.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function list(int $limit = 50): array
    {
        $pdo  = Database::getInstance();
        $rows = $pdo->query(
            'SELECT id, path, hits, referrer, first_seen, last_seen FROM not_found_log
          ORDER BY hits DESC, last_seen DESC, id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $open = array_values(array_filter($rows, static fn (array $r): bool => Redirects::forRequest((string) $r['path']) === null));

        return ['rows' => array_slice($open, 0, max(1, $limit)), 'total' => count($open)];
    }

    /** One row by its id, or null. @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM not_found_log WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /** Take one address off the list. */
    public static function dismiss(int $id): void
    {
        Database::getInstance()->prepare('DELETE FROM not_found_log WHERE id = :id')->execute([':id' => $id]);
    }

    /** Take an address off the list by itself: a redirect now answers it. */
    public static function forget(string $path): void
    {
        Database::getInstance()->prepare('DELETE FROM not_found_log WHERE path = :path')->execute([':path' => $path]);
    }

    /** Empty the list. */
    public static function clear(): void
    {
        Database::getInstance()->exec('DELETE FROM not_found_log');
    }

    /** Keep the newest KEEP rows. */
    private static function prune(PDO $pdo): void
    {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM not_found_log')->fetchColumn();
        if ($count <= self::KEEP) {
            return;
        }
        // The cut-off is the KEEP-th newest; everything older goes. Integers
        // produced here, never bound: LIMIT takes no placeholder everywhere.
        $cut = $pdo->query(
            'SELECT last_seen, id FROM not_found_log ORDER BY last_seen DESC, id DESC LIMIT 1 OFFSET ' . (self::KEEP - 1)
        )->fetch(PDO::FETCH_ASSOC);
        if ($cut !== false) {
            $pdo->prepare('DELETE FROM not_found_log WHERE last_seen < :seen OR (last_seen = :seen2 AND id < :id)')
                ->execute([':seen' => $cut['last_seen'], ':seen2' => $cut['last_seen'], ':id' => (int) $cut['id']]);
        }
    }
}
