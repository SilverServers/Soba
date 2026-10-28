<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Models\Option;

/**
 * THE PASSWORD RULE — applied to every place a password is set: adding a
 * user, changing one, the first-sign-in screen, and a reset.
 *
 * ── LENGTH AND A BLOCKLIST, NOT A RECIPE ────────────────────────────────────
 * The defaults follow NIST SP 800-63B rather than the 2004 advice: a long
 * minimum, and a refusal of what attackers try first — common passwords,
 * leetspeak spellings of them, a common word with a year and a "!" wrapped
 * round it, keyboard walks, sequences, and your own username. The composition
 * boxes (mixed case, a number, a symbol) exist because some clients' policies
 * demand them; they are off by default, because "Password1!" satisfies all
 * three and "correct horse battery staple" none.
 *
 * ── NOTHING IS SENT ANYWHERE ────────────────────────────────────────────────
 * A check against a breach database over the network sends only five
 * characters of a hash, but it is a request to a third party on every change
 * all the same. The list here ships with the CMS (`common-passwords.txt`) and
 * the checks are local.
 *
 * ── AND OLD PASSWORDS ARE HELD TO IT ────────────────────────────────────────
 * A rule that only applies to passwords set after it was switched on protects
 * nobody who already has one. At sign-in — the one moment the plain password
 * is in hand — a password that breaks the rule sends its owner to choose a new
 * one (`AuthController`).
 */
final class PasswordPolicy
{
    /** Options keys, so the settings screen and this class cannot disagree. */
    public const MIN_KEY      = 'security_password_min';
    public const CASE_KEY     = 'security_password_mixed_case';
    public const DIGIT_KEY    = 'security_password_digit';
    public const SYMBOL_KEY   = 'security_password_symbol';
    public const COMMON_KEY   = 'security_password_common';
    public const PERSONAL_KEY = 'security_password_personal';
    public const EXISTING_KEY = 'security_password_existing';

    public const DEFAULTS = [
        self::MIN_KEY      => '12',
        self::CASE_KEY     => '0',
        self::DIGIT_KEY    => '0',
        self::SYMBOL_KEY   => '0',
        self::COMMON_KEY   => '1',
        self::PERSONAL_KEY => '1',
        self::EXISTING_KEY => '1',
    ];

    /** The range the minimum may be set in. 72 is bcrypt's own limit. */
    public const MIN_RANGE = [8, 64];
    public const MAX_BYTES = 72;

    /** @var array<string, mixed>|null */
    private static ?array $settings = null;

    /** @var array<string, true>|null */
    private static ?array $common = null;

    /**
     * @return array{min: int, mixed_case: bool, digit: bool, symbol: bool, common: bool, personal: bool, existing: bool}
     */
    public static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        $raw = Option::getMany(self::DEFAULTS);
        $min = (string) ($raw[self::MIN_KEY] ?? '12');
        $min = ctype_digit($min) ? (int) $min : 12;

        return self::$settings = [
            'min'        => max(self::MIN_RANGE[0], min(self::MIN_RANGE[1], $min)),
            'mixed_case' => ($raw[self::CASE_KEY] ?? '0') === '1',
            'digit'      => ($raw[self::DIGIT_KEY] ?? '0') === '1',
            'symbol'     => ($raw[self::SYMBOL_KEY] ?? '0') === '1',
            'common'     => ($raw[self::COMMON_KEY] ?? '1') === '1',
            'personal'   => ($raw[self::PERSONAL_KEY] ?? '1') === '1',
            'existing'   => ($raw[self::EXISTING_KEY] ?? '1') === '1',
        ];
    }

    /** Forget what was read this request — after a save. */
    public static function forget(): void
    {
        self::$settings = null;
    }

    /**
     * Why this password may not be used, one sentence each, or [] when it may.
     *
     * @param array{username?: string, email?: string, display_name?: string} $who
     *        the account it would belong to — for the "not your own name" rule
     * @return list<string>
     */
    public static function problems(string $password, array $who = []): array
    {
        $s        = self::settings();
        $problems = [];

        if (mb_strlen($password) < $s['min']) {
            $problems[] = sprintf('Use at least %d characters.', $s['min']);
        }
        if (strlen($password) > self::MAX_BYTES) {
            // bcrypt reads the first 72 bytes and silently ignores the rest,
            // so a longer password would be a shorter one the owner does not
            // know about.
            $problems[] = sprintf('Use at most %d characters.', self::MAX_BYTES);
        }
        if ($s['mixed_case'] && (!preg_match('/\p{Lu}/u', $password) || !preg_match('/\p{Ll}/u', $password))) {
            $problems[] = 'Use both capital and small letters.';
        }
        if ($s['digit'] && !preg_match('/\d/', $password)) {
            $problems[] = 'Include a number.';
        }
        if ($s['symbol'] && !preg_match('/[^\p{L}\p{N}]/u', $password)) {
            $problems[] = 'Include a symbol, such as ! or -.';
        }
        if ($s['common'] && self::isCommon($password)) {
            $problems[] = 'That is one of the passwords everybody tries first.';
        }
        if ($s['personal'] && self::isPersonal($password, $who)) {
            $problems[] = 'That is too close to the account\'s own name or the site\'s.';
        }

        return $problems;
    }

    /** The rule in one line, as the forms state it. */
    public static function summary(): string
    {
        $s     = self::settings();
        $parts = [sprintf('At least %d characters', $s['min'])];
        if ($s['mixed_case']) {
            $parts[] = 'capital and small letters';
        }
        if ($s['digit']) {
            $parts[] = 'a number';
        }
        if ($s['symbol']) {
            $parts[] = 'a symbol';
        }
        $line = implode(', ', $parts) . '.';
        if ($s['common'] || $s['personal']) {
            $line .= ' Not a common password' . ($s['personal'] ? ', and not the account\'s own name' : '') . '.';
        }

        return $line;
    }

    /**
     * Is this one of the passwords attackers try first — or one of those with
     * its letters swapped for look-alikes, digits and symbols wrapped round it,
     * or said twice? Also: a keyboard walk or alphabet run, and anything made
     * of three characters or fewer.
     */
    public static function isCommon(string $password): bool
    {
        $lower = mb_strtolower($password);
        $list  = self::commonList();

        if (isset($list[$lower])) {
            return true;
        }

        // FIND THE WORD UNDER THE DECORATION, in the right order: the digits
        // and symbols wrapped round it come off FIRST, and only then are the
        // look-alike letters undone. The other way round, "password2026" is
        // "passwordzozg" by the time anything looks at it.
        //
        //   "P@$$w0rd2026!"  → p@$$w0rd → password
        //   "$ummer!!2026"   → $ummer   → summer
        //   "2026Password!"  → password
        //   "welcom3"        → welcome        (the whole thing, undone)
        //   "123456!"        → 123456         (symbols off, digits kept)
        $deleet = static fn (string $s): string => strtr($s, [
            '@' => 'a', '4' => 'a', '8' => 'b', '(' => 'c', '3' => 'e', '6' => 'g',
            '1' => 'i', '!' => 'i', '|' => 'l', '0' => 'o', '$' => 's', '5' => 's',
            '7' => 't', '+' => 't',
        ]);
        $letters = static fn (string $s): string => (string) preg_replace('/^[^a-z]+|[^a-z]+$/', '', $s);

        $candidates = [
            $letters($deleet((string) preg_replace('/^[0-9]+|[^a-z]+$/', '', $lower))),
            $letters($deleet($lower)),
            (string) preg_replace('/^[^a-z0-9]+|[^a-z0-9]+$/', '', $lower),
        ];
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            if (isset($list[$candidate])) {
                return true;
            }
            // Said twice or more: "passwordpassword", "monkeymonkey".
            if (preg_match('/^(.{3,}?)\1+$/', $candidate, $m) === 1 && isset($list[$m[1]])) {
                return true;
            }
        }

        // Three different characters or fewer: "aaaaaaaaaaaa", "abababababab".
        if (count(array_unique(mb_str_split($lower))) <= 3) {
            return true;
        }

        // A run along the keyboard or the alphabet, either way round.
        $letters = (string) preg_replace('/[^a-z0-9]/', '', $lower);
        if (strlen($letters) >= 8) {
            $runs = ['01234567890123456789', 'abcdefghijklmnopqrstuvwxyz', 'qwertyuiopasdfghjklzxcvbnm',
                     '1qaz2wsx3edc4rfv5tgb6yhn7ujm8ik9ol0p', 'qazwsxedcrfvtgbyhnujmikolp',
                     '1q2w3e4r5t6y7u8i9o0p', 'zaq1xsw2cde3vfr4bgt5nhy6mju7ki8lo9p0'];
            foreach ($runs as $run) {
                if (str_contains($run, $letters) || str_contains(strrev($run), $letters)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Is this password mostly the account's own name, its address, or the
     * site's name? Take every such word out; if fewer than four LETTERS are
     * left, it was. Letters, because a year is the commonest addition there
     * is and adds nothing: "admin123", "Robin2026" and "example2026"
     * are personal; "editor-pass-1" — the name plus a real word — is not.
     *
     * @param array{username?: string, email?: string, display_name?: string} $who
     * @param string|null $site  the Site title; read from the settings when null
     */
    public static function isPersonal(string $password, array $who, ?string $site = null): bool
    {
        $site  = $site ?? (string) \Cms\SEO\Head::globalTokens()['site_name'];
        $words = [];
        $email = (string) ($who['email'] ?? '');
        foreach ([(string) ($who['username'] ?? ''), (string) ($who['display_name'] ?? ''),
                  $email, explode('@', $email)[0], $site] as $source) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($source)) ?: [] as $word) {
                if (mb_strlen($word) >= 3) {
                    $words[] = $word;
                }
            }
            $whole = mb_strtolower(trim($source));
            if (mb_strlen($whole) >= 3) {
                $words[] = $whole;
            }
        }
        if ($words === []) {
            return false;
        }

        // Longest first, so "examplestore" goes before "store" can split it.
        usort($words, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $left = str_ireplace(array_unique($words), '', mb_strtolower($password));

        return mb_strlen((string) preg_replace('/[^\p{L}]/u', '', $left)) < 4;
    }

    /** @return array<string, true> */
    private static function commonList(): array
    {
        if (self::$common !== null) {
            return self::$common;
        }

        $list = [];
        foreach (file(__DIR__ . '/common-passwords.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $list[mb_strtolower($line)] = true;
            }
        }

        return self::$common = $list;
    }
}
