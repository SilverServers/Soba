<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Auth\Auth;
use Cms\Database\Writes;
use Cms\Ui\AdminMenu;

/**
 * WHO CHANGED WHAT — a trail of the changes made in the admin.
 *
 * ── ONE PLACE, NOT A CALL IN EVERY CONTROLLER ───────────────────────────────
 * Every change an administrator makes is a POST to the admin or the API, and
 * every change writes to the database through one connection
 * (`Database\Connection`, which notes each table written in `Writes`). So at
 * the end of such a POST, if anything but bookkeeping was written, ONE row goes
 * into the security log: who, on which screen, what was posted to, which
 * tables. A controller cannot forget to say — none of them is asked.
 *
 * ── SECRETS ARE MASKED BY CONSTRUCTION ──────────────────────────────────────
 * No field's value is ever read: a password changed, an SMTP password saved, a
 * webhook secret set — each is a screen, a path and a table name. There is
 * nothing to mask because nothing is taken.
 *
 * ── "WHICH SCREEN" IS THE SIDEBAR'S OWN NAME ────────────────────────────────
 * Forms post to an action (`/admin/security/notifications`) but are filled in
 * on a screen (`/admin/security/activity?tab=notifications`). The screen is
 * the form's Referer when it is this site's — the admin sends Referrer-Policy:
 * same-origin, so it always is — named as the sidebar names it ("Security ›
 * Activity log"), with its tab. Otherwise the action is named.
 *
 * The name is the row the sidebar LIGHTS on that page, asked of the sidebar,
 * not worked out again here. A copy of its matching (the longest row address
 * the page begins with) would call every page without a row of its own
 * "Dashboard", because `/admin` begins them all. With no row lit, the group
 * that is ("Appearance"); with neither, the page's own path.
 *
 * Already in the log, better, and so left out: signing in and out,
 * re-confirming, choosing a password, setting up two-step sign-in.
 */
final class Audit
{
    /** Tables whose writes are bookkeeping, not something an owner changed. */
    public const BOOKKEEPING = ['security_log', 'user_sessions', 'migrations'];

    /** Paths the security log already records with their own words. */
    public const ELSEWHERE = ['/admin/login', '/admin/logout', '/admin/confirm', '/admin/choose-password', '/admin/two-step'];

    private static ?string $path = null;

    /**
     * A POST to the admin or the API: watch it. Writes made before this — the
     * migrations a request runs first — are not its own and are forgotten.
     */
    public static function watch(string $path): void
    {
        if (self::$path !== null || self::isElsewhere($path, LoginUrl::path())) {
            return;
        }
        self::$path = $path;
        Writes::reset();
        register_shutdown_function([self::class, 'finish']);
    }

    /** Record the change, if there was one. Never throws. */
    public static function finish(): void
    {
        try {
            $tables = array_values(array_diff(Writes::tables(), self::BOOKKEEPING));
            $user   = Auth::user();
            if ($tables === [] || self::$path === null || $user === null) {
                return;
            }
            $screen = self::screen(
                self::$path,
                (string) ($_SERVER['HTTP_REFERER'] ?? ''),
                static fn (string $page): array => AdminMenu::sidebarSpec($page)
            );
            SecurityLog::record(
                SecurityLog::CHANGE,
                (string) $user['username'],
                (int) $user['id'],
                $screen . ' · POST ' . self::$path . ' · ' . implode(', ', $tables)
            );
        } catch (\Throwable $e) {
            error_log('CMS audit: ' . $e->getMessage());
        }
    }

    /** Is `$path` one the security log records itself? Pure — `$signIn` is `LoginUrl::path()`, wherever it has moved. */
    public static function isElsewhere(string $path, string $signIn): bool
    {
        $path = '/' . trim($path, '/');
        if ($path === '/' . trim($signIn, '/')) {
            return true;
        }
        foreach (self::ELSEWHERE as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The screen a change was made on, as the sidebar names it: the row it
     * lights on that page, else the group it lights, else the page's path.
     * Pure — the checks hand it a sidebar.
     *
     * @param callable(string): array<string, mixed> $sidebarFor  `AdminMenu::sidebarSpec()` for a page
     */
    public static function screen(string $path, string $referer, callable $sidebarFor): string
    {
        $from  = $path;
        $query = [];
        if ($referer !== '') {
            $ref  = parse_url($referer);
            $self = (string) ($_SERVER['HTTP_HOST'] ?? '');
            if (is_array($ref) && isset($ref['path']) && ($self === '' || strcasecmp((string) ($ref['host'] ?? '') . (isset($ref['port']) ? ':' . $ref['port'] : ''), $self) === 0)) {
                $from = (string) $ref['path'];
                parse_str((string) ($ref['query'] ?? ''), $query);
            }
        }
        $from = AdminMenu::pathOf($from);

        // The sidebar lights one row per page; should two ever be lit, the
        // longer address is the nearer one.
        $row   = null;
        $group = '';
        foreach ((array) ($sidebarFor($from)['nav'] ?? []) as $section) {
            if ($group === '' && ($section['active'] ?? false) === true) {
                $group = (string) ($section['label'] ?? '');
            }
            foreach ((array) ($section['items'] ?? []) as $item) {
                if (($item['active'] ?? false) !== true) {
                    continue;
                }
                $href = (string) parse_url((string) ($item['href'] ?? ''), PHP_URL_PATH);
                if ($row === null || strlen($href) > strlen($row['href'])) {
                    $row = ['href' => $href, 'section' => (string) ($section['label'] ?? ''), 'item' => (string) ($item['full'] ?? $item['label'] ?? '')];
                }
            }
        }

        $name = match (true) {
            $row !== null => $row['section'] !== '' && $row['section'] !== $row['item'] ? $row['section'] . ' › ' . $row['item'] : $row['item'],
            $group !== '' => $group,
            default       => $from,
        };
        $tab  = is_string($query['tab'] ?? null) ? preg_replace('/[^a-z0-9-]/', '', strtolower($query['tab'])) : '';

        return $tab !== '' ? $name . ' (' . $tab . ')' : $name;
    }

    /**
     * A logged change, split back out of its `detail`.
     *
     * @return array{0: string, 1: string, 2: string}  screen, what was posted to, tables
     */
    public static function parse(string $detail): array
    {
        $parts = explode(' · ', $detail, 3);

        return [(string) ($parts[0] ?? ''), (string) ($parts[1] ?? ''), (string) ($parts[2] ?? '')];
    }
}
