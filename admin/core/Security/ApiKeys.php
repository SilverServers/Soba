<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Database\Database;
use PDO;

/**
 * THE KEYS ANOTHER PROGRAM REACHES THE SHOP'S API WITH: bookkeeping, a
 * shipping service, a till at a market stall.
 *
 * ── SHOWN ONCE, KEPT AS A HASH ──────────────────────────────────────────────
 * A key is made on Settings → API, named for what will use it, and shown in
 * full that once; only its SHA-256 is kept, with its last eight characters to
 * tell keys apart. A lost key is revoked and a new one made, as a password
 * would be.
 *
 * ── READ, OR READ AND WRITE ─────────────────────────────────────────────────
 * Bookkeeping reads orders; a till also sets counts and a shipping service
 * marks orders sent. A key that only reads is refused anything else.
 *
 * ── SENT IN A HEADER ────────────────────────────────────────────────────────
 * `X-Api-Key: cms_…` on each request, which reaches PHP on any server, or
 * `Authorization: Bearer cms_…`, which reaches it where the server passes that
 * header on (a new install's `.htaccess` asks it to; a site updated from before
 * keeps the rewrite rules it has) and the site has no password of its own in
 * front to take it. Never in an address, so it is not written into the logs an
 * address passes through.
 */
final class ApiKeys
{
    private const PREFIX = 'cms_';

    /**
     * A new key: the key itself, to be shown once, and its row.
     *
     * @return array{key: string, id: int}
     */
    public static function create(string $name, bool $canWrite, string $by): array
    {
        $key  = self::PREFIX . bin2hex(random_bytes(24));
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO api_keys (name, key_hash, hint, can_write, created_by) VALUES (:n, :h, :t, :w, :b)'
        );
        $stmt->execute([
            ':n' => mb_substr(trim($name) !== '' ? trim($name) : 'A program', 0, 120),
            ':h' => hash('sha256', $key),
            ':t' => substr($key, -8),
            ':w' => $canWrite ? 1 : 0,
            ':b' => mb_substr($by, 0, 120),
        ]);

        return ['key' => $key, 'id' => (int) Database::getInstance()->lastInsertId()];
    }

    /**
     * The key a request carries, if it is one: its row, its last use noted.
     *
     * @return array<string, mixed>|null
     */
    public static function fromRequest(): ?array
    {
        $key = self::sent();
        if ($key === null) {
            return null;
        }
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM api_keys WHERE key_hash = :h LIMIT 1');
        $stmt->execute([':h' => hash('sha256', $key)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $db->prepare('UPDATE api_keys SET last_used_at = NOW() WHERE id = :id')->execute([':id' => (int) $row['id']]);

        return $row;
    }

    /** The key a request sends, in either header, whether or not it is one; null for none. */
    public static function sent(): ?string
    {
        $bearer = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if (preg_match('/^Bearer\s+(' . self::PREFIX . '[a-f0-9]{48})$/', $bearer, $m)) {
            return $m[1];
        }
        $plain = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));

        return preg_match('/^' . self::PREFIX . '[a-f0-9]{48}$/', $plain) ? $plain : null;
    }

    /** @return list<array<string, mixed>> every key, newest first */
    public static function all(): array
    {
        return Database::getInstance()->query('SELECT * FROM api_keys ORDER BY created_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** A key revoked: it answers nothing from now. */
    public static function revoke(int $id): void
    {
        Database::getInstance()->prepare('DELETE FROM api_keys WHERE id = :id')->execute([':id' => $id]);
    }
}
