<?php

declare(strict_types=1);

namespace Cms\Site;

/**
 * WHICH COPY OF THE SITE THIS IS: config.php's `app_env`, read one way
 * everywhere. Trimmed and in lower case; left out or empty, it is production,
 * the safe reading for a live site whose config.php never named it.
 *
 *   production    an error is logged and never shown; an account that owes a
 *                 new password chooses one at its next sign-in; Google's tags
 *                 are sent (`Site\Google`)
 *   development   errors are left as PHP is set to show them; a seeded password
 *                 is a fixture to sign in with by script, not a hole; Google's
 *                 tags print inside a comment, unsent
 *   any other     staging, test and the like: errors hidden and passwords
 *                 enforced, as in production; Google's tags unsent, as in
 *                 development; and the admin names it in its top bar
 */
final class Environment
{
    public const PRODUCTION  = 'production';
    public const DEVELOPMENT = 'development';

    private static ?string $name = null;

    /** An `app_env` value as this reads it. */
    public static function read(mixed $value): string
    {
        $name = is_string($value) ? strtolower(trim($value)) : '';

        return $name !== '' ? $name : self::PRODUCTION;
    }

    /** This copy's, from config.php, read once: production where it cannot be read. */
    public static function name(): string
    {
        if (self::$name === null) {
            $config     = defined('ROOT_PATH') ? ConfigFile::read(ROOT_PATH . '/config/config.php') : null;
            self::$name = self::read(is_array($config) ? ($config['app_env'] ?? null) : null);
        }

        return self::$name;
    }

    public static function isProduction(): bool
    {
        return self::name() === self::PRODUCTION;
    }

    public static function isDevelopment(): bool
    {
        return self::name() === self::DEVELOPMENT;
    }
}
