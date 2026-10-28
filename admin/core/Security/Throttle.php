<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Database\Database;
use Cms\Models\Option;
use PDO;

/**
 * LIMIT SIGN-IN ATTEMPTS — on by default.
 *
 * An address that fails `attempts` times is shut out for `minutes`; while it is
 * shut out its password is never even checked, so guessing is worth nothing.
 * An address that keeps coming back — `escalate_after` lockouts inside a day —
 * is shut out for `escalate_hours` instead: the rule that actually stops a
 * patient script.
 *
 * ── ON BY DEFAULT ───────────────────────────────────────────────────────────
 * A protection that has to be discovered before it protects anything protects
 * almost nobody, and the one person it inconveniences — the owner who has
 * forgotten their password — gets the same minutes back that the script does.
 *
 * ── WHAT COUNTS ─────────────────────────────────────────────────────────────
 * Failed sign-ins from this address (`ClientIp::key()`, so a whole IPv6 /64 is
 * one address) in the last `WINDOW_HOURS`, after its last successful sign-in,
 * lockout or lift. The window means a typo last Tuesday is not held against
 * you; the sign-in means a success resets the count; the lockout means one
 * ending hands back a full set of attempts rather than one.
 *
 * Counted from the log rather than kept in a counter of its own, so the number
 * the lockout acts on is the number the activity log shows.
 */
final class Throttle
{
    /** Options keys, so the settings screen and this class cannot disagree. */
    public const ENABLED_KEY        = 'security_limit_enabled';
    public const ATTEMPTS_KEY       = 'security_limit_attempts';
    public const MINUTES_KEY        = 'security_limit_minutes';
    public const ESCALATE_AFTER_KEY = 'security_limit_escalate_after';
    public const ESCALATE_HOURS_KEY = 'security_limit_escalate_hours';
    public const MESSAGE_KEY        = 'security_limit_message';

    /** What an install that has never opened the screen gets. */
    public const DEFAULTS = [
        self::ENABLED_KEY        => '1',
        self::ATTEMPTS_KEY       => '5',
        self::MINUTES_KEY        => '20',
        self::ESCALATE_AFTER_KEY => '4',
        self::ESCALATE_HOURS_KEY => '24',
        self::MESSAGE_KEY        => '',
    ];

    /** The message when none is set. `{time}` becomes "20 minutes", "24 hours". */
    public const DEFAULT_MESSAGE = 'Too many failed attempts. Try again in {time}.';

    /** Older failures never count, however many there were. */
    public const WINDOW_HOURS = 12;

    /** The limits the screen enforces, so a stored value cannot escape them. */
    public const LIMITS = [
        self::ATTEMPTS_KEY       => [1, 100],
        self::MINUTES_KEY        => [1, 1440],
        self::ESCALATE_AFTER_KEY => [0, 100],     // 0 = never escalate
        self::ESCALATE_HOURS_KEY => [1, 720],
    ];

    /** @var array{enabled: bool, attempts: int, minutes: int, escalate_after: int, escalate_hours: int, message: string}|null */
    private static ?array $settings = null;

    /**
     * The settings in force, typed and clamped.
     *
     * @return array{enabled: bool, attempts: int, minutes: int, escalate_after: int, escalate_hours: int, message: string}
     */
    public static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        $raw = Option::getMany(self::DEFAULTS);
        $int = static function (string $key) use ($raw): int {
            [$min, $max] = self::LIMITS[$key];
            $value = (string) ($raw[$key] ?? self::DEFAULTS[$key]);

            return max($min, min($max, ctype_digit($value) ? (int) $value : (int) self::DEFAULTS[$key]));
        };

        return self::$settings = [
            'enabled'        => ($raw[self::ENABLED_KEY] ?? '1') === '1',
            'attempts'       => $int(self::ATTEMPTS_KEY),
            'minutes'        => $int(self::MINUTES_KEY),
            'escalate_after' => $int(self::ESCALATE_AFTER_KEY),
            'escalate_hours' => $int(self::ESCALATE_HOURS_KEY),
            'message'        => trim((string) ($raw[self::MESSAGE_KEY] ?? '')),
        ];
    }

    /** Forget the settings read this request — after a save. */
    public static function forget(): void
    {
        self::$settings = null;
    }

    public static function enabled(): bool
    {
        return self::settings()['enabled'];
    }

    /**
     * The lockout in force on this key right now, or null. A lifted or expired
     * one is not in force.
     *
     * @return array<string, mixed>|null
     */
    public static function lockoutFor(string $key): ?array
    {
        if (!self::enabled()) {
            return null;
        }

        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM security_lockouts
              WHERE ip = :ip AND username IS NULL AND lifted_at IS NULL AND until_at > UTC_TIMESTAMP()
              ORDER BY until_at DESC LIMIT 1'
        );
        $stmt->execute([':ip' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** Someone at a locked address tried again. Counted, never checked. */
    public static function refuse(array $lockout): void
    {
        Database::getInstance()
            ->prepare('UPDATE security_lockouts SET refused = refused + 1 WHERE id = :id')
            ->execute([':id' => (int) $lockout['id']]);
    }

    /**
     * Failed sign-ins that count against this key right now. See the header.
     *
     * ── "SINCE" IS AN ID, NOT A TIME ────────────────────────────────────────
     * The first version restarted the count at the latest sign-in's or
     * lockout's TIMESTAMP — and a DATETIME has one-second resolution, so a
     * failure in the same second as the lockout it followed was never counted:
     * lift a lockout, fail three times at once, and no second lockout came.
     * Log ids are strictly ordered, so the count starts after the last event
     * that resets it — a sign-in, a lockout, or a lockout being lifted —
     * whatever second it landed in.
     */
    public static function failures(string $key): int
    {
        $pdo = Database::getInstance();
        $pat = ClientIp::likePattern($key);

        $reset = $pdo->prepare(
            "SELECT COALESCE(MAX(id), 0) FROM security_log
              WHERE ip LIKE :ip AND event IN (:a, :b, :c)"
        );
        $reset->execute([
            ':ip' => $pat,
            ':a'  => SecurityLog::SIGN_IN,
            ':b'  => SecurityLog::LOCKOUT,
            ':c'  => SecurityLog::LOCKOUT_LIFTED,
        ]);

        $count = $pdo->prepare(
            'SELECT COUNT(*) FROM security_log
              WHERE event = :event AND ip LIKE :ip AND id > :after
                AND created_at > UTC_TIMESTAMP() - INTERVAL ' . (int) self::WINDOW_HOURS . ' HOUR'
        );
        $count->execute([
            ':event' => SecurityLog::SIGN_IN_FAILED,
            ':ip'    => $pat,
            ':after' => (int) $reset->fetchColumn(),
        ]);

        return (int) $count->fetchColumn();
    }

    /**
     * The address a key's own events are logged under: this request's, when
     * it is the one being acted on — or the key's base address, when an
     * administrator is acting on someone else's (a lift). Either way the row
     * falls inside `ClientIp::likePattern($key)`, so the log counts it.
     */
    private static function addressFor(string $key): string
    {
        $mine = ClientIp::get();
        if (ClientIp::key($mine) === $key) {
            return $mine;
        }

        return str_ends_with($key, '::/64')
            ? substr($key, 0, 19) . ':0000:0000:0000:0000'
            : $key;
    }

    /** How many more failures before this key is shut out. */
    public static function attemptsLeft(string $key): int
    {
        return max(0, self::settings()['attempts'] - self::failures($key));
    }

    /**
     * Call AFTER the failed sign-in has been logged. If that failure used up
     * the key's last attempt, shut it out — log it — and return the lockout.
     *
     * @return array<string, mixed>|null
     */
    public static function fail(string $key, ?string $username = null): ?array
    {
        $s = self::settings();
        if (!$s['enabled']) {
            return null;
        }

        $failures = self::failures($key);
        if ($failures < $s['attempts']) {
            return null;
        }

        // Two requests failing in the same instant can both get here. The
        // second one finds the first one's lockout and returns it, rather than
        // stacking a duplicate that lifting one would leave in force.
        $existing = self::lockoutFor($key);
        if ($existing !== null) {
            return $existing;
        }

        return self::lock($key, $username, $failures, sprintf('%d failed attempts', $failures));
    }

    /**
     * Shut `$key` out NOW, whatever its count: an attempt with a TRAP USERNAME
     * (`Usernames`) is never a typo — it is a script's word list. The same
     * lockout as a used-up limit, with the same duration, escalation and Lift.
     * It IS the attempt limit's lockout, so with the limit off it does nothing.
     *
     * @return array<string, mixed>|null
     */
    public static function lockNow(string $key, ?string $username, string $why): ?array
    {
        if (!self::enabled()) {
            return null;
        }
        $existing = self::lockoutFor($key);
        if ($existing !== null) {
            return $existing;
        }

        return self::lock($key, $username, self::failures($key), $why);
    }

    /**
     * Write the lockout row — its duration escalated after repeated lockouts —
     * log it as `$why · {duration}`, and return the row.
     *
     * @return array<string, mixed>|null
     */
    private static function lock(string $key, ?string $username, int $failures, string $why): ?array
    {
        $s   = self::settings();
        $pdo = Database::getInstance();

        $minutes = $s['minutes'];
        if ($s['escalate_after'] > 0) {
            $recent = $pdo->prepare(
                'SELECT COUNT(*) FROM security_lockouts
                  WHERE ip = :ip AND username IS NULL AND locked_at > UTC_TIMESTAMP() - INTERVAL 1 DAY'
            );
            $recent->execute([':ip' => $key]);
            if ((int) $recent->fetchColumn() >= $s['escalate_after']) {
                $minutes = $s['escalate_hours'] * 60;
            }
        }

        $pdo->prepare(
            'INSERT INTO security_lockouts (ip, attempts, until_at)
             VALUES (:ip, :attempts, UTC_TIMESTAMP() + INTERVAL ' . (int) $minutes . ' MINUTE)'
        )->execute([':ip' => $key, ':attempts' => $failures]);
        $id = (int) $pdo->lastInsertId();

        SecurityLog::record(
            SecurityLog::LOCKOUT,
            $username,
            null,
            $why . ' · ' . self::duration($minutes),
            self::addressFor($key)
        );

        $stmt = $pdo->prepare('SELECT * FROM security_lockouts WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * One lockout row by its id — an address's or a name's — or null.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM security_lockouts WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Let a locked-out address back in early. Lifts EVERY lockout in force on
     * its key, so a duplicate cannot keep it shut.
     *
     * A row with a `username` is a NAME locked (`AccountLock`), not an
     * address: lifting it lets the name back in, and leaves alone whatever
     * address happened to make its last attempt.
     */
    public static function lift(int $id, int $byUserId): bool
    {
        $row = self::find($id);
        if ($row === null) {
            return false;
        }

        if ($row['username'] !== null) {
            return AccountLock::lift((string) $row['username'], $byUserId > 0 ? $byUserId : null, 'lifted early');
        }

        $lift = Database::getInstance()->prepare(
            'UPDATE security_lockouts
                SET lifted_at = UTC_TIMESTAMP(), lifted_by = :by
              WHERE ip = :ip AND username IS NULL AND lifted_at IS NULL AND until_at > UTC_TIMESTAMP()'
        );
        // 0 is the server (`admin/bin/security.php`), not an account.
        $lift->execute([':by' => $byUserId > 0 ? $byUserId : null, ':ip' => (string) $row['ip']]);
        if ($lift->rowCount() === 0) {
            return false;     // already over: nothing was lifted, so nothing to log
        }

        SecurityLog::record(
            SecurityLog::LOCKOUT_LIFTED,
            null,
            $byUserId > 0 ? $byUserId : null,
            'lifted early',
            self::addressFor((string) $row['ip'])
        );

        return true;
    }

    /**
     * Lockouts for the activity log, newest first — in force and past alike.
     * `state` is `active`, `lifted` or `expired`.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public static function page(int $page = 1, int $perPage = 50): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $pdo     = Database::getInstance();

        $total = (int) $pdo->query('SELECT COUNT(*) FROM security_lockouts')->fetchColumn();
        $rows  = $pdo->query(
            'SELECT l.*,
                    CASE WHEN l.lifted_at IS NOT NULL THEN \'lifted\'
                         WHEN l.until_at > UTC_TIMESTAMP() THEN \'active\'
                         ELSE \'expired\' END AS state,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), l.until_at)) AS seconds_left,
                    u.username AS lifted_by_name
               FROM security_lockouts l
               LEFT JOIN users u ON u.id = l.lifted_by
              ORDER BY l.id DESC
              LIMIT ' . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage)
        )->fetchAll(PDO::FETCH_ASSOC);

        return ['items' => $rows, 'total' => $total];
    }

    /** How many lockouts are in force right now — addresses and names. */
    public static function activeCount(): int
    {
        return (int) Database::getInstance()->query(
            'SELECT COUNT(DISTINCT CASE WHEN username IS NULL THEN ip END) + COUNT(DISTINCT username)
               FROM security_lockouts
              WHERE lifted_at IS NULL AND until_at > UTC_TIMESTAMP()'
        )->fetchColumn();
    }

    /** Whole minutes left on a lockout, never less than one. */
    public static function minutesLeft(array $lockout): int
    {
        $until = strtotime((string) $lockout['until_at'] . ' UTC');

        return max(1, (int) ceil(($until - time()) / 60));
    }

    /** What the sign-in page says to a locked-out address. */
    public static function message(array $lockout): string
    {
        $message = self::settings()['message'];
        if ($message === '') {
            $message = self::DEFAULT_MESSAGE;
        }

        return str_replace('{time}', self::duration(self::minutesLeft($lockout)), $message);
    }

    /** "1 minute", "20 minutes", "2 hours", "24 hours" — rounded UP. */
    public static function duration(int $minutes): string
    {
        if ($minutes < 90) {
            return $minutes === 1 ? '1 minute' : $minutes . ' minutes';
        }
        $hours = (int) ceil($minutes / 60);

        return $hours === 1 ? '1 hour' : $hours . ' hours';
    }
}
