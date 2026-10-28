<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Database\Database;
use PDO;

/**
 * AN ACCOUNT'S SECOND STEP — the ways it can prove itself after its password
 * (migration 063), and its backup codes. `TwoStep` decides when to ask; this
 * holds what is asked about.
 *
 *   app    an authenticator app. The secret is sealed (`Sealed`), and a code is
 *          accepted once: the step it was made for is kept (`last_step`), and a
 *          code for that step or an earlier one is refused.
 *   email  a six-digit code sent to the account's address at sign-in. Nothing to
 *          store but that it is on, and since when; the code lives only in the
 *          sign-in it belongs to (`TwoStep`).
 *
 * ── BACKUP CODES ────────────────────────────────────────────────────────────
 * Ten, each good once, for the day the phone is lost or the mail does not come.
 * Shown once, when made; stored as an HMAC under app_secret, so a copy of the
 * database is not a list of ways in. New codes replace all ten.
 */
final class Factors
{
    public const APP    = 'app';
    public const EMAIL  = 'email';
    public const BACKUP = 'backup';

    public const BACKUP_COUNT = 10;

    /** No 0/o, no 1/i/l: a code is read off paper and typed. */
    private const BACKUP_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /**
     * The account's methods, oldest first.
     *
     * @return array<string, array<string, mixed>> kind => row (no secret)
     */
    public static function of(int $userId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, kind, created_at, last_used_at FROM user_factors WHERE user_id = :u ORDER BY id'
        );
        $stmt->execute([':u' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['kind']] = $row;
        }

        return $out;
    }

    /** Does the account have any second step at all? */
    public static function any(int $userId): bool
    {
        return self::of($userId) !== [];
    }

    /** Keep a confirmed app — replacing an earlier one — and the step its first code was for. */
    public static function saveApp(int $userId, string $secret, int $step): void
    {
        Database::getInstance()->prepare(
            'INSERT INTO user_factors (user_id, kind, secret, last_step) VALUES (:u, :k, :s, :step)
             ON DUPLICATE KEY UPDATE secret = VALUES(secret), last_step = VALUES(last_step),
                                     created_at = CURRENT_TIMESTAMP, last_used_at = NULL'
        )->execute([':u' => $userId, ':k' => self::APP, ':s' => Sealed::seal($secret), ':step' => $step]);
    }

    /** Turn email codes on for the account (its address has just been proved). */
    public static function saveEmail(int $userId): void
    {
        Database::getInstance()->prepare(
            'INSERT INTO user_factors (user_id, kind) VALUES (:u, :k)
             ON DUPLICATE KEY UPDATE created_at = CURRENT_TIMESTAMP, last_used_at = NULL'
        )->execute([':u' => $userId, ':k' => self::EMAIL]);
    }

    /**
     * Is `$code` the account's app's current code, not used before? Right, it
     * is spent — by a conditional update, so two requests racing with one code
     * cannot both be let in.
     */
    public static function checkApp(int $userId, string $code): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('SELECT id, secret, last_step FROM user_factors WHERE user_id = :u AND kind = :k');
        $stmt->execute([':u' => $userId, ':k' => self::APP]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }

        $secret = Sealed::open((string) $row['secret']);
        $step   = $secret === null ? null
            : Totp::verify($secret, $code, $row['last_step'] !== null ? (int) $row['last_step'] : null);
        if ($step === null) {
            return false;
        }

        $spend = $pdo->prepare(
            'UPDATE user_factors SET last_step = :step, last_used_at = UTC_TIMESTAMP()
              WHERE id = :id AND (last_step IS NULL OR last_step < :before)'
        );
        $spend->execute([':step' => $step, ':before' => $step, ':id' => (int) $row['id']]);

        return $spend->rowCount() === 1;
    }

    /** Note that a method was used just now. */
    public static function used(int $userId, string $kind): void
    {
        Database::getInstance()->prepare(
            'UPDATE user_factors SET last_used_at = UTC_TIMESTAMP() WHERE user_id = :u AND kind = :k'
        )->execute([':u' => $userId, ':k' => $kind]);
    }

    /** Remove one method. With none left, the backup codes go too — they belong to the second step. */
    public static function remove(int $userId, string $kind): void
    {
        Database::getInstance()->prepare('DELETE FROM user_factors WHERE user_id = :u AND kind = :k')
            ->execute([':u' => $userId, ':k' => $kind]);

        if (!self::any($userId)) {
            self::removeBackupCodes($userId);
        }
    }

    /** Every method and every backup code — "turn off two-step sign-in". */
    public static function removeAll(int $userId): void
    {
        Database::getInstance()->prepare('DELETE FROM user_factors WHERE user_id = :u')->execute([':u' => $userId]);
        self::removeBackupCodes($userId);
    }

    /**
     * Ten new backup codes, replacing the old — returned in plain text this once.
     *
     * @return list<string> formatted `abcde-fghjk`
     */
    public static function newBackupCodes(int $userId): array
    {
        self::removeBackupCodes($userId);

        $insert = Database::getInstance()->prepare('INSERT INTO user_backup_codes (user_id, code_hash) VALUES (:u, :h)');
        $codes  = [];
        while (count($codes) < self::BACKUP_COUNT) {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= self::BACKUP_ALPHABET[random_int(0, strlen(self::BACKUP_ALPHABET) - 1)];
            }
            if (isset($codes[$code])) {
                continue;
            }
            $insert->execute([':u' => $userId, ':h' => Sealed::hmac($code)]);
            $codes[$code] = substr($code, 0, 5) . '-' . substr($code, 5);
        }

        return array_values($codes);
    }

    /** How many backup codes the account has not used. */
    public static function backupCodesLeft(int $userId): int
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM user_backup_codes WHERE user_id = :u AND used_at IS NULL'
        );
        $stmt->execute([':u' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /** Spend a backup code: true when it was the account's and unused. Spaces, dashes and case are forgiven. */
    public static function spendBackupCode(int $userId, string $code): bool
    {
        $code = strtolower((string) preg_replace('/[\s-]+/', '', $code));
        if (preg_match('/^[' . self::BACKUP_ALPHABET . ']{10}$/', $code) !== 1) {
            return false;
        }

        $stmt = Database::getInstance()->prepare(
            'UPDATE user_backup_codes SET used_at = UTC_TIMESTAMP()
              WHERE user_id = :u AND code_hash = :h AND used_at IS NULL LIMIT 1'
        );
        $stmt->execute([':u' => $userId, ':h' => Sealed::hmac($code)]);

        return $stmt->rowCount() === 1;
    }

    private static function removeBackupCodes(int $userId): void
    {
        Database::getInstance()->prepare('DELETE FROM user_backup_codes WHERE user_id = :u')->execute([':u' => $userId]);
    }
}
