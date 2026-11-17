<?php

declare(strict_types=1);

/**
 * media-sizes — the smaller widths of the library's public images
 * (`Media\Sizes`), for those that have none.
 *
 *   php admin/bin/media-sizes.php [--all]
 *
 * An image gets its widths as it enters the library. One added before they
 * were made, or while the server had no Imagick, has none until this runs;
 * `--all` makes every image's again. Prints how many were made, and each that
 * could not be (exit 1 then).
 */

use Cms\Database\Database;
use Cms\Media\Sizes;
use Cms\Models\Media;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ROOT_PATH', dirname(__DIR__));
define('PUBLIC_PATH', dirname(__DIR__, 2) . '/www');
require ROOT_PATH . '/vendor/autoload.php';

$config = ROOT_PATH . '/config/config.php';
if (!is_file($config)) {
    fwrite(STDERR, "No config at {$config}.\n");
    exit(1);
}
$settings = require $config;
define('BASE_URL', rtrim((string) ($settings['base_url'] ?? ''), '/'));
Database::init($settings);
Database::runMigrations(ROOT_PATH . '/core/Database/migrations', ROOT_PATH . '/site/migrations');

if (!extension_loaded('imagick') || \Imagick::queryFormats('WEBP') === []) {
    fwrite(STDERR, "This server's PHP has no Imagick with WebP, so no widths can be made here.\n");
    exit(1);
}

$all  = in_array('--all', $argv, true);
$rows = Database::getInstance()->query(
    "SELECT id FROM attachments
      WHERE visibility = 'public' AND mime_type IN ('image/webp', 'image/jpeg', 'image/png')
        AND width > " . Sizes::WIDTHS[0] . ($all ? '' : ' AND sizes IS NULL') . ' ORDER BY id'
)->fetchAll(\PDO::FETCH_COLUMN);

$made   = 0;
$failed = [];
foreach ($rows as $id) {
    $row = Media::find((int) $id);
    if ($row !== null && Sizes::make($row)) {
        $made++;
    } else {
        $failed[] = '#' . $id . ' ' . (string) ($row['original_name'] ?? '');
    }
}

printf("Smaller widths made for %d image(s)%s.\n", $made, $all ? '' : ' that had none');
foreach ($failed as $f) {
    echo '  could not: ', $f, "\n";
}
exit($failed === [] ? 0 : 1);
