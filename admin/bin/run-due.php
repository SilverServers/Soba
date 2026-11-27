<?php

declare(strict_types=1);

/**
 * THE SITE'S DUE WORK, FROM A REAL CRON: the shop's unpaid orders, the
 * backups (Tools → Backups), and the site's own scheduled tasks
 * (`admin/config/tasks.php`, `Site\Tasks`).
 *
 *   php admin/bin/run-due.php
 *   php admin/bin/run-due.php --task=<key>   that task alone, now, whatever its time
 *
 * A page request runs it at most every ten minutes (`Commerce\Lapse`,
 * `Site\Backups`), which is all shared hosting allows. Where the host has cron, this runs it on a
 * timetable instead, so an unpaid order lapses on time on a quiet site. It
 * prints what it did.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$admin = dirname(__DIR__);
require $admin . '/vendor/autoload.php';
require_once $admin . '/core/Helpers/Url.php';
$settings = \Cms\Site\ConfigFile::read($admin . '/config/config.php');

defined('ROOT_PATH') || define('ROOT_PATH', $admin);
defined('PUBLIC_PATH') || define('PUBLIC_PATH', dirname($admin) . '/www');
defined('BASE_URL') || define('BASE_URL', rtrim((string) ($settings['base_url'] ?? ''), '/'));
defined('ADMIN_URL_SLUG') || define('ADMIN_URL_SLUG', 'admin');
defined('ADMIN_URL') || define('ADMIN_URL', BASE_URL . '/' . ADMIN_URL_SLUG);

// THE SCHEMA A PAGE REQUEST WOULD FIND: a cron can come before the first visit
// after an update.
\Cms\Database\Database::init($settings);
\Cms\Database\Database::runMigrations(ROOT_PATH . '/core/Database/migrations', ROOT_PATH . '/site/migrations');

// ONE TASK, NOW: a site's developer trying a task, or a person running one
// by hand. Nothing else is done.
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--task=')) {
        $key = substr($arg, 7);
        if (!isset(\Cms\Site\Tasks::all()[$key])) {
            fwrite(STDERR, 'There is no task "' . $key . "\" in admin/config/tasks.php.\n");
            exit(1);
        }
        $run = \Cms\Site\Tasks::runNow($key);
        echo $run === null ? "Another run holds the tasks' lock, so nothing was run.\n" : 'Ran ' . $key . ': ' . \Cms\Site\Tasks::sentence($run) . "\n";
        exit($run !== null && $run['outcome'] === 'done' ? 0 : 1);
    }
    fwrite(STDERR, "Usage: php admin/bin/run-due.php [--task=<key>]\n");
    exit(2);
}

$days = \Cms\Commerce\Lapse::days();
if ($days <= 0) {
    echo "Unpaid orders are kept until somebody cancels them (Settings → Shop).\n";
} else {
    $done = \Cms\Commerce\Lapse::run($days);
    echo 'Reminded ' . $done['reminded'] . ' customer' . ($done['reminded'] === 1 ? '' : 's') . " to pay.\n";
    echo 'Cancelled ' . $done['cancelled'] . ' unpaid order' . ($done['cancelled'] === 1 ? '' : 's')
        . ' older than ' . $days . ' day' . ($days === 1 ? '' : 's') . ".\n";
}

// BACKUPS ON THEIR TIMETABLE (Tools → Backups): one due is made whole here,
// and one a visit left half made is finished.
if (\Cms\Site\Backups::every() === 'never') {
    echo "Backups are off (Tools → Backups).\n";
} else {
    while (!\Cms\Site\Backups::turn(60.0)) {
        // a turn at a time, until nothing is left to do
    }
    $newest = \Cms\Site\Backups::all()[0] ?? null;
    echo $newest === null ? "No backup yet.\n" : 'The newest backup is ' . $newest['id'] . ".\n";
}

// THE SITE'S OWN TASKS, each due one run in turn (`Site\Tasks`).
if (\Cms\Site\Tasks::all() === []) {
    echo "The site declares no scheduled tasks (admin/config/tasks.php).\n";
} else {
    $runs = \Cms\Site\Tasks::runDue('cron');
    if ($runs === null) {
        echo "Another run holds the tasks' lock, so no task was run.\n";
    } elseif ($runs === []) {
        echo "No scheduled task was due.\n";
    }
    foreach ($runs ?? [] as $run) {
        echo 'Ran ' . $run['task'] . ': ' . \Cms\Site\Tasks::sentence($run) . "\n";
    }
}

// THAT A CRON RAN THIS, for System health (`Site\GoingLive`), and the
// address the site runs at (`Site\OldAddresses`).
\Cms\Models\Option::set(\Cms\Site\GoingLive::CRON_KEY, (string) time());
\Cms\Site\OldAddresses::note();
