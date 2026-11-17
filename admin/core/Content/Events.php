<?php

declare(strict_types=1);

namespace Cms\Content;

use Cms\Database\Database;
use Cms\Helpers\Dates;
use PDO;

/**
 * AN EVENTS LIST'S ORDER (`Public\ContentTypeController::archive()`): what is
 * still to come first, the soonest first; then what is over, the latest
 * first; then any event with no date, the newest first.
 *
 * It is decided for the whole type, and the page is cut from it. Sorted only
 * within each page, the page an event fell on was decided by when it was
 * published, so next week's meeting could sit on page three while page one
 * opened on last year's.
 *
 * An event is over once its end has passed, or its start when it has no end,
 * so one that began yesterday and ends tomorrow is still to come. Its times
 * are the site's wall-clock times as typed, in Settings → General's zone, and
 * are read that way.
 */
final class Events
{
    /**
     * A type's live events in list order, each with whether it is over.
     *
     * @return list<array{id: int, past: bool}>
     */
    public static function ordered(string $type, ?\DateTimeImmutable $now = null): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT p.id, s.meta_value AS starts, e.meta_value AS ends
               FROM posts p
               LEFT JOIN post_meta s ON s.post_id = p.id AND s.meta_key = 'event_start_date'
               LEFT JOIN post_meta e ON e.post_id = p.id AND e.meta_key = 'event_end_date'
              WHERE p.post_type = :pt AND " . \Cms\Models\Post::live('p') . "
           ORDER BY p.published_at DESC, p.id DESC"
        );
        $stmt->execute([':pt' => $type]);

        return self::order($stmt->fetchAll(PDO::FETCH_ASSOC), $now ?? new \DateTimeImmutable('now'));
    }

    /**
     * Rows of `id`, `starts` and `ends`, put in list order. The order they
     * arrive in breaks every tie.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{id: int, past: bool}>
     */
    public static function order(array $rows, \DateTimeImmutable $now): array
    {
        $keyed = [];
        foreach (array_values($rows) as $position => $row) {
            $start = self::moment($row['starts'] ?? null);
            $end   = self::moment($row['ends'] ?? null, true);
            $last  = $start !== null && $end !== null && $end > $start ? $end : self::moment($row['starts'] ?? null, true);
            // 0 still to come, 1 over, 2 no date
            $group   = $start === null ? 2 : ($last < $now ? 1 : 0);
            $at      = $start !== null ? $start->getTimestamp() : 0;
            $keyed[] = ['id' => (int) $row['id'], 'group' => $group, 'at' => $group === 1 ? -$at : $at, 'position' => $position];
        }
        usort($keyed, static fn (array $a, array $b): int =>
            [$a['group'], $a['at'], $a['position']] <=> [$b['group'], $b['at'], $b['position']]);

        return array_map(static fn (array $k): array => ['id' => $k['id'], 'past' => $k['group'] === 1], $keyed);
    }

    /**
     * A time as typed, in the site's zone; null for none, or for what is not
     * a date. A date with no time is the start of its day, or with
     * `$endOfDay` its end: an event on the 26th is not over until the 26th is.
     */
    public static function moment(mixed $value, bool $endOfDay = false): ?\DateTimeImmutable
    {
        $value = is_string($value) ? trim($value) : '';
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d'] as $shape) {
            $at = \DateTimeImmutable::createFromFormat('!' . $shape, $value, Dates::zone());
            if ($at === false) {
                continue;
            }
            return $shape === 'Y-m-d' && $endOfDay ? $at->setTime(23, 59, 59) : $at;
        }

        return null;
    }
}
