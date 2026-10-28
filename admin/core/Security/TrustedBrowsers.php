<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Compat\OldNames;
use Cms\Database\Database;
use PDO;

/**
 * "REMEMBER THIS BROWSER" — after a second step done right, the owner may let
 * this browser skip it for 7, 30 or 90 days (`TwoStep` settings). The password
 * is still asked for every time.
 *
 * A random cookie, stored as its SHA-256 (migration 063) beside the account it
 * was earned by, the address and the browser. It proves nothing on its own:
 * it only counts for the account that earned it, and until it expires or is
 * forgotten from the account screen — where every remembered browser is
 * listed, and where turning two-step sign-in off or changing it forgets them
 * all.
 */
final class TrustedBrowsers
{
    public const COOKIE = 'cms_trusted';

    /** Remember this browser for `$days`, for this account. */
    public static function remember(int $userId, int $days): void
    {
        $token = bin2hex(random_bytes(32));
        Database::getInstance()->prepare(
            'INSERT INTO trusted_browsers (user_id, token_hash, ip, user_agent, expires_at)
             VALUES (:u, :h, :ip, :agent, UTC_TIMESTAMP() + INTERVAL ' . max(1, $days) . ' DAY)'
        )->execute([
            ':u'     => $userId,
            ':h'     => hash('sha256', $token),
            ':ip'    => ClientIp::get(),
            ':agent' => SecurityLog::agent(),
        ]);

        self::send(self::COOKIE, $token, time() + max(1, $days) * 86400);
    }

    /** Has this browser been remembered, and not forgotten, for this account? */
    public static function recognises(int $userId): bool
    {
        $token = self::token();
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return false;
        }

        $stmt = Database::getInstance()->prepare(
            'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expires_at) FROM trusted_browsers
              WHERE token_hash = :h AND user_id = :u AND expires_at > UTC_TIMESTAMP()'
        );
        $stmt->execute([':h' => hash('sha256', $token), ':u' => $userId]);
        $left = $stmt->fetchColumn();
        if ($left === false) {
            return false;
        }
        // Remembered before 0.7.0: the same token, re-issued under the cookie's
        // name now for the time it has left, and the old cookie cleared.
        if (!isset($_COOKIE[self::COOKIE])) {
            self::send(self::COOKIE, $token, time() + max(1, (int) $left));
            self::send(OldNames::TRUSTED_COOKIE, '', 1);
        }

        return true;
    }

    /** This browser's cookie — under its name now, or the one it had before 0.7.0 (Compat\OldNames). */
    private static function token(): string
    {
        return (string) ($_COOKIE[self::COOKIE] ?? $_COOKIE[OldNames::TRUSTED_COOKIE] ?? '');
    }

    private static function send(string $name, string $value, int $expires): void
    {
        if (!headers_sent()) {
            $params = session_get_cookie_params();
            setcookie($name, $value, [
                'expires'  => $expires,
                'path'     => '/',
                'secure'   => (bool) $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    /**
     * The account's remembered browsers, newest first, each marked `current`
     * when it is this one. Expired rows are cleared rather than listed.
     *
     * @return list<array<string, mixed>>
     */
    public static function of(int $userId): array
    {
        $pdo = Database::getInstance();
        $pdo->prepare('DELETE FROM trusted_browsers WHERE user_id = :u AND expires_at <= UTC_TIMESTAMP()')
            ->execute([':u' => $userId]);

        $stmt = $pdo->prepare(
            'SELECT id, ip, user_agent, created_at, expires_at, token_hash = :h AS current
               FROM trusted_browsers WHERE user_id = :u ORDER BY id DESC'
        );
        $stmt->execute([':u' => $userId, ':h' => hash('sha256', self::token())]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Forget one. False when it is not the account's. */
    public static function forget(int $userId, int $id): bool
    {
        $stmt = Database::getInstance()->prepare('DELETE FROM trusted_browsers WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() === 1;
    }

    /** Forget every browser the account has remembered. Returns how many. */
    public static function forgetAll(int $userId): int
    {
        $stmt = Database::getInstance()->prepare('DELETE FROM trusted_browsers WHERE user_id = :u');
        $stmt->execute([':u' => $userId]);

        return $stmt->rowCount();
    }
}
