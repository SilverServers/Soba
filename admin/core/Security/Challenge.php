<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Auth\Auth;
use Cms\Forms\Captcha;
use Cms\Models\Option;

/**
 * THE SIGN-IN PAGE'S CAPTCHA — Security → Blocking → CAPTCHA, on the sign-in
 * and lost-password forms.
 *
 * ── THE BUILT-IN QUESTION, AND ONLY THAT, ON THE SIGN-IN PAGE ───────────────
 * Forms may use hCaptcha or reCAPTCHA with the owner's keys (`Forms\Captcha`).
 * The sign-in page never does: a provider's script would run on the one page
 * where the password is typed, able to read it, for every visitor. The built-in
 * question holds its answer in the session and puts nothing from anywhere else
 * in the page.
 *
 * ── AFTER A FAILED ATTEMPT, BY DEFAULT ──────────────────────────────────────
 * An owner signing in normally never sees it; a script that has already been
 * wrong once from an address answers a question before its next guess counts.
 * "Always" asks at every sign-in; "never" turns it off.
 *
 * ── NEVER ON A DEVELOPMENT TREE ─────────────────────────────────────────────
 * Scripts sign in to a development tree from one address, with no way to
 * answer. One failed sign-in by anybody would put the question in front of
 * every one of them, and only a successful sign-in — which none could then
 * make — would take it away again. A client install is production, and asks.
 */
final class Challenge
{
    public const MODE_KEY = 'security_captcha';

    /** @var array<string, string> mode => what it means, in the order offered */
    public const MODES = [
        'failure' => 'After a failed attempt from the same address',
        'always'  => 'At every sign-in',
        'off'     => 'Never',
    ];

    private static ?string $mode = null;

    public static function mode(): string
    {
        if (self::$mode === null) {
            $mode       = (string) (Option::get(self::MODE_KEY, 'failure') ?? 'failure');
            self::$mode = isset(self::MODES[$mode]) ? $mode : 'failure';
        }

        return self::$mode;
    }

    /** Forget what was read this request — after a save. */
    public static function forget(): void
    {
        self::$mode = null;
    }

    /** Must a sign-in from address `$key` answer the question? */
    public static function required(string $key): bool
    {
        if (!Auth::enforcesPasswordChanges()) {
            return false;
        }

        return match (self::mode()) {
            'always'  => true,
            'failure' => Throttle::failures($key) > 0,
            default   => false,
        };
    }

    /**
     * A new question, when `$key` must answer one — or null.
     *
     * @return array{0: string, 1: string}|null [nonce, question]
     */
    public static function questionFor(string $key): ?array
    {
        return self::required($key) ? Captcha::challenge() : null;
    }

    /** Was the posted answer right? Spent either way. */
    public static function passes(): bool
    {
        return Captcha::passesBuiltin();
    }
}
