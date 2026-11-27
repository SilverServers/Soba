<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\Database\Database;
use Cms\Helpers\Dates;
use Cms\Models\Option;
use PDO;

/**
 * A SITE'S OWN SCHEDULED TASKS: a live stream switched off at the end of an
 * event day, a report each Monday, a check each night.
 *
 * DECLARED IN CODE, in the site's own `admin/config/tasks.php`, which no
 * package ships and no update touches:
 *
 *     \Cms\Site\Tasks::register('stream-off', [
 *         'every'   => 'day 23:00',            // when: see when() below
 *         'run'     => 'tasks/stream-off.php', // a file in admin/site/, or a callable
 *         'timeout' => 60,                     // the seconds it may take: 60 unless named, 600 at most
 *     ]);
 *
 * `every` is `N minutes` or `N hours`, counted from its last run, or a time
 * of day in the site's time zone (Settings → General): `day 23:00` every day,
 * `monday 06:00` every Monday.
 *
 * RUN AS THE CMS'S OWN DUE WORK IS: by `admin/bin/run-due.php` from a cron
 * where the host has one, and otherwise by visits, which look at most every
 * ten minutes and run what is due after the visitor's page is sent. One task
 * at a time, under a lock no second process can take, so two visits never
 * run one twice. A time that passed with no visit runs at the next one, and
 * its run says when. A task first seen waits for its first time rather than
 * running at once.
 *
 * EACH RUN IS A ROW of `task_runs` (migration 134): when it started and
 * finished, whether a cron, a visit or a person ran it, and how it ended
 * (done, failed with its message, or out of time), with what it printed.
 * Tools → System health shows each task's last run and next time. A task
 * that throws is a failed run in the log, never a broken page; one that runs
 * past its time is stopped by PHP, and the next look marks it so.
 */
final class Tasks
{
    public const DEFAULT_TIMEOUT = 60;
    public const MAX_TIMEOUT     = 600;

    /** The days a weekly task names. */
    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /** How a run ended. */
    public const OUTCOMES = ['running' => 'Running', 'done' => 'Done', 'failed' => 'Failed', 'timed_out' => 'Out of time'];

    /** Who ran it. */
    public const BY = ['cron' => 'the cron', 'visit' => 'a visit', 'hand' => 'hand'];

    /** Runs kept for each task, the newest. */
    private const KEEP = 50;

    /** How often visits look, in seconds, and the option that holds the last look. */
    private const LOOK   = 600;
    private const LOOKED = 'tasks_looked_at';

    /** When each task was first seen: key => time, as JSON. */
    private const SEEN = 'tasks_seen';

    private const KEY  = '/^[a-z0-9][a-z0-9-]{0,40}$/';
    private const FILE = '~^[a-z0-9_-]+(?:/[a-z0-9_-]+)*\.php$~i';

    /** What a run prints, kept to this many characters. */
    private const MESSAGE = 2000;

    /** @var array<string, array<string, mixed>> */
    private static array $tasks = [];

    private static bool $loaded = false;

    // ----------------------------------------------------------------
    // Declaring
    // ----------------------------------------------------------------

    /**
     * Declare a task. Its definition is checked here, once: a row that is not
     * one is said in the log and left out.
     *
     * @param array<string, mixed> $definition
     */
    public static function register(string $key, array $definition): void
    {
        $when    = is_string($definition['every'] ?? null) ? self::when($definition['every']) : null;
        $run     = $definition['run'] ?? null;
        $file    = is_string($run) ? preg_replace('~^admin/site/~', '', trim($run)) : null;
        $timeout = $definition['timeout'] ?? self::DEFAULT_TIMEOUT;
        $problem = match (true) {
            preg_match(self::KEY, $key) !== 1                                   => 'its key is not lower-case letters, digits and dashes',
            $when === null                                                     => 'its every is not "N minutes", "N hours", "day HH:MM" or a weekday and a time',
            !is_string($run) && !is_callable($run)                             => 'its run is neither a file in admin/site/ nor a callable',
            $file !== null && (preg_match(self::FILE, $file) !== 1 || str_contains($file, '..')) => 'its run is not a path in admin/site/ ending .php',
            !is_int($timeout) || $timeout < 1 || $timeout > self::MAX_TIMEOUT => 'its timeout is not a whole number of seconds from 1 to ' . self::MAX_TIMEOUT,
            default                                                            => null,
        };
        if ($problem !== null) {
            error_log('Scheduled tasks: "' . $key . '" left out: ' . $problem);
            return;
        }
        self::$tasks[$key] = [
            'key'     => $key,
            'every'   => trim((string) $definition['every']),
            'when'    => $when,
            'run'     => $file ?? $run,
            'timeout' => $timeout,
        ];
    }

    /**
     * Every task the site declares, in the order declared.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        self::load();

        return self::$tasks;
    }

    /** Forget every declaration, for a script that declares its own. */
    public static function reset(): void
    {
        self::$tasks  = [];
        self::$loaded = true;
    }

    /**
     * What an `every` means, or null: `['interval' => seconds]`, or
     * `['day' => null|weekday, 'time' => 'HH:MM']` for a time of day.
     *
     * @return array{interval?: int, day?: ?string, time?: string}|null
     */
    public static function when(string $every): ?array
    {
        $every = strtolower(trim((string) preg_replace('/\s+/', ' ', $every)));
        if (preg_match('/^(\d{1,4}) minutes?$/', $every, $m) === 1 && (int) $m[1] >= 1 && (int) $m[1] <= 10080) {
            return ['interval' => (int) $m[1] * 60];
        }
        if (preg_match('/^(?:(\d{1,3}) hours?|hour)$/', $every, $m) === 1) {
            $hours = (int) ($m[1] ?? 1) ?: 1;
            return $hours <= 168 ? ['interval' => $hours * 3600] : null;
        }
        if (preg_match('/^(day|' . implode('|', self::DAYS) . ') ([01]?\d|2[0-3]):([0-5]\d)$/', $every, $m) === 1) {
            return ['day' => $m[1] === 'day' ? null : $m[1], 'time' => sprintf('%02d:%s', (int) $m[2], $m[3])];
        }

        return null;
    }

    /** An `every`, in words: "Every 15 minutes", "Every day at 23:00", "Every Monday at 06:00". */
    public static function words(array $when): string
    {
        if (isset($when['interval'])) {
            $s = (int) $when['interval'];
            return $s % 3600 === 0
                ? 'Every ' . ($s === 3600 ? 'hour' : ($s / 3600) . ' hours')
                : 'Every ' . ($s === 60 ? 'minute' : ($s / 60) . ' minutes');
        }

        return 'Every ' . ($when['day'] === null ? 'day' : ucfirst((string) $when['day'])) . ' at ' . $when['time'];
    }

    // ----------------------------------------------------------------
    // When each is due
    // ----------------------------------------------------------------

    /**
     * The time a task is next due, as seconds since 1970: from its last run
     * (or, never run, from when it was first seen) for an interval; the
     * first of its times after that for a time of day. At or before `$now`
     * means it is due.
     */
    public static function nextAt(array $task, ?int $lastStart, int $seen): int
    {
        $from = $lastStart ?? $seen;
        if (isset($task['when']['interval'])) {
            return $from + (int) $task['when']['interval'];
        }

        return self::slotAfter($task['when'], $from);
    }

    /** The first of a timed task's times after `$from`, in the site's time zone. */
    private static function slotAfter(array $when, int $from): int
    {
        $zone = Dates::zone();
        $at   = (new \DateTimeImmutable('@' . $from))->setTimezone($zone);
        [$h, $i] = array_map('intval', explode(':', (string) $when['time']));
        $slot = $at->setTime($h, $i);
        if ($when['day'] !== null) {
            $slot = $slot->modify((string) $when['day'] . ' this week');
            $slot = $slot->setTime($h, $i);
        }
        while ($slot->getTimestamp() <= $from) {
            $slot = $slot->modify($when['day'] === null ? '+1 day' : '+1 week')->setTime($h, $i);
        }

        return $slot->getTimestamp();
    }

    // ----------------------------------------------------------------
    // Running
    // ----------------------------------------------------------------

    /**
     * On every visit: one read when the last look was under ten minutes ago;
     * the look, when it was not, after the page.
     */
    public static function onVisit(): void
    {
        if (\Cms\Update\Updater::isProbe($_SERVER) || time() - (int) (Option::get(self::LOOKED, '0') ?? '0') < self::LOOK) {
            return;
        }
        if (self::all() === []) {
            return;
        }
        register_shutdown_function([self::class, 'due']);
    }

    /** The look, when this visit wins the claim to it. Never throws: a task must not become a broken page. */
    public static function due(): void
    {
        try {
            if (!self::claimLook()) {
                return;
            }
            if (PHP_SAPI !== 'cli' && function_exists('fastcgi_finish_request')) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                fastcgi_finish_request();
            }
            self::runDue('visit');
        } catch (\Throwable $e) {
            error_log('Scheduled tasks: ' . $e->getMessage());
        }
    }

    /**
     * Run every task now due, one at a time, under the lock. Null when another
     * process holds the lock, and nothing is run; otherwise each run made.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function runDue(string $by, ?int $now = null): ?array
    {
        return self::locked(static function () use ($by, $now): array {
            $now  = $now ?? time();
            $seen = self::seen($now);
            $runs = [];
            foreach (self::all() as $key => $task) {
                $last = self::lastStart($key);
                if (self::nextAt($task, $last, (int) $seen[$key]) <= $now) {
                    $runs[] = self::run($key, $by);
                }
            }
            return $runs;
        });
    }

    /**
     * Run one task now, whatever its time: `php admin/bin/run-due.php
     * --task=<key>`, and a site's own checks. Null when another process holds
     * the lock, or there is no such task.
     *
     * @return array<string, mixed>|null
     */
    public static function runNow(string $key, string $by = 'hand'): ?array
    {
        if (!isset(self::all()[$key])) {
            return null;
        }

        return self::locked(static fn (): array => self::run($key, $by));
    }

    /**
     * One run, under the lock: its row written first, as Running, so a run
     * PHP stops is still in the log; then the task, what it printed kept, and
     * the row finished with how it ended.
     *
     * @return array<string, mixed> the run's row
     */
    private static function run(string $key, string $by): array
    {
        $task = self::$tasks[$key];
        $pdo  = Database::getInstance();
        $pdo->prepare("INSERT INTO task_runs (task, started_at, outcome, ran_by) VALUES (?, UTC_TIMESTAMP(), 'running', ?)")
            ->execute([$key, isset(self::BY[$by]) ? $by : 'hand']);
        $id    = (int) $pdo->lastInsertId();
        $start = microtime(true);
        @set_time_limit((int) $task['timeout']);

        $level   = ob_get_level();
        $outcome = 'done';
        $message = '';
        ob_start();
        try {
            if (is_string($task['run'])) {
                $file = ROOT_PATH . '/site/' . $task['run'];
                if (!is_file($file)) {
                    throw new \RuntimeException('there is no admin/site/' . $task['run']);
                }
                (static function (string $file): void {
                    require $file;
                })($file);
            } else {
                ($task['run'])();
            }
        } catch (\Throwable $e) {
            $outcome = 'failed';
            $message = $e->getMessage();
            error_log('Scheduled tasks: "' . $key . '" failed: ' . $message);
        } finally {
            $printed = '';
            while (ob_get_level() > $level) {
                $printed = (string) ob_get_clean() . $printed;
            }
        }
        $message = trim($message !== '' ? $message . ($printed !== '' ? "\n" . $printed : '') : $printed);
        $message = mb_substr($message, 0, self::MESSAGE);
        $seconds = round(microtime(true) - $start, 2);

        $pdo->prepare('UPDATE task_runs SET finished_at = UTC_TIMESTAMP(), seconds = ?, outcome = ?, message = ? WHERE id = ?')
            ->execute([$seconds, $outcome, $message !== '' ? $message : null, $id]);
        self::prune($key);
        @set_time_limit(0);

        return self::runs($key, 1)[0] ?? ['id' => $id, 'task' => $key, 'outcome' => $outcome];
    }

    /**
     * `$work` under the tasks' lock, or null when another process holds it.
     * The lock is the database's own (`GET_LOCK`), which it lets go when the
     * process ends, however it ends.
     */
    private static function locked(callable $work): mixed
    {
        $pdo  = Database::getInstance();
        $lock = 'cms_tasks_' . (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ((int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 0)')->fetchColumn() !== 1) {
            return null;
        }
        try {
            self::closeStale();
            return $work();
        } finally {
            $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
        }
    }

    /**
     * A run left Running past its time was stopped by PHP, or its process
     * died: marked Out of time. Asked under the lock, so no run still going
     * is touched.
     */
    private static function closeStale(): void
    {
        $pdo  = Database::getInstance();
        $rows = $pdo->query("SELECT id, task, TIMESTAMPDIFF(SECOND, started_at, UTC_TIMESTAMP()) AS age FROM task_runs WHERE outcome = 'running'")
            ->fetchAll(PDO::FETCH_ASSOC);
        $mark = $pdo->prepare("UPDATE task_runs SET outcome = 'timed_out', finished_at = UTC_TIMESTAMP(),"
            . " message = COALESCE(message, 'It ran past its time, or its process stopped, and did not finish.') WHERE id = ?");
        foreach ($rows as $row) {
            $limit = (int) (self::$tasks[(string) $row['task']]['timeout'] ?? self::MAX_TIMEOUT);
            if ((int) $row['age'] > $limit + 30) {
                $mark->execute([(int) $row['id']]);
            }
        }
    }

    /** The newest runs beyond KEEP, gone. */
    private static function prune(string $key): void
    {
        $stmt = Database::getInstance()->prepare(
            'DELETE FROM task_runs WHERE task = ? AND id NOT IN (SELECT id FROM (SELECT id FROM task_runs WHERE task = ? ORDER BY id DESC LIMIT ' . self::KEEP . ') kept)'
        );
        $stmt->execute([$key, $key]);
    }

    /** The visit that may look now takes the look, so a second does not: compared and swapped on the stored time, as `Commerce\Lapse` does. */
    private static function claimLook(): bool
    {
        $db   = Database::getInstance();
        $now  = time();
        $read = $db->prepare('SELECT option_value FROM options WHERE option_key = :k');
        $read->execute([':k' => self::LOOKED]);
        $last = $read->fetchColumn();
        if ($last === false) {
            $stmt = $db->prepare('INSERT IGNORE INTO options (option_key, option_value) VALUES (:k, :v)');
            $stmt->execute([':k' => self::LOOKED, ':v' => (string) $now]);
            return $stmt->rowCount() === 1;
        }
        if ($now - (int) $last < self::LOOK) {
            return false;
        }
        $stmt = $db->prepare('UPDATE options SET option_value = :v WHERE option_key = :k AND option_value <=> :was');
        $stmt->execute([':v' => (string) $now, ':k' => self::LOOKED, ':was' => $last]);

        return $stmt->rowCount() === 1;
    }

    /**
     * When each task was first seen, a task seen now for the first time noted
     * as now.
     *
     * @return array<string, int>
     */
    private static function seen(int $now): array
    {
        $seen = json_decode((string) (Option::get(self::SEEN, '{}') ?? '{}'), true);
        $seen = is_array($seen) ? array_map('intval', $seen) : [];
        $new  = array_diff_key(self::all(), $seen);
        if ($new !== []) {
            foreach (array_keys($new) as $key) {
                $seen[$key] = $now;
            }
            Option::set(self::SEEN, (string) json_encode($seen));
        }

        return $seen;
    }

    /** When a task's last run started, as seconds since 1970, or null. */
    private static function lastStart(string $key): ?int
    {
        $stmt = Database::getInstance()->prepare('SELECT UNIX_TIMESTAMP(MAX(started_at)) FROM task_runs WHERE task = ?');
        $stmt->execute([$key]);
        $at = $stmt->fetchColumn();

        return $at !== null && $at !== false ? (int) $at : null;
    }

    // ----------------------------------------------------------------
    // Reading
    // ----------------------------------------------------------------

    /**
     * The newest runs, of one task or all.
     *
     * @return list<array<string, mixed>>
     */
    public static function runs(?string $key = null, int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $sql   = 'SELECT id, task, started_at, finished_at, seconds, outcome, ran_by, message FROM task_runs'
            . ($key !== null ? ' WHERE task = ?' : '') . ' ORDER BY id DESC LIMIT ' . $limit;
        $stmt  = Database::getInstance()->prepare($sql);
        $stmt->execute($key !== null ? [$key] : []);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Each task for Tools → System health: its key, its time in words, its
     * last run and when it is next due.
     *
     * @return list<array{key: string, words: string, last: ?array<string, mixed>, next: int}>
     */
    public static function report(?int $now = null): array
    {
        $now  = $now ?? time();
        $seen = self::seen($now);
        $out  = [];
        foreach (self::all() as $key => $task) {
            $last  = self::runs($key, 1)[0] ?? null;
            $out[] = [
                'key'   => $key,
                'words' => self::words($task['when']),
                'last'  => $last,
                'next'  => self::nextAt($task, self::lastStart($key), (int) $seen[$key]),
            ];
        }

        return $out;
    }

    /**
     * Each task as a row of Tools → System health: its time, its next run and
     * how its last run went, needing attention when that one failed or ran
     * out of time.
     *
     * @return array<string, array{label: string, value: string, status: string, hint: string}>
     */
    public static function rows(?int $now = null): array
    {
        $out = [];
        foreach (self::report($now) as $task) {
            $last   = $task['last'];
            $failed = $last !== null && in_array($last['outcome'], ['failed', 'timed_out'], true);
            $out['task:' . $task['key']] = [
                'label'  => $task['key'],
                'value'  => $task['words'] . '. Next: ' . Dates::stamp($task['next']) . '.',
                'status' => $failed ? 'warning' : 'ok',
                'hint'   => $last === null ? 'Not run yet.' : 'Last: ' . self::sentence($last),
            ];
        }

        return $out;
    }

    /**
     * One run in words: "Done in under a second, by a visit, Nov 26, 2026, 11:00 PM."
     * and what it said, its first line.
     *
     * @param array<string, mixed> $run a row of `task_runs`
     */
    public static function sentence(array $run): string
    {
        $outcome = (string) ($run['outcome'] ?? 'running');
        $words   = self::OUTCOMES[$outcome] ?? $outcome;
        if ($run['seconds'] !== null && $outcome !== 'running') {
            $seconds = (float) $run['seconds'];
            $words  .= $seconds < 1 ? ' in under a second' : ' in ' . rtrim(rtrim(number_format($seconds, 2, '.', ''), '0'), '.') . ' s';
        }
        $words .= ', by ' . (self::BY[(string) ($run['ran_by'] ?? '')] ?? 'hand') . ', ' . Dates::stamp((string) $run['started_at']) . '.';
        $said = trim(strtok((string) ($run['message'] ?? ''), "\n") ?: '');

        return $said !== '' ? $words . ' ' . $said : $words;
    }

    /** Read the site's list once, quietly (`ConfigFile::read()`). */
    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        if (defined('ROOT_PATH') && is_file(ROOT_PATH . '/config/tasks.php')) {
            ConfigFile::read(ROOT_PATH . '/config/tasks.php');
        }
    }
}
