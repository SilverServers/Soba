<?php

declare(strict_types=1);

namespace Cms\Helpers;

/**
 * THE SITE'S PEOPLE: the people a byline can
 * name — a site's team, say, listed by the site itself — and the account
 * each is. The site lists them in `people.php`, in the active theme or in
 * `admin/site/templates/` (`Theme::file()`), as `Places` does its places:
 *
 *     return [
 *         'label'  => 'Team member',
 *         'help'   => 'Their byline links to this team page with its photo.',
 *         'people' => [
 *             'dana'  => ['name' => 'Dana Whitfield', 'url' => '/team/dana/', 'photo' => '/img/dana.jpg'],
 *             'team'  => ['name' => 'The whole team', 'url' => '/about/#team', 'group' => true],
 *         ],
 *     ];
 *
 * An account is linked on the admin's Users screen (`users.person_key`,
 * unique: one person, one account); the site's templates ask
 * `People::find($user['person_key'])` for the page and photo. Without a
 * people.php there is no one to link, and the admin shows no field. A
 * people.php that fails is logged, and `error()` says why.
 *
 * The list is the SITE'S, not a store the admin keeps: the admin links an
 * account to a person, and that link it can set, change and clear.
 */
final class People
{
    /** @var array<string, array{name: string, url?: string, photo?: string, group?: bool}>|null */
    private static ?array $people = null;
    private static bool $loaded = false;
    private static ?string $error = null;
    private static string $label = 'Person';
    private static string $help = '';

    /** Does this site list any people? */
    public static function declared(): bool
    {
        return self::load() !== null;
    }

    /** @return array<string, array{name: string, url?: string, photo?: string, group?: bool}> */
    public static function all(): array
    {
        return self::load() ?? [];
    }

    /** @return array{name: string, url?: string, photo?: string, group?: bool}|null */
    public static function find(?string $key): ?array
    {
        return $key !== null && $key !== '' ? (self::all()[$key] ?? null) : null;
    }

    /** The field's label, as the site names its people ('Team member'). */
    public static function label(): string
    {
        self::load();
        return self::$label;
    }

    /** The field's help line, as the site says what the link does, or ''. */
    public static function help(): string
    {
        self::load();
        return self::$help;
    }

    /** Why the list could not be read, or null. */
    public static function error(): ?string
    {
        self::load();
        return self::$error;
    }

    /** Forget the list — in tests. */
    public static function reset(): void
    {
        self::$people = null;
        self::$loaded = false;
        self::$error  = null;
        self::$label  = 'Person';
        self::$help   = '';
    }

    /** @return array<string, array{name: string, url?: string, photo?: string, group?: bool}>|null */
    private static function load(): ?array
    {
        if (self::$loaded) {
            return self::$people;
        }
        self::$loaded = true;

        $file = Theme::file('people.php');
        if ($file === null) {
            return null;
        }
        try {
            $raw = (static fn (string $__file): mixed => require $__file)($file);
        } catch (\Throwable $e) {
            error_log('People: ' . $file . ' failed: ' . $e->getMessage());
            self::$error = 'The list of people could not be read (' . $e->getMessage() . ').';
            return self::$people = [];
        }
        if (!is_array($raw) || !is_array($raw['people'] ?? null)) {
            return null;
        }
        if (is_string($raw['label'] ?? null) && trim($raw['label']) !== '') {
            self::$label = trim($raw['label']);
        }
        if (is_string($raw['help'] ?? null)) {
            self::$help = trim($raw['help']);
        }

        // A key fits the column (100) and an address; a person has a name.
        // Anything else is left out, as Places leaves out a malformed place.
        $people = [];
        foreach ($raw['people'] as $key => $p) {
            if (!is_string($key) || preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $key) !== 1
                || !is_array($p) || trim((string) ($p['name'] ?? '')) === '') {
                continue;
            }
            $person = ['name' => trim((string) $p['name'])];
            foreach (['url', 'photo'] as $k) {
                if (is_string($p[$k] ?? null) && trim($p[$k]) !== '') {
                    $person[$k] = trim($p[$k]);
                }
            }
            if (!empty($p['group'])) {
                $person['group'] = true;
            }
            $people[$key] = $person;
        }
        return self::$people = $people;
    }
}
