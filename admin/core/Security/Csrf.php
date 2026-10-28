<?php

declare(strict_types=1);

namespace Cms\Security;

class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        // A public form that carries one (the shop's) starts the visitor's
        // session here, before its page is sent (`Session`). Too late —
        // a whole-page theme drawing the form after its output began — and the
        // token cannot be kept, so the form cannot post: said in the log.
        if (!Session::ensure()) {
            error_log('Csrf: a form token was asked for after the page began, with no session to keep it in');
        }
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(self::token()) . '">';
    }

    public static function metaTag(): string
    {
        return '<meta name="csrf-token" content="' . htmlspecialchars(self::token()) . '">';
    }

    public static function validate(): bool
    {
        $token = $_POST['_csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? '';

        return self::matches(is_string($token) ? $token : '');
    }

    /**
     * Is `$token` this session's token? For the one place a token travels in a
     * URL rather than a form — the sign-out link, which is a GET.
     */
    public static function matches(string $token): bool
    {
        if ($token === '' || empty($_SESSION[self::SESSION_KEY])) {
            return false;
        }

        return hash_equals($_SESSION[self::SESSION_KEY], $token);
    }

    public static function regenerate(): void
    {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }
}
