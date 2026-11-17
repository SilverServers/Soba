<?php

declare(strict_types=1);

namespace Cms\Content;

use Cms\Database\Database;
use Cms\Models\User;
use PDO;

/**
 * WHO ELSE HAS THIS PAGE OR ENTRY OPEN in its editor, so two people do not
 * write over each other without knowing.
 *
 * Opening the editor puts the person in it (`open()`); writing in it keeps
 * them there, through the question the editor already asks while somebody
 * types (`Api\SessionController`, `keep-writing.js`), which also answers who
 * else is in it now and whether somebody else has saved it since this screen
 * was drawn; leaving the editor takes them out (`leave()`). Nothing asks on a
 * timer while nobody types, so an editor left open still signs out on time:
 * a person is shown for fifteen minutes after they were last seen in it.
 *
 * It tells; it does not lock. Two people may still both save, and the later
 * save is what the page holds, with the earlier one in its history.
 */
final class Presence
{
    /** Seconds after which a person last seen in an editor is no longer shown there. */
    public const WINDOW = 900;

    /** Put `$userId` in the editor of `$type` ('page' or 'post') `$id`: opened now. */
    public static function open(string $type, int $id, int $userId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        Database::getInstance()->prepare(
            'INSERT INTO edit_presence (object_type, object_id, user_id, opened_at, seen_at, writing_at)
             VALUES (:t, :i, :u, :opened, :seen, NULL)
             ON DUPLICATE KEY UPDATE opened_at = VALUES(opened_at), seen_at = VALUES(seen_at), writing_at = NULL'
        )->execute([':t' => self::type($type), ':i' => $id, ':u' => $userId, ':opened' => $now, ':seen' => $now]);
        self::sweep();
    }

    /** They are still there, writing: seen now. Puts them back in it if they had gone. */
    public static function writing(string $type, int $id, int $userId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        Database::getInstance()->prepare(
            'INSERT INTO edit_presence (object_type, object_id, user_id, opened_at, seen_at, writing_at)
             VALUES (:t, :i, :u, :opened, :seen, :writing)
             ON DUPLICATE KEY UPDATE seen_at = VALUES(seen_at), writing_at = VALUES(writing_at)'
        )->execute([':t' => self::type($type), ':i' => $id, ':u' => $userId, ':opened' => $now, ':seen' => $now, ':writing' => $now]);
    }

    /** They have left the editor. */
    public static function leave(string $type, int $id, int $userId): void
    {
        Database::getInstance()->prepare(
            'DELETE FROM edit_presence WHERE object_type = :t AND object_id = :i AND user_id = :u'
        )->execute([':t' => self::type($type), ':i' => $id, ':u' => $userId]);
    }

    /**
     * Everyone but `$userId` seen in the editor within the window: their name,
     * when they opened it and when they last wrote in it (UTC), longest there
     * first.
     *
     * @return list<array{name: string, opened_at: string, writing_at: ?string}>
     */
    public static function others(string $type, int $id, int $userId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT ' . User::nameSql('u') . ' AS name, e.opened_at, e.writing_at
               FROM edit_presence e
               JOIN users u ON u.id = e.user_id
              WHERE e.object_type = :t AND e.object_id = :i AND e.user_id <> :u
                AND e.seen_at > :since
           ORDER BY e.opened_at ASC, e.user_id ASC'
        );
        $stmt->execute([
            ':t'     => self::type($type),
            ':i'     => $id,
            ':u'     => $userId,
            ':since' => gmdate('Y-m-d H:i:s', time() - self::WINDOW),
        ]);
        return array_map(static fn (array $r): array => [
            'name'       => (string) $r['name'],
            'opened_at'  => (string) $r['opened_at'],
            'writing_at' => $r['writing_at'] !== null ? (string) $r['writing_at'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Who saved it last, and when (UTC), if that was somebody else after
     * `$since`: each save keeps what it replaced, under the saver's name
     * (`Models\Revision`). Null when nobody else has saved since.
     *
     * @return array{name: string, at: string}|null
     */
    public static function savedSince(string $type, int $id, int $userId, string $since): ?array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT r.author_id, r.created_at, " . User::nameSql('u') . " AS name
               FROM revisions r
               LEFT JOIN users u ON u.id = r.author_id
              WHERE r.object_type = :t AND r.object_id = :i
           ORDER BY r.id DESC
              LIMIT 1"
        );
        $stmt->execute([':t' => self::type($type), ':i' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (int) $row['author_id'] === $userId || (string) $row['created_at'] <= $since) {
            return null;
        }
        return ['name' => (string) ($row['name'] ?? ''), 'at' => (string) $row['created_at']];
    }

    /** What is past the window goes, now and then. */
    private static function sweep(): void
    {
        if (random_int(1, 20) !== 1) {
            return;
        }
        Database::getInstance()->prepare('DELETE FROM edit_presence WHERE seen_at < :since')
            ->execute([':since' => gmdate('Y-m-d H:i:s', time() - self::WINDOW)]);
    }

    private static function type(string $type): string
    {
        return $type === 'page' ? 'page' : 'post';
    }
}
