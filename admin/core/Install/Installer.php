<?php

declare(strict_types=1);

namespace Cms\Install;

use Cms\Database\Database;
use Cms\Models\Option;
use Cms\Models\User;
use Cms\Security\Csrf;
use Cms\Security\PasswordPolicy;
use Cms\Security\Usernames;
use PDO;
use PDOException;
use RuntimeException;

/**
 * SETTING A NEW SITE UP FROM THE BROWSER. A site with no config.php that has
 * never been set up answers every address with /install, in two steps:
 *
 *   1. the database   its host, name, user and password, connected to before
 *                     going on. One that already holds a site (an account
 *                     with a password of its own), or another program's
 *                     tables, is refused.
 *   2. the site       its title, its address and the first account. The
 *                     migrations run, the account, the title and the
 *                     administration email are saved, and config.php is
 *                     written from config.example.php with an app_secret of
 *                     its own. Where the server will not let it be written,
 *                     its text is shown, to save by hand.
 *
 * Then a page names the account and links to the sign-in page.
 *
 * ONLY A SITE NEVER SET UP (`neverSetUp()`). A site that had a config.php and
 * lost it must not offer every visitor a way to point it at another database.
 * A site that has run with a config.php keeps a mark of it (`markSetUp()`),
 * and one with anything in its uploads, logs, backups or update history
 * counts as set up too: either answers a bare 500, as before. With config.php
 * in place, /install is an address like any other: not found.
 *
 * The command line installs as before (README, Installing).
 */
final class Installer
{
    /** The set-up's own session, apart from the admin's. */
    private const SESSION = 'cms_install';

    /** A site's mark of having run with a config.php, under admin/. */
    private const MARK = 'logs/.set-up';

    /** Files a package ships in www/uploads/: they say nothing about a site. */
    private const SHIPPED = ['.htaccess', '.gitkeep', 'index.html'];

    /** config.example.php's value for each setting the set-up writes. */
    private const EXAMPLE = [
        'base_url'   => 'https://example.com',
        'app_env'    => 'development',
        'app_secret' => 'change-me-to-a-random-secret-string',
        'db_host'    => '127.0.0.1',
        'db_name'    => 'cms',
        'db_user'    => 'YOUR_MYSQL_USER',
        'db_pass'    => 'YOUR_MYSQL_PASS',
    ];

    /** Note that this site has run with a config.php: from then on it is never offered the set-up. */
    public static function markSetUp(): void
    {
        $mark = ROOT_PATH . '/' . self::MARK;
        if (!is_file($mark)) {
            @mkdir(dirname($mark), 0755, true);
            @file_put_contents($mark, "This site has run with a config.php, so it is never offered the browser set-up again.\n");
        }
    }

    /**
     * Has this site never been set up? Only then does a missing config.php
     * open the set-up. A site that has run with a config.php has at least
     * its mark in admin/logs/ (`markSetUp()`).
     */
    public static function neverSetUp(): bool
    {
        foreach (['logs', 'backups', 'updates'] as $dir) {
            if (self::holdsFile(ROOT_PATH . '/' . $dir, [])) {
                return false;
            }
        }

        return !self::holdsFile(PUBLIC_PATH . '/uploads', self::SHIPPED);
    }

    /** Answer this request with the set-up, and stop. */
    public static function handle(): never
    {
        ini_set('display_errors', '0');
        [$base, $path] = self::where();
        defined('BASE_URL') || define('BASE_URL', $base);
        defined('BASE_PATH') || define('BASE_PATH', (string) (parse_url($base, PHP_URL_PATH) ?? ''));
        defined('ADMIN_URL_SLUG') || define('ADMIN_URL_SLUG', 'admin');
        defined('ADMIN_URL') || define('ADMIN_URL', BASE_URL . '/' . ADMIN_URL_SLUG);
        require_once ROOT_PATH . '/core/Ui/globals.php';
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: same-origin');

        if ($path !== '/install') {
            header('Location: ' . BASE_URL . '/install', true, 302);
            exit;
        }

        session_name(self::SESSION);
        session_set_cookie_params(['path' => (BASE_PATH === '' ? '/' : BASE_PATH . '/'), 'httponly' => true,
            'samesite' => 'Lax', 'secure' => str_starts_with(BASE_URL, 'https://')]);
        session_start();
        $state = is_array($_SESSION['install'] ?? null) ? $_SESSION['install'] : [];
        $db    = is_array($state['db'] ?? null) ? $state['db'] : null;

        $errors = [];
        $step   = $db !== null && ($_GET['step'] ?? '') !== 'database' ? 'site' : 'database';
        $form   = [
            'db_host' => (string) ($db['host'] ?? 'localhost'), 'db_name' => (string) ($db['name'] ?? ''), 'db_user' => (string) ($db['user'] ?? ''),
            'title' => '', 'address' => BASE_URL, 'username' => '', 'email' => '',
        ];

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $posted = (string) ($_POST['step'] ?? '');
            if (!Csrf::validate()) {
                $errors[] = 'This page was open too long to send. Send it again.';
            } elseif ($posted === 'database') {
                $step  = 'database';
                $asked = [
                    'host' => trim((string) ($_POST['db_host'] ?? '')), 'name' => trim((string) ($_POST['db_name'] ?? '')),
                    'user' => trim((string) ($_POST['db_user'] ?? '')), 'pass' => (string) ($_POST['db_pass'] ?? ''),
                ];
                $form = ['db_host' => $asked['host'], 'db_name' => $asked['name'], 'db_user' => $asked['user']] + $form;
                $problem = self::databaseProblem($asked);
                if ($problem === null) {
                    $_SESSION['install'] = ['db' => $asked];
                    header('Location: ' . BASE_URL . '/install', true, 303);
                    exit;
                }
                $errors[] = $problem;
            } elseif ($posted === 'site' && $db !== null) {
                $step = 'site';
                $site = [
                    'title' => trim((string) ($_POST['title'] ?? '')), 'address' => trim((string) ($_POST['address'] ?? '')),
                    'username' => trim((string) ($_POST['username'] ?? '')), 'email' => trim((string) ($_POST['email'] ?? '')),
                    'password' => (string) ($_POST['password'] ?? ''), 'password2' => (string) ($_POST['password2'] ?? ''),
                ];
                $form = ['title' => $site['title'], 'address' => $site['address'], 'username' => $site['username'], 'email' => $site['email']] + $form;
                [$errors, $done] = self::install($db, $site);
                if ($done !== null) {
                    unset($_SESSION['install']);
                    self::render('done', [], $form, $done);
                }
            }
        }

        self::render($step, $errors, $form, null);
    }

    /**
     * Why this database will not do, or null: it cannot be reached as given,
     * or it holds a site already, or another program's tables.
     *
     * @param array{host: string, name: string, user: string, pass: string} $db
     */
    private static function databaseProblem(array $db): ?string
    {
        if ($db['host'] === '' || $db['name'] === '' || $db['user'] === '') {
            return 'Fill in the database\'s host, name and user.';
        }
        if (preg_match('/[;\x00]/', $db['host'] . $db['name']) === 1) {
            return 'A database\'s host and name have no ; in them.';
        }
        try {
            $pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['name']), $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (PDOException $e) {
            $code = preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) === 1 ? (int) $m[1] : (int) $e->getCode();
            return match ($code) {
                1045    => 'The database server refused that user and password.',
                1044    => 'That user may not use the database “' . $db['name'] . '”.',
                1049    => 'There is no database called “' . $db['name'] . '” there. Make it first, in your host\'s control panel.',
                2002, 2003, 2005, 2006 => 'No database server answered at “' . $db['host'] . '”. On most hosts it is localhost.',
                default => 'The database could not be reached: ' . trim((string) preg_replace('/^SQLSTATE\[\w+\]\s*(\[\d+\])?\s*/', '', $e->getMessage())),
            };
        }

        return self::occupied($pdo);
    }

    /**
     * Why the database cannot take this site, or null when it is empty or
     * holds only a set-up of this site that stopped before it finished.
     */
    private static function occupied(PDO $pdo): ?string
    {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if ($tables === []) {
            return null;
        }
        if (!in_array('migrations', $tables, true) || !in_array('users', $tables, true)) {
            return 'That database holds another program\'s tables. Name an empty one.';
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE password_hash <> :none');
        $stmt->execute([':none' => User::NO_PASSWORD]);

        return (int) $stmt->fetchColumn() > 0
            ? 'That database already holds a site. Put that site\'s own config.php back in admin/config/, or name an empty database.'
            : null;
    }

    /**
     * Set the site up: the errors that stopped it, or what was done.
     *
     * @param array{host: string, name: string, user: string, pass: string} $db
     * @param array<string, string> $site
     * @return array{0: list<string>, 1: array{username: string, signin: string, config: ?string}|null}
     */
    private static function install(array $db, array $site): array
    {
        $errors  = [];
        $address = self::address($site['address']);
        if ($site['title'] === '') {
            $errors[] = 'Give the site a title.';
        }
        if ($address === null) {
            $errors[] = 'The site\'s address is a whole address, such as https://www.example.com.';
        }
        if ($site['username'] === '') {
            $errors[] = 'Choose a username.';
        } elseif (mb_strlen($site['username']) > 100) {
            $errors[] = 'A username has at most 100 characters.';
        }
        if (!filter_var($site['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($site['password'] !== $site['password2']) {
            $errors[] = 'The two passwords are not the same.';
        }
        if ($errors !== []) {
            return [$errors, null];
        }

        $config = [
            'base_url' => (string) $address, 'app_env' => 'production', 'app_secret' => bin2hex(random_bytes(32)),
            'db_host' => $db['host'], 'db_name' => $db['name'], 'db_user' => $db['user'], 'db_pass' => $db['pass'],
        ];
        try {
            Database::init($config + ['db_charset' => 'utf8mb4']);
            $pdo = Database::getInstance();
            $occupied = self::occupied($pdo);
            if ($occupied !== null) {
                return [[$occupied], null];
            }
            Database::runMigrations(ROOT_PATH . '/core/Database/migrations', ROOT_PATH . '/site/migrations');
        } catch (\Throwable $e) {
            error_log('CMS set-up: ' . $e->getMessage());
            return [['The database could not be set up: ' . $e->getMessage()], null];
        }

        // The username and password rules read the settings the migrations
        // have just made.
        $problems = PasswordPolicy::problems($site['password'], ['username' => $site['username'], 'email' => $site['email']]);
        if (Usernames::blocked($site['username'])) {
            array_unshift($problems, '“' . $site['username'] . '” is one of the names every script tries first. Choose another.');
        }
        if ($problems !== []) {
            return [$problems, null];
        }
        $stmt = $pdo->prepare('SELECT id FROM users WHERE password_hash = :none ORDER BY id LIMIT 1');
        $stmt->execute([':none' => User::NO_PASSWORD]);
        $id = (int) $stmt->fetchColumn();
        if ($id === 0) {
            return [['The database has no account waiting to be set up.'], null];
        }
        $pdo->prepare('UPDATE users SET username = :username, email = :email WHERE id = :id')
            ->execute([':username' => $site['username'], ':email' => $site['email'], ':id' => $id]);
        User::updatePassword($id, $site['password']);
        Option::set('site_name', $site['title']);
        Option::set('admin_email', $site['email']);
        StartingContent::create($id);

        $text = self::configText($config);

        return [[], [
            'username' => $site['username'],
            'signin'   => $address . '/admin/login',
            'config'   => self::writeConfig($text) ? null : $text,
        ]];
    }

    /** config.example.php with the set-up's values in place, its comments kept. */
    private static function configText(array $config): string
    {
        $text = (string) file_get_contents(ROOT_PATH . '/config/config.example.php');
        foreach (self::EXAMPLE as $key => $example) {
            $line = "/('" . $key . "'\\s*=>\\s*)'" . preg_quote($example, '/') . "',/";
            if (preg_match_all($line, $text) !== 1) {
                throw new RuntimeException("config.example.php does not hold its {$key} line as the set-up reads it.");
            }
            $text = (string) preg_replace_callback($line, static fn (array $m): string => $m[1] . var_export($config[$key], true) . ',', $text);
        }

        return str_replace(' * Copy this file to config.php and fill in environment-specific values.', ' * Written by the set-up at /install.', $text);
    }

    /**
     * config.php written beside itself, read back, and put in place by a
     * rename: false when the server will not let it be written.
     */
    private static function writeConfig(string $text): bool
    {
        $path = ROOT_PATH . '/config/config.php';
        $tmp  = ROOT_PATH . '/config/.config.php.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $text) !== strlen($text)) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0640);
        $read = (static fn (string $__f): mixed => require $__f)($tmp);
        if (!is_array($read) || ($read['app_secret'] ?? null) === self::EXAMPLE['app_secret'] || ($read['db_name'] ?? null) === null) {
            @unlink($tmp);
            throw new RuntimeException('The config.php the set-up wrote does not read back as written.');
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        return true;
    }

    /** A site's address as typed: scheme and host, a path if any, no slash at the end; null if it is not one. */
    private static function address(string $typed): ?string
    {
        $typed = rtrim($typed, '/');
        $parts = parse_url($typed);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ($parts['host'] ?? '') === ''
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || filter_var($typed, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $typed;
    }

    /**
     * The site's address as this request reached it, and the path asked for
     * under it.
     *
     * @return array{0: string, 1: string}
     */
    private static function where(): array
    {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host  = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if (preg_match('/^([A-Za-z0-9.-]+|\[[0-9A-Fa-f:.]+\])(:\d{1,5})?$/', $host) !== 1) {
            $host = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
        }
        $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
        $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($dir !== '' && $uri !== $dir && !str_starts_with($uri, $dir . '/')) {
            $dir = '';
        }

        return [($https ? 'https' : 'http') . '://' . $host . $dir, '/' . ltrim(substr($uri, strlen($dir)), '/')];
    }

    /** Does `$dir` hold a file, other than ones named in `$except`? */
    private static function holdsFile(string $dir, array $except): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile() && !in_array($file->getFilename(), $except, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $form
     * @param array{username: string, signin: string, config: ?string}|null $done
     */
    private static function render(string $step, array $errors, array $form, ?array $done): never
    {
        $action = BASE_URL . '/install';
        require ROOT_PATH . '/admin/templates/install.php';
        exit;
    }
}
