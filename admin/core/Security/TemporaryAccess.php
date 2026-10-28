<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Database\Database;
use Cms\Models\User;
use PDO;

/**
 * TEMPORARY ACCESS — a sign-in link to share with someone, with a lifespan
 * and a limit on its uses.
 *
 * An administrator makes one on Security → Temporary access, for someone who
 * needs in for a while: a developer, a designer, a contractor. It makes an
 * ACCOUNT OF ITS OWN, so what that person does is theirs in every log, never
 * the owner's. That account is:
 *
 *   - never an administrator. An administrator can make accounts, move the
 *     sign-in page and switch protections off, and is who the two-step policy
 *     is for. An editor or an author.
 *   - signed in ONLY by its link. Its password is random and never told, and
 *     the password form, a reset link and the passwordless ways all refuse it,
 *     so its lifespan and its uses mean what they say.
 *   - not asked for two-step sign-in by the policy: the link is what it has.
 *   - SUSPENDED (migration 067) when its time runs out or an administrator
 *     revokes it: every browser signed out, every way in shut, its work kept
 *     and attributed. Renewing it restores it with a new link.
 *
 * Out of uses, the link stops working; a browser already signed in keeps
 * going until the time runs out.
 *
 * ── THE LINK ────────────────────────────────────────────────────────────────
 * At the sign-in address (`?action=access&key=`), wherever it lives. It opens
 * a page with ONE button, and each press spends one use by a conditional
 * update — a mail scanner that opens it first spends nothing (`Passwordless`
 * has why). Only its SHA-256 is kept, so it is shown ONCE, when it is made; a
 * lost one is replaced (New link), never shown again.
 */
final class TemporaryAccess
{
    /** The roles it may have, in the Users screen's order. Never an administrator — see above. */
    public const ROLES = ['editor' => 'Editor', 'author' => 'Author'];

    /** @var array<string, int> each unit in seconds */
    public const UNITS = ['hours' => 3600, 'days' => 86400];

    /** @var array{0: int, 1: int} an hour (for a quick look) to 365 days */
    public const LIFESPAN = [3600, 365 * 86400];

    /** @var array{0: int, 1: int} */
    public const USES = [1, 100];

    public const DEFAULT_DAYS = 7;
    public const DEFAULT_USES = 10;

    public const MESSAGE = 'This account signs in only by its temporary access link.';

    /** Accounts known to be temporary, this request. */
    private static array $known = [];

    /** The lifespan in seconds of `$count` `$unit`, or null when it is not one this allows. */
    public static function lifespan(string $count, string $unit): ?int
    {
        $count = trim($count);
        if (!isset(self::UNITS[$unit]) || preg_match('/^\d{1,4}$/', $count) !== 1) {
            return null;
        }
        $seconds = (int) $count * self::UNITS[$unit];

        return $seconds >= self::LIFESPAN[0] && $seconds <= self::LIFESPAN[1] ? $seconds : null;
    }

    /** A number of uses this allows, or null. */
    public static function uses(string $typed): ?int
    {
        $typed = trim($typed);
        if (preg_match('/^\d{1,3}$/', $typed) !== 1) {
            return null;
        }
        $n = (int) $typed;

        return $n >= self::USES[0] && $n <= self::USES[1] ? $n : null;
    }

    /**
     * A username for the account, from its email address: the part before
     * the @, made safe — and never one of the names every script tries first,
     * nor one an account has. Nobody types it; it names the account in logs.
     */
    public static function username(string $email, callable $taken): string
    {
        $local = strstr($email, '@', true);
        $base  = strtolower((string) preg_replace('/[^A-Za-z0-9._-]+/', '', $local === false ? $email : $local));
        $base = trim(substr($base, 0, 40), '._-');
        if ($base === '') {
            $base = 'temporary';
        }
        for ($n = 1; $n <= 50; $n++) {
            $name = $n === 1 ? $base : $base . '-' . $n;
            if (!Usernames::blocked($name) && !$taken($name)) {
                return $name;
            }
        }

        return 'temporary-' . bin2hex(random_bytes(4));
    }

    /**
     * Make one: an account and its link. Returns the link's token — once; it
     * is not kept. The controller has checked the address, the role, the
     * lifespan and the uses.
     *
     * @return array{user_id: int, token: string}
     */
    public static function create(string $email, string $name, string $role, int $seconds, int $maxUses, int $by): array
    {
        if (!isset(self::ROLES[$role])) {
            throw new \InvalidArgumentException('A temporary account is an editor or an author.');
        }
        $username = self::username($email, static fn (string $u): bool => User::usernameExists($u));
        $display  = trim($name) !== '' && mb_strtolower(trim($name)) !== $username ? trim($name) : null;
        $userId   = User::create([
            'username'     => $username,
            'display_name' => $display,
            'email'        => $email,
            // Random, and never told: the account signs in only by its link.
            'password'     => bin2hex(random_bytes(32)),
            'role'         => $role,
        ]);

        $token = bin2hex(random_bytes(32));
        Database::getInstance()->prepare(
            'INSERT INTO temporary_access (user_id, token_hash, max_uses, expires_at, created_by)
             VALUES (:u, :h, :m, UTC_TIMESTAMP() + INTERVAL :s SECOND, :b)'
        )->execute([':u' => $userId, ':h' => hash('sha256', $token), ':m' => $maxUses, ':s' => $seconds, ':b' => $by]);
        self::$known[$userId] = true;

        SecurityLog::record(SecurityLog::ACCESS_GIVEN, $username, $userId, self::terms($maxUses, $seconds));

        return ['user_id' => $userId, 'token' => $token];
    }

    /**
     * Renew an access whose time ran out, or that was revoked — or replace
     * the terms of one still running: a new link, the uses counted from
     * nothing, the time from now. A suspended account is restored.
     */
    public static function renew(int $userId, int $seconds, int $maxUses, int $by): ?string
    {
        $token = bin2hex(random_bytes(32));
        $stmt  = Database::getInstance()->prepare(
            'UPDATE temporary_access
                SET token_hash = :h, max_uses = :m, uses = 0, expires_at = UTC_TIMESTAMP() + INTERVAL :s SECOND,
                    ended_at = NULL, ended_why = NULL, last_used_at = NULL
              WHERE user_id = :u'
        );
        $stmt->execute([':h' => hash('sha256', $token), ':m' => $maxUses, ':s' => $seconds, ':u' => $userId]);
        if ($stmt->rowCount() !== 1) {
            return null;
        }
        Accounts::restore($userId, $by);
        SecurityLog::record(SecurityLog::ACCESS_GIVEN, self::name($userId), $userId, 'renewed · ' . self::terms($maxUses, $seconds));

        return $token;
    }

    /**
     * A new link for an access still running, replacing the old one — a link
     * lost or sent to the wrong person. The token, once; null when the access
     * has ended or its uses are spent (renew it instead).
     */
    public static function newLink(int $userId): ?string
    {
        $token = bin2hex(random_bytes(32));
        $stmt  = Database::getInstance()->prepare(
            'UPDATE temporary_access SET token_hash = :h
              WHERE user_id = :u AND ended_at IS NULL AND expires_at > UTC_TIMESTAMP() AND uses < max_uses'
        );
        $stmt->execute([':h' => hash('sha256', $token), ':u' => $userId]);

        return $stmt->rowCount() === 1 ? $token : null;
    }

    /** The link for a token — at the sign-in page, wherever it lives. */
    public static function link(string $token): string
    {
        return LoginUrl::url() . '?action=access&key=' . $token;
    }

    /**
     * The access a link opens while it still can — or null. Read only:
     * opening the link spends nothing.
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
            'SELECT t.user_id, t.token_hash, t.uses, t.max_uses, t.expires_at, u.username
               FROM temporary_access t JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = :h AND t.ended_at IS NULL AND t.expires_at > UTC_TIMESTAMP()
                AND t.uses < t.max_uses AND u.suspended_at IS NULL LIMIT 1'
        );
        $stmt->execute([':h' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false && hash_equals((string) $row['token_hash'], $hash) ? $row : null;
    }

    /**
     * Spend one use: the account's id — or null when the link can no longer
     * open it. Two presses racing for the last use cannot both have it.
     */
    public static function spend(string $token): ?int
    {
        $row = self::find($token);
        if ($row === null) {
            return null;
        }
        $stmt = Database::getInstance()->prepare(
            'UPDATE temporary_access SET uses = uses + 1, last_used_at = UTC_TIMESTAMP()
              WHERE user_id = :u AND token_hash = :h AND ended_at IS NULL
                AND expires_at > UTC_TIMESTAMP() AND uses < max_uses'
        );
        $stmt->execute([':u' => (int) $row['user_id'], ':h' => hash('sha256', $token)]);

        return $stmt->rowCount() === 1 ? (int) $row['user_id'] : null;
    }

    /**
     * End it: its time is up (`$by` null) or an administrator revoked it. The
     * account is suspended — every browser signed out — and dated when the
     * time ran out, not when someone noticed. False when it had ended already.
     */
    public static function end(int $userId, ?int $by): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'UPDATE temporary_access
                SET ended_at = LEAST(expires_at, UTC_TIMESTAMP()), ended_why = :why
              WHERE user_id = :u AND ended_at IS NULL'
        );
        $stmt->execute([':why' => $by === null ? 'expired' : 'revoked', ':u' => $userId]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $at = $pdo->prepare('SELECT ended_at FROM temporary_access WHERE user_id = :u');
        $at->execute([':u' => $userId]);
        Accounts::suspend($userId, 'temporary', $by, (int) strtotime((string) $at->fetchColumn() . ' UTC'));

        return true;
    }

    /**
     * End every access whose time is up — before a screen that shows
     * accounts, as the inactivity rule is swept. One indexed query when there
     * is nothing to do.
     *
     * @return int how many it ended
     */
    public static function sweep(): int
    {
        $ids = Database::getInstance()->query(
            'SELECT user_id FROM temporary_access WHERE ended_at IS NULL AND expires_at <= UTC_TIMESTAMP() ORDER BY id LIMIT 100'
        )->fetchAll(PDO::FETCH_COLUMN);
        $n = 0;
        foreach ($ids as $id) {
            $n += self::end((int) $id, null) ? 1 : 0;
        }

        return $n;
    }

    /** Is this a temporary account? Asked often in one request, so remembered for it. */
    public static function is(int $userId): bool
    {
        if (!array_key_exists($userId, self::$known)) {
            $stmt = Database::getInstance()->prepare('SELECT 1 FROM temporary_access WHERE user_id = :u');
            $stmt->execute([':u' => $userId]);
            self::$known[$userId] = $stmt->fetchColumn() !== false;
        }

        return self::$known[$userId];
    }

    /**
     * One account's access, whatever its state — or null when it is not
     * temporary.
     *
     * @return array<string, mixed>|null
     */
    public static function of(int $userId): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT t.*, b.username AS created_by_name FROM temporary_access t
               LEFT JOIN users b ON b.id = t.created_by
              WHERE t.user_id = :u'
        );
        $stmt->execute([':u' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Every access, running ones first, each with its account.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return Database::getInstance()->query(
            'SELECT t.*, u.username, u.display_name, u.email, u.role, b.username AS created_by_name
               FROM temporary_access t
               JOIN users u ON u.id = t.user_id
               LEFT JOIN users b ON b.id = t.created_by
              ORDER BY t.ended_at IS NOT NULL, t.expires_at DESC, t.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Where an access stands: 'active', 'used' (no uses left, time left),
     * 'expired' or 'revoked'. Pure: the checks hand it a row and the time.
     *
     * @param array<string, mixed> $row  uses, max_uses, expires_at, ended_at, ended_why
     */
    public static function state(array $row, int $now): string
    {
        if (($row['ended_at'] ?? null) !== null) {
            return ($row['ended_why'] ?? '') === 'revoked' ? 'revoked' : 'expired';
        }
        if ((int) strtotime((string) $row['expires_at'] . ' UTC') <= $now) {
            return 'expired';
        }

        return (int) $row['uses'] >= (int) $row['max_uses'] ? 'used' : 'active';
    }

    /** "10 uses, 7 days" — the terms, for the log. */
    public static function terms(int $maxUses, int $seconds): string
    {
        $span = $seconds % 86400 === 0
            ? ($seconds / 86400) . ' day' . ($seconds === 86400 ? '' : 's')
            : intdiv($seconds, 3600) . ' hour' . ($seconds === 3600 ? '' : 's');

        return $maxUses . ' use' . ($maxUses === 1 ? '' : 's') . ', ' . $span;
    }

    private static function name(int $userId): string
    {
        return (string) (User::find($userId)['username'] ?? '');
    }
}
