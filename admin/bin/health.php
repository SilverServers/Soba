<?php

declare(strict_types=1);

/**
 * SYSTEM HEALTH, FROM THE COMMAND LINE (Tools → System health).
 *
 *   php admin/bin/health.php
 *
 * Prints the rows the screen shows (`Site\Health`, `Site\GoingLive`): what
 * the site runs on, and what going to production changes, or in production
 * what to see to, each row that names accounts or pages followed by them.
 * A row that needs attention starts with `!`. The exit status is 1 while any
 * does, and 0 when every check passes, so a launch can rest on one command.
 * It changes nothing, beyond noting the address the site runs at, as a sign-in
 * does (`Site\OldAddresses`).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$admin = dirname(__DIR__);
require $admin . '/vendor/autoload.php';
require_once $admin . '/core/Helpers/Url.php';
$settings = \Cms\Site\ConfigFile::read($admin . '/config/config.php');
if (!is_array($settings)) {
    fwrite(STDERR, "There is no admin/config/config.php to read: set the site up first.\n");
    exit(1);
}

defined('ROOT_PATH') || define('ROOT_PATH', $admin);
defined('PUBLIC_PATH') || define('PUBLIC_PATH', dirname($admin) . '/www');
defined('BASE_URL') || define('BASE_URL', rtrim((string) ($settings['base_url'] ?? ''), '/'));
defined('ADMIN_URL_SLUG') || define('ADMIN_URL_SLUG', 'admin');
defined('ADMIN_URL') || define('ADMIN_URL', BASE_URL . '/' . ADMIN_URL_SLUG);

try {
    \Cms\Database\Database::init($settings);
    \Cms\ContentType\Registries::load();
} catch (\Throwable $e) {
    fwrite(STDERR, 'The database cannot be read: ' . $e->getMessage() . "\n");
    exit(1);
}
\Cms\Site\OldAddresses::note();

/**
 * A group's rows as lines: a mark, the label, the value, the hint, and each
 * thing the row names on a line of its own.
 *
 * @param array<array-key, array<string, mixed>> $rows
 */
$print = static function (string $title, array $rows): void {
    echo "\n", $title, "\n";
    foreach ($rows as $row) {
        $mark = in_array((string) ($row['status'] ?? 'ok'), \Cms\Site\Health::ATTENTION, true) ? '!' : ' ';
        $hint = trim((string) ($row['hint'] ?? ''));
        echo '  ', $mark, ' ', str_pad((string) $row['label'], 44), ' ', (string) $row['value'], $hint !== '' ? ' — ' . $hint : '', "\n";
        foreach ((array) ($row['items'] ?? []) as $item) {
            echo '        ', (string) $item['text'], '  ', (string) $item['url'], "\n";
        }
    }
};

$environment = \Cms\Site\Health::environment();
try {
    $goingLive = \Cms\Site\GoingLive::rows();
} catch (\Throwable $e) {
    // A schema still waiting for its migrations: the Migrations row says so.
    $goingLive = ['unread' => ['label' => 'Going to production', 'value' => 'Cannot be read', 'status' => 'error', 'hint' => $e->getMessage()]];
}

echo 'System health of ', BASE_URL !== '' ? BASE_URL : 'this site', ' (', \Cms\Site\Environment::name(), ")\n";
$print('Environment', $environment);
$print(\Cms\Site\GoingLive::title(), $goingLive);

$tasks = \Cms\Site\Tasks::all() !== [] ? \Cms\Site\Tasks::rows() : [];
if ($tasks !== []) {
    $print('Scheduled tasks', $tasks);
}

$problems = \Cms\Site\Health::attention($environment, $goingLive, $tasks);
echo "\n", $problems === 0 ? 'Every check passed.' : $problems . ' check' . ($problems === 1 ? ' needs' : 's need') . ' attention.', "\n";
exit($problems === 0 ? 0 : 1);
