<?php

declare(strict_types=1);

namespace Cms\Security;

use InvalidArgumentException;

/**
 * AN AUTHENTICATOR APP'S SIX DIGITS — RFC 6238 (TOTP) over RFC 4226 (HOTP),
 * the scheme Google Authenticator, Microsoft Authenticator, Authy, 1Password
 * and every other app read out of the same QR code. SHA-1, six digits, thirty
 * seconds: what every app assumes when the otpauth:// link says nothing else,
 * so the link says nothing else.
 *
 * ── ONE STEP EITHER SIDE ────────────────────────────────────────────────────
 * A phone's clock and the server's drift. The code for the step before and the
 * step after the current one are accepted too (RFC 6238 §5.2 recommends no
 * more than one), which is three right answers in a million at any moment.
 *
 * ── A CODE WORKS ONCE ───────────────────────────────────────────────────────
 * The step a code was accepted for is stored (`user_factors.last_step`); a code
 * for that step or an earlier one is refused. Without that, anyone who reads a
 * code over a shoulder has up to ninety seconds to use it as well.
 */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;
    public const WINDOW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new secret: 20 random bytes — RFC 4226's 160 bits — in base32. */
    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** The 30-second step `$time` falls in. */
    public static function step(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /** The code for one step (RFC 4226 §5.3, dynamic truncation). */
    public static function code(string $secret, int $step): string
    {
        $hmac   = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hmac[19]) & 0x0f;
        $number = ((ord($hmac[$offset]) & 0x7f) << 24)
                | (ord($hmac[$offset + 1]) << 16)
                | (ord($hmac[$offset + 2]) << 8)
                | ord($hmac[$offset + 3]);

        return str_pad((string) ($number % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The step `$code` was made for — within one step of now and later than
     * `$lastStep` — or null when it is wrong, out of date or already used.
     * Every candidate is compared, whatever matched first, so the time taken
     * does not say which step was right.
     */
    public static function verify(string $secret, string $code, ?int $lastStep = null, ?int $time = null): ?int
    {
        $code = (string) preg_replace('/\s+/', '', $code);
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }

        $now   = self::step($time);
        $found = null;
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $step = $now + $i;
            if (hash_equals(self::code($secret, $step), $code) && ($lastStep === null || $step > $lastStep)) {
                $found = $step;
            }
        }

        return $found;
    }

    /**
     * The otpauth:// link an app reads from the QR code (Google's "Key Uri
     * Format"). The site's name is the issuer, so the app lists the account
     * under the site, never under the product's name.
     */
    public static function uri(string $issuer, string $account, string $secret): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer);
    }

    /** The secret as it is typed by hand: groups of four. */
    public static function grouped(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    /** RFC 4648 base32, unpadded — what authenticator apps take. */
    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    /** Base32 back to bytes. Spaces, dashes, padding and lower case are forgiven. */
    public static function base32Decode(string $text): string
    {
        $text = strtoupper((string) preg_replace('/[\s=-]+/', '', $text));

        $bits = '';
        foreach (str_split($text) as $char) {
            $value = strpos(self::ALPHABET, $char);
            if ($value === false) {
                throw new InvalidArgumentException('Not base32: ' . $char);
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
