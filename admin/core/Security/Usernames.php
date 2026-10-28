<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Models\Option;

/**
 * USERNAMES NOBODY SHOULD HAVE.
 *
 * Half of a password guess is the name, and the name every script tries first
 * is `admin`. The list starts `admin, test, user, admin123, test123, username`
 * and adds the other names every script's word list starts with. The list is
 * the owner's to edit (Security → Login protection → Common usernames).
 *
 * WHERE IT APPLIES: adding a user, and the first-sign-in screen — which asks an
 * account that already has one of these names (the `admin` every install is
 * seeded with) to choose another. A rule the seeded account were exempt from
 * would exempt the one account every install has.
 */
final class Usernames
{
    public const ENABLED_KEY = 'security_usernames_enabled';
    public const LIST_KEY    = 'security_usernames_list';
    /** An address that signs in as one of these names is locked out at once. */
    public const TRAP_KEY    = 'security_usernames_trap';

    /** The names every script's word list starts with. */
    public const DEFAULT_LIST = [
        'admin', 'test', 'user', 'admin123', 'test123', 'username',
        'administrator', 'root', 'demo', 'guest', 'webmaster', 'sysadmin',
        'superuser', 'owner', 'manager', 'support', 'info', 'office',
        'login', 'wpadmin', 'cms',
    ];

    /** @var array{enabled: bool, list: list<string>, trap: bool}|null */
    private static ?array $settings = null;

    /** @return array{enabled: bool, list: list<string>, trap: bool} */
    public static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        $raw  = Option::getMany([self::ENABLED_KEY => '1', self::LIST_KEY => null, self::TRAP_KEY => '1']);
        $list = $raw[self::LIST_KEY] === null ? self::DEFAULT_LIST : self::parse((string) $raw[self::LIST_KEY]);

        return self::$settings = [
            'enabled' => ($raw[self::ENABLED_KEY] ?? '1') === '1',
            'list'    => $list,
            'trap'    => ($raw[self::TRAP_KEY] ?? '1') === '1',
        ];
    }

    /** Forget what was read this request — after a save. */
    public static function forget(): void
    {
        self::$settings = null;
    }

    /** Is this name one nobody may have? Compared ignoring case and spaces. */
    public static function blocked(string $username): bool
    {
        $s = self::settings();

        return $s['enabled'] && in_array(mb_strtolower(trim($username)), $s['list'], true);
    }

    /**
     * Is a sign-in as `$username` a TRAP — a name on the list that no account
     * has? Then the lock-out is instant: nobody types `admin` at a site whose
     * accounts cannot be called that, except a script's word list.
     *
     * NEVER A NAME AN ACCOUNT HAS. The seeded `admin` keeps its name until its
     * first sign-in renames it (migration 061), and a development tree keeps
     * it for good; trapping those would lock the owner out of their own door.
     */
    public static function trapped(string $username): bool
    {
        // Production only: scripts sign in to a development tree from one
        // address, and one wrong name would shut every one of them out.
        return \Cms\Auth\Auth::enforcesPasswordChanges()
            && self::settings()['trap'] && self::blocked($username)
            && !\Cms\Models\User::usernameExists(trim($username));
    }

    /**
     * The list as typed — commas, spaces or new lines between names — cleaned
     * to lowercase, unique, in the order given.
     *
     * @return list<string>
     */
    public static function parse(string $typed): array
    {
        $names = preg_split('/[\s,]+/', mb_strtolower($typed)) ?: [];

        return array_values(array_unique(array_filter($names, static fn (string $n): bool => $n !== '')));
    }
}
