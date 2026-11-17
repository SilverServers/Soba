<?php

declare(strict_types=1);

/**
 * BACKUPS, FROM THE COMMAND LINE (`Site\Backups`).
 *
 *   php admin/bin/backup.php list                 the backups kept, newest first
 *   php admin/bin/backup.php now                  make one now, whole
 *   php admin/bin/backup.php restore <id> --yes   put the site back as <id> has it
 *
 * A restore backs up the site as it is first, then puts back the database and
 * the uploads, and says what it did. It is here and not in the admin: it
 * replaces the accounts and sessions of whoever would press the button.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$admin = dirname(__DIR__);
require $admin . '/vendor/autoload.php';
require_once $admin . '/core/Helpers/Url.php';
$settings = require $admin . '/config/config.php';

defined('ROOT_PATH') || define('ROOT_PATH', $admin);
defined('PUBLIC_PATH') || define('PUBLIC_PATH', dirname($admin) . '/www');
defined('BASE_URL') || define('BASE_URL', rtrim((string) ($settings['base_url'] ?? ''), '/'));
defined('ADMIN_URL_SLUG') || define('ADMIN_URL_SLUG', 'admin');
defined('ADMIN_URL') || define('ADMIN_URL', BASE_URL . '/' . ADMIN_URL_SLUG);

\Cms\Database\Database::init($settings);
\Cms\Database\Database::runMigrations(ROOT_PATH . '/core/Database/migrations', ROOT_PATH . '/site/migrations');

use Cms\Site\Backups;

$command = $argv[1] ?? '';
try {
    switch ($command) {
        case 'list':
            $all = Backups::all();
            if ($all === []) {
                echo "No backups yet.\n";
                break;
            }
            foreach ($all as $b) {
                printf("%s  %s  %8s  %s\n", $b['id'], gmdate('Y-m-d H:i', $b['finished']) . ' UTC', Backups::size($b['bytes']), $b['why']);
            }
            break;

        case 'now':
            $id = Backups::now('from the command line');
            echo 'Made ' . $id . ' (' . Backups::size((int) filesize((string) Backups::file($id))) . ").\n";
            break;

        case 'restore':
            $id = (string) ($argv[2] ?? '');
            if ($id === '' || Backups::file($id) === null) {
                fwrite(STDERR, "Name a backup from `php admin/bin/backup.php list`.\n");
                exit(1);
            }
            if (!in_array('--yes', $argv, true)) {
                fwrite(STDERR, "This replaces the database and the uploads with backup {$id}'s, after backing up the site as it is.\nRun it again with --yes to go ahead.\n");
                exit(1);
            }
            Backups::restore($id, static function (string $line): void {
                echo $line, "\n";
            });
            break;

        default:
            fwrite(STDERR, "php admin/bin/backup.php list | now | restore <id> --yes\n");
            exit(1);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Stopped: ' . $e->getMessage() . "\n");
    exit(1);
}
