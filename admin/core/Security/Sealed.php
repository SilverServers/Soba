<?php

declare(strict_types=1);

namespace Cms\Security;

use RuntimeException;

/**
 * A SECRET THE DATABASE KEEPS BUT CANNOT READ — AES-256-GCM, keyed from
 * `app_secret` in config.php, for what must be stored but must not be of use
 * to whoever copies the database: an authenticator app's secret, today.
 *
 * A password is stored as a hash because it only ever has to be CHECKED. An
 * app's secret has to be USED — the server works out the same six digits the
 * phone does — so it is sealed instead. A leaked database, or a backup of one,
 * then carries no second factor: the key is in config.php, not beside the data.
 *
 * ── THE KEY IS THE SITE'S OWN ───────────────────────────────────────────────
 * Installing a site means writing a long random `app_secret` of its own into
 * config.php. An install still on config.example.php's placeholder, or with
 * none, would be sealing with a key printed in the source, so `seal()` refuses
 * (`ready()` says whether it can).
 * Changing `app_secret` afterwards unseals nothing: every authenticator app is
 * set up again, and until then its owner signs in with a backup code, an email
 * code, or `php admin/bin/security.php two-step off <user>`.
 *
 * Stored as `v2:` + base64(nonce ‖ tag ‖ ciphertext); the version leaves room
 * to change the scheme without guessing what a stored value is.
 */
final class Sealed
{
    private const PLACEHOLDER = 'change-me-to-a-random-secret-string';

    /**
     * FIXED INPUTS TO THE CIPHER AND THE KEYED HASH. Every value sealed or
     * hashed depends on them byte for byte: a different one opens no
     * authenticator secret and matches no backup code. They changed once,
     * with the stored form's prefix (`v2:`): migration 126 cleared what was
     * kept the earlier way, and each account sets its authenticator up again.
     * A new scheme gets a new prefix again, never a quiet edit here.
     */
    private const AAD       = 'cms';
    private const KEY_INFO  = 'cms sealed v2';
    private const HMAC_INFO = 'cms hmac v2';

    /** The stored form's version, ahead of its base64. */
    private const PREFIX = 'v2:';

    /** Can anything be sealed on this install? */
    public static function ready(): bool
    {
        return self::secret() !== null;
    }

    public static function seal(string $plain): string
    {
        $secret = self::secret();
        if ($secret === null) {
            throw new RuntimeException('config.php has no app_secret of its own.');
        }

        return self::sealWith($secret, $plain);
    }

    /** The plain value, or null when it was sealed under another key or has been altered. */
    public static function open(string $sealed): ?string
    {
        $secret = self::secret();

        return $secret === null ? null : self::openWith($secret, $sealed);
    }

    /** seal(), under a given secret — what the checks call. */
    public static function sealWith(string $secret, string $plain): string
    {
        $nonce  = random_bytes(12);
        $tag    = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::keyFor($secret), OPENSSL_RAW_DATA, $nonce, $tag, self::AAD, 16);
        if ($cipher === false) {
            throw new RuntimeException('Could not seal.');
        }

        return self::PREFIX . base64_encode($nonce . $tag . $cipher);
    }

    /** open(), under a given secret. */
    public static function openWith(string $secret, string $sealed): ?string
    {
        if (!str_starts_with($sealed, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($sealed, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }

        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::keyFor($secret), OPENSSL_RAW_DATA,
            substr($raw, 0, 12), substr($raw, 12, 16), self::AAD);

        return $plain === false ? null : $plain;
    }

    /**
     * A keyed hash for what only ever has to be CHECKED but is too short to
     * hash bare — a backup code has ~50 bits, which a copy of the database
     * could simply try. Under app_secret it cannot be tried without config.php.
     */
    public static function hmac(string $data): string
    {
        $secret = self::secret();
        if ($secret === null) {
            throw new RuntimeException('config.php has no app_secret of its own.');
        }

        return hash_hmac('sha256', $data, hash_hkdf('sha256', $secret, 32, self::HMAC_INFO));
    }

    private static function keyFor(string $secret): string
    {
        return hash_hkdf('sha256', $secret, 32, self::KEY_INFO);
    }

    private static function secret(): ?string
    {
        static $secret = false;
        if ($secret === false) {
            $config = defined('ROOT_PATH') ? \Cms\Site\ConfigFile::read(ROOT_PATH . '/config/config.php') : null;
            $value  = is_array($config) ? trim((string) ($config['app_secret'] ?? '')) : '';
            $secret = ($value === '' || $value === self::PLACEHOLDER) ? null : $value;
        }

        return $secret;
    }
}
