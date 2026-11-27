<?php

declare(strict_types=1);

namespace Cms\Security;

/**
 * THE ADDRESS A REQUEST CAME FROM — the key every lockout and every row of the
 * sign-in log is filed under.
 *
 * ── REMOTE_ADDR, UNLESS A PROXY YOU NAMED SAYS OTHERWISE ────────────────────
 * The only safe default. `X-Forwarded-For` is a header the CLIENT writes:
 * trusted blindly, an attacker changes it on every request and every attempt
 * lands on a fresh, never-locked address, which turns the attempt limit into
 * decoration. So a forwarded header is read only when the connection itself
 * comes from a proxy listed in config.php:
 *
 *     'trusted_proxies'      => ['173.245.48.0/20', '10.0.0.5'],
 *     'trusted_proxy_header' => 'CF-Connecting-IP',    // or 'X-Forwarded-For'
 *
 * Config, not a setting: it describes the HOSTING, which the site owner neither
 * chooses nor sees, and a wrong value is a hole rather than a preference.
 *
 * ── A LIST IS READ FROM THE RIGHT ───────────────────────────────────────────
 * Every hop APPENDS to `X-Forwarded-For`, so in `<typed>, <client>, <proxy>`
 * the left-most entry is whatever the client chose to send. The address that
 * counts is the right-most one that is not one of your own proxies.
 *
 * ── AN IPv6 CLIENT IS ITS /64 ───────────────────────────────────────────────
 * One IPv6 connection is routinely handed a whole /64: 18 quintillion
 * addresses, any of which it may use for the next request. Counted per address,
 * a lockout there stops nothing. `key()` files IPv4 by address and IPv6 by its
 * /64, and `get()` stores IPv6 fully expanded so the log can be counted by that
 * prefix with a plain LIKE.
 */
final class ClientIp
{
    private static ?string $resolved = null;

    /** @var array{0: list<string>, 1: string}|null  [proxies, header] */
    private static ?array $config = null;

    /**
     * This request's client address — IPv4 dotted, IPv6 fully expanded
     * (`2001:0db8:0000:…`), or `0.0.0.0` when there is nothing usable.
     */
    public static function get(): string
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        [$proxies, $header] = self::config();

        return self::$resolved = self::resolve($_SERVER, $proxies, $header);
    }

    /**
     * THE RULE ITSELF, with nothing read from the request or the disk — which
     * address does a request with these server variables count as, behind
     * these proxies? `get()` is this, applied to the real request and cached.
     *
     * @param array<string, mixed> $server   `$_SERVER`, or a forged one
     * @param list<string>         $proxies  addresses / CIDR ranges
     */
    public static function resolve(array $server, array $proxies, string $header): string
    {
        $remote = self::normalise((string) ($server['REMOTE_ADDR'] ?? ''));

        $ip = $remote;
        if ($remote !== '' && $header !== '' && $proxies !== [] && self::inAny($remote, $proxies)) {
            $forwarded = self::fromHeader($server, $header, $proxies);
            if ($forwarded !== '') {
                $ip = $forwarded;
            }
        }

        return $ip !== '' ? $ip : '0.0.0.0';
    }

    /**
     * What a lockout is filed under: the address itself for IPv4, the /64 for
     * IPv6 (`2001:0db8:0001:0002::/64`). See the class header.
     */
    public static function key(?string $ip = null): string
    {
        $ip = $ip ?? self::get();

        return str_contains($ip, ':') ? substr($ip, 0, 19) . '::/64' : $ip;
    }

    /**
     * A LIKE pattern matching every logged address that falls under `key()`.
     * IPv4 matches itself; IPv6 matches its expanded /64 prefix.
     */
    public static function likePattern(string $key): string
    {
        return str_ends_with($key, '::/64') ? substr($key, 0, 19) . ':%' : $key;
    }

    /** For display: IPv6 back to its short form, IPv4 unchanged. */
    public static function display(string $ip): string
    {
        if (str_ends_with($ip, '::/64')) {
            $packed = @inet_pton(substr($ip, 0, 19) . ':0:0:0:0');
            return $packed !== false ? (string) inet_ntop($packed) . '/64' : $ip;
        }
        if (str_contains($ip, ':')) {
            $packed = @inet_pton($ip);
            return $packed !== false ? (string) inet_ntop($packed) : $ip;
        }

        return $ip;
    }

    /** Is `$ip` inside `$range` — a single address or CIDR, IPv4 or IPv6? */
    public static function inRange(string $ip, string $range): bool
    {
        $range = trim($range);
        if ($range === '') {
            return false;
        }

        [$subnet, $bits] = str_contains($range, '/')
            ? explode('/', $range, 2)
            : [$range, null];

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton(trim($subnet));
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $max  = 8 * strlen($ipBin);
        $bits = $bits === null ? $max : (int) $bits;
        if ($bits < 0 || $bits > $max) {
            return false;
        }

        $mask = str_repeat("\xff", intdiv($bits, 8));
        if ($bits % 8 !== 0) {
            $mask .= chr((0xff << (8 - $bits % 8)) & 0xff);
        }
        $mask = str_pad($mask, strlen($ipBin), "\x00");

        return ($ipBin & $mask) === ($subnetBin & $mask);
    }

    /** @param list<string> $ranges */
    public static function inAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One address, cleaned: brackets and ports removed, IPv4-mapped IPv6 back to
     * IPv4, IPv6 expanded to eight four-digit groups. '' when it is not an
     * address at all.
     */
    public static function normalise(string $ip): string
    {
        $ip = trim($ip);

        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $ip, $m) === 1) {
            $ip = $m[1];                                   // [2001:db8::1]:443
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $ip, $m) === 1) {
            $ip = $m[1];                                   // 203.0.113.9:51234
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return '';
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\x00", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));   // ::ffff:198.51.100.7
        }

        return strlen($packed) === 4
            ? (string) inet_ntop($packed)
            : implode(':', str_split(bin2hex($packed), 4));
    }

    /**
     * @param array<string, mixed> $server
     * @param list<string>         $proxies
     */
    private static function fromHeader(array $server, string $header, array $proxies): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
        $raw = (string) ($server[$key] ?? '');
        if ($raw === '') {
            return '';
        }

        foreach (array_reverse(explode(',', $raw)) as $part) {
            $candidate = self::normalise($part);
            if ($candidate === '') {
                // Garbage in a header we were told to trust: stop rather than
                // skip past it to something further left, which is the part a
                // client controls.
                return '';
            }
            if (!self::inAny($candidate, $proxies)) {
                return $candidate;
            }
        }

        return '';
    }

    /** @return array{0: list<string>, 1: string} */
    private static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $config  = defined('ROOT_PATH') ? \Cms\Site\ConfigFile::read(ROOT_PATH . '/config/config.php') : null;
        $proxies = is_array($config) ? ($config['trusted_proxies'] ?? []) : [];
        if (is_string($proxies)) {
            $proxies = explode(',', $proxies);
        }
        $proxies = array_values(array_filter(array_map('trim', is_array($proxies) ? $proxies : []), 'strlen'));
        $header  = is_array($config) ? trim((string) ($config['trusted_proxy_header'] ?? '')) : '';

        return self::$config = [$proxies, $header];
    }

    // ----------------------------------------------------------------
    // A proxy nobody named
    // ----------------------------------------------------------------

    /** Where a forwarded request from an address `trusted_proxies` does not name is noted (`unnamed()`). */
    public const UNNAMED_KEY = 'proxy_unnamed_seen';

    /** The headers a proxy writes the visitor's address in, by their `$_SERVER` keys. */
    private const FORWARDED = ['HTTP_X_FORWARDED_FOR' => 'X-Forwarded-For', 'HTTP_FORWARDED' => 'Forwarded', 'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP'];

    /**
     * A REQUEST THAT CAME THROUGH A PROXY config.php does not name, noted for
     * System health: one carrying a forwarded header from an address
     * `trusted_proxies` does not list. Behind such a proxy every visitor has
     * the proxy's address, so the sign-in limit locks everyone out at once,
     * and nothing says why. The header is never trusted for it, only noted:
     * each address at most once an hour, the last five kept, and on a GET
     * only, so a form's POST is not read as that form's change (`Audit`).
     *
     * @param array<string, mixed> $server `$_SERVER`
     */
    public static function noteUnnamed(array $server): void
    {
        if (($server['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }
        $header = null;
        foreach (self::FORWARDED as $key => $name) {
            if (trim((string) ($server[$key] ?? '')) !== '') {
                $header = $name;
                break;
            }
        }
        $remote = self::normalise((string) ($server['REMOTE_ADDR'] ?? ''));
        [$proxies] = self::config();
        if ($header === null || $remote === '' || ($proxies !== [] && self::inAny($remote, $proxies))) {
            return;
        }
        try {
            $seen = self::unnamed();
            if (time() - (int) ($seen[$remote]['at'] ?? 0) < 3600) {
                return;
            }
            $seen[$remote] = ['at' => time(), 'header' => $header];
            uasort($seen, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);
            \Cms\Models\Option::set(self::UNNAMED_KEY, (string) json_encode(array_slice($seen, 0, 5, true)));
        } catch (\Throwable $e) {
            error_log('CMS: a forwarded request could not be noted: ' . $e->getMessage());
        }
    }

    /**
     * The addresses noted by `noteUnnamed()`, newest first.
     *
     * @return array<string, array{at: int, header: string}>
     */
    public static function unnamed(): array
    {
        $raw = json_decode((string) \Cms\Models\Option::get(self::UNNAMED_KEY, '{}'), true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $ip => $row) {
            if (is_string($ip) && is_array($row) && is_int($row['at'] ?? null)) {
                $out[$ip] = ['at' => $row['at'], 'header' => (string) ($row['header'] ?? '')];
            }
        }

        return $out;
    }
}
