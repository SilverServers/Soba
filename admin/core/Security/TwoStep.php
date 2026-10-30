<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Auth\Auth;
use Cms\Database\Database;
use Cms\Helpers\Url;
use Cms\Mail\Mailer;
use Cms\Models\Option;
use Cms\Models\User;
use Cms\SEO\Head;

/**
 * TWO-STEP SIGN-IN — after the password, a code from an authenticator app or
 * from the account's email, or a backup code. `Factors` holds what each
 * account has; this decides when it is asked for, and keeps the sign-in that
 * is waiting for it.
 *
 * ── WHERE IT HAPPENS ────────────────────────────────────────────────────────
 * At the sign-in page's own address, `?action=two-step`, like the lost-password
 * actions: it moves with the page (`LoginUrl`). The password has been checked;
 * the account is NOT signed in. What is kept is the sign-in in progress, in
 * this session only, for `PENDING_MINUTES` — then the password again.
 *
 * ── A WRONG CODE IS A FAILED SIGN-IN ────────────────────────────────────────
 * Logged as one (`wrong code (app)`), so the attempt limit counts it by address
 * like any other. And by ACCOUNT: each wrong code doubles the wait before the
 * next is looked at, up to `MAX_WAIT` — for the attacker who has the password
 * and a thousand addresses. After `RESET_AFTER` wrong codes the password is
 * replaced and the owner told by mail: whoever keeps getting that far knows
 * it.
 *
 * ── WHO MUST HAVE IT ────────────────────────────────────────────────────────
 * The roles in the policy (administrators, by default: the protections are
 * on). An account in one of them without a method is asked at sign-in; for
 * `grace_days` it may say "not now", then it is stopped at the setup screen
 * like an owed password (`Auth::requireAuth()`). As with an owed password,
 * only a PRODUCTION install stops anyone — and only one whose config.php has
 * an app_secret of its own, without which nothing can be sealed
 * (`Sealed::ready()`).
 *
 * ── AND CHANGING IT MEANS PROVING IT IS YOU ─────────────────────────────────
 * Setting up, removing, new backup codes and the Security screens ask for the
 * password again unless it was given in the last `CONFIRM_SECONDS` (Two
 * Factor's ten minutes). A browser left signed in is not enough to switch the
 * second step off.
 */
final class TwoStep
{
    public const APP_KEY           = 'security_two_step_app';
    public const EMAIL_KEY         = 'security_two_step_email';
    public const REMEMBER_KEY      = 'security_two_step_remember';
    public const REMEMBER_DAYS_KEY = 'security_two_step_remember_days';
    public const ROLES_KEY         = 'security_two_step_roles';
    public const GRACE_KEY         = 'security_two_step_grace_days';
    public const CODE_MINUTES_KEY  = 'security_two_step_code_minutes';

    public const DEFAULTS = [
        self::APP_KEY           => '1',
        self::EMAIL_KEY         => '1',
        self::REMEMBER_KEY      => '1',
        self::REMEMBER_DAYS_KEY => '30',
        self::ROLES_KEY         => 'admin',
        self::GRACE_KEY         => '7',
        self::CODE_MINUTES_KEY  => '10',
    ];

    /** The choices for "Remember this browser", in days. */
    public const REMEMBER_CHOICES = [7, 30, 90];

    public const ROLE_NAMES = ['admin' => 'Administrators', 'shop_manager' => 'Shop managers', 'editor' => 'Editors', 'author' => 'Authors'];

    public const GRACE_RANGE        = [0, 90];
    public const CODE_MINUTES_RANGE = [2, 60];

    public const PENDING_MINUTES = 10;
    public const RESEND_SECONDS  = 60;
    public const RESET_AFTER     = 30;
    public const MAX_WAIT        = 900;
    public const CONFIRM_SECONDS = 600;

    private const PENDING_KEY   = 'cms_two_step';
    private const CONFIRMED_KEY = 'cms_confirmed_at';

    /** @var array<string, mixed>|null */
    private static ?array $settings = null;

    // ── The settings ────────────────────────────────────────────────────────

    /**
     * @return array{app: bool, email: bool, remember: bool, remember_days: int,
     *               roles: list<string>, grace_days: int, code_minutes: int}
     */
    public static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        $raw   = Option::getMany(self::DEFAULTS);
        $clamp = static function (string $key, array $range) use ($raw): int {
            $value = (string) ($raw[$key] ?? self::DEFAULTS[$key]);

            return max($range[0], min($range[1], ctype_digit($value) ? (int) $value : (int) self::DEFAULTS[$key]));
        };
        $days = (int) ($raw[self::REMEMBER_DAYS_KEY] ?? 30);

        return self::$settings = [
            'app'           => ($raw[self::APP_KEY] ?? '1') === '1',
            'email'         => ($raw[self::EMAIL_KEY] ?? '1') === '1',
            'remember'      => ($raw[self::REMEMBER_KEY] ?? '1') === '1',
            'remember_days' => in_array($days, self::REMEMBER_CHOICES, true) ? $days : 30,
            'roles'         => self::parseRoles((string) ($raw[self::ROLES_KEY] ?? '')),
            'grace_days'    => $clamp(self::GRACE_KEY, self::GRACE_RANGE),
            'code_minutes'  => $clamp(self::CODE_MINUTES_KEY, self::CODE_MINUTES_RANGE),
        ];
    }

    /** Forget the settings read this request — after a save. */
    public static function forget(): void
    {
        self::$settings = null;
    }

    /** @return list<string> the known roles in `$list`, in ROLE_NAMES order */
    public static function parseRoles(string $list): array
    {
        $given = array_map('trim', explode(',', $list));

        return array_values(array_filter(array_keys(self::ROLE_NAMES), static fn (string $r): bool => in_array($r, $given, true)));
    }

    // ── The policy ──────────────────────────────────────────────────────────

    /** Can this install offer two-step sign-in at all? It needs app_secret. */
    public static function ready(): bool
    {
        return Sealed::ready();
    }

    /** Does this install stop an account that must have two-step and has not? (Production installs only.) */
    public static function enforces(): bool
    {
        $s = self::settings();

        return Auth::enforcesPasswordChanges() && self::ready() && ($s['app'] || $s['email']);
    }

    /**
     * Does the policy ask this account? Not a temporary one (`TemporaryAccess`):
     * its link is what it has, and the policy is for the administrators a
     * temporary account can never be.
     *
     * @param array<string, mixed> $user
     */
    public static function requiredFor(array $user): bool
    {
        return in_array((string) ($user['role'] ?? ''), self::settings()['roles'], true)
            && !TemporaryAccess::is((int) ($user['id'] ?? 0));
    }

    /**
     * The methods an account can use today: what it has set up, of what the
     * site has switched on, the app first.
     *
     * @return list<string>
     */
    public static function methods(int $userId): array
    {
        $s    = self::settings();
        $have = Factors::of($userId);
        $out  = [];
        if ($s['app'] && isset($have[Factors::APP])) {
            $out[] = Factors::APP;
        }
        if ($s['email'] && isset($have[Factors::EMAIL])) {
            $out[] = Factors::EMAIL;
        }

        return $out;
    }

    /**
     * Must the signed-in account set up two-step sign-in before anything else?
     * 'strict' — yes; 'grace' — it is asked, and may say not now; null — no.
     */
    public static function owed(): ?string
    {
        $user = Auth::user();
        if ($user === null || !self::enforces() || !self::requiredFor($user) || self::methods((int) $user['id']) !== []) {
            return null;
        }

        // THE FIRST TIME THE POLICY MEETS THIS ACCOUNT — an update that brings
        // it, a policy widened to its role, a promotion — even mid-session:
        // its grace starts now. Without this, an administrator signed in when
        // migration 063 landed was locked onto the setup screen on its next
        // click, the day-of-update lock-out the grace exists to prevent.
        $ends = self::graceEnds((int) $user['id']);
        if ($ends === null) {
            self::markAsked((int) $user['id']);
            $ends = self::graceEnds((int) $user['id']);
        }

        return $ends !== null && $ends > time() ? 'grace' : 'strict';
    }

    /** "Required for administrators and editors" — the policy in one line. */
    public static function policySummary(): string
    {
        $roles = self::settings()['roles'];
        if ($roles === []) {
            return 'Not required';
        }
        if (count($roles) === count(self::ROLE_NAMES)) {
            return 'Required for everyone';
        }
        $names = array_map(static fn (string $r): string => strtolower(self::ROLE_NAMES[$r]), $roles);

        return 'Required for ' . (count($names) > 1
            ? implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names)
            : $names[0]);
    }

    /**
     * How many accounts have it: all of them, and those the policy requires it of.
     *
     * @return array{total: int, on: int, required: int, requiredOn: int}
     */
    public static function adoption(): array
    {
        $rows = Database::getInstance()->query(
            'SELECT u.role, COUNT(*) AS total,
                    SUM(EXISTS (SELECT 1 FROM user_factors f WHERE f.user_id = u.id)) AS on_count
               FROM users u GROUP BY u.role'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $roles = self::settings()['roles'];
        $out   = ['total' => 0, 'on' => 0, 'required' => 0, 'requiredOn' => 0];
        foreach ($rows as $row) {
            $out['total'] += (int) $row['total'];
            $out['on']    += (int) $row['on_count'];
            if (in_array((string) $row['role'], $roles, true)) {
                $out['required']   += (int) $row['total'];
                $out['requiredOn'] += (int) $row['on_count'];
            }
        }

        return $out;
    }

    /** When the account's grace period ends — or null before it was first asked. */
    public static function graceEnds(int $userId): ?int
    {
        $stmt = Database::getInstance()->prepare('SELECT two_step_asked_at FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $asked = $stmt->fetchColumn();
        if (!is_string($asked) || $asked === '') {
            return null;
        }

        return (int) strtotime($asked . ' UTC') + self::settings()['grace_days'] * 86400;
    }

    /**
     * The account's last method is gone. If it was never asked — it set two-step
     * sign-in up before the policy got to it — there is no grace to start now:
     * removing a method must not buy the days the policy gives a newcomer.
     */
    public static function noNewGrace(int $userId): void
    {
        if (Factors::any($userId)) {
            return;
        }
        Database::getInstance()->prepare(
            "UPDATE users SET two_step_asked_at = '2000-01-01 00:00:00' WHERE id = :id AND two_step_asked_at IS NULL"
        )->execute([':id' => $userId]);
    }

    /** Start the account's grace period, the first time it is asked. */
    public static function markAsked(int $userId): void
    {
        Database::getInstance()->prepare(
            'UPDATE users SET two_step_asked_at = UTC_TIMESTAMP() WHERE id = :id AND two_step_asked_at IS NULL'
        )->execute([':id' => $userId]);
    }

    // ── The sign-in in progress ─────────────────────────────────────────────

    /**
     * After a right password: is a second step owed? Not when the account has
     * no method the site allows, and not on a browser it remembered.
     *
     * @param array<string, mixed> $user
     */
    public static function needed(array $user, array $proven = []): bool
    {
        $id = (int) $user['id'];

        return array_diff(self::methods($id), $proven) !== []
            && !(self::settings()['remember'] && TrustedBrowsers::recognises($id));
    }

    /**
     * What the sign-in in progress may still be asked for: the account's
     * methods, less what the first step already proved. A code or a link by
     * email proves the inbox (`Passwordless`), so a second email code would
     * prove nothing more.
     *
     * @param array<string, mixed> $pending
     * @return list<string>
     */
    public static function pendingMethods(array $pending): array
    {
        return array_values(array_diff(self::methods((int) $pending['id']), (array) ($pending['proven'] ?? [])));
    }

    /**
     * Hold the sign-in here until the second step. A new session id now, so an
     * id planted before the password is worth nothing after it.
     *
     * @param array<string, mixed> $user
     */
    public static function begin(array $user, array $proven = [], ?string $via = null): void
    {
        session_regenerate_id(true);

        $methods = array_values(array_diff(self::methods((int) $user['id']), $proven));
        $_SESSION[self::PENDING_KEY] = [
            'id'         => (int) $user['id'],
            'username'   => (string) $user['username'],
            'proven'     => array_values($proven),
            'via'        => $via,
            'at'         => time(),
            'method'     => $methods[0] ?? Factors::APP,
            'code'       => null,
            'code_until' => 0,
            'sent_at'    => 0,
        ];

        if (($methods[0] ?? '') === Factors::EMAIL) {
            self::sendEmailCode();
        }
    }

    /** @return array<string, mixed>|null the sign-in waiting for its second step, while it is fresh */
    public static function pending(): ?array
    {
        $pending = $_SESSION[self::PENDING_KEY] ?? null;
        if (!is_array($pending)) {
            return null;
        }
        if (time() - (int) $pending['at'] > self::PENDING_MINUTES * 60) {
            self::abandon();

            return null;
        }

        return $pending;
    }

    /** Ask with another method on the page: app, email or backup. Switching to email sends a code if none is out. */
    public static function choose(string $method): void
    {
        $pending = self::pending();
        if ($pending === null) {
            return;
        }
        $allowed = [...self::pendingMethods($pending), Factors::BACKUP];
        if (!in_array($method, $allowed, true)) {
            return;
        }

        $_SESSION[self::PENDING_KEY]['method'] = $method;
        if ($method === Factors::EMAIL && (int) $pending['code_until'] < time()) {
            self::sendEmailCode();
        }
    }

    /**
     * Mail the account a six-digit code for this sign-in. 'sent'; 'wait' when
     * the last one went less than RESEND_SECONDS ago; 'none' when there is
     * nothing to send it for or nowhere to send it.
     */
    public static function sendEmailCode(): string
    {
        $pending = self::pending();
        if ($pending === null || !in_array(Factors::EMAIL, self::pendingMethods($pending), true)) {
            return 'none';
        }
        if (time() - (int) $pending['sent_at'] < self::RESEND_SECONDS) {
            return 'wait';
        }
        $user = User::find((int) $pending['id']);
        if ($user === null || trim((string) $user['email']) === '') {
            return 'none';
        }

        $code    = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $minutes = self::settings()['code_minutes'];
        $_SESSION[self::PENDING_KEY]['code']       = Sealed::hmac('sign-in code:' . $pending['id'] . ':' . $code);
        $_SESSION[self::PENDING_KEY]['code_until'] = time() + $minutes * 60;
        $_SESSION[self::PENDING_KEY]['sent_at']    = time();

        $site = (string) Head::globalTokens()['site_name'];
        Mailer::send(
            [(string) $user['email']],
            $code . ' is your sign-in code for ' . $site,
            "Your code to finish signing in to {$site} is:\n\n    {$code}\n\n"
            . "It works for {$minutes} minutes, once.\n\n"
            . "If you did not just sign in, someone else knows your password. Change it: "
            . LoginUrl::url() . "?action=lostpassword\n"
        );

        return 'sent';
    }

    /** Is `$code` right for the waiting sign-in, by `$method`? A right code is spent. */
    public static function check(string $method, string $code): bool
    {
        $pending = self::pending();
        if ($pending === null) {
            return false;
        }
        $id      = (int) $pending['id'];
        $methods = self::pendingMethods($pending);

        if ($method === Factors::APP) {
            return in_array(Factors::APP, $methods, true) && Factors::checkApp($id, $code);
        }

        if ($method === Factors::EMAIL) {
            $code = (string) preg_replace('/\s+/', '', $code);
            $ok   = in_array(Factors::EMAIL, $methods, true)
                && is_string($pending['code'])
                && time() <= (int) $pending['code_until']
                && preg_match('/^\d{6}$/', $code) === 1
                && hash_equals($pending['code'], Sealed::hmac('sign-in code:' . $id . ':' . $code));
            if ($ok) {
                $_SESSION[self::PENDING_KEY]['code'] = null;
                Factors::used($id, Factors::EMAIL);
            }

            return $ok;
        }

        return $method === Factors::BACKUP && $methods !== [] && Factors::spendBackupCode($id, $code);
    }

    /**
     * The second step is done: the account's row, fresh, to start the session
     * with — and the sign-in in progress is over.
     *
     * @return array<string, mixed>|null
     */
    public static function finish(): ?array
    {
        $pending = self::pending();
        self::abandon();

        return $pending === null ? null : Auth::accountRow((int) $pending['id']);
    }

    public static function abandon(): void
    {
        unset($_SESSION[self::PENDING_KEY]);
    }

    // ── Too many wrong codes ────────────────────────────────────────────────

    /** Wrong second-step codes for the account since it last signed in or had its password reset. */
    public static function wrongCodes(int $userId): int
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT COUNT(*) FROM security_log
              WHERE event = :failed AND user_id = :u AND detail LIKE 'wrong code%'
                AND id > (SELECT COALESCE(MAX(id), 0) FROM security_log
                           WHERE user_id = :u2 AND event IN (:in, :reset))"
        );
        $stmt->execute([
            ':failed' => SecurityLog::SIGN_IN_FAILED, ':u' => $userId, ':u2' => $userId,
            ':in'     => SecurityLog::SIGN_IN,        ':reset' => SecurityLog::PASSWORD_RESET,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /** Seconds before the account's next code will be looked at: 1, 2, 4… after each wrong one, up to MAX_WAIT. */
    public static function waitSeconds(int $userId): int
    {
        $wrong = self::wrongCodes($userId);
        if ($wrong === 0) {
            return 0;
        }

        $stmt = Database::getInstance()->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), UTC_TIMESTAMP()) FROM security_log
              WHERE event = :failed AND user_id = :u AND detail LIKE 'wrong code%'"
        );
        $stmt->execute([':failed' => SecurityLog::SIGN_IN_FAILED, ':u' => $userId]);
        $since = (int) $stmt->fetchColumn();

        return max(0, min(self::MAX_WAIT, 2 ** min(20, $wrong - 1)) - $since);
    }

    /**
     * RESET_AFTER wrong codes: whoever is at the second step has the password,
     * so the password stops working. Every session and remembered browser
     * ends, and the owner is told — with the way back in.
     *
     * @param array<string, mixed> $pending
     */
    public static function replacePassword(array $pending): void
    {
        $id = (int) $pending['id'];
        User::updatePassword($id, bin2hex(random_bytes(32)), null);
        Auth::endSessions($id);
        Sessions::revokeAll($id);
        TrustedBrowsers::forgetAll($id);
        self::abandon();
        SecurityLog::record(SecurityLog::PASSWORD_RESET, (string) $pending['username'], $id,
            self::RESET_AFTER . ' wrong second-step codes — password replaced');

        $user = User::find($id);
        if ($user !== null && trim((string) $user['email']) !== '') {
            $site = (string) Head::globalTokens()['site_name'];
            Mailer::send(
                [(string) $user['email']],
                'Your password for ' . $site . ' has been replaced',
                "Someone signed in to {$site} with your password, then typed a wrong second-step code "
                . self::RESET_AFTER . " times. Your password has been replaced so that it no longer works.\n\n"
                . "Choose a new one here:\n\n" . LoginUrl::url() . "?action=lostpassword\n"
            );
        }
    }

    // ── Re-authentication ───────────────────────────────────────────────────

    /** This session gave its password (or finished signing in) just now. */
    public static function markConfirmed(): void
    {
        $_SESSION[self::CONFIRMED_KEY] = time();
    }

    /** Within CONFIRM_SECONDS of the password — or a development tree, which never asks. */
    public static function confirmed(): bool
    {
        return !Auth::enforcesPasswordChanges()
            || time() - (int) ($_SESSION[self::CONFIRMED_KEY] ?? 0) <= self::CONFIRM_SECONDS;
    }

    /** Stop here, and ask for the password, unless it was given just now. Back to `$return` after. */
    public static function requireConfirmed(string $return): void
    {
        if (!self::confirmed()) {
            header('Location: ' . Url::admin('confirm') . '?return=' . rawurlencode($return), true, 303);
            exit;
        }
    }

    /** An address to come back to after confirming: an admin path, nothing else. */
    public static function safeReturn(string $return): string
    {
        return preg_match('#^/admin(/[A-Za-z0-9/_\-]*)?(\?[A-Za-z0-9=&_\-]*)?$#', $return) === 1
            ? $return
            : '/admin';
    }
}
