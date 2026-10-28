<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Database\Database;
use Cms\Mail\Mailer;
use Cms\Models\Option;
use Cms\SEO\Head;
use PDO;

/**
 * ONE NAME, MANY ADDRESSES — the per-account half of the attempt limit.
 * `Throttle` counts guesses per address; a botnet spreads them across
 * thousands and never trips it. This counts them per NAME, whatever address
 * they come from.
 *
 * ── FROM ANYWHERE BUT WHERE THE ACCOUNT HAS SIGNED IN ───────────────────────
 * A locked name is refused from every address that has never signed in as it.
 * From the ones that have, nothing changes: the owner at the office is not
 * locked out of their own site because a botnet guessed at their name. That is
 * what migration 060 reserved `security_lockouts.username` for.
 *
 * ── AND A WAY IN FOR THE OWNER ON A NEW ADDRESS ─────────────────────────────
 * When a lock starts, the account's owner is mailed a link — 32 random bytes,
 * stored as their SHA-256 (migration 064), good until the lock ends. Opening it
 * lifts the lock.
 *
 * ── A NAME WITH NO ACCOUNT IS LOCKED THE SAME WAY ───────────────────────────
 * Same counting, same page, no mail. Otherwise the lock itself would say which
 * names have accounts.
 *
 * Only attempts that tested a password count — a wrong password, an unknown
 * name. A honeypot, a wrong answer or a trap name never reached one.
 */
final class AccountLock
{
    public const ENABLED_KEY  = 'security_account_limit_enabled';
    public const ATTEMPTS_KEY = 'security_account_attempts';
    public const MINUTES_KEY  = 'security_account_minutes';

    public const DEFAULTS = [
        self::ENABLED_KEY  => '1',
        self::ATTEMPTS_KEY => '10',
        self::MINUTES_KEY  => '60',
    ];

    public const LIMITS = [
        self::ATTEMPTS_KEY => [3, 100],
        self::MINUTES_KEY  => [5, 1440],
    ];

    /** The page's words — the same for a name with an account and one without. */
    public const MESSAGE = 'Too many failed attempts on this name. Try again in {time}, or use the link emailed to the account.';

    /** What counts: an attempt that tested a password. */
    private const COUNTED = ['wrong password', 'unknown username'];

    /** @var array{enabled: bool, attempts: int, minutes: int}|null */
    private static ?array $settings = null;

    /** @return array{enabled: bool, attempts: int, minutes: int} */
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
            'enabled'  => ($raw[self::ENABLED_KEY] ?? '1') === '1',
            'attempts' => $int(self::ATTEMPTS_KEY),
            'minutes'  => $int(self::MINUTES_KEY),
        ];
    }

    /** Forget the settings read this request — after a save. */
    public static function forget(): void
    {
        self::$settings = null;
    }

    /**
     * Failed attempts on `$username`, from any address, since it last signed
     * in or was last locked or unlocked — within the address limit's window.
     */
    public static function failures(string $username): int
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM security_log
              WHERE event = :failed AND username = :u AND detail IN (:d1, :d2)
                AND created_at > UTC_TIMESTAMP() - INTERVAL ' . (int) Throttle::WINDOW_HOURS . ' HOUR
                AND id > (SELECT COALESCE(MAX(id), 0) FROM security_log
                           WHERE username = :u2 AND event IN (:in, :locked, :unlocked))'
        );
        $stmt->execute([
            ':failed' => SecurityLog::SIGN_IN_FAILED, ':u' => self::name($username), ':u2' => self::name($username),
            ':d1'     => self::COUNTED[0], ':d2' => self::COUNTED[1],
            ':in'     => SecurityLog::SIGN_IN, ':locked' => SecurityLog::ACCOUNT_LOCKED,
            ':unlocked' => SecurityLog::ACCOUNT_UNLOCKED,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The lock in force on `$username` FOR THE ADDRESS `$key` — null when there
     * is none, or when this address has signed in as this name before.
     *
     * @return array<string, mixed>|null
     */
    public static function lockFor(string $username, string $key): ?array
    {
        if (!self::settings()['enabled'] || trim($username) === '') {
            return null;
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT * FROM security_lockouts
              WHERE username = :u AND lifted_at IS NULL AND until_at > UTC_TIMESTAMP()
              ORDER BY until_at DESC LIMIT 1'
        );
        $stmt->execute([':u' => self::name($username)]);
        $lock = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($lock === false) {
            return null;
        }

        $known = $pdo->prepare('SELECT 1 FROM security_log WHERE event = :in AND username = :u AND ip LIKE :ip LIMIT 1');
        $known->execute([':in' => SecurityLog::SIGN_IN, ':u' => self::name($username), ':ip' => ClientIp::likePattern($key)]);

        return $known->fetchColumn() !== false ? null : $lock;
    }

    /**
     * Call AFTER a counted failure has been logged. If it used up the name's
     * last attempt, lock it — log it, and mail the account's owner the way back
     * in — and return the lock.
     *
     * @return array<string, mixed>|null
     */
    public static function fail(string $username, ?int $userId, string $key): ?array
    {
        $s    = self::settings();
        $name = self::name($username);
        if (!$s['enabled'] || $name === '') {
            return null;
        }

        $failures = self::failures($name);
        if ($failures < $s['attempts']) {
            return null;
        }

        $pdo  = Database::getInstance();
        $open = $pdo->prepare(
            'SELECT * FROM security_lockouts WHERE username = :u AND lifted_at IS NULL AND until_at > UTC_TIMESTAMP() LIMIT 1'
        );
        $open->execute([':u' => $name]);
        $existing = $open->fetch(PDO::FETCH_ASSOC);
        if ($existing !== false) {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        $pdo->prepare(
            'INSERT INTO security_lockouts (ip, username, attempts, until_at, unlock_hash)
             VALUES (:ip, :u, :n, UTC_TIMESTAMP() + INTERVAL ' . (int) $s['minutes'] . ' MINUTE, :hash)'
        )->execute([':ip' => $key, ':u' => $name, ':n' => $failures, ':hash' => hash('sha256', $token)]);
        $id = (int) $pdo->lastInsertId();

        SecurityLog::record(SecurityLog::ACCOUNT_LOCKED, $name, $userId,
            sprintf('%d failed attempts on this name · %s', $failures, Throttle::duration($s['minutes'])));

        if ($userId !== null) {
            self::mailOwner($userId, $token, $s['minutes']);
        }

        $stmt = $pdo->prepare('SELECT * FROM security_lockouts WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * The lock a mailed link belongs to, while it is still in force — or null
     * for a link that is wrong, used, or out of date. Compared by hash in the
     * query and in constant time here, as `PasswordReset::find()` does.
     *
     * @return array<string, mixed>|null
     */
    public static function forLink(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $hash = hash('sha256', $token);
        $stmt = Database::getInstance()->prepare(
            'SELECT l.*, u.id AS user_id
               FROM security_lockouts l LEFT JOIN users u ON u.username = l.username
              WHERE l.unlock_hash = :h AND l.lifted_at IS NULL AND l.until_at > UTC_TIMESTAMP() LIMIT 1'
        );
        $stmt->execute([':h' => $hash]);
        $lock = $stmt->fetch(PDO::FETCH_ASSOC);

        return $lock !== false && hash_equals((string) $lock['unlock_hash'], $hash) ? $lock : null;
    }

    /** Lift the lock a mailed link belongs to. False for a wrong, used or out-of-date link. */
    public static function unlock(string $token): bool
    {
        $lock = self::forLink($token);
        if ($lock === null) {
            return false;
        }

        return self::lift(
            (string) $lock['username'],
            $lock['user_id'] !== null ? (int) $lock['user_id'] : null,
            'by the emailed link'
        );
    }

    /**
     * Let `$username` back in from everywhere, now — and log who did it and
     * how (`$how`): the emailed link, a password reset by link (which proves
     * the same inbox), or an administrator's Lift. False when nothing was
     * locked, so nothing is logged.
     *
     * The `account_unlocked` row also starts the name's count again
     * (`failures()`), as a sign-in does.
     */
    public static function lift(string $username, ?int $byUserId, string $how): bool
    {
        $name = self::name($username);
        if ($name === '') {
            return false;
        }

        $lift = Database::getInstance()->prepare(
            'UPDATE security_lockouts SET lifted_at = UTC_TIMESTAMP(), lifted_by = :by, unlock_hash = NULL
              WHERE username = :u AND lifted_at IS NULL AND until_at > UTC_TIMESTAMP()'
        );
        $lift->execute([':by' => $byUserId, ':u' => $name]);
        if ($lift->rowCount() === 0) {
            return false;
        }

        SecurityLog::record(SecurityLog::ACCOUNT_UNLOCKED, $name, $byUserId, $how);

        return true;
    }

    /** The page's words for a lock, with the time left in them. */
    public static function message(array $lock): string
    {
        return str_replace('{time}', Throttle::duration(Throttle::minutesLeft($lock)), self::MESSAGE);
    }

    /** A name as it is compared and stored: trimmed, lower case. */
    private static function name(string $username): string
    {
        return mb_strtolower(trim($username));
    }

    private static function mailOwner(int $userId, string $token, int $minutes): void
    {
        $user = \Cms\Models\User::find($userId);
        $to   = trim((string) ($user['email'] ?? ''));
        if ($to === '') {
            return;
        }

        $site = (string) Head::globalTokens()['site_name'];
        Mailer::send(
            [$to],
            'Too many wrong passwords for your account on ' . $site,
            "Someone has typed a wrong password for your account \"{$user['username']}\" on {$site} too many times,\n"
            . "so signing in as it is paused for {$minutes} minutes — except from places you have signed in from before.\n\n"
            . "If that was you, or you need to sign in now from somewhere new, open this link:\n\n"
            . LoginUrl::url() . '?action=unlock&key=' . $token . "\n\n"
            . "If it was not you, your password has not been guessed. You may still want to choose a stronger one:\n"
            . LoginUrl::url() . "?action=lostpassword\n"
        );
    }
}
