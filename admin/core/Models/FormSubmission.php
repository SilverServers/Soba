<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Database\Database;
use PDO;

/**
 * A form submission: one that was RECEIVED, or one a form turned away and kept
 * as SPAM with its reason (migration 066).
 *
 * ── EVERY LIST IS A PAGE ────────────────────────────────────────────────────
 * `page()` is the only way a screen reads a list. Every row decoded and merged
 * and sorted in PHP is fine at forty rows and not at a quarter of a million,
 * where a Spam view never finishes loading. A page is two queries on
 * `idx_form_submissions_form_status` or `idx_form_submissions_status` — the
 * ids first, from the index alone, then just those rows — so page 1 and page
 * 12,000 cost about the same.
 *
 * ── WHAT IS NOT STORED IS COUNTED ───────────────────────────────────────────
 * A post the guard is certain about is discarded, and `countDiscard()` adds it
 * to `form_spam_counts` by day, form and reason. See `Forms\Guard`.
 */
class FormSubmission
{
    /** A submission the form accepted. */
    public const RECEIVED = 'received';
    /** One it turned away, kept with its reason. */
    public const SPAM = 'spam';

    /** The bulk actions each list offers, by the status it lists. */
    public const ACTIONS = [
        self::RECEIVED => ['spam', 'read', 'unread', 'delete'],
        self::SPAM     => ['not_spam', 'delete'],
    ];

    /**
     * Every received submission of one form, newest first. For a caller that
     * genuinely wants all of them — a screen asks for a `page()`.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function allForForm(int $formId): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT * FROM form_submissions
             WHERE form_id = :fid AND status = 'received'
             ORDER BY submitted_at DESC, id DESC"
        );
        $stmt->execute([':fid' => $formId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['data'] = $row['data'] ? json_decode($row['data'], true) : [];
        }
        return $rows;
    }

    /**
     * Find a single submission by ID. Decodes JSON.
     */
    public static function find(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM form_submissions WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['data'] = $row['data'] ? json_decode($row['data'], true) : [];
        return $row;
    }

    /**
     * Create a new submission.
     *
     * $opts, each optional:
     *   status        'received' (the default) or 'spam'
     *   spam_reason   why the form turned it away (spam only)
     *   source_page   the page it was sent from
     *   user_agent    the browser
     *   token_hash    the time-trap token it carried, hashed (`Guard::tokenHash()`)
     *   submitted_at  its own time, 'Y-m-d H:i:s' UTC (a history import);
     *                 now when absent
     *   is_read       true for a submission nobody needs to open (history)
     *   is_test       true for a test: one sent from the form's test address,
     *                 or a history import's test
     *   id            its own number, from a history import, so a site's lead
     *                 numbers carry on. Refused when a row already has it (a
     *                 \RuntimeException, and nothing is written); new
     *                 submissions then continue after the highest, so no
     *                 number is ever given out twice. Absent: the next one.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $opts
     */
    public static function create(int $formId, array $data, ?string $ipAddress = null, array $opts = []): int
    {
        $status = ($opts['status'] ?? self::RECEIVED) === self::SPAM ? self::SPAM : self::RECEIVED;
        $cut    = static fn (mixed $v, int $n): ?string => ($v = trim((string) ($v ?? ''))) !== '' ? mb_substr($v, 0, $n) : null;
        $at     = (string) ($opts['submitted_at'] ?? '');
        $at     = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $at) === 1 ? $at : gmdate('Y-m-d H:i:s');
        $id     = null;
        if (isset($opts['id'])) {
            $id = filter_var($opts['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
            if ($id === false) {
                throw new \InvalidArgumentException('A submission\'s id is a whole number from 1.');
            }
            if (self::find($id) !== null) {
                throw new \RuntimeException("Submission #{$id} already exists: an imported submission keeps its own number or is not imported.");
            }
        }

        $pdo  = Database::getInstance();
        // `id` is NULL unless one is given, and NULL takes the next number.
        $stmt = $pdo->prepare(
            'INSERT INTO form_submissions
                (id, form_id, data, status, spam_reason, source_page, is_read, is_test, ip_address, user_agent, token_hash, submitted_at)
             VALUES (:id, :fid, :data, :status, :reason, :page, :read, :test, :ip, :ua, :token, :at)'
        );
        try {
            self::insert($stmt, $id, $formId, $data, $status, $opts, $cut, $ipAddress, $at);
        } catch (\PDOException $e) {
            // Taken between the look above and the write: refused all the same.
            if ($id !== null && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new \RuntimeException("Submission #{$id} already exists: an imported submission keeps its own number or is not imported.", 0, $e);
            }
            throw $e;
        }
        // AN ID GIVEN IS THE ID: the database's last insert id names only one
        // it chose itself.
        return $id ?? (int) $pdo->lastInsertId();
    }

    /**
     * The write itself, for `create()`.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $opts
     */
    private static function insert(\PDOStatement $stmt, ?int $id, int $formId, array $data, string $status, array $opts, \Closure $cut, ?string $ipAddress, string $at): void
    {
        $stmt->execute([
            ':id'     => $id,
            ':fid'    => $formId,
            // NEVER FALSE. A byte that is not UTF-8 made `json_encode()` return
            // false, the JSON-only column refused the row, and an accepted
            // submission was a 500 with nothing kept. It becomes U+FFFD instead.
            ':data'   => json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE),
            ':status' => $status,
            ':reason' => $status === self::SPAM ? ($cut($opts['spam_reason'] ?? 'turned away', 255) ?? 'turned away') : null,
            ':page'   => $cut($opts['source_page'] ?? null, 500),
            ':read'   => !empty($opts['is_read']) ? 1 : 0,
            ':test'   => !empty($opts['is_test']) ? 1 : 0,
            ':ip'     => $ipAddress !== null && $ipAddress !== '' ? mb_substr($ipAddress, 0, 45) : null,
            ':ua'     => $cut($opts['user_agent'] ?? null, 300),
            ':token'  => preg_match('/^[0-9a-f]{64}$/', (string) ($opts['token_hash'] ?? '')) === 1 ? $opts['token_hash'] : null,
            ':at'     => $at,
        ]);
    }

    /** Whether the notification email went: true sent, false failed. */
    public static function setNotified(int $id, bool $sent): void
    {
        Database::getInstance()->prepare('UPDATE form_submissions SET notified = :n WHERE id = :id')
            ->execute([':n' => $sent ? 1 : 0, ':id' => $id]);
    }

    /**
     * Take the conversion a received submission's after-sending page carries
     * (`Site\Google::conversion()`): true the first time, and false ever after,
     * for a row that is gone, or for one a person has since marked as spam. One
     * statement, so two pages asking at once cannot both have it.
     */
    public static function takeConversion(int $id): bool
    {
        $stmt = Database::getInstance()->prepare(
            "UPDATE form_submissions SET conversion_taken_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = 'received' AND conversion_taken_at IS NULL"
        );
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() === 1;
    }

    // ----------------------------------------------------------------
    // Lists
    // ----------------------------------------------------------------

    /**
     * One page of a list, newest first, each row with its form's title.
     *
     * TWO QUERIES, AND THE FIRST NEVER TOUCHES A ROW. The ids come from the
     * index alone (status, or form + status, then the time; the primary key
     * rides along in every InnoDB secondary key), so skipping to page 12,000
     * walks index entries rather than reading 240,000 payloads. The second
     * reads the page's rows and nothing else.
     *
     * `$test`: true lists tests alone, false leaves them out, null both.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public static function page(string $status, ?int $formId, int $page, int $perPage, ?bool $test = null): array
    {
        $pdo     = Database::getInstance();
        $perPage = max(1, min(999, $perPage));
        $page    = max(1, $page);
        [$where, $args] = self::scope($status, $formId, $test);

        $ids = $pdo->prepare(
            "SELECT id FROM form_submissions WHERE $where
             ORDER BY submitted_at DESC, id DESC
             LIMIT " . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $ids->execute($args);
        $idList = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));

        $items = [];
        if ($idList !== []) {
            $in   = implode(',', array_fill(0, count($idList), '?'));
            $rows = $pdo->prepare(
                "SELECT s.id, s.form_id, s.data, s.status, s.spam_reason, s.source_page, s.is_read, s.is_test,
                        s.ip_address, s.notified, s.submitted_at, f.title AS form_title
                 FROM form_submissions s JOIN forms f ON f.id = s.form_id
                 WHERE s.id IN ($in)
                 ORDER BY s.submitted_at DESC, s.id DESC"
            );
            $rows->execute($idList);
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $row['data'] = $row['data'] ? (json_decode((string) $row['data'], true) ?: []) : [];
                $items[]     = $row;
            }
        }

        return ['items' => $items, 'total' => self::count($status, $formId, $test)];
    }

    /** How many a list holds; `$test` as `page()` reads it. */
    public static function count(string $status, ?int $formId = null, ?bool $test = null): int
    {
        [$where, $args] = self::scope($status, $formId, $test);
        $stmt = Database::getInstance()->prepare("SELECT COUNT(*) FROM form_submissions WHERE $where");
        $stmt->execute($args);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Whether a list of received submissions, one form's or every form's,
     * holds any test, from the index alone (`idx_form_submissions_test`).
     */
    public static function hasTests(?int $formId): bool
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT 1 FROM form_submissions WHERE is_test = 1 AND status = 'received'"
            . ($formId !== null ? ' AND form_id = ?' : '') . ' LIMIT 1'
        );
        $stmt->execute($formId !== null ? [$formId] : []);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * The WHERE for one list: a status, a form when the list has one, and
     * tests alone or none when it asks.
     *
     * @return array{0: string, 1: list<int|string>}
     */
    private static function scope(string $status, ?int $formId, ?bool $test = null): array
    {
        $status = $status === self::SPAM ? self::SPAM : self::RECEIVED;
        [$where, $args] = $formId !== null
            ? ['form_id = ? AND status = ?', [$formId, $status]]
            : ['status = ?', [$status]];
        if ($test !== null) {
            $where .= ' AND is_test = ?';
            $args[]  = $test ? 1 : 0;
        }
        return [$where, $args];
    }

    // ----------------------------------------------------------------
    // The whole list, for an export
    // ----------------------------------------------------------------

    /**
     * Every received submission — of one form, or of every form — newest
     * first, handed to `$each` one at a time and read `$batch` at a time, so
     * an export of any size holds one batch in memory. Spam is never in it.
     *
     * KEYSET, NOT OFFSET. Each batch starts after the last row the previous
     * one ended on (the time, then the id for a tie), on the same index a
     * list page uses, so the four-hundredth batch costs what the first did.
     *
     * @param callable(array<string, mixed>): void $each
     * @return int how many rows were handed over
     */
    public static function eachReceived(?int $formId, callable $each, int $batch = 500): int
    {
        $pdo   = Database::getInstance();
        $batch = max(1, $batch);
        [$where, $args] = self::scope(self::RECEIVED, $formId);

        $after = null;
        $count = 0;
        do {
            $bind = $args;
            $more = '';
            if ($after !== null) {
                $more = ' AND (submitted_at < ? OR (submitted_at = ? AND id < ?))';
                array_push($bind, $after[0], $after[0], $after[1]);
            }
            $stmt = $pdo->prepare(
                "SELECT id, form_id, data, source_page, ip_address, notified, is_test, submitted_at
                 FROM form_submissions WHERE $where$more
                 ORDER BY submitted_at DESC, id DESC LIMIT " . $batch
            );
            $stmt->execute($bind);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $row['data'] = $row['data'] ? (json_decode((string) $row['data'], true) ?: []) : [];
                $each($row);
                $count++;
            }
            $last  = $rows === [] ? null : $rows[count($rows) - 1];
            $after = $last === null ? null : [(string) $last['submitted_at'], (int) $last['id']];
        } while (count($rows) === $batch);

        return $count;
    }

    /**
     * Every answer name the received submissions carry — of one form, or of
     * every form — from the newest set back. An export's columns come from
     * the form first; this is what a submission holds that the form no longer
     * defines (a field renamed or removed since), so its answers still get a
     * column and are never dropped. Asked of the database, not of every row
     * decoded in PHP.
     *
     * @return list<string>
     */
    public static function answerKeys(?int $formId): array
    {
        [$where, $args] = self::scope(self::RECEIVED, $formId);
        $stmt = Database::getInstance()->prepare(
            "SELECT JSON_KEYS(data) AS k FROM form_submissions WHERE $where
             GROUP BY k ORDER BY MAX(submitted_at) DESC"
        );
        $stmt->execute($args);

        $keys = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $list = json_decode((string) $json, true);
            foreach (is_array($list) ? $list : [] as $key) {
                $keys[(string) $key] = true;
            }
        }

        return array_map('strval', array_keys($keys));
    }

    // ----------------------------------------------------------------
    // Many at once
    // ----------------------------------------------------------------

    /**
     * Apply one bulk action to the ticked ids THAT BELONG TO THE LIST.
     *
     * The ids are re-read under the list's own conditions — its status, and
     * its form when it has one — so an id from another list (a forged post, a
     * second tab where the row already moved) is never touched; it is counted
     * as skipped. What `done` reports is the rows that matched, found before
     * the write: MariaDB's affected-rows counts only rows that CHANGED, so
     * marking twenty rows read when three already were would have said 17.
     *
     * Delete is one statement: appointment bookings go with their submission
     * through migration 040's ON DELETE CASCADE, exactly as the row's own
     * Delete does.
     *
     * @param  list<int> $ids
     * @return array{done: int, skipped: int}
     */
    public static function bulk(string $action, array $ids, string $status, ?int $formId, string $reason = ''): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        $status = $status === self::SPAM ? self::SPAM : self::RECEIVED;
        if ($ids === [] || !in_array($action, self::ACTIONS[$status], true)) {
            return ['done' => 0, 'skipped' => count($ids)];
        }

        $pdo = Database::getInstance();
        [$where, $args] = self::scope($status, $formId);
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $find = $pdo->prepare("SELECT id FROM form_submissions WHERE $where AND id IN ($in)");
        $find->execute([...$args, ...$ids]);
        $hit = array_map('intval', $find->fetchAll(PDO::FETCH_COLUMN));
        if ($hit === []) {
            return ['done' => 0, 'skipped' => count($ids)];
        }

        $in2  = implode(',', array_fill(0, count($hit), '?'));
        $sql  = match ($action) {
            'spam'     => "UPDATE form_submissions SET status = 'spam', spam_reason = ?, is_read = 1 WHERE status = 'received' AND id IN ($in2)",
            'not_spam' => "UPDATE form_submissions SET status = 'received', spam_reason = NULL, is_read = 0 WHERE status = 'spam' AND id IN ($in2)",
            'read'     => "UPDATE form_submissions SET is_read = 1 WHERE status = 'received' AND id IN ($in2)",
            'unread'   => "UPDATE form_submissions SET is_read = 0 WHERE status = 'received' AND id IN ($in2)",
            'delete'   => "DELETE FROM form_submissions WHERE id IN ($in2)",
        };
        $params = $action === 'spam' ? [mb_substr($reason !== '' ? $reason : 'marked as spam', 0, 255), ...$hit] : $hit;
        $pdo->prepare($sql)->execute($params);

        return ['done' => count($hit), 'skipped' => count($ids) - count($hit)];
    }

    /**
     * Of the ticked ids, the ones in a received list — one form's, or every
     * form's — whose email could not be sent (`notified` = 0), oldest first:
     * what "Send the email again" acts on. Re-read under the list's own
     * conditions, as `bulk()` does, so a forged id or a row another tab moved
     * is never sent.
     *
     * @param  list<int> $ids
     * @return list<int>
     */
    public static function failedIn(array $ids, ?int $formId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        [$where, $args] = self::scope(self::RECEIVED, $formId);
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT id FROM form_submissions WHERE $where AND notified = 0 AND id IN ($in) ORDER BY submitted_at, id"
        );
        $stmt->execute([...$args, ...$ids]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** One received submission marked as spam by a person. */
    public static function markSpam(int $id, string $reason): bool
    {
        return self::bulk('spam', [$id], self::RECEIVED, null, $reason)['done'] === 1;
    }

    /** A turned-away submission that was a real one: received, and unread. */
    public static function markNotSpam(int $id): bool
    {
        return self::bulk('not_spam', [$id], self::SPAM, null)['done'] === 1;
    }

    /**
     * Delete the spam in one list — every form's, or one form's — a batch at a
     * time, for at most $seconds. Batches, because one DELETE of a quarter of a
     * million rows holds its locks for as long as it runs, inside a request
     * that has a time limit. What is left (a pile too big for one request) is
     * reported, and the button deletes it on the next press.
     *
     * @return array{deleted: int, left: int}
     */
    public static function emptySpam(?int $formId, float $seconds = 20.0): array
    {
        $pdo   = Database::getInstance();
        $until = microtime(true) + $seconds;
        [$where, $args] = self::scope(self::SPAM, $formId);
        $stmt  = $pdo->prepare("DELETE FROM form_submissions WHERE $where ORDER BY submitted_at LIMIT 5000");
        $done  = 0;
        do {
            $stmt->execute($args);
            $n     = $stmt->rowCount();
            $done += $n;
        } while ($n > 0 && microtime(true) < $until);

        return ['deleted' => $done, 'left' => self::count(self::SPAM, $formId)];
    }

    /**
     * Delete spam older than $days days, at most $limit rows. Returns how many
     * went. BOUNDED, because it runs inside other requests — on a stored post,
     * and when the Spam view opens — and a month-old flood would otherwise come
     * due as one statement in a visitor's request.
     */
    public static function purgeSpam(int $days, int $limit = 1000): int
    {
        if ($days < 1) {
            return 0;
        }
        $stmt = Database::getInstance()->prepare(
            "DELETE FROM form_submissions
             WHERE status = 'spam' AND submitted_at < UTC_TIMESTAMP() - INTERVAL :d DAY
             ORDER BY submitted_at LIMIT " . max(1, $limit)
        );
        $stmt->execute([':d' => $days]);
        return $stmt->rowCount();
    }

    // ----------------------------------------------------------------
    // What the guard asks
    // ----------------------------------------------------------------

    /**
     * Received submissions from one address in the last $minutes, every form
     * (the rate limit) — the ones that CAME THROUGH THE GUARD, which is what a
     * stored token says. A signed-in member of staff testing the forms, or a
     * history import, is not a visitor sending messages, and an office's own
     * tests must not leave its first real enquiry of the morning refused.
     */
    public static function receivedFromSince(string $ip, int $minutes): int
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT COUNT(*) FROM form_submissions
             WHERE ip_address = :ip AND status = 'received' AND token_hash IS NOT NULL
               AND submitted_at > UTC_TIMESTAMP() - INTERVAL :m MINUTE"
        );
        $stmt->execute([':ip' => $ip, ':m' => max(1, $minutes)]);
        return (int) $stmt->fetchColumn();
    }

    /** Posts from one address the rate limit already kept as spam in the last $minutes. */
    public static function rateKeptSince(string $ip, int $minutes): int
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT COUNT(*) FROM form_submissions
             WHERE ip_address = :ip AND status = 'spam' AND spam_reason LIKE 'rate limit%'
               AND submitted_at > UTC_TIMESTAMP() - INTERVAL :m MINUTE"
        );
        $stmt->execute([':ip' => $ip, ':m' => max(1, $minutes)]);
        return (int) $stmt->fetchColumn();
    }

    /** The received submission a time-trap token already carried, or null. */
    public static function byToken(string $hash): ?int
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT id FROM form_submissions WHERE token_hash = :h AND status = 'received' LIMIT 1"
        );
        $stmt->execute([':h' => $hash]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** How many spam rows already hold this token: replays the guard kept. */
    public static function spamWithToken(string $hash): int
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT COUNT(*) FROM form_submissions WHERE token_hash = :h AND status = 'spam'"
        );
        $stmt->execute([':h' => $hash]);
        return (int) $stmt->fetchColumn();
    }

    // ----------------------------------------------------------------
    // What was discarded
    // ----------------------------------------------------------------

    /** One more post discarded as certain spam, today, for this form and reason. */
    public static function countDiscard(int $formId, string $reason): void
    {
        Database::getInstance()->prepare(
            'INSERT INTO form_spam_counts (form_id, day, reason, hits) VALUES (:f, UTC_DATE(), :r, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1'
        )->execute([':f' => $formId, ':r' => mb_substr($reason, 0, 32)]);
    }

    /**
     * What was discarded in the last $days days, by reason, most first.
     *
     * @return array<string, int>
     */
    public static function discards(int $days, ?int $formId = null): array
    {
        $sql  = 'SELECT reason, SUM(hits) FROM form_spam_counts
                 WHERE day > UTC_DATE() - INTERVAL :d DAY' . ($formId !== null ? ' AND form_id = :f' : '') . '
                 GROUP BY reason ORDER BY SUM(hits) DESC, reason';
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($formId !== null ? [':d' => max(1, $days), ':f' => $formId] : [':d' => max(1, $days)]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** Counts older than a year go: a day's row per form per reason is all it ever was. */
    public static function pruneDiscards(): void
    {
        Database::getInstance()->exec('DELETE FROM form_spam_counts WHERE day < UTC_DATE() - INTERVAL 1 YEAR');
    }

    // ----------------------------------------------------------------
    // Read and unread
    // ----------------------------------------------------------------

    /** Unread received submissions for a form. Spam is never unread. */
    public static function countUnread(int $formId): int
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT COUNT(*) FROM form_submissions WHERE form_id = :fid AND is_read = 0 AND status = 'received'"
        );
        $stmt->execute([':fid' => $formId]);
        return (int) $stmt->fetchColumn();
    }

    /** Unread received submissions across all forms. */
    public static function countAllUnread(): int
    {
        return (int) Database::getInstance()
            ->query("SELECT COUNT(*) FROM form_submissions WHERE is_read = 0 AND status = 'received'")
            ->fetchColumn();
    }

    /**
     * Mark a single submission as read.
     */
    public static function markRead(int $id): bool
    {
        return Database::getInstance()->prepare('UPDATE form_submissions SET is_read = 1 WHERE id = :id')
            ->execute([':id' => $id]);
    }

    /**
     * Mark all received submissions for a form as read.
     */
    public static function markAllRead(int $formId): bool
    {
        return Database::getInstance()->prepare(
            "UPDATE form_submissions SET is_read = 1 WHERE form_id = :fid AND is_read = 0 AND status = 'received'"
        )->execute([':fid' => $formId]);
    }

    /**
     * Delete a single submission.
     */
    public static function delete(int $id): bool
    {
        return Database::getInstance()->prepare('DELETE FROM form_submissions WHERE id = :id')
            ->execute([':id' => $id]);
    }
}
