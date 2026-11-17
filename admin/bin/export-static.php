<?php

declare(strict_types=1);

/**
 * A STATIC COPY OF THE SITE, from the command line (`Export\StaticCopy`).
 *
 *   php admin/bin/export-static.php [address] [--anywhere] [--no-php] [--instead="words"]
 *
 * `address` is where the copy will be published, such as
 * https://www.example.com; the site's own address when it is left out. Every
 * link, canonical and sitemap entry in the copy names it.
 *
 * `--anywhere` makes the copy's links relative, each page's naming its file
 * (`news/index.html`), so it works at any address and opened from a folder
 * with no web server; its canonicals and sitemap still name `address`.
 *
 * The copy carries a small form handler of its own (`forms/send.php`), which
 * needs PHP 8.2 or later and nothing else, and mails each form's answers the
 * way the site does (`Export\Forms`). `--no-php` is for a host that runs no
 * PHP: no form can be sent there, so each form's place shows `--instead`'s
 * words, such as "Email us at hello@example.org" (an address in them is a
 * link). Those words also stand in for a form the copy cannot send anywhere:
 * one that books appointments, or names nobody to send to.
 *
 * The copy is written to `admin/exports/<id>/site/` and packed beside it;
 * both paths are printed at the end, with anything that went wrong. The exit
 * status is 1 when a page failed.
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
defined('BASE_PATH') || define('BASE_PATH', (string) (parse_url(BASE_URL, PHP_URL_PATH) ?? ''));
defined('ADMIN_URL_SLUG') || define('ADMIN_URL_SLUG', 'admin');
defined('ADMIN_URL') || define('ADMIN_URL', BASE_URL . '/' . ADMIN_URL_SLUG);

\Cms\Database\Database::init($settings);
\Cms\Database\Database::runMigrations(ROOT_PATH . '/core/Database/migrations', ROOT_PATH . '/site/migrations');
\Cms\Helpers\Modules::configure(is_array($settings['modules'] ?? null) ? $settings['modules'] : []);
\Cms\ContentType\Registries::load();

$php      = true;
$anywhere = false;
$instead  = '';
$address  = BASE_URL;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--no-php') {
        $php = false;
    } elseif ($arg === '--anywhere') {
        $anywhere = true;
    } elseif (str_starts_with($arg, '--instead=')) {
        $instead = substr($arg, strlen('--instead='));
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "php admin/bin/export-static.php [address] [--anywhere] [--no-php] [--instead=\"Email us at hello@example.org\"]\n";
        exit(0);
    } elseif (str_starts_with($arg, '-')) {
        fwrite(STDERR, "Unknown option {$arg}. Try --help.\n");
        exit(2);
    } else {
        $address = $arg;
    }
}

try {
    $copy = \Cms\Export\StaticCopy::start($address, $php, $instead, $anywhere);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

$status = $copy->status();
echo "A static copy for {$status['address']}" . ($php ? '' : ', for a host without PHP') . "\n";
while (!$copy->step(5.0)) {
    $status = $copy->status();
    echo "  {$status['pages']} pages, {$status['files']} files, {$status['queued']} to go\n";
}

$status   = $copy->status();
$errors   = array_values(array_filter($status['problems'], static fn (array $p): bool => $p['level'] === \Cms\Export\StaticCopy::ERROR));
$notes    = array_values(array_filter($status['problems'], static fn (array $p): bool => $p['level'] !== \Cms\Export\StaticCopy::ERROR));
$seconds  = max(1, (int) $status['finished'] - (int) $status['started']);

echo "Done in {$seconds} s: {$status['pages']} pages and {$status['files']} files.\n";
foreach ([['Problems', $errors], ['Notes', $notes]] as [$title, $list]) {
    if ($list === []) {
        continue;
    }
    echo "\n{$title}:\n";
    foreach ($list as $p) {
        echo "  {$p['at']} {$p['what']}\n";
    }
}
echo "\nThe copy:   " . $copy->siteDir() . "/\n";
echo 'Packed:     ' . $status['archive'] . "\n";

exit($errors === [] ? 0 : 1);
