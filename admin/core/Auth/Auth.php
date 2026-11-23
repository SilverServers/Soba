<?php

declare(strict_types=1);

namespace Cms\Auth;

use Cms\Database\Database;
use Cms\Security\Accounts;
use Cms\Security\Csrf;
use Cms\Security\LoginUrl;
use Cms\Security\Sessions;
use Cms\Security\TemporaryAccess;
use PDO;

class Auth
{
    private const SESSION_KEY = 'cms_user';

    /** When this session was last used, for the idle timeout. */
    private const SEEN_KEY = 'cms_seen';

    /** `users.session_version` when this session was issued (migration 060). */
    private const VERSION_KEY = 'cms_ver';

    /** Fallback idle window when config has no `session_lifetime`. */
    private const DEFAULT_IDLE = 7200;

    /**
     * The columns a session carries. radius_pref rides along with theme_preset:
     * both are read on every admin render by Ui\Theme, straight out of the
     * session. A column that exists but is not selected here is a preference
     * the user can set and never see applied.
     */
    private const SESSION_COLUMNS = 'id, username, email, role, theme_preset, radius_pref, session_version, must_change_password, suspended_at, last_seen_at';

    /** This request's answer to check(), once asked. */
    private static ?bool $checked = null;

    /**
     * CHECK A USERNAME AND PASSWORD — and nothing else. It does not sign
     * anybody in, count anything or write to the log: `AuthController` does
     * those in order, around this, and it is the only caller. There used to be
     * a `login()` that did all of it in one call, which is exactly the shape a
     * second caller would use to skip the attempt limit.
     *
     * ── AN UNKNOWN NAME COSTS THE SAME AS A WRONG PASSWORD ──────────────────
     * The error message never said which one was wrong, but the CLOCK did: a
     * name with no account returned at once, and a real one spent ~60 ms in
     * bcrypt. Timing the form was a way to list the accounts. So an unknown
     * name is checked against a real account's hash anyway — the same
     * algorithm, the same cost, whatever PHP's default becomes — and the
     * answer thrown away.
     *
     * @return array{user: array<string, mixed>|null, id: int|null}
     *         `user` on success; `id` is the account the NAME matched, if any,
     *         so a failure can be logged against it
     */
    public static function attempt(string $username, string $password): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT ' . self::SESSION_COLUMNS . ', password_hash FROM users WHERE username = :username LIMIT 1'
        );
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user === false) {
            $decoy = $pdo->query('SELECT password_hash FROM users ORDER BY id LIMIT 1')->fetchColumn();
            password_verify($password, is_string($decoy) ? $decoy : '$2y$10$' . str_repeat('.', 53));

            return ['user' => null, 'id' => null];
        }

        $hash = (string) $user['password_hash'];
        unset($user['password_hash']);

        if (!password_verify($password, $hash)) {
            return ['user' => null, 'id' => (int) $user['id']];
        }

        // Stored under an older algorithm or cost: upgrade it now, the one
        // moment the plain password is in hand.
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
                ->execute([':hash' => password_hash($password, PASSWORD_DEFAULT), ':id' => (int) $user['id']]);
        }

        return ['user' => $user, 'id' => (int) $user['id']];
    }

    /**
     * The account's row as a session carries it — for a sign-in finished by a
     * second step (`TwoStep::finish()`), which starts from the account as it is
     * NOW, not as it was when the password was typed.
     *
     * @return array<string, mixed>|null
     */
    public static function accountRow(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT ' . self::SESSION_COLUMNS . ' FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Make this browser signed in as `$user` (a row from attempt()).
     *
     * A new session id, so one planted before sign-in is worthless after it
     * (fixation), and a new CSRF token for the same reason.
     *
     * @param array<string, mixed> $user
     */
    public static function startSession(array $user): void
    {
        session_regenerate_id(true);

        $_SESSION[self::SESSION_KEY] = self::sessionRow($user);
        $_SESSION[self::SEEN_KEY]    = time();
        $_SESSION[self::VERSION_KEY] = (int) ($user['session_version'] ?? 1);
        self::$checked               = true;

        Sessions::start((int) $user['id']);
        Accounts::signedIn((int) $user['id']);
        Csrf::regenerate();
    }

    /**
     * END EVERY SESSION THIS ACCOUNT HAS — on every machine. Called when its
     * password changes. If it is the account signed in HERE, this browser is
     * re-stamped and stays signed in: changing your own password should sign
     * out the laptop you left at the library, not the one you are typing on.
     */
    public static function endSessions(int $userId): void
    {
        Database::getInstance()
            ->prepare('UPDATE users SET session_version = session_version + 1 WHERE id = :id')
            ->execute([':id' => $userId]);

        if ((int) ($_SESSION[self::SESSION_KEY]['id'] ?? 0) === $userId) {
            $stmt = Database::getInstance()->prepare('SELECT session_version FROM users WHERE id = :id');
            $stmt->execute([':id' => $userId]);
            $_SESSION[self::VERSION_KEY] = (int) $stmt->fetchColumn();
            Sessions::revokeOthers($userId);
        } else {
            Sessions::revokeAll($userId);
        }
    }

    /** @param array<string, mixed> $row */
    private static function sessionRow(array $row): array
    {
        unset($row['session_version'], $row['password_hash']);

        return $row;
    }

    /** The idle window, in seconds — for the account screen's list of sessions. */
    public static function idleSeconds(): int
    {
        return self::idleWindow();
    }

    /**
     * How long a session may sit unused before it stops counting as logged in.
     *
     * Same value as the session cookie's lifetime, from `session_lifetime` in
     * config.php, so the two halves cannot disagree.
     */
    private static function idleWindow(): int
    {
        static $seconds = null;

        if ($seconds === null) {
            $config  = \Cms\Site\ConfigFile::read(ROOT_PATH . '/config/config.php');
            $seconds = (int) (is_array($config) ? ($config['session_lifetime'] ?? 0) : 0);
            $seconds = $seconds > 0 ? $seconds : self::DEFAULT_IDLE;
        }

        return $seconds;
    }

    /**
     * Mark the session as used, and slide the cookie forward.
     *
     * **This fixes a real bug as much as it hardens anything.** The session cookie
     * is created with an *absolute* lifetime measured from login, so an admin who
     * signed in two hours ago was logged out mid-sentence no matter how busy they
     * had been — losing whatever was in the editor. Re-issuing the cookie on each
     * authenticated request makes the window idle-based, which is what everyone
     * assumes it already was.
     *
     * The security half is that `check()` now enforces the same window
     * *server-side*. Before this, the only thing expiring a session was the cookie
     * and PHP's own garbage collection, and `session.gc_maxlifetime` is a server
     * setting this project cannot rely on — the whole point is that the CMS runs on
     * hosting it does not control. A session id copied off a shared machine
     * outlived its cookie for as long as the file survived on disk.
     *
     * Called from requireAuth(), which runs before any output, because re-issuing a
     * cookie after headers are sent would be a no-op — and a silent one.
     */
    private static function touch(): void
    {
        $_SESSION[self::SEEN_KEY] = time();

        if (headers_sent() || !ini_get('session.use_cookies')) {
            return;
        }

        $params = session_get_cookie_params();

        if ((int) $params['lifetime'] === 0) {
            return; // A browser-session cookie; nothing to slide.
        }

        setcookie(session_name(), session_id(), [
            'expires'  => time() + (int) $params['lifetime'],
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => $params['samesite'] ?: 'Lax',
        ]);
    }

    /**
     * Destroy the session and send the browser to the sign-in page — wherever
     * it lives now (`LoginUrl`), not where it used to — or to `$to`, an
     * address on this site a login redirection rule chose.
     */
    public static function logout(?string $to = null): void
    {
        self::$checked = false;
        Sessions::end();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();

        header('Location: ' . ($to ?? LoginUrl::url()));
        exit;
    }

    /**
     * Return true if the current request is authenticated.
     *
     * ── THE ACCOUNT IS ASKED, NOT REMEMBERED ────────────────────────────────
     * A session used to be a copy of the user row taken at sign-in and trusted
     * from then on. So a DELETED account went on working until its browser
     * sat idle for two hours, a DEMOTED administrator kept administrator
     * rights for as long as they stayed busy, and a changed password left
     * every other signed-in machine exactly as signed in as before.
     *
     * Now the row is read once per request, by primary key: gone, and the
     * session is over; `session_version` moved on (a password change, "sign
     * out everywhere"), and it is over; otherwise its role and details are
     * refreshed from the row, so a change of role applies on the next click.
     */
    public static function check(): bool
    {
        if (self::$checked !== null) {
            return self::$checked;
        }
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            return self::$checked = false;
        }

        // Enforced here rather than left to the cookie and to PHP's session GC —
        // see touch() for why neither can be relied on. A session with no `seen`
        // stamp predates this check and is treated as current rather than kicking
        // everyone out on deploy.
        $seen = (int) ($_SESSION[self::SEEN_KEY] ?? 0);

        if ($seen > 0 && (time() - $seen) > self::idleWindow()) {
            self::forget();

            return self::$checked = false;
        }

        // The account and THIS browser's row in `user_sessions` (migration
        // 062), in one query: the row is the session's right to exist.
        $columns = (string) preg_replace('/(\w+)/', 'u.$1', self::SESSION_COLUMNS);
        // And TEMPORARY ACCESS (migration 069): whether its time is up, so an
        // open session ends when it does rather than when a screen next sweeps.
        $stmt    = Database::getInstance()->prepare(
            'SELECT ' . $columns . ', s.id AS session_row, s.last_seen_at AS session_seen,
                    (t.ended_at IS NULL AND t.expires_at <= UTC_TIMESTAMP()) AS access_over
               FROM users u
               LEFT JOIN user_sessions s ON s.user_id = u.id AND s.session_hash = :hash
               LEFT JOIN temporary_access t ON t.user_id = u.id
              WHERE u.id = :id LIMIT 1'
        );
        $stmt->execute([
            ':id'   => (int) ($_SESSION[self::SESSION_KEY]['id'] ?? 0),
            ':hash' => Sessions::currentHash(),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Gone, or SUSPENDED (migration 067, `Accounts`): the session is over
        // on its next click, as for a deleted account.
        if ($row === false || $row['suspended_at'] !== null) {
            self::forget();

            return self::$checked = false;
        }
        if ((int) ($row['access_over'] ?? 0) === 1) {
            TemporaryAccess::end((int) $row['id'], null);
            self::forget();

            return self::$checked = false;
        }

        // A session issued before migration 060 carries no version. It adopts
        // the account's current one rather than signing everybody out on
        // deploy — the same courtesy the `seen` stamp above extends.
        $version = (int) $row['session_version'];
        if (!isset($_SESSION[self::VERSION_KEY])) {
            $_SESSION[self::VERSION_KEY] = $version;
        } elseif ((int) $_SESSION[self::VERSION_KEY] !== $version) {
            self::forget();

            return self::$checked = false;
        }

        // SIGNED OUT FROM ANOTHER BROWSER — its row deleted from the account
        // screen, "Sign out everywhere else", or a reset. `Sessions` has why a
        // session with no row at all is adopted instead.
        $sessionRow = $row['session_row'] !== null ? (int) $row['session_row'] : null;
        if (!Sessions::confirm((int) $row['id'], $sessionRow, $row['session_seen'])) {
            self::forget();

            return self::$checked = false;
        }
        unset($row['session_row'], $row['session_seen']);

        // In use: the inactivity rule's clock, at most once a day.
        Accounts::seen((int) $row['id'], $row['last_seen_at']);

        $_SESSION[self::SESSION_KEY] = self::sessionRow($row);

        return self::$checked = true;
    }

    /** Drop the signed-in state from this session, and nothing else in it. */
    private static function forget(): void
    {
        unset($_SESSION[self::SESSION_KEY], $_SESSION[self::SEEN_KEY], $_SESSION[self::VERSION_KEY],
              $_SESSION[Sessions::TRACKED_KEY]);
    }

    /**
     * Return the current user array, or null if not authenticated.
     */
    public static function user(): ?array
    {
        return self::check() ? $_SESSION[self::SESSION_KEY] : null;
    }

    /**
     * Refresh a single field in the session user record without requiring re-login.
     * Used after profile updates (e.g. theme_preset change).
     */
    public static function refreshSessionField(string $field, mixed $value): void
    {
        if (self::check()) {
            $_SESSION[self::SESSION_KEY][$field] = $value;
        }
    }

    /**
     * Stop here unless the user is authenticated.
     * Call this at the top of any protected controller method.
     *
     * Signed out, this used to redirect to /admin/login — which, once the
     * sign-in page has moved, would hand its new address to anybody who typed
     * /admin. `LoginUrl::turnAway()` decides: the sign-in page while it has
     * not moved, the old-address rule once it has.
     *
     * ── AND AN ACCOUNT THAT OWES A NEW PASSWORD GOES NOWHERE ELSE ──────────
     * Signed in with the password every install is seeded with, one somebody
     * else set, or one that breaks the password rule (`mustChange()`): every
     * admin screen sends it to choose a new one first. Only that screen and
     * sign-out are let through — a rule that can be clicked past is a banner.
     */
    public static function requireAuth(): void
    {
        if (!self::check()) {
            if (self::isApiRequest()) {
                self::denyApi(401, 'Not signed in.');
            }
            LoginUrl::turnAway();
        }

        self::touch();

        if (self::mustChange() !== null
            && !in_array(\Cms\Ui\AdminMenu::here(), ['/admin/choose-password', '/admin/logout'], true)) {
            if (self::isApiRequest()) {
                self::denyApi(403, 'Choose a new password first.');
            }
            header('Location: ' . \Cms\Helpers\Url::admin('choose-password'));
            exit;
        }

        // ── TWO-STEP SIGN-IN, WHEN THE POLICY SAYS AND THE GRACE IS OVER ────
        // The same shape as the owed password, and after it: an account the
        // site requires it of (`TwoStep::owed()`) reaches its setup screens,
        // the password confirmation they may ask for, and sign-out.
        if (\Cms\Security\TwoStep::owed() === 'strict'
            && !in_array(\Cms\Ui\AdminMenu::here(), self::TWO_STEP_PATHS, true)) {
            if (self::isApiRequest()) {
                self::denyApi(403, 'Set up two-step sign-in first.');
            }
            header('Location: ' . \Cms\Helpers\Url::admin('two-step'));
            exit;
        }
    }

    /**
     * IS THIS A CALL TO THE API (`/api/…`)? A script cannot follow a redirect
     * to the sign-in page or read a not-found page, so the API's refusals are
     * JSON — 401 signed out, 403 for a role or an owed step — and every other
     * address keeps its redirect or its not-found.
     * `Helpers\ErrorPage` answers the API's
     * server errors in the same shape.
     */
    public static function isApiRequest(): bool
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $base = defined('BASE_PATH') ? rtrim((string) BASE_PATH, '/') : '';

        return $path === $base . '/api' || str_starts_with($path, $base . '/api/');
    }

    /** The API's refusal, in the shape every API error uses. Ends the request. */
    public static function denyApi(int $status, string $error): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['success' => false, 'error' => $error]);
        exit;
    }

    /** What an account that owes two-step sign-in can still reach. */
    private const TWO_STEP_PATHS = [
        '/admin/two-step', '/admin/two-step/app', '/admin/two-step/email', '/admin/two-step/codes',
        '/admin/confirm', '/admin/choose-password', '/admin/logout',
    ];

    /**
     * Why the signed-in account must choose a new password before anything
     * else — 'seed', 'admin', 'all', 'weak' (migration 061) — or null.
     *
     * NULL ON A DEVELOPMENT TREE, whatever the row says. A development tree
     * signs in by script with the password its install was seeded with, on
     * purpose, many times over, and a "choose a new password" screen would
     * stop every one of them. A live site runs as production (config.php's
     * `app_env`), which is where the seeded password is a hole rather than a
     * fixture — and where this applies.
     * Read as everything reads it (`Site\Environment`).
     */
    public static function mustChange(): ?string
    {
        if (!self::check() || !self::enforcesPasswordChanges()) {
            return null;
        }

        $reason = $_SESSION[self::SESSION_KEY]['must_change_password'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * Does this install stop an account that owes a new password? Every copy
     * but a development one does (`Site\Environment`; see mustChange()), a
     * staging copy included. Asked by the forms that promise "they choose
     * their own at their first sign-in", so the promise is only made where it
     * is kept.
     */
    public static function enforcesPasswordChanges(): bool
    {
        return !\Cms\Site\Environment::isDevelopment();
    }

    /**
     * Return the current user's role, or null if not authenticated.
     */
    public static function role(): ?string
    {
        return self::user()['role'] ?? null;
    }

    /**
     * The roles there are, and their order: admin > shop manager > editor > author.
     *
     * ── A SHOP MANAGER RUNS THE SHOP AND NOTHING ELSE ABOVE AN EDITOR ───────
     * Orders, discount codes and (later) stock, customers and reports. Orders
     * asked only for a sign-in, so an author — somebody who writes the news —
     * could read every customer's address, move orders and delete them, and the
     * only role above that was administrator, which also hands over users,
     * security and settings. A shop manager is an editor who may also run the
     * shop; the shop's settings, users and security stay with administrators.
     */
    public const ROLE_LEVELS = ['author' => 1, 'editor' => 2, 'shop_manager' => 3, 'admin' => 4];

    /**
     * Each role's mark beside its name, on the Users list and wherever a rule
     * names roles. One map, beside the names, so a role added to one cannot be
     * missing from the other: a role with no glyph was a fatal on the
     * passwordless settings screen. `check-icons.php` holds it to every role.
     */
    public const ROLE_GLYPHS = [
        'admin'        => 'shield-halved',
        'shop_manager' => 'box-open',
        'editor'       => 'pen-to-square',
        'author'       => 'pen',
    ];

    /** What each role is called, highest first — the Users screen's order. One list, read by every screen that names a role. */
    public const ROLE_LABELS = [
        'admin'        => 'Administrator',
        'shop_manager' => 'Shop manager',
        'editor'       => 'Editor',
        'author'       => 'Author',
    ];

    /**
     * Check whether the current user has at least the given role level.
     */
    public static function hasRole(string $minimumRole): bool
    {
        return self::roleAllows(self::role(), $minimumRole);
    }

    /**
     * Whether an account of role `$current` has at least `$minimumRole`. A role
     * there is none of allows nothing, on either side: read as level 0, a
     * minimum someone mistyped ('admins' in a site tool's row) would let every
     * account through.
     */
    public static function roleAllows(?string $current, string $minimumRole): bool
    {
        return $current !== null && isset(self::ROLE_LEVELS[$current], self::ROLE_LEVELS[$minimumRole])
            && self::ROLE_LEVELS[$current] >= self::ROLE_LEVELS[$minimumRole];
    }

    /**
     * Require at least the given role. Redirects to dashboard if insufficient.
     * Calls requireAuth() first to ensure the user is logged in.
     */
    public static function requireRole(string $minimumRole): void
    {
        self::requireAuth();
        if (!self::hasRole($minimumRole)) {
            if (self::isApiRequest()) {
                self::denyApi(403, 'Your role does not allow this.');
            }
            header('Location: ' . ADMIN_URL);
            exit;
        }
    }

    /**
     * May the signed-in user change this entry? Editors and admins may change
     * any entry; an author only the ones they wrote.
     *
     * ── ONE RULE, EVERY WRITE PATH ──────────────────────────────────────────
     * This was only ever applied to the LIST: authors saw their own entries
     * there, while the edit, save, trash, restore and delete handlers checked
     * sign-in and nothing else — so an author could open, rewrite or delete
     * anyone's entry by typing its address. A rule that filters what you can
     * SEE and not what you can DO is a courtesy, not a permission.
     *
     * So every handler that writes an entry asks this, and so does the terms
     * API and the revision restore. One function, because the day the rule
     * changes (a contributor role, say), five copies would change four times.
     *
     * @param array<string, mixed> $entry  a `posts` row; only `author_id` is read
     */
    public static function canChange(array $entry): bool
    {
        if (!self::check()) {
            return false;
        }
        if (self::hasRole('editor')) {
            return true;
        }
        return (int) ($entry['author_id'] ?? 0) === (int) (self::user()['id'] ?? -1);
    }
}
