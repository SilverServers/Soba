<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\Auth\Auth;
use Cms\ContentType\FieldRenderer;
use Cms\Helpers\Url;
use Cms\Http\Cache;
use Cms\Security\Audit;
use Cms\Security\Csrf;
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
 *                                  under Dashboard; `'frame' => true` for a
 *                                  tool drawn inside the admin's frame; and
 *                                  `'csrf' => false` for an address another
 *                                  service posts to, such as a webhook
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
 * ── A POST CARRIES THE FORM TOKEN ───────────────────────────────────────────
 * As every admin POST does: the hidden field `csrfField()` prints, or, from
 * a script, the `X-CSRF-Token` header (`Security\Csrf::validate()`). One
 * without it is refused before the tool runs, after the sign-in and role
 * checks, so a signed-out POST is answered as any is: an action with a JSON
 * 403 it can read, `{"ok":false,"error":"csrf"}`, and a page with the refusal
 * the admin gives. A framed tool's POST meets the front controller's own
 * check. A row with `'csrf' => false` is an address another service posts to,
 * such as a webhook, which takes a POST without the token and checks its
 * sender itself.
 *
 * ── WHERE IT SITS: `place` ──────────────────────────────────────────────────
 * Under Tools, by default: a studio or a report is something staff go to now
 * and then. But a site can be built around one screen of its own — an
 * applications desk where its staff read and book every application, say —
 * and filed under Tools that screen is buried. `'place' =>
 * 'top'` puts its row among the lead rows, under Dashboard; its address, door
 * and role are unchanged. Any other place is said in the log and taken as
 * Tools, so a typo never hides a tool.
 *
 * ── IN THE ADMIN'S FRAME: `'frame' => true` ─────────────────────────────────
 * A tool that prints its whole page has no top bar, sidebar or flash messages
 * and none of the admin's components. A row with `'frame' => true` is answered
 * after the admin has loaded instead, through the router like any admin screen
 * (`Controllers\Admin\SiteToolController`), so it has the admin's door, its
 * form-token check on every POST, a line in Activity log → Changes for a POST
 * that changes something, and the template functions: such a tool defines
 * none of its own. Its entry returns its page, `Tools::page($title, $html)`,
 * and the CMS draws it inside the frame with the tool's sidebar row lit; what
 * it prints is sent as it is, so an action's JSON and an asset still answer
 * for themselves. For its pages: `csrfField()`, `flash()`, `mediaPicker()`
 * and `url()` below.
 */
final class Tools
{
    /** The CMS's own pages under /admin/tools/ — never a site tool's path. */
    public const CORE = ['import-export', 'import', 'redirects', 'static-copy', 'broken-links', 'backups', 'system-health'];

    /** Who may use a site's tool whose row names no role: the administrators. */
    public const ROLE = 'admin';

    /** Where a row may sit: the Tools group (the default), or the lead rows under Dashboard. */
    public const PLACES = ['tools', 'top'];

    /** @var array<string, array{label: string, path: string, icon: string, role: string, place: string, frame: bool, csrf: bool, entry: string}>|null */
    private static ?array $all = null;

    /** Where a tool's flash messages wait in the session, by tool. */
    private const FLASH = 'site_tool_flash';

    /**
     * The site's tools whose entry exists, by path, in the list's order (`rows()`);
     * a row with no entry file is left out too, said in the log.
     *
     * @return array<string, array{label: string, path: string, icon: string, role: string, place: string, frame: bool, entry: string}>
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $file = defined('ROOT_PATH') ? ROOT_PATH . '/config/admin-tools.php' : '';
        $list = $file !== '' ? (\Cms\Site\ConfigFile::read($file) ?? []) : [];

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
     * @return array<string, array{label: string, path: string, icon: string, role: string, place: string, frame: bool, csrf: bool}>
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
            $rows[$path] = ['label' => $label, 'path' => $path, 'icon' => (string) ($row['icon'] ?? ''), 'role' => $role, 'place' => $place,
                            'frame' => ($row['frame'] ?? false) === true, 'csrf' => ($row['csrf'] ?? true) !== false];
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
        // A TOOL IN THE ADMIN'S FRAME is answered through the router, after
        // the admin has loaded (`runFramed()`).
        if ($tool === null || $tool['frame']) {
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
            }
        } else {
            Session::begin(true);
        }
        self::admit($tool, $asset);
        self::enter($tool);

        (static function (string $__entry): void {
            require $__entry;
        })($tool['entry']);
        exit;
    }

    /**
     * The site's tools drawn in the admin's frame, by path: the router answers
     * each at every address under it.
     *
     * @return array<string, array{label: string, path: string, icon: string, role: string, place: string, frame: bool, entry: string}>
     */
    public static function framed(): array
    {
        return array_filter(self::all(), static fn (array $tool): bool => $tool['frame']);
    }

    /**
     * Run a framed tool, the admin loaded: the page its entry returns
     * (`page()`), for the frame to draw, or null once what it printed is sent,
     * an action's JSON or an asset, say. What it prints before returning a page
     * goes at the head of the page's markup.
     *
     * @return array{title: string, content: string, back: array{label: string, url: string}|null, subtitle: string}|null
     */
    public static function runFramed(string $path): ?array
    {
        $tool = self::framed()[$path] ?? null;
        if ($tool === null) {
            self::notFound();
        }
        $base  = defined('BASE_PATH') ? rtrim((string) BASE_PATH, '/') : '';
        $here  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $asset = str_starts_with(substr($here, strlen($base . '/' . ADMIN_URL_SLUG . '/tools/' . $path)), '/assets/');
        self::admit($tool, $asset);
        self::enter($tool);

        ob_start();
        try {
            $returned = (static function (string $__entry): mixed {
                return require $__entry;
            })($tool['entry']);
        } finally {
            $printed = (string) ob_get_clean();
        }
        if (!$asset && is_array($returned) && array_key_exists('content', $returned)) {
            return self::page((string) ($returned['title'] ?? $tool['label']), $printed . (string) $returned['content'], $returned);
        }
        echo $printed;

        return null;
    }

    /**
     * A framed tool's page, as its entry returns it: its title, for the screen
     * and the top bar; its markup, escaped by the tool; and, if it says so, a
     * back link above the title, as the admin's own screens have
     * (`'back' => ['Reports', Tools::url()]`), and a line under it
     * (`'subtitle' => …`).
     *
     * @param array<string, mixed> $opts
     * @return array{title: string, content: string, back: array{label: string, url: string}|null, subtitle: string}
     */
    public static function page(string $title, string $content, array $opts = []): array
    {
        $back = $opts['back'] ?? null;
        $back = is_array($back) ? [(string) ($back['label'] ?? $back[0] ?? ''), (string) ($back['url'] ?? $back[1] ?? '')] : ['', ''];

        return [
            'title'    => $title,
            'content'  => $content,
            'back'     => $back[0] !== '' && $back[1] !== '' ? ['label' => $back[0], 'url' => $back[1]] : null,
            'subtitle' => (string) ($opts['subtitle'] ?? ''),
        ];
    }

    /** The form token, as a hidden field, for every form a tool's page posts. */
    public static function csrfField(): string
    {
        return Csrf::field();
    }

    /**
     * Did this POST carry the form token? A framed tool's POST has been checked
     * before its entry runs, as every admin POST is; a tool that draws its own
     * page asks here, and refuses one that did not.
     */
    public static function checkCsrf(): bool
    {
        return Csrf::validate();
    }

    /**
     * A line for the tool's next page, shown once: 'success', 'error' or
     * 'info'. A framed page shows it above its content; a tool that draws its
     * own page takes it with `takeFlashes()`.
     */
    public static function flash(string $message, string $kind = 'success'): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || !defined('SITE_TOOL')) {
            return;
        }
        $_SESSION[self::FLASH][SITE_TOOL][] = ['kind' => in_array($kind, ['success', 'error', 'info'], true) ? $kind : 'info', 'message' => $message];
    }

    /**
     * The lines left for this tool's page, taken, so each shows once.
     *
     * @return list<array{kind: string, message: string}>
     */
    public static function takeFlashes(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE || !defined('SITE_TOOL')) {
            return [];
        }
        $lines = array_values(array_filter((array) ($_SESSION[self::FLASH][SITE_TOOL] ?? []), 'is_array'));
        unset($_SESSION[self::FLASH][SITE_TOOL]);

        return $lines;
    }

    /**
     * The media picker a picture field has on an entry, choosing from and
     * uploading to the library, which posts the picture's id as `$name` (0 for
     * none). A framed page loads its script.
     *
     * @param array{label?: string, help?: string} $opts
     */
    public static function mediaPicker(string $name, mixed $value = null, array $opts = []): string
    {
        return FieldRenderer::renderNamed([
            'key'         => $name,
            'type'        => 'image',
            'label'       => (string) ($opts['label'] ?? ''),
            'description' => (string) ($opts['help'] ?? ''),
        ], $name, $value);
    }

    /** An address in this tool: `url()` its first page, `url('rows/4')`, `url('?do=save')`. */
    public static function url(string $sub = ''): string
    {
        $tool = Url::admin('tools' . (defined('SITE_TOOL') ? '/' . SITE_TOOL : ''));
        $sub  = ltrim($sub, '/');

        return $sub === '' ? $tool : $tool . (str_starts_with($sub, '?') ? '' : '/') . $sub;
    }

    /**
     * Is this POST's address one a tool's row says another service posts to
     * (`'csrf' => false`)? The front controller asks it for a framed tool,
     * whose POSTs meet its own token check.
     */
    public static function csrfExempt(string $requestPath): bool
    {
        foreach (self::all() as $tool) {
            $at = '/' . ADMIN_URL_SLUG . '/tools/' . $tool['path'];
            if (!$tool['csrf'] && ($requestPath === $at || str_starts_with($requestPath, $at . '/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Refuse what this session may not have: a tool's own action (`?do=`)
     * answers JSON it can read, 401 signed out and 403 to another role; an
     * asset is a bare 404; and a page gets what any admin screen gives
     * (`Auth::requireRole()`). Then a POST without the form token, unless
     * the tool's row says another service posts to it.
     *
     * @param array{role: string, csrf: bool} $tool
     */
    private static function admit(array $tool, bool $asset): void
    {
        $action = isset($_GET['do']);
        if ($asset) {
            if (!Auth::check() || !Auth::hasRole($tool['role'])) {
                self::notFound();
            }
        } else {
            if ($action) {
                $refused = !Auth::check() ? [401, 'signin'] : (!Auth::hasRole($tool['role']) ? [403, 'role'] : null);
                if ($refused !== null) {
                    self::refuse($refused[0], $refused[1]);
                }
            }
            Auth::requireRole($tool['role']);
        }
        if ($tool['csrf'] && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !Csrf::validate()) {
            if ($action) {
                self::refuse(403, 'csrf');
            }
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            Cache::noStore();
            echo 'Invalid or missing CSRF token. Please go back and try again.';
            exit;
        }
    }

    /** An action's refusal, as JSON it can read: `{"ok":false,"error":…}`. */
    private static function refuse(int $status, string $error): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        Cache::noStore();
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }

    /**
     * The tool's own constants, for its entry, and the robots' header.
     *
     * @param array{path: string} $tool
     */
    private static function enter(array $tool): void
    {
        $base = defined('BASE_PATH') ? rtrim((string) BASE_PATH, '/') : '';
        define('SITE_TOOL', $tool['path']);
        define('SITE_TOOL_URL', Url::admin('tools/' . $tool['path']));
        define('SITE_TOOL_ALIAS', $base . '/' . ADMIN_URL_SLUG . '/tools/' . $tool['path']);
        header('X-Robots-Tag: noindex, nofollow, noarchive');
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
