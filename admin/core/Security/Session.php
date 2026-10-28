<?php

declare(strict_types=1);

namespace Cms\Security;

/**
 * When a PHP session runs — kept by this name, because a
 * site's own tools call `Session::begin(true)`, and `Site\Tools` opens a
 * tool's session through it.
 *
 *   begin($admin)  at bootstrap: start one for the admin, or resume the one a
 *                  visitor already carries (their cookie says they have one)
 *   ensure()       start one now, if none is running and it still can
 *
 * The front controller starts a session on every request. Making an anonymous
 * public page start none would move every lazy caller — CSRF, the captcha, the
 * password gate, the shop, and the sign-in pages wherever the sign-in address
 * has moved — onto `ensure()`.
 */
final class Session
{
    /** At bootstrap: resume a session the visitor already has, or start one for the admin. */
    public static function begin(bool $adminRequest): void
    {
        if ($adminRequest || isset($_COOKIE[session_name()])) {
            self::ensure();
        }
    }

    /** Start the session now if it is not running. Returns whether it is running. */
    public static function ensure(): bool
    {
        $status = session_status();
        if ($status === PHP_SESSION_ACTIVE) {
            return true;
        }
        if ($status === PHP_SESSION_DISABLED || headers_sent()) {
            return false;
        }

        if (!session_start()) {
            return false;
        }
        \Cms\Compat\OldNames::carrySession();

        return true;
    }
}
