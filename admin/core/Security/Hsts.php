<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Models\Option;

/**
 * HTTPS ONLY — HTTP Strict Transport Security, as a setting. Off until
 * switched on.
 *
 * Once a browser has seen the header it refuses this site over plain HTTP for
 * the whole of `max-age`, and nothing the site says afterwards shortens that
 * for the browsers that already have it. On a host whose certificate lapses,
 * or a subdomain that never had one, that turns a warning a visitor can click
 * past into a wall. So it is an owner's decision, said plainly where it is
 * made; it is never sent by default, and never with `preload` unless asked —
 * a preload list takes months to leave.
 *
 * Sent on every response (`www/index.php`) when the site's own address is
 * HTTPS; a browser ignores it over HTTP anyway.
 */
final class Hsts
{
    public const ON_KEY         = 'security_hsts_on';
    public const MAX_AGE_KEY    = 'security_hsts_max_age';
    public const SUBDOMAINS_KEY = 'security_hsts_subdomains';
    public const PRELOAD_KEY    = 'security_hsts_preload';

    /** @var array<int, string> how long, in seconds — a day to try it, up to two years */
    public const AGES = [
        86400    => 'A day',
        604800   => 'A week',
        2592000  => '30 days',
        15768000 => '6 months',
        31536000 => 'A year',
        63072000 => 'Two years',
    ];

    public const DEFAULT_AGE = 86400;

    /** What a browser's preload list asks for: a year at least, and the subdomains. */
    public const PRELOAD_MIN_AGE = 31536000;

    /** @return array{on: bool, max_age: int, subdomains: bool, preload: bool} */
    public static function settings(): array
    {
        $raw = Option::getMany([self::ON_KEY => '0', self::MAX_AGE_KEY => (string) self::DEFAULT_AGE, self::SUBDOMAINS_KEY => '0', self::PRELOAD_KEY => '0']);
        $age = (int) $raw[self::MAX_AGE_KEY];

        return [
            'on'         => $raw[self::ON_KEY] === '1',
            'max_age'    => isset(self::AGES[$age]) ? $age : self::DEFAULT_AGE,
            'subdomains' => $raw[self::SUBDOMAINS_KEY] === '1',
            'preload'    => $raw[self::PRELOAD_KEY] === '1',
        ];
    }

    /**
     * The header's value for these settings — or null when it is not sent.
     * Pure: the checks hand it the settings and the site's address.
     *
     * @param array{on: bool, max_age: int, subdomains: bool, preload: bool} $s
     */
    public static function value(array $s, string $siteUrl): ?string
    {
        if (!$s['on'] || !str_starts_with(strtolower($siteUrl), 'https://')) {
            return null;
        }
        $value = 'max-age=' . $s['max_age'];
        if ($s['subdomains']) {
            $value .= '; includeSubDomains';
        }
        // Preload only as a preload list accepts it, or not at all.
        if ($s['preload'] && $s['subdomains'] && $s['max_age'] >= self::PRELOAD_MIN_AGE) {
            $value .= '; preload';
        }

        return $value;
    }

    /** On this response, when switched on. */
    public static function send(): void
    {
        try {
            $value = self::value(self::settings(), defined('BASE_URL') ? (string) BASE_URL : '');
        } catch (\Throwable) {
            return;   // before the database exists (install), there is nothing to send
        }
        if ($value !== null) {
            header('Strict-Transport-Security: ' . $value);
        }
    }
}
