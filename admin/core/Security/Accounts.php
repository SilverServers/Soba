<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Database\Database;
use Cms\Helpers\Dates;
use Cms\Models\Option;
use PDO;

/**
 * SUSPENDED ACCOUNTS — accounts left unused, suspended by a rule, and
 * suspension by hand.
 *
 * A suspended account cannot sign in by any route — a password, a second
 * step, a reset link — until an administrator restores it. A browser it is
 * already signed in on is signed out on its next click (`Auth::check()` reads
 * `suspended_at`). Its content, role and password are kept, so
 * restoring it is one button.
 *
 * ── WHO SUSPENDS ────────────────────────────────────────────────────────────
 * An administrator, on Users → Edit (never their own account), or the server
 * (`security.php suspend`). And THE INACTIVITY RULE: an editor or author
 * nobody has used for 90 days. An account nobody uses is the one where a
 * stolen password goes unnoticed. Administrators are never suspended by the
 * rule: on most sites that is the owner, locked out of their own site after a
 * long holiday, with only the server to get them back in.
 *
 * ── THE CLOCK ───────────────────────────────────────────────────────────────
 * `users.last_seen_at` (migration 067): set at sign-in, and once a day while
 * a session is in use. It never counts time from before the rule was switched
 * on (`since`), and an account an administrator restored starts again from
 * that moment (`restored_at`).
 *
 * ── WHEN ────────────────────────────────────────────────────────────────────
 * At the door, exactly: a right password for an account past the rule
 * suspends it there (`mayEnter()`). And just before any screen that shows
 * accounts (`sweep()`), so the screens say who is suspended before anyone
 * tries. Either way `suspended_at` is the moment the account crossed the line,
 * not the moment someone looked, so the date shown is true.
 */
final class Accounts
{
    public const ENABLED_KEY = 'security_inactive_enabled';
    public const DAYS_KEY    = 'security_inactive_days';
    public const ROLES_KEY   = 'security_inactive_roles';
    public const SINCE_KEY   = 'security_inactive_since';

    /** The roles the rule may cover, in the Users screen's order. Not administrators — see above. */
    public const ROLES = ['shop_manager' => 'Shop managers', 'editor' => 'Editors', 'author' => 'Authors'];

    public const DEFAULT_DAYS = 90;

    /** @var array{0: int, 1: int} */
    public const DAYS_LIMITS = [30, 3650];

    /** How often a session in use refreshes `last_seen_at`. */
    public const SEEN_EVERY = 86400;

    public const MESSAGE = 'This account is suspended. An administrator of this site can restore it.';

    /** @return array{enabled: bool, days: int, roles: list<string>, since: string} */
    public static function settings(): array
    {
        $raw  = Option::getMany([
            self::ENABLED_KEY => '1',
            self::DAYS_KEY    => (string) self::DEFAULT_DAYS,
            self::ROLES_KEY   => implode(',', array_keys(self::ROLES)),
            self::SINCE_KEY   => '',
        ]);
        $days = (int) $raw[self::DAYS_KEY];

        return [
            'enabled' => $raw[self::ENABLED_KEY] === '1',
            'days'    => max(self::DAYS_LIMITS[0], min(self::DAYS_LIMITS[1], $days)),
            'roles'   => self::parseRoles((string) $raw[self::ROLES_KEY]),
            'since'   => (string) $raw[self::SINCE_KEY],
        ];
    }

    /** @return list<string> the roles named in `$list` that the rule may cover */
    public static function parseRoles(string $list): array
    {
        $named = array_map('trim', explode(',', $list));

        return array_values(array_filter(array_keys(self::ROLES), static fn (string $r): bool => in_array($r, $named, true)));
    }

    /**
     * WHEN THE RULE SUSPENDS THIS ACCOUNT — a Unix time, or null when it never
     * will (rule off, a role it does not cover, already suspended). Pure: the
     * checks hand it the row, the settings and the time.
     *
     * The clock is the latest of: last seen (or created, if never seen), last
     * restored, and the moment the rule was switched on.
     *
     * @param array<string, mixed>                                              $row      role, created_at, last_seen_at, restored_at, suspended_at
     * @param array{enabled: bool, days: int, roles: list<string>, since: string} $settings
     */
    public static function dueAt(array $row, array $settings): ?int
    {
        if (!$settings['enabled'] || ($row['suspended_at'] ?? null) !== null
            || !in_array((string) ($row['role'] ?? ''), $settings['roles'], true)) {
            return null;
        }
        $clock = max(
            self::time($row['last_seen_at'] ?? null) ?? self::time($row['created_at'] ?? null) ?? 0,
            self::time($row['restored_at'] ?? null) ?? 0,
            self::time($settings['since']) ?? 0,
        );

        return $clock + $settings['days'] * 86400;
    }

    /** @param array<string, mixed> $row  see dueAt() */
    public static function isInactive(array $row, array $settings, int $now): bool
    {
        $due = self::dueAt($row, $settings);

        return $due !== null && $due <= $now;
    }

    /**
     * MAY THIS ACCOUNT SIGN IN? Asked after a right password and again just
     * before the session starts (`AuthController`). An account the rule has
     * passed is suspended here, so the answer is exact even if no screen has
     * swept today.
     */
    public static function mayEnter(int $id): bool
    {
        $row = self::row($id);
        if ($row === null) {
            return false;
        }
        if ($row['suspended_at'] !== null) {
            return false;
        }
        $settings = self::settings();
        if (self::isInactive($row, $settings, time())) {
            self::suspend($id, 'inactive', null, (int) self::dueAt($row, $settings));

            return false;
        }

        return true;
    }

    /**
     * Suspend every account the rule has passed — before a screen shows
     * accounts. One query when there is nothing to do, which is nearly always.
     *
     * Temporary access whose time is up is ended first (`TemporaryAccess`):
     * the same screens, the same moment.
     *
     * @return int how many the inactivity rule suspended
     */
    public static function sweep(): int
    {
        TemporaryAccess::sweep();
        $settings = self::settings();
        if (!$settings['enabled'] || $settings['roles'] === []) {
            return 0;
        }
        $in   = implode(', ', array_map(static fn (int $i): string => ':r' . $i, array_keys($settings['roles'])));
        $stmt = Database::getInstance()->prepare(
            'SELECT id, role, created_at, last_seen_at, restored_at, suspended_at FROM users
              WHERE suspended_at IS NULL AND role IN (' . $in . ')
                AND GREATEST(COALESCE(last_seen_at, created_at), COALESCE(restored_at, :never), :since)
                    <= UTC_TIMESTAMP() - INTERVAL :days DAY
              ORDER BY id LIMIT 100'
        );
        // The same clock as dueAt(), term for term: never restored is no floor
        // at all — not the day it was made, which is the first term's job.
        $params = [':never' => '1000-01-01 00:00:00', ':since' => $settings['since'] !== '' ? $settings['since'] : '1000-01-01 00:00:00',
                   ':days' => $settings['days']];
        foreach ($settings['roles'] as $i => $role) {
            $params[':r' . $i] = $role;
        }
        $stmt->execute($params);

        $n = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // The SQL chose it; the rule in PHP confirms it, so the two can
            // never suspend for different reasons.
            if (self::isInactive($row, $settings, time()) && self::suspend((int) $row['id'], 'inactive', null, (int) self::dueAt($row, $settings))) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Suspend one account: every browser of it signed out, any reset link it
     * has spent. `$why` is 'inactive', 'admin' (with `$by`), 'server', or
     * 'temporary' (its access ended; `$by` when an administrator revoked it).
     * False when it was suspended already.
     *
     * @param int|null $at  when (Unix time) — the rule's own moment; default now
     */
    public static function suspend(int $id, string $why, ?int $by, ?int $at = null): bool
    {
        $stmt = Database::getInstance()->prepare(
            'UPDATE users
                SET suspended_at = :at, suspended_why = :why, suspended_by = :by,
                    session_version = session_version + 1,
                    reset_token_hash = NULL, reset_expires_at = NULL
              WHERE id = :id AND suspended_at IS NULL'
        );
        $stmt->execute([
            ':at'  => gmdate('Y-m-d H:i:s', min($at ?? time(), time())),
            ':why' => $why,
            ':by'  => $by,
            ':id'  => $id,
        ]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }

        Sessions::revokeAll($id);
        $row = self::row($id);
        SecurityLog::record(SecurityLog::ACCOUNT_SUSPENDED, (string) ($row['username'] ?? ''), $id, match ($why) {
            'inactive'  => 'no sign-in for ' . self::settings()['days'] . ' days',
            'server'    => 'from the server',
            'temporary' => $by === null ? 'temporary access ended' : 'temporary access revoked',
            default     => 'by an administrator',
        });

        return true;
    }

    /** Let a suspended account sign in again. Its inactivity clock starts over. False when it was not suspended. */
    public static function restore(int $id, ?int $by): bool
    {
        $stmt = Database::getInstance()->prepare(
            'UPDATE users
                SET suspended_at = NULL, suspended_why = NULL, suspended_by = NULL, restored_at = UTC_TIMESTAMP()
              WHERE id = :id AND suspended_at IS NOT NULL'
        );
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }

        $row = self::row($id);
        SecurityLog::record(SecurityLog::ACCOUNT_RESTORED, (string) ($row['username'] ?? ''), $id,
            $by === null ? 'from the server' : 'by an administrator');

        return true;
    }

    /**
     * A session in use: refresh the clock, at most once a day. Only on a GET —
     * every form is reached from a page, and a write on a POST would be read
     * as that form's own change (`Audit`).
     */
    public static function seen(int $id, ?string $lastSeen): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }
        $last = self::time($lastSeen);
        if ($last !== null && $last > time() - self::SEEN_EVERY) {
            return;
        }
        Database::getInstance()
            ->prepare('UPDATE users SET last_seen_at = UTC_TIMESTAMP() WHERE id = :id')
            ->execute([':id' => $id]);
    }

    /** At a sign-in. */
    public static function signedIn(int $id): void
    {
        Database::getInstance()
            ->prepare('UPDATE users SET last_seen_at = UTC_TIMESTAMP() WHERE id = :id')
            ->execute([':id' => $id]);
    }

    /**
     * Every suspended account, newest first, with who suspended it.
     *
     * @return list<array<string, mixed>>
     */
    public static function suspended(): array
    {
        return Database::getInstance()->query(
            'SELECT u.id, u.username, u.display_name, u.role, u.last_seen_at, u.suspended_at, u.suspended_why, u.suspended_by,
                    b.username AS suspended_by_name
               FROM users u
               LEFT JOIN users b ON b.id = u.suspended_by
              WHERE u.suspended_at IS NOT NULL
              ORDER BY u.suspended_at DESC, u.id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Why it is suspended, in a few words — words that stay true. Not "no
     * sign-in for 90 days": the rule's days can change after the fact, and
     * then the sentence would describe a rule nobody applied.
     *
     * @param array<string, mixed> $row  suspended_why, last_seen_at, suspended_by_name
     */
    public static function why(array $row): string
    {
        return match ((string) ($row['suspended_why'] ?? '')) {
            'inactive'  => ($row['last_seen_at'] ?? null) !== null
                ? 'Unused since ' . Dates::short((string) $row['last_seen_at'])
                : 'Never signed in',
            'server'    => 'From the server',
            'temporary' => trim((string) ($row['suspended_by_name'] ?? '')) !== ''
                ? 'Temporary access revoked by ' . $row['suspended_by_name']
                : 'Temporary access ended',
            default     => trim((string) ($row['suspended_by_name'] ?? '')) !== ''
                ? 'By ' . $row['suspended_by_name']
                : 'By an administrator',
        };
    }

    /** @return array<string, mixed>|null */
    private static function row(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, username, role, created_at, last_seen_at, restored_at, suspended_at FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** A stored UTC `Y-m-d H:i:s` as a Unix time; null for none. */
    private static function time(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $t = strtotime($value . ' UTC');

        return $t === false ? null : $t;
    }
}
