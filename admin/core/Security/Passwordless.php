<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Database\Database;
use Cms\Mail\Mailer;
use Cms\Models\Option;
use PDO;

/**
 * SIGNING IN WITHOUT A PASSWORD — a code by email, or a sign-in link.
 *
 * Both are OFF until an administrator switches them on (Security →
 * Passwordless). They are a convenience, not a protection, so they do not ship
 * switched on as the protections do. Both depend on mail, and say so where
 * they are set: a development install sends none, and a site with no sending
 * server hands mail to the host's own program, which may not deliver it.
 *
 * ── THE SAME ANSWER FOR EVERYONE ────────────────────────────────────────────
 * Asking for a code or a link answers the same whatever was typed — an account
 * or not, allowed or not, throttled or not — exactly as the reset link does.
 * The LOG says which it was (`passwordless_requested`).
 *
 * ── A LINK IS SPENT BY A BUTTON, NOT BY OPENING IT ─────────────────────────
 * A link that signed in the moment it was opened would be spent by a mail
 * scanner that opens links before the owner does — and the scanner would be
 * signed in. Here the link opens a page with one button (the unlock page's
 * pattern, `AccountLock`); only that POST spends it.
 *
 * ── THE SECOND STEP IS STILL ASKED ─────────────────────────────────────────
 * A code or a link proves the INBOX, so the second step asks for anything else
 * the account has — its authenticator app — and not for another code by email.
 *
 * Who may: the roles chosen for each way (editors and authors by default).
 * Never a suspended account (`Accounts`), nor one with no email address.
 */
final class Passwordless
{
    public const CODE_KEY         = 'security_passwordless_code';
    public const LINK_KEY         = 'security_passwordless_link';
    public const CODE_MINUTES_KEY = 'security_passwordless_code_minutes';
    public const LINK_MINUTES_KEY = 'security_passwordless_link_minutes';
    public const CODE_ROLES_KEY   = 'security_passwordless_code_roles';
    public const LINK_ROLES_KEY   = 'security_passwordless_link_roles';

    public const METHODS = ['code' => 'A code by email', 'link' => 'A sign-in link'];

    /** The roles that may, in the Users screen's order. */
    public const ROLES = ['admin' => 'Administrators', 'editor' => 'Editors', 'author' => 'Authors'];

    public const DEFAULT_ROLES = ['editor', 'author'];

    /** @var array<string, array{0: int, 1: int}> minutes a code or a link works, 10 by default */
    public const LIMITS = ['code' => [5, 60], 'link' => [5, 1440]];

    public const DEFAULT_MINUTES = 10;

    /** Requests an hour: the reset link's numbers. */
    public const PER_ADDRESS = 5;
    public const PER_ACCOUNT = 3;

    /** Wrong codes before a sent code stops working. */
    public const WRONG_CODES = 5;

    /**
     * How a wrong sign-in code is logged. NOT "wrong code …": the second
     * step's counters (`TwoStep::wrongCodes()`) read those, and someone who can
     * only ASK for codes must not lengthen an owner's wait at the second step,
     * nor bring on its 30-wrong-codes password reset.
     */
    public const WRONG_CODE_DETAIL = 'wrong sign-in code (email)';

    private const PENDING_KEY = 'cms_passwordless';

    /** @return array{code: bool, link: bool, code_minutes: int, link_minutes: int, code_roles: list<string>, link_roles: list<string>} */
    public static function settings(): array
    {
        $raw = Option::getMany([
            self::CODE_KEY         => '0',
            self::LINK_KEY         => '0',
            self::CODE_MINUTES_KEY => (string) self::DEFAULT_MINUTES,
            self::LINK_MINUTES_KEY => (string) self::DEFAULT_MINUTES,
            self::CODE_ROLES_KEY   => implode(',', self::DEFAULT_ROLES),
            self::LINK_ROLES_KEY   => implode(',', self::DEFAULT_ROLES),
        ]);
        $clamp = static fn (string $m, string $v): int => max(self::LIMITS[$m][0], min(self::LIMITS[$m][1], (int) $v));

        return [
            'code'         => $raw[self::CODE_KEY] === '1',
            'link'         => $raw[self::LINK_KEY] === '1',
            'code_minutes' => $clamp('code', (string) $raw[self::CODE_MINUTES_KEY]),
            'link_minutes' => $clamp('link', (string) $raw[self::LINK_MINUTES_KEY]),
            'code_roles'   => self::parseRoles((string) $raw[self::CODE_ROLES_KEY]),
            'link_roles'   => self::parseRoles((string) $raw[self::LINK_ROLES_KEY]),
        ];
    }

    /** @return list<string> */
    public static function parseRoles(string $list): array
    {
        $named = array_map('trim', explode(',', $list));

        return array_values(array_filter(array_keys(self::ROLES), static fn (string $r): bool => in_array($r, $named, true)));
    }

    /**
     * Does the sign-in page offer `$method`? Switched on, and on an install
     * that sends mail — a development install sends none, and a way
     * in that cannot arrive is not offered.
     */
    public static function offered(string $method): bool
    {
        return (self::settings()[$method] ?? false) === true && Alerts::emailSends();
    }

    /** May this account sign in this way at all? Never a temporary account (`TemporaryAccess`): its link is its only way. */
    public static function allows(array $user, array $settings, string $method): bool
    {
        return in_array((string) ($user['role'] ?? ''), $settings[$method . '_roles'] ?? [], true)
            && ($user['suspended_at'] ?? null) === null
            && empty($user['temporary'])
            && trim((string) ($user['email'] ?? '')) !== '';
    }

    /**
     * Someone asked for a code or a link for `$login` (a username or an email
     * address). Returns nothing on purpose: the caller shows one answer.
     */
    public static function request(string $method, string $login): void
    {
        $login = trim($login);
        if (!isset(self::METHODS[$method]) || $login === '' || mb_strlen($login) > 191) {
            return;
        }
        $settings = self::settings();
        $pdo      = Database::getInstance();
        $stmt     = $pdo->prepare(
            'SELECT id, username, email, role, suspended_at,
                    EXISTS (SELECT 1 FROM temporary_access t WHERE t.user_id = users.id) AS temporary
               FROM users WHERE username = :a OR email = :b LIMIT 1'
        );
        $stmt->execute([':a' => $login, ':b' => $login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $throttled = self::recent('ip LIKE :ip', [':ip' => ClientIp::likePattern(ClientIp::key())]) >= self::PER_ADDRESS
            || ($user !== null && self::recent('user_id = :id', [':id' => (int) $user['id']]) >= self::PER_ACCOUNT);
        $why = match (true) {
            !self::offered($method)                     => 'switched off — nothing sent',
            $user === null                              => 'no such account',
            $throttled                                  => 'throttled — nothing sent',
            ($user['suspended_at'] ?? null) !== null    => 'suspended — nothing sent',
            !empty($user['temporary'])                  => 'temporary — nothing sent',
            !self::allows($user, $settings, $method)    => 'not allowed — nothing sent',
            default                                     => $method . ' sent',
        };
        SecurityLog::record(SecurityLog::PASSWORDLESS_REQUESTED, $login, $user !== null ? (int) $user['id'] : null, $why);
        if ($why !== $method . ' sent') {
            if ($method === 'code') {
                // A code is asked for on the next page whoever asked: an empty
                // pending sign-in makes every code typed there wrong.
                self::holdCode(null, '');
            }
            return;
        }

        $site = (string) \Cms\SEO\Head::globalTokens()['site_name'];
        if ($method === 'link') {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare(
                'UPDATE users SET signin_token_hash = :hash, signin_expires_at = UTC_TIMESTAMP() + INTERVAL ' . (int) $settings['link_minutes'] . ' MINUTE
                  WHERE id = :id'
            )->execute([':hash' => hash('sha256', $token), ':id' => (int) $user['id']]);
            Mailer::send(
                [(string) $user['email']],
                'Sign in to ' . $site,
                "Someone asked to sign in to {$site} as \"{$user['username']}\" without a password.\n\n"
                . 'If it was you, open this link within ' . $settings['link_minutes'] . " minutes and press the button:\n\n"
                . self::link($token) . "\n\n"
                . "If it was not, ignore this message. The link works once, and stops on its own.\n"
            );
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        self::holdCode((int) $user['id'], $code);
        Mailer::send(
            [(string) $user['email']],
            $code . ' is your sign-in code for ' . $site,
            "Your code to sign in to {$site} is:\n\n    {$code}\n\n"
            . 'It works for ' . $settings['code_minutes'] . " minutes, once.\n\n"
            . "If you did not ask for it, ignore this message. Nobody can use it without this browser.\n"
        );
    }

    /** The link for a token — at the sign-in page, wherever it lives. */
    public static function link(string $token): string
    {
        return LoginUrl::url() . '?action=signin&key=' . $token;
    }

    /**
     * The account a link belongs to while it is still good — or null. Read
     * only: opening the link spends nothing (see the class note).
     *
     * @return array<string, mixed>|null
     */
    public static function findLink(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $hash = hash('sha256', $token);
        $stmt = Database::getInstance()->prepare(
            'SELECT id, username, email, role, suspended_at, signin_token_hash,
                    EXISTS (SELECT 1 FROM temporary_access t WHERE t.user_id = users.id) AS temporary FROM users
              WHERE signin_token_hash = :hash AND signin_expires_at > UTC_TIMESTAMP() AND suspended_at IS NULL LIMIT 1'
        );
        $stmt->execute([':hash' => $hash]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user !== false && hash_equals((string) $user['signin_token_hash'], $hash)
            && self::settings()['link'] && self::allows($user, self::settings(), 'link') ? $user : null;
    }

    /**
     * Spend a link: the account's id, once — a second press, or a second tab,
     * finds it gone. Null for a link that is no good.
     */
    public static function spendLink(string $token): ?int
    {
        $user = self::findLink($token);
        if ($user === null) {
            return null;
        }
        $stmt = Database::getInstance()->prepare(
            'UPDATE users SET signin_token_hash = NULL, signin_expires_at = NULL
              WHERE id = :id AND signin_token_hash = :hash AND signin_expires_at > UTC_TIMESTAMP()'
        );
        $stmt->execute([':id' => (int) $user['id'], ':hash' => hash('sha256', $token)]);

        return $stmt->rowCount() === 1 ? (int) $user['id'] : null;
    }

    /** Is a code waiting in this browser? */
    public static function codePending(): bool
    {
        return is_array($_SESSION[self::PENDING_KEY] ?? null);
    }

    /**
     * Is `$code` the one sent to this browser? The account's id when it is —
     * and the code is spent. Wrong codes count; the fifth stops the code.
     * `$wrong` says whose code was wrong, for the log (null: nobody's).
     */
    public static function checkCode(string $code, ?int &$wrong = null): ?int
    {
        $wrong   = null;
        $pending = $_SESSION[self::PENDING_KEY] ?? null;
        if (!is_array($pending)) {
            return null;
        }
        $minutes = self::settings()['code_minutes'];
        $code    = (string) preg_replace('/\s+/', '', $code);
        $id      = $pending['id'] !== null ? (int) $pending['id'] : null;
        $ok      = $id !== null
            && time() <= (int) $pending['at'] + $minutes * 60
            && preg_match('/^\d{6}$/', $code) === 1
            && hash_equals((string) $pending['hash'], hash_hmac('sha256', $code, (string) $pending['salt']));
        if ($ok) {
            unset($_SESSION[self::PENDING_KEY]);

            return $id;
        }

        $wrong = $id;
        $_SESSION[self::PENDING_KEY]['wrong'] = (int) ($pending['wrong'] ?? 0) + 1;
        if ($_SESSION[self::PENDING_KEY]['wrong'] >= self::WRONG_CODES) {
            unset($_SESSION[self::PENDING_KEY]);
        }

        return null;
    }

    /** The code sent to this browser, kept as its HMAC under a salt of its own — never the code. */
    private static function holdCode(?int $id, string $code): void
    {
        $salt = bin2hex(random_bytes(16));
        $_SESSION[self::PENDING_KEY] = [
            'id'    => $id,
            'at'    => time(),
            'salt'  => $salt,
            'hash'  => $id === null ? '' : hash_hmac('sha256', $code, $salt),
            'wrong' => 0,
        ];
    }

    /** How many requests matching `$where` in the last hour. */
    private static function recent(string $where, array $params): int
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT COUNT(*) FROM security_log
              WHERE event = 'passwordless_requested' AND {$where} AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR"
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
