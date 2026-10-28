<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Models\Option;

/**
 * WHO MAY REACH THE DOOR AT ALL — a list of addresses and ranges, and what
 * the list means.
 *
 *   off     nobody is turned away for their address
 *   block   the addresses on the list are turned away
 *   allow   ONLY the addresses on the list get through
 *
 * It applies to the sign-in page, wherever it lives, and to every admin
 * address (`www/index.php`, before anything else is routed). The public site
 * is not affected: shutting a whole address out of a business's website is a
 * firewall's job.
 *
 * ── ADDRESSES AND RANGES, IPv6 INCLUDED ─────────────────────────────────────
 * One per line: `203.0.113.9`, `198.51.100.0/24`, `2001:db8::1`,
 * `2001:db8::/48`. Ranges matter: an IPv6 address on its own is nearly
 * useless — the same household has a whole /64.
 *
 * ── THE OWNER CANNOT SHUT THEIR OWN ADDRESS OUT FROM THE SCREEN ─────────────
 * A save that would turn away the address making it is refused, and says
 * which address that is (`SecurityController`). If it happens anyway — the
 * office's address changes — `php admin/bin/security.php addresses off` is the
 * way back in, from the server.
 *
 * Turned away: 403, and the owner's message, on a page with nothing else on it.
 */
final class AddressRules
{
    public const MODE_KEY    = 'security_addresses_mode';
    public const LIST_KEY    = 'security_addresses_list';
    public const MESSAGE_KEY = 'security_addresses_message';

    /** @var array<string, string> mode => what it means */
    public const MODES = [
        'off'   => 'Off — no address is turned away',
        'block' => 'Turn away the addresses on the list',
        'allow' => 'Let in only the addresses on the list',
    ];

    public const DEFAULT_MESSAGE = 'Access from your address is not allowed.';

    /** How many entries a list may hold — a list is not a firewall. */
    public const MAX_ENTRIES = 500;

    /** @var array{mode: string, list: list<string>, message: string}|null */
    private static ?array $settings = null;

    /** @return array{mode: string, list: list<string>, message: string} */
    public static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        $raw  = Option::getMany([self::MODE_KEY => 'off', self::LIST_KEY => '', self::MESSAGE_KEY => '']);
        $mode = (string) ($raw[self::MODE_KEY] ?? 'off');

        return self::$settings = [
            'mode'    => isset(self::MODES[$mode]) ? $mode : 'off',
            'list'    => self::parse((string) ($raw[self::LIST_KEY] ?? ''))[0],
            'message' => trim((string) ($raw[self::MESSAGE_KEY] ?? '')),
        ];
    }

    /** Forget what was read this request — after a save. */
    public static function forget(): void
    {
        self::$settings = null;
    }

    /**
     * The list as typed — one entry per line, or separated by commas or spaces —
     * split into the entries that are addresses or ranges and the ones that are
     * not. Valid entries are returned in a single written form, so the same
     * address typed two ways is one entry.
     *
     * @return array{0: list<string>, 1: list<string>} [valid, not valid]
     */
    public static function parse(string $typed): array
    {
        $valid = [];
        $bad   = [];
        foreach (preg_split('/[\s,]+/', trim($typed)) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }
            $clean = self::clean($entry);
            if ($clean === null) {
                $bad[] = $entry;
            } elseif (!in_array($clean, $valid, true)) {
                $valid[] = $clean;
            }
        }

        return [$valid, $bad];
    }

    /**
     * One entry, written one way — or null when it is neither an address nor a
     * range. A range keeps its prefix; its base address is written out in full
     * (`ClientIp::normalise()`), so `2001:DB8::/32` and `2001:db8:0::/32` match.
     */
    public static function clean(string $entry): ?string
    {
        $entry = trim($entry);
        $bits  = null;
        if (str_contains($entry, '/')) {
            [$entry, $bits] = explode('/', $entry, 2);
            if (preg_match('/^\d{1,3}$/', $bits) !== 1) {
                return null;
            }
        }

        // An address WITH A PORT is not what a list of addresses means, and
        // normalise() would quietly drop the port and accept it. Only the two
        // forms that can carry one are refused: `[v6]:port` and `v4:port` —
        // a bare IPv6 address legitimately ends in `:digits`.
        if (str_contains($entry, '[') || preg_match('/^\d{1,3}(?:\.\d{1,3}){3}:\d+$/', $entry) === 1) {
            return null;
        }

        $ip = ClientIp::normalise($entry);
        if ($ip === '') {
            return null;
        }
        // Written back the short way (`2001:db8::/48`), as an owner types it —
        // normalise() spells IPv6 out in eight groups, which is a key, not a list.
        if (str_contains($ip, ':')) {
            $ip = (string) inet_ntop((string) inet_pton($ip));
        }
        if ($bits === null) {
            return $ip;
        }

        $max = str_contains($ip, ':') ? 128 : 32;

        return (int) $bits <= $max ? $ip . '/' . (int) $bits : null;
    }

    /** Is `$ip` turned away by the rules in force (or by `$settings`, when given)? */
    public static function refuses(string $ip, ?array $settings = null): bool
    {
        $s = $settings ?? self::settings();
        if ($s['mode'] === 'off') {
            return false;
        }

        $listed = ClientIp::inAny(ClientIp::normalise($ip), $s['list']);

        return $s['mode'] === 'block' ? $listed : !$listed;
    }

    /** The owner's message, or the default. */
    public static function message(): string
    {
        $message = self::settings()['message'];

        return $message !== '' ? $message : self::DEFAULT_MESSAGE;
    }

    /** 403, the message, nothing else — and nothing after it. */
    public static function turnAway(): never
    {
        http_response_code(403);
        header('Cache-Control: no-store');

        // The page uses h(). A site's tool is turned away before the front
        // controller loads the template functions (`Security\Door`), and the
        // request ends here, so its own icon() never meets these.
        require_once ROOT_PATH . '/core/Ui/globals.php';
        $message = self::message();
        require ROOT_PATH . '/admin/templates/blocked.php';
        exit;
    }
}
