<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Database\Database;
use PDO;

/**
 * WHERE AN ACCOUNT IS SIGNED IN — one row per browser (migration 062), and the
 * way to end any of them from any other.
 *
 * It is the first thing anybody needs after "I think someone has my password":
 * see every place the account is signed in, and end the ones that are not you.
 *
 * ── A ROW IS THE SESSION'S RIGHT TO EXIST ───────────────────────────────────
 * `Auth::check()` reads the row in the same query as the account. A browser
 * whose row has been deleted is signed out on its next click, on whatever
 * machine it is — that is the whole mechanism behind "Sign out" and "Sign out
 * everywhere else".
 *
 * ── A SESSION FROM BEFORE THIS EXISTED IS ADOPTED, NOT SIGNED OUT ───────────
 * Sessions open when migration 062 lands have no row. The session remembers
 * whether it was ever given one (`TRACKED_KEY`): one that never was is given
 * one now; one that was, and has lost it, was signed out on purpose. The same
 * courtesy `Auth::check()` already extends to sessions with no version stamp.
 */
final class Sessions
{
    /** This session has been given a row — so a missing row means "ended". */
    public const TRACKED_KEY = 'cms_tracked';

    /** `last_seen_at` is written at most this often, in seconds. */
    private const TOUCH_EVERY = 60;

    /** The SHA-256 of this request's session id — what the table stores. */
    public static function currentHash(): string
    {
        return hash('sha256', (string) session_id());
    }

    /** Give this browser a row. Called right after sign-in regenerates the id. */
    public static function start(int $userId): void
    {
        Database::getInstance()->prepare(
            'INSERT INTO user_sessions (user_id, session_hash, ip, user_agent)
             VALUES (:user, :hash, :ip, :agent)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), last_seen_at = UTC_TIMESTAMP()'
        )->execute([
            ':user'  => $userId,
            ':hash'  => self::currentHash(),
            ':ip'    => ClientIp::get(),
            ':agent' => SecurityLog::agent(),
        ]);
        $_SESSION[self::TRACKED_KEY] = 1;
    }

    /**
     * Keep this browser's row current, once a minute at most. Returns false
     * when the row is gone and the session had one: it was ended elsewhere.
     *
     * @param int|null    $rowId     this session's row, from `Auth::check()`'s join
     * @param string|null $lastSeen  its `last_seen_at`
     */
    public static function confirm(int $userId, ?int $rowId, ?string $lastSeen): bool
    {
        if ($rowId === null) {
            if (!empty($_SESSION[self::TRACKED_KEY])) {
                return false;             // ended elsewhere
            }
            self::start($userId);         // a session from before 062: adopted
            return true;
        }

        if ($lastSeen === null || strtotime($lastSeen . ' UTC') < time() - self::TOUCH_EVERY) {
            Database::getInstance()
                ->prepare('UPDATE user_sessions SET last_seen_at = UTC_TIMESTAMP() WHERE id = :id')
                ->execute([':id' => $rowId]);
        }

        return true;
    }

    /** Forget this browser — at sign-out. */
    public static function end(): void
    {
        Database::getInstance()
            ->prepare('DELETE FROM user_sessions WHERE session_hash = :hash')
            ->execute([':hash' => self::currentHash()]);
        unset($_SESSION[self::TRACKED_KEY]);
    }

    /**
     * Every browser this account is signed in on, most recently active first,
     * each marked `current` when it is the one asking. Rows idle longer than
     * `$idleSeconds` are over — `Auth::check()` would refuse them — and are
     * cleared rather than listed.
     *
     * @return list<array<string, mixed>>
     */
    public static function forUser(int $userId, int $idleSeconds): array
    {
        $pdo = Database::getInstance();
        $pdo->prepare(
            'DELETE FROM user_sessions
              WHERE user_id = :user AND last_seen_at < UTC_TIMESTAMP() - INTERVAL ' . max(60, $idleSeconds) . ' SECOND'
        )->execute([':user' => $userId]);

        $stmt = $pdo->prepare(
            'SELECT id, ip, user_agent, created_at, last_seen_at, session_hash = :hash AS current
               FROM user_sessions WHERE user_id = :user ORDER BY last_seen_at DESC'
        );
        $stmt->execute([':user' => $userId, ':hash' => self::currentHash()]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** End one of this account's browsers. False when the row is not the account's. */
    public static function revoke(int $userId, int $rowId): bool
    {
        $stmt = Database::getInstance()->prepare(
            'DELETE FROM user_sessions WHERE id = :id AND user_id = :user AND session_hash <> :hash'
        );
        $stmt->execute([':id' => $rowId, ':user' => $userId, ':hash' => self::currentHash()]);

        return $stmt->rowCount() === 1;
    }

    /** End every browser this account has — a reset, or an administrator acting on someone else. */
    public static function revokeAll(int $userId): int
    {
        $stmt = Database::getInstance()->prepare('DELETE FROM user_sessions WHERE user_id = :user');
        $stmt->execute([':user' => $userId]);

        return $stmt->rowCount();
    }

    /** End every browser this account has except the one asking. Returns how many. */
    public static function revokeOthers(int $userId): int
    {
        $stmt = Database::getInstance()->prepare(
            'DELETE FROM user_sessions WHERE user_id = :user AND session_hash <> :hash'
        );
        $stmt->execute([':user' => $userId, ':hash' => self::currentHash()]);

        return $stmt->rowCount();
    }

    /** End every browser of every OTHER account — Security's "Ask everyone". Returns how many. */
    public static function revokeEveryoneBut(int $userId): int
    {
        $stmt = Database::getInstance()->prepare('DELETE FROM user_sessions WHERE user_id <> :user');
        $stmt->execute([':user' => $userId]);

        return $stmt->rowCount();
    }
}
