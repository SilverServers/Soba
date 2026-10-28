<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Auth\Auth;
use Cms\Database\Database;
use Cms\Mail\Mailer;
use Cms\Models\User;
use Cms\SEO\Head;
use PDO;

/**
 * THE WAY BACK IN — the sign-in page's "Forgot your password?".
 *
 * ── IT LIVES AT THE SIGN-IN ADDRESS ─────────────────────────────────────────
 * `{sign-in}?action=lostpassword` and `?action=resetpass&key=…`, two actions
 * on whatever address the sign-in page has (`LoginUrl`). An `/admin/forgot`
 * would be an admin address a signed-out stranger could reach, and would hand
 * the moved sign-in page's secret straight back.
 *
 * ── THE SAME ANSWER, WHATEVER WAS TYPED ─────────────────────────────────────
 * "If that account exists, a link is on its way" — for a name that has one,
 * one that does not, and a request that was throttled. Anything else lists the
 * accounts, which is the thing the sign-in page's vague error protects.
 *
 * ── A LINK IS A PASSWORD FOR ONE USE ────────────────────────────────────────
 * 32 random bytes, mailed; only their SHA-256 is stored (migration 062). One
 * link per account — asking again replaces it — valid for `LIFETIME_MINUTES`,
 * spent by use, and spent by ANY password change (`User::updatePassword()`).
 * Using it signs out every other session, because the reason anybody resets a
 * password is that somebody else might know it.
 *
 * ── AND IT CANNOT BE USED TO FLOOD AN INBOX ─────────────────────────────────
 * `PER_ADDRESS` requests an hour from one address and `PER_ACCOUNT` an hour
 * for one account, counted from the log. Past either, the page says the same
 * thing and no mail is sent.
 */
final class PasswordReset
{
    public const LIFETIME_MINUTES = 60;
    public const PER_ADDRESS      = 5;
    public const PER_ACCOUNT      = 3;

    /**
     * Someone asked for a link for `$login` (a username or an email address).
     * Returns nothing on purpose: the caller shows one answer whatever happens.
     */
    public static function request(string $login): void
    {
        $login = trim($login);
        if ($login === '' || mb_strlen($login) > 191) {
            return;
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT id, username, email, suspended_at FROM users WHERE username = :a OR email = :b LIMIT 1'
        );
        $stmt->execute([':a' => $login, ':b' => $login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $throttled = self::recent('ip LIKE :ip', [':ip' => ClientIp::likePattern(ClientIp::key())]) >= self::PER_ADDRESS
            || ($user !== null && self::recent('user_id = :id', [':id' => (int) $user['id']]) >= self::PER_ACCOUNT);

        // A SUSPENDED account (migration 067) is sent nothing: a new password
        // would not let it in, and only an administrator can. The page says
        // what it says to everyone.
        $suspended = $user !== null && $user['suspended_at'] !== null;
        // A TEMPORARY account (migration 069) signs in only by its link: a
        // password of its own would outlive its time and its uses.
        $temporary = $user !== null && TemporaryAccess::is((int) $user['id']);

        SecurityLog::record(
            SecurityLog::RESET_REQUESTED,
            $login,
            $user !== null ? (int) $user['id'] : null,
            match (true) {
                $user === null => 'no such account',
                $suspended     => 'suspended — nothing sent',
                $temporary     => 'temporary — nothing sent',
                $throttled     => 'throttled — nothing sent',
                default        => 'link sent',
            }
        );

        if ($user === null || $throttled || $suspended) {
            return;
        }
        if ($temporary) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $pdo->prepare(
            'UPDATE users
                SET reset_token_hash = :hash,
                    reset_expires_at = UTC_TIMESTAMP() + INTERVAL ' . (int) self::LIFETIME_MINUTES . ' MINUTE
              WHERE id = :id'
        )->execute([':hash' => hash('sha256', $token), ':id' => (int) $user['id']]);

        $site = (string) Head::globalTokens()['site_name'];
        Mailer::send(
            [(string) $user['email']],
            'Choose a new password for ' . $site,
            "Someone asked to choose a new password for the account \"{$user['username']}\" on {$site}.\n\n"
            . "If it was you, open this link within " . self::LIFETIME_MINUTES . " minutes:\n\n"
            . self::link($token) . "\n\n"
            . "If it was not, ignore this message. Your password has not changed, and the link stops working on its own.\n"
        );
    }

    /**
     * The account a link belongs to, while it is still good — or null for a
     * link that is wrong, used, or out of date. Compared by hash in the query
     * AND in constant time here, so the lookup is not an oracle either.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $hash = hash('sha256', $token);
        $stmt = Database::getInstance()->prepare(
            'SELECT id, username, email, display_name, reset_token_hash FROM users
              WHERE reset_token_hash = :hash AND reset_expires_at > UTC_TIMESTAMP() AND suspended_at IS NULL LIMIT 1'
        );
        $stmt->execute([':hash' => $hash]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user !== false && hash_equals((string) $user['reset_token_hash'], $hash)
            && !TemporaryAccess::is((int) $user['id']) ? $user : null;
    }

    /**
     * Spend the link: the new password, the flag cleared, every session ended,
     * the link gone. The caller has checked the password against the rule.
     *
     * @param array<string, mixed> $user  from find()
     */
    public static function complete(array $user, string $password): void
    {
        $id = (int) $user['id'];
        User::updatePassword($id, $password, null);     // also spends the link (062)
        Auth::endSessions($id);
        Sessions::revokeAll($id);
        SecurityLog::record(SecurityLog::PASSWORD_RESET, (string) $user['username'], $id, 'reset by emailed link');

        // The link proved the account's inbox — what the unlock link proves —
        // so a NAME locked by someone else's guessing is let back in too.
        // Otherwise an owner on a new network chooses a new password and is
        // still turned away (`AccountLock`).
        AccountLock::lift((string) $user['username'], $id, 'by a password reset');
    }

    /** The link for a token — at the sign-in page, wherever it lives. */
    public static function link(string $token): string
    {
        return LoginUrl::url() . '?action=resetpass&key=' . $token;
    }

    /** How many reset requests matching `$where` in the last hour. */
    private static function recent(string $where, array $params): int
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT COUNT(*) FROM security_log
              WHERE event = 'reset_requested' AND {$where} AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR"
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
