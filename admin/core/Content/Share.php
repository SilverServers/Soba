<?php

declare(strict_types=1);

namespace Cms\Content;

use Cms\Database\Database;
use Cms\Http\Cache;
use PDO;

/**
 * A PAGE OR AN ENTRY NOT YET ON THE SITE, SHARED BY A LINK: the page's own
 * address with `?share=` and a code after it, which shows it to whoever has
 * the link, without signing in, until the date the owner chose.
 *
 * Only for what a visitor cannot see yet: Private, a publish date still to
 * come, or an unpublish date gone by. A live page needs no link, and a
 * password-protected one is shared by its password.
 *
 * ── THE CODE IS NOT STORED ──────────────────────────────────────────────────
 * The row holds a random salt; the code is made from it, the page it is for
 * and the date it lapses, under the site's `app_secret` (`Forms\Guard::mac()`),
 * so a copy of the database alone does not give a link away, and a link
 * cannot be moved to another page or another date. One link a page: making a
 * new one replaces it, and Stop sharing deletes it.
 *
 * A page seen through its link says `noindex` and is not cached, so neither a
 * search engine nor a shared cache keeps a copy past the link's date.
 */
final class Share
{
    /** How long a link may last, in days, as the editor offers it. */
    public const DAYS = [1, 7, 30];

    public const DEFAULT_DAYS = 7;

    /** The query parameter that carries the code. */
    public const PARAM = 'share';

    /** @var array<string, bool> type:id => whether this request's code opens it */
    private static array $granted = [];

    /**
     * Make a link for `$type` ('page' or 'post') `$id`, lasting `$days`,
     * replacing any it had.
     */
    public static function make(string $type, int $id, int $days, ?int $by): void
    {
        if (!in_array($days, self::DAYS, true)) {
            $days = self::DEFAULT_DAYS;
        }
        $pdo = Database::getInstance();
        $pdo->prepare('DELETE FROM share_links WHERE object_type = :t AND object_id = :i')
            ->execute([':t' => self::type($type), ':i' => $id]);
        $pdo->prepare(
            'INSERT INTO share_links (object_type, object_id, salt, expires_at, created_by, created_at)
             VALUES (:t, :i, :salt, :expires, :by, :now)'
        )->execute([
            ':t'       => self::type($type),
            ':i'       => $id,
            ':salt'    => bin2hex(random_bytes(16)),
            ':expires' => gmdate('Y-m-d H:i:s', time() + $days * 86400),
            ':by'      => $by,
            ':now'     => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** Stop sharing it: the link stops working at once. */
    public static function stop(string $type, int $id): void
    {
        Database::getInstance()->prepare('DELETE FROM share_links WHERE object_type = :t AND object_id = :i')
            ->execute([':t' => self::type($type), ':i' => $id]);
    }

    /**
     * Its link while it works: the code and when it lapses (UTC). Null when
     * it has none, or the one it had has lapsed.
     *
     * @return array{code: string, expires_at: string}|null
     */
    public static function current(string $type, int $id): ?array
    {
        $row = self::row($type, $id);
        if ($row === null || strtotime((string) $row['expires_at'] . ' UTC') <= time()) {
            return null;
        }
        return ['code' => self::code($row), 'expires_at' => (string) $row['expires_at']];
    }

    /**
     * Whether this request carries the working code for `$type` `$id`. Once
     * it has, the page is sent with `noindex` and no caching.
     */
    public static function grants(string $type, int $id): bool
    {
        $key = self::type($type) . ':' . $id;
        if (isset(self::$granted[$key])) {
            return self::$granted[$key];
        }
        $given = $_GET[self::PARAM] ?? '';
        $ok    = false;
        if (is_string($given) && preg_match('/^[a-f0-9]{32}$/', $given) === 1) {
            $now = self::current($type, $id);
            $ok  = $now !== null && hash_equals($now['code'], $given);
        }
        if ($ok && !headers_sent()) {
            header('X-Robots-Tag: noindex, nofollow');
            Cache::noStore(private: true);
        }
        return self::$granted[$key] = $ok;
    }

    /** Whether any page this request drew was opened by its link. */
    public static function shown(): bool
    {
        return in_array(true, self::$granted, true);
    }

    /** Forget what this request decided (for a test that changes the link). */
    public static function reset(): void
    {
        self::$granted = [];
    }

    /** Its links go when it does. */
    public static function deleteFor(string $type, int $id): void
    {
        self::stop($type, $id);
    }

    /** @return array<string, mixed>|null */
    private static function row(string $type, int $id): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM share_links WHERE object_type = :t AND object_id = :i LIMIT 1'
        );
        $stmt->execute([':t' => self::type($type), ':i' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /** @param array<string, mixed> $row */
    private static function code(array $row): string
    {
        return substr(\Cms\Forms\Guard::mac(
            'share|' . $row['object_type'] . '|' . (int) $row['object_id'] . '|' . $row['salt'] . '|' . $row['expires_at']
        ), 0, 32);
    }

    private static function type(string $type): string
    {
        return $type === 'page' ? 'page' : 'post';
    }
}
