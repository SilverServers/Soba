<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\Auth\Auth;
use Cms\Helpers\Url;
use Cms\Security\Audit;
use Cms\Security\Door;
use Cms\Security\Session;

/**
 * A SITE'S OWN TOOLS — what a site runs beside the CMS, behind the same
 * sign-in, that is not a CMS feature (an image-prompt
 * studio, say, or newsletter pictures). Their code and their list are the
 * site's; this class is the one door, so an update never has to know them.
 *
 *   admin/config/admin-tools.php   the list: `label`, `path`, `icon`, the `role`
 *                                  it is for (the administrators when it names
 *                                  none), and `place` — one row each, in the
 *                                  sidebar's Tools group after the CMS's own, or
 *                                  with `'place' => 'top'` among the lead rows
 *                                  under Dashboard
 *   admin/site/tools/{path}.php    the tool's entry: site-owned, never shipped,
 *                                  never touched by an update
 *   /{admin}/tools/{path}[/…]      where it answers — every address under it
 *                                  goes to its entry, which serves what it will
 *
 * ── WHY THE FRONT CONTROLLER HANDS IT OVER EARLY ────────────────────────────
 * A tool brings its own `icon()` and `partial()`, and so does the CMS, for its
 * templates; two in one request is a fatal "cannot redeclare". So the tool is
 * handed over after the database and the session cookie are set up and BEFORE
 * the CMS's template functions are loaded (`www/index.php`), and the request
 * ends with it. A tool carrying its own copy of the front controller's
 * bootstrap instead would stop seeing the sign-in as soon as an update changed
 * how sessions are kept (they are kept in the database).
 *
 * ── THE ADMIN'S DOOR FIRST (`Security\Door`) ────────────────────────────────
 * Leaving the front controller early must not mean leaving its door behind:
 * a tool answers with the admin's headers (never framed by another site,
 * never in a Referer), an address Security → Blocking turns away is turned
 * away here too, and a POST is one line in Activity log → Changes. Without
 * these, a tool would be the one admin page another site could frame.
 *
 * ── THE SIGN-IN, AS FOR ANY ADMIN SCREEN — AND THE ROLE THE ROW NAMES ──────
 * A tool is the administrators' (`ROLE`) unless its row names another role —
 * `'role' => 'editor'` for a studio the editors use — and the sidebar shows it
 * to exactly the roles that may open it. Reached by its address, any other
 * session gets what the role check gives any admin screen.
 *   a page          `Auth::requireRole()`: signed out, turned away exactly as
 *                   any admin address is (a 404 while the sign-in page is
 *                   hidden); an owed password or two-step setup, its screen;
 *                   another role, the dashboard
 *   ?do=…           a tool's own action from an open page: signed out, a JSON
 *                   401 `{"ok":false,"error":"signin"}` it can read; another
 *                   role, a 403 `{"ok":false,"error":"role"}`
 *   /assets/…       read-only session (no lock: a page's dozen parallel asset
 *                   requests must not queue on it); signed out or another
 *                   role, a bare 404
 *
 * A tool's path may not be one of the CMS's own Tools pages (`CORE`).
 *
 * ── WHERE IT SITS: `place` ──────────────────────────────────────────────────
 * Under Tools, by default: a studio or a report is something staff go to now
 * and then. But a site can be built around one screen of its own — an
 * applications desk where its staff read and book every application, say —
 * and filed under Tools that screen is buried. `'place' =>
 * 'top'` puts its row among the lead rows, under Dashboard; its address, door
 * and role are unchanged. Any other place is said in the log and taken as
 * Tools, so a typo never hides a tool.
 */
final class Tools
{
    /** The CMS's own pages under /admin/tools/ — never a site tool's path. */
    public const CORE = ['import-export', 'import', 'redirects', 'system-health'];

    /** Who may use a site's tool whose row names no role: the administrators. */
    public const ROLE = 'admin';

    /** Where a row may sit: the Tools group (the default), or the lead rows under Dashboard. */
    public const PLACES = ['tools', 'top'];

    /** @var array<string, array{label: string, path: string, icon: string, role: string, place: string, entry: string}>|null */
    private static ?array $all = null;

    /**
     * The site's tools whose entry exists, by path, in the list's order (`rows()`);
     * a row with no entry file is left out too, said in the log.
     *
     * @return array<string, array{label: string, path: string, icon: string, role: string, place: string, entry: string}>
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $file = defined('ROOT_PATH') ? ROOT_PATH . '/config/admin-tools.php' : '';
        $list = ($file !== '' && is_file($file)) ? (static fn (string $__f): mixed => require $__f)($file) : [];

        $tools = [];
        foreach (self::rows(is_array($list) ? $list : []) as $path => $tool) {
            $entry = ROOT_PATH . '/site/tools/' . $path . '.php';
            if (!is_file($entry)) {
                error_log('CMS site tools: "' . $path . '" has no entry at admin/site/tools/' . $path . '.php');
                continue;
            }
            $tools[$path] = $tool + ['entry' => $entry];
        }

        return self::$all = $tools;
    }

    /**
     * The rows of admin/config/admin-tools.php that make a tool, by path. A row
     * without a label, with a path that is not a plain name or is one of the
     * CMS's own pages, or naming a role there is none of, is left out — said in
     * the log, since the row in the list is the site saying it should be there.
     * A place there is none of is said in the log too, and the row kept, under
     * Tools. Pure: the caller hands it the rows.
     *
     * @param array<mixed> $list
     * @return array<string, array{label: string, path: string, icon: string, role: string, place: string}>
     */
    public static function rows(array $list): array
    {
        $rows = [];
        foreach ($list as $row) {
            $row   = is_array($row) ? $row : [];
            $path  = trim((string) ($row['path'] ?? ''), '/');
            $label = trim((string) ($row['label'] ?? ''));
            $role  = (string) ($row['role'] ?? self::ROLE);
            if ($label === '' || preg_match('/^[a-z0-9][a-z0-9-]{0,49}$/', $path) !== 1 || in_array($path, self::CORE, true)
                || !isset(Auth::ROLE_LEVELS[$role])) {
                error_log('CMS site tools: a row in admin/config/admin-tools.php is left out (label "' . $label . '", path "' . $path . '", role "' . $role . '")');
                continue;
            }
            $place = (string) ($row['place'] ?? 'tools');
            if (!in_array($place, self::PLACES, true)) {
                error_log('CMS site tools: "' . $path . '" names a place there is none of ("' . $place . '"); it sits under Tools');
                $place = 'tools';
            }
            $rows[$path] = ['label' => $label, 'path' => $path, 'icon' => (string) ($row['icon'] ?? ''), 'role' => $role, 'place' => $place];
        }

        return $rows;
    }

    /**
     * The front controller's hand-over. Returns at once when the request is
     * not for a site tool; otherwise runs it, and the request ends here.
     */
    public static function dispatch(): void
    {
        $path   = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $base   = defined('BASE_PATH') ? rtrim((string) BASE_PATH, '/') : '';
        $prefix = $base . '/' . ADMIN_URL_SLUG . '/tools/';
        if (!str_starts_with($path, $prefix)) {
            return;
        }
        $rest = substr($path, strlen($prefix));
        $name = explode('/', $rest, 2)[0];
        $tool = self::all()[$name] ?? null;
        if ($tool === null) {
            return;
        }
        $sub   = substr($rest, strlen($name));
        $asset = str_starts_with($sub, '/assets/');

        Door::everywhere();
        Door::adminHeaders();
        Door::checkAddress();
        // Watched before anything can write or record, as the front controller
        // does it: a POST that changed something is one row, at shutdown.
        if (!$asset && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Audit::watch('/admin/tools/' . $tool['path'] . $sub);
        }

        if ($asset) {
            if (isset($_COOKIE[session_name()])) {
                session_start(['read_and_close' => true]);
                \Cms\Compat\OldNames::carrySession();
            }
            if (!Auth::check() || !Auth::hasRole($tool['role'])) {
                self::notFound();
            }
        } else {
            Session::begin(true);
            if (isset($_GET['do'])) {
                $refused = !Auth::check() ? [401, 'signin'] : (!Auth::hasRole($tool['role']) ? [403, 'role'] : null);
                if ($refused !== null) {
                    http_response_code($refused[0]);
                    header('Content-Type: application/json; charset=utf-8');
                    header('Cache-Control: no-store');
                    echo json_encode(['ok' => false, 'error' => $refused[1]]);
                    exit;
                }
            }
            Auth::requireRole($tool['role']);
        }

        define('SITE_TOOL', $tool['path']);
        define('SITE_TOOL_URL', Url::admin('tools/' . $tool['path']));
        define('SITE_TOOL_ALIAS', $base . '/' . ADMIN_URL_SLUG . '/tools/' . $tool['path']);
        header('X-Robots-Tag: noindex, nofollow, noarchive');

        (static function (string $__entry): void {
            require $__entry;
        })($tool['entry']);
        exit;
    }

    /** Forget the list, so the next call reads it again. */
    public static function reset(): void
    {
        self::$all = null;
    }

    /** A plain 404: a signed-out asset request and a missing file read the same. */
    private static function notFound(): never
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo "<!doctype html>\n<title>404 Not Found</title>\n<h1>Not Found</h1>\n";
        exit;
    }
}
