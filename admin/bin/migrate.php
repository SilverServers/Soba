<?php

declare(strict_types=1);

/**
 * The CMS's database migrations, from the command line.
 *
 *   php admin/bin/migrate.php [status]     what is applied, what is pending
 *   php admin/bin/migrate.php run          apply everything pending, in order
 *
 *   --config=<path>   a config.php other than admin/config/config.php
 *   --no-first-password
 *                     do not give a new site's first account its password
 *                     (the updater's run: its output is saved to a file)
 *
 * A NEW SITE'S FIRST PASSWORD. Migration 002 seeds `admin` with none, so
 * nobody can sign in from the web, not even after a page view has applied
 * the migrations. `run` gives that account a random password and prints it
 * here, once, for whoever holds the server. Its owner chooses their own at
 * the first sign-in; `security.php password` prints a new one if it is lost.
 *
 * THE SITE'S OWN FILES IN admin/config/ ARE CHECKED FIRST (`php -l`, through
 * `Cms\Site\ConfigFile`): one that does not compile, such as a byte-order mark
 * before `<?php` in a file that declares strict_types, is named and nothing is
 * done; one that only prints a blank line is noted.
 *
 * Exit status: 0 done (or nothing to do), 1 a migration failed, the database
 * could not be reached or a file in admin/config/ does not compile, 2 bad usage.
 *
 * The front controller applies pending migrations on the first request after a
 * deploy, and still does. This is the same runner (`Cms\Database\Migrator`)
 * for the times that is not good enough: an update, which must know the
 * database is migrated BEFORE it lets a visitor in, and a failure, which on a
 * page view is a 500 for everyone and here is a message with the file's name.
 *
 * One of the operator scripts in admin/bin/ that ship in a package (listed in
 * admin/core/Update/ownership.php).
 */

use Cms\Auth\Auth;
use Cms\Database\Database;
use Cms\Database\Migrator;
use Cms\Models\User;
use Cms\Security\LoginUrl;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$admin = dirname(__DIR__);
require $admin . '/vendor/autoload.php';

$command       = 'status';
$config        = $admin . '/config/config.php';
$firstPassword = true;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--config=')) {
        $config = substr($arg, strlen('--config='));
    } elseif ($arg === '--no-first-password') {
        $firstPassword = false;
    } elseif (in_array($arg, ['status', 'run'], true)) {
        $command = $arg;
    } else {
        fwrite(STDERR, "Usage: php admin/bin/migrate.php [status|run] [--config=<path>] [--no-first-password]\n");
        exit(2);
    }
}

if (!is_file($config)) {
    fwrite(STDERR, "No config at {$config}. Copy config/config.example.php to config/config.php first.\n");
    exit(1);
}

$version = is_file($admin . '/VERSION') ? trim((string) file_get_contents($admin . '/VERSION')) : 'unknown (no admin/VERSION)';

// THE SITE'S OWN FILES IN admin/config/, and the config.php given, before
// anything reads them (`Site\ConfigFile`). One that does not compile stops
// every page, and would stop this run with a fatal error, so it is named here
// and nothing is done; one that only prints a blank line is noted.
$problems = [];
foreach (\Cms\Site\ConfigFile::problemsIn($admin . '/config') as $file => $found) {
    $problems['admin/config/' . $file] = $found;
}
if (realpath(dirname($config)) !== realpath($admin . '/config')) {
    $problems[$config] = \Cms\Site\ConfigFile::problems($config);
}
$broken = false;
foreach ($problems as $file => $found) {
    if (isset($found['fatal'])) {
        fwrite(STDERR, "{$file}: {$found['fatal']}\n");
        $broken = true;
    } elseif (isset($found['output'])) {
        echo "WARNING  {$file}: {$found['output']}\n";
    }
}
if ($broken) {
    fwrite(STDERR, "Nothing was done.\n");
    exit(1);
}

try {
    $settings = require $config;
    Database::init($settings);
    // The CMS's migrations and the site's own (admin/site/migrations/), as the front controller runs them.
    $migrator = Migrator::forInstall(Database::getInstance(), $admin);

    $applied = $migrator->applied();
    $pending = $migrator->pending();
    $orphans = $migrator->orphans();

    // The site's own files are not part of a release: counted apart.
    $siteFiles = count(glob($admin . '/site/migrations/*.sql') ?: []);
    $release   = count($migrator->files()) - $siteFiles;

    echo "CMS {$version}\n";
    echo 'Migrations: ' . $release . ' in this release' . ($siteFiles > 0 ? ' and ' . $siteFiles . " of the site's own" : '')
        . ', ' . count($applied) . ' applied, ' . count($pending) . " pending\n";
    foreach ($orphans as $id) {
        echo "  WARNING: {$id} is recorded as applied but is not in this release — the database and the code are from different releases\n";
    }

    if ($command === 'status') {
        foreach ($pending as $id) {
            echo "  pending  {$id}\n";
        }
        exit(0);
    }

    if ($pending === []) {
        echo "Nothing to apply.\n";
        $code = 0;
    } else {
        $ran = $migrator->run(60, static function (string $id): void {
            echo "  applying {$id}\n";
        });
        $left = $migrator->pending();
        echo 'Applied ' . count($ran) . '. Pending now: ' . count($left) . ".\n";
        $code = $left === [] ? 0 : 1;
    }

    // Also after "Nothing to apply": a page view may have applied the
    // migrations first, and the account still has no password.
    if ($code === 0 && $firstPassword && ($first = User::issueFirstPassword()) !== null) {
        // What LoginUrl needs to build the address, as security.php gives it.
        defined('ROOT_PATH') || define('ROOT_PATH', $admin);
        defined('BASE_URL') || define('BASE_URL', rtrim((string) ($settings['base_url'] ?? ''), '/'));
        defined('ADMIN_URL_SLUG') || define('ADMIN_URL_SLUG', 'admin');
        defined('ADMIN_URL') || define('ADMIN_URL', BASE_URL . '/' . ADMIN_URL_SLUG);
        echo "\nFirst sign-in: {$first['username']} / {$first['password']} at " . LoginUrl::url() . "\n"
            // Only where it is kept: a development install does not insist.
            . (Auth::enforcesPasswordChanges() ? "It asks for a name and password of your own straight away.\n" : '')
            . "This is the only time it is printed. A new one: php admin/bin/security.php password {$first['username']}\n";
    }
    exit($code);
} catch (\Throwable $e) {
    fwrite(STDERR, "\nFAILED: " . $e->getMessage() . "\n");
    exit(1);
}
