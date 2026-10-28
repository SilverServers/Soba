<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Database\Database;
use PDO;

/**
 * THE SIGN-IN LOG — what happened at the door, in the order it happened.
 * Migration 060 has the reasoning for keeping it apart from the lockouts.
 *
 * APPEND-ONLY. Nothing here updates a row: lifting a lockout is a new
 * `lockout_lifted` row, not an edit to the `lockout` one, so the record of an
 * attack survives whoever dealt with it.
 *
 * ── IT PRUNES ITSELF ────────────────────────────────────────────────────────
 * A site on shared hosting has no cron this can rely on, and a log that only
 * grows is a disk filling at the rate somebody else chooses. So one write in
 * two hundred also deletes anything older than `KEEP_DAYS` — the same
 * lottery PHP's own session garbage collection uses, and for the same reason.
 */
final class SecurityLog
{
    public const SIGN_IN        = 'sign_in';
    public const SIGN_IN_FAILED = 'sign_in_failed';
    public const LOCKOUT        = 'lockout';
    public const LOCKOUT_LIFTED = 'lockout_lifted';
    /** The sign-in page was moved, or put back — by `user_id`. */
    public const SIGN_IN_MOVED  = 'sign_in_moved';
    /** An account chose a new password it owed (migration 061). */
    public const PASSWORD_CHANGED = 'password_changed';
    /** An administrator asked every account for a new password. */
    public const PASSWORDS_RESET  = 'passwords_reset';
    /** Someone asked for a reset link (migration 062) — sent or not, see `detail`. */
    public const RESET_REQUESTED  = 'reset_requested';
    /** A reset link was used to choose a new password. */
    public const PASSWORD_RESET   = 'password_reset';
    /** Browsers signed out from the account screen — by `user_id`. */
    public const SESSION_ENDED    = 'session_ended';
    /** An account's two-step sign-in was set up, changed or turned off (migration 063) — `detail` says which. */
    public const TWO_STEP_CHANGED = 'two_step_changed';
    /** A NAME ran out of attempts and is locked from addresses it has never signed in from (migration 064). */
    public const ACCOUNT_LOCKED   = 'account_locked';
    /** A locked name was let back in — by the emailed link, or by `user_id` from the Lockouts tab. */
    public const ACCOUNT_UNLOCKED = 'account_unlocked';
    /** A request for one of WordPress's lists of usernames (`Enumeration`) — `detail` is `kind · address`. */
    public const ENUMERATION      = 'enumeration';
    /** An administrator's form changed something (`Audit`) — `detail` is `screen · POST path · tables`. */
    public const CHANGE           = 'change';
    /** An account was suspended (migration 067, `Accounts`) — `detail` says by whom, or the inactivity rule. */
    public const ACCOUNT_SUSPENDED = 'account_suspended';
    /** A suspended account was restored. */
    public const ACCOUNT_RESTORED  = 'account_restored';
    /** A file check found the CMS's files differing from what shipped, in a way the last did not (`FileCheck`) — `detail` counts them. */
    public const FILES_CHANGED     = 'files_changed';
    /** Someone asked for a code or a sign-in link (`Passwordless`) — sent or not, see `detail`. */
    public const PASSWORDLESS_REQUESTED = 'passwordless_requested';
    /** An administrator gave someone temporary access, or renewed it (`TemporaryAccess`) — `detail` has the terms. */
    public const ACCESS_GIVEN = 'access_given';

    /** How long a row is kept. */
    public const KEEP_DAYS = 90;

    /**
     * Write one event for this request's address and browser.
     *
     * @param string|null $username  what was TYPED, even when no account has it
     * @param int|null    $userId    the account it matched, if any
     */
    public static function record(
        string $event,
        ?string $username = null,
        ?int $userId = null,
        ?string $detail = null,
        ?string $ip = null,
        bool $byRequest = true,
    ): int {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO security_log (event, username, user_id, ip, user_agent, detail)
             VALUES (:event, :username, :user_id, :ip, :agent, :detail)'
        );
        $stmt->execute([
            ':event'    => $event,
            ':username' => self::clean($username, 191),
            ':user_id'  => $userId,
            ':ip'       => $ip ?? ClientIp::get(),
            // The site's own events (a file check run after some visitor's
            // page) are not that visitor's: no browser, the server's address.
            ':agent'    => $byRequest ? self::agent() : null,
            ':detail'   => self::clean($detail, 255),
        ]);
        $id = (int) $pdo->lastInsertId();

        if (random_int(1, 200) === 1) {
            self::prune();
        }

        // Something happened: the notification channels look at the end of
        // this request, after the page has gone (`Alerts`).
        Alerts::poke();

        return $id;
    }

    /**
     * WHAT A ROW IS, as the Overview and the Activity log both show it: a
     * glyph, a label, a status modifier. One map for the two screens — they
     * each had their own, and the day a wrong second-step code joined the log
     * both called it "Wrong password", which is exactly the thing an owner
     * reading the log must be able to tell apart.
     *
     * @param array<string, mixed> $row
     * @return array{0: string, 1: string, 2: string}
     */
    public static function describe(array $row): array
    {
        $detail = (string) ($row['detail'] ?? '');

        return match ((string) ($row['event'] ?? '')) {
            self::SIGN_IN          => ['arrow-right-to-bracket', 'Signed in', 'status--active'],
            self::LOCKOUT          => ['lock', 'Locked out', ''],
            self::LOCKOUT_LIFTED   => ['unlock', 'Lifted', 'status--archived'],
            self::ACCOUNT_LOCKED   => ['lock', 'Name locked', ''],
            self::ACCOUNT_UNLOCKED => ['unlock', 'Name unlocked', 'status--archived'],
            // Someone looking, whichever place they looked in (`Ui\Sections`
            // has why this glyph).
            self::CHANGE           => ['pen-to-square', 'Changed', ''],
            self::ACCOUNT_SUSPENDED => ['pause', 'Suspended', ''],
            self::ACCOUNT_RESTORED  => ['play', 'Restored', 'status--active'],
            self::FILES_CHANGED     => ['file', 'Files changed', ''],
            self::PASSWORDLESS_REQUESTED => str_starts_with($detail, 'link')
                ? ['envelope', 'Asked for a link', '']
                : ['envelope', 'Asked for a code', ''],
            self::ACCESS_GIVEN      => ['stopwatch', str_starts_with($detail, 'renewed') ? 'Access renewed' : 'Access given', ''],
            self::ENUMERATION      => ['microscope', Enumeration::KINDS[Enumeration::parse($detail)[0]] ?? 'Looking for names', ''],
            self::PASSWORD_CHANGED => ['check', 'New password', 'status--active'],
            self::PASSWORDS_RESET  => ['rotate', 'Everyone asked', ''],
            self::SIGN_IN_MOVED    => ['location-arrow', 'Sign-in moved', ''],
            self::PASSWORD_RESET   => str_contains($detail, 'wrong')
                ? ['rotate', 'Password replaced', '']
                : ['rotate', 'Reset by link', ''],
            self::TWO_STEP_CHANGED => match (true) {
                str_contains($detail, 'set up')       => ['mobile', 'Two-step set up', 'status--active'],
                str_contains($detail, 'backup codes') => ['list-ol', 'New backup codes', ''],
                default                               => ['mobile', 'Two-step removed', ''],
            },
            default => match (true) {
                $detail === 'unknown username'          => ['user-slash', 'Unknown name', ''],
                str_starts_with($detail, 'wrong code')  => ['mobile', 'Wrong code', ''],
                // A code by email to sign in with, not a second step.
                str_starts_with($detail, 'wrong sign-in code') => ['envelope', 'Wrong code', ''],
                // What a script trips over before a password is read.
                $detail === 'trap username'             => ['user-slash', 'Trap name', ''],
                $detail === 'hidden field filled'       => ['location-crosshairs', 'Bot', ''],
                $detail === 'wrong CAPTCHA answer'      => ['equals', 'Wrong answer', ''],
                // The right password, for an account that is suspended.
                $detail === 'account suspended'         => ['pause', 'Suspended account', ''],
                default                                 => ['x', 'Wrong password', ''],
            },
        };
    }

    /**
     * Has anyone ever signed in from address `$key` (within the log's
     * `KEEP_DAYS`)? A trap username tried from such an address is far likelier
     * the owner mistyping a name they used to have than a script's word list,
     * so it is not trapped (`AuthController`).
     */
    public static function signedInFrom(string $key): bool
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT 1 FROM security_log WHERE event = :event AND ip LIKE :ip LIMIT 1'
        );
        $stmt->execute([':event' => self::SIGN_IN, ':ip' => ClientIp::likePattern($key)]);

        return $stmt->fetchColumn() !== false;
    }

    /** This request's browser, as sent — trimmed to what the column holds. */
    public static function agent(): ?string
    {
        $agent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

        return $agent === '' ? null : self::clean($agent, 255);
    }

    /**
     * TEXT A STRANGER SENT, made fit to store: valid UTF-8 (`mb_scrub`), cut to
     * the column. A typed name, a browser string, an address asked for — this
     * log takes whatever arrives, and bytes that are not UTF-8 are a database's
     * to judge otherwise. MariaDB 10.11 stores `?` for them even in strict
     * mode; a server that refused them instead would turn a script's junk into
     * an error on the sign-in page. Cleaned here, it is `?` on every server.
     */
    public static function clean(?string $text, int $max): ?string
    {
        return $text === null ? null : mb_substr(mb_scrub($text, 'UTF-8'), 0, $max, 'UTF-8');
    }

    /**
     * A stored user agent as a person would say it: "Chrome on macOS",
     * "curl", "No browser named". The full string stays in the row; this is
     * what a list shows, because 140 characters of `Mozilla/5.0 (Macintosh;
     * …)` tell a site owner nothing — and "python-requests" beside a failed
     * sign-in tells them exactly what they are looking at.
     */
    public static function browser(?string $agent): string
    {
        $agent = trim((string) $agent);
        if ($agent === '') {
            return 'No browser named';
        }

        // Tools first: a script announcing itself is the most useful answer.
        foreach (['curl', 'Wget', 'python-requests', 'python-urllib', 'Go-http-client', 'okhttp',
                  'HeadlessChrome', 'PhantomJS', 'libwww-perl', 'Java/', 'axios', 'node-fetch'] as $tool) {
            if (stripos($agent, $tool) !== false) {
                return rtrim($tool, '/');
            }
        }

        $browser = match (true) {
            str_contains($agent, 'Edg/')                                   => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera')  => 'Opera',
            str_contains($agent, 'Firefox/')                               => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/')                                => 'Safari',
            default                                                        => '',
        };
        $system = match (true) {
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Android')                                => 'Android',
            str_contains($agent, 'Windows')                                => 'Windows',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'CrOS')                                   => 'ChromeOS',
            str_contains($agent, 'Linux')                                  => 'Linux',
            default                                                        => '',
        };

        if ($browser === '') {
            return mb_strimwidth($agent, 0, 40, '…');
        }

        return $system !== '' ? $browser . ' on ' . $system : $browser;
    }

    /**
     * How many of these events from `$since` up to (not including) `$until`,
     * both UTC — for the overview's period cards.
     *
     * @param list<string> $events
     */
    public static function count(array $events, string $since, ?string $until = null): int
    {
        if ($events === []) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($events), '?'));
        $stmt  = Database::getInstance()->prepare(
            "SELECT COUNT(*) FROM security_log WHERE event IN ($marks) AND created_at >= ?"
            . ($until !== null ? ' AND created_at < ?' : '')
        );
        $stmt->execute($until !== null ? [...$events, $since, $until] : [...$events, $since]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of the log, newest first.
     *
     * @param  list<string> $events
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public static function page(array $events, int $page = 1, int $perPage = 50): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $pdo     = Database::getInstance();

        $marks = implode(',', array_fill(0, max(1, count($events)), '?'));
        $args  = $events !== [] ? $events : [''];

        $total = $pdo->prepare("SELECT COUNT(*) FROM security_log WHERE event IN ($marks)");
        $total->execute($args);

        $stmt = $pdo->prepare(
            "SELECT id, event, username, user_id, ip, user_agent, detail, created_at
               FROM security_log
              WHERE event IN ($marks)
              ORDER BY id DESC
              LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage)
        );
        $stmt->execute($args);

        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $total->fetchColumn()];
    }

    /** Delete what is older than `KEEP_DAYS`. Returns how many rows went. */
    public static function prune(): int
    {
        return (int) Database::getInstance()->exec(
            'DELETE FROM security_log WHERE created_at < UTC_TIMESTAMP() - INTERVAL ' . (int) self::KEEP_DAYS . ' DAY'
        );
    }
}
