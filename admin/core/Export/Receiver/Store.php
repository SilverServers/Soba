<?php

declare(strict_types=1);

namespace Cms\Export\Receiver;

/**
 * WHAT A STATIC COPY'S FORM HANDLER REMEMBERS, in files under its `data/`
 * folder: the copy has no database.
 *
 *   t/       each time-trap token a received message carried, and how many
 *            spam posts carried it — the guard's replay rule
 *            (`FormSubmission::byToken()`, `spamWithToken()`)
 *   r/       when each address last sent, and when the rate limit kept one —
 *            the guard's rate limit (`receivedFromSince()`, `rateKeptSince()`);
 *            the file is named by a keyed hash of the address, never the
 *            address
 *   c/       each built-in captcha question answered (`Forms\Captcha`'s
 *            `captcha_spent`): one answer per question
 *   spam/    what the guard kept as suspicious, for a person to look at, as
 *            the site's Spam view keeps it; never mailed
 *   unsent/  a message the host's mail program refused, so it is not lost
 *   tmp/     a visitor's files while their message is sent, then deleted
 *
 * A kept message is a visitor's words, so `spam/` and `unsent/` are written
 * as PHP files that exit at once: a server that serves the folder despite its
 * `.htaccess` runs them and shows nothing. Their names are random.
 *
 * Old entries go on one post in fifty (`purge()`): the copy has no cron.
 */
final class Store
{
    private static string $dir = '';

    public static function open(string $dir): void
    {
        self::$dir = rtrim($dir, '/');
        foreach (['t', 'r', 'c', 'spam', 'unsent', 'tmp'] as $sub) {
            if (!is_dir(self::$dir . '/' . $sub)) {
                @mkdir(self::$dir . '/' . $sub, 0770, true);
            }
        }
    }

    // ----------------------------------------------------------------
    // Tokens
    // ----------------------------------------------------------------

    /** Did a received message already carry this token? */
    public static function receivedWith(string $hash): bool
    {
        return self::valid($hash) && is_file(self::$dir . '/t/' . $hash . '.received');
    }

    /** How many kept spam posts carried this token. */
    public static function spamWith(string $hash): int
    {
        return self::valid($hash) ? (int) @file_get_contents(self::$dir . '/t/' . $hash . '.spam') : 0;
    }

    /** A message was received: its token is spent, and its address has sent one more. */
    public static function received(?string $hash, string $ip): void
    {
        if ($hash !== null && self::valid($hash)) {
            @file_put_contents(self::$dir . '/t/' . $hash . '.received', (string) time(), LOCK_EX);
        }
        self::note($ip, 'received');
    }

    /** A post kept as spam: its token counted, and a rate-limit keep noted. */
    public static function spam(?string $hash, string $ip, string $reason): void
    {
        if ($hash !== null && self::valid($hash)) {
            $file = self::$dir . '/t/' . $hash . '.spam';
            $fh   = @fopen($file, 'c+');
            if ($fh !== false && flock($fh, LOCK_EX)) {
                $n = (int) stream_get_contents($fh);
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, (string) ($n + 1));
                flock($fh, LOCK_UN);
            }
            if ($fh !== false) {
                fclose($fh);
            }
        }
        if (str_starts_with($reason, 'rate limit')) {
            self::note($ip, 'rate');
        }
    }

    // ----------------------------------------------------------------
    // Addresses
    // ----------------------------------------------------------------

    /** How many `$kind` (received, rate) from `$ip` in the last `$minutes`. */
    public static function count(string $ip, string $kind, int $minutes): int
    {
        $file = self::addressFile($ip);
        if ($file === null || !is_file($file)) {
            return 0;
        }
        $since = time() - max(1, $minutes) * 60;
        $n = 0;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            [$at, $what] = array_pad(explode(' ', $line, 2), 2, '');
            if ($what === $kind && (int) $at > $since) {
                $n++;
            }
        }
        return $n;
    }

    private static function note(string $ip, string $kind): void
    {
        $file = self::addressFile($ip);
        if ($file !== null) {
            @file_put_contents($file, time() . ' ' . $kind . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    private static function addressFile(string $ip): ?string
    {
        if ($ip === '') {
            return null;
        }
        $secret = (string) (Handler::config()['app_secret'] ?? '');
        return self::$dir . '/r/' . hash_hmac('sha256', $ip, $secret);
    }

    // ----------------------------------------------------------------
    // The built-in captcha
    // ----------------------------------------------------------------

    /** Note a question as answered; false when it already was. */
    public static function spend(string $nonce): bool
    {
        $fh = @fopen(self::$dir . '/c/' . hash('sha256', $nonce), 'x');
        if ($fh === false) {
            return false;
        }
        fclose($fh);
        return true;
    }

    // ----------------------------------------------------------------
    // Kept messages and files
    // ----------------------------------------------------------------

    /** Keep a record — `spam` or `unsent` — for a person to read. */
    public static function keep(string $kind, array $record): void
    {
        if (!in_array($kind, ['spam', 'unsent'], true)) {
            return;
        }
        $name = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.php';
        @file_put_contents(
            self::$dir . '/' . $kind . '/' . $name,
            "<?php exit; ?>\n" . json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            LOCK_EX
        );
    }

    /** Where a visitor's file waits while their message is sent. */
    public static function tmpDir(): string
    {
        return self::$dir . '/tmp';
    }

    /**
     * Old entries, gone, on one post in fifty: spent tokens, addresses and
     * questions after three days (a token lasts two hours, a question two),
     * files left by a failed send after a day, kept spam after thirty days.
     */
    public static function purge(): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }
        $now = time();
        foreach (['t' => 3 * 86400, 'r' => 3 * 86400, 'c' => 3 * 86400, 'tmp' => 86400, 'spam' => 30 * 86400] as $sub => $age) {
            foreach (glob(self::$dir . '/' . $sub . '/*') ?: [] as $file) {
                if (is_file($file) && filemtime($file) < $now - $age) {
                    @unlink($file);
                }
            }
        }
    }

    private static function valid(string $hash): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $hash) === 1;
    }
}
