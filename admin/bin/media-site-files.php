<?php

declare(strict_types=1);

/**
 * media-site-files — files the site already has under its web root, into the
 * media library where they are (`Models\Media::register()`).
 *
 *   php admin/bin/media-site-files.php add <file or folder> [<file or folder> …]
 *   php admin/bin/media-site-files.php list
 *
 * add   Each file named, as its address from the web root (/img/blog/x.jpg)
 *       or as a path on disk inside it, joins the library without being
 *       copied, moved, renamed or re-encoded: its address stays its address,
 *       and its narrower copies beside it (x-480.jpg, x-768.jpg) are its
 *       widths. A folder adds each file in it, not in the folders below it,
 *       except the copies another file there has, which are that file's
 *       widths, and those the library does not take, which are said. A file
 *       already in the library is said and left as it is. A file named on its
 *       own that is refused says why, and the exit status is 1.
 * list  Every file of the site's in the library: its id, address and widths.
 */

use Cms\Database\Database;
use Cms\Media\Sizes;
use Cms\Models\Media;
use Cms\Models\MediaRejected;

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
$settings = \Cms\Site\ConfigFile::read($config);
define('BASE_URL', rtrim((string) ($settings['base_url'] ?? ''), '/'));
Database::init($settings);
Database::runMigrations(ROOT_PATH . '/core/Database/migrations', ROOT_PATH . '/site/migrations');

$command = $argv[1] ?? '';
$widths  = static fn (array $row): string => implode(', ', array_keys(Sizes::addresses($row)));

if ($command === 'list') {
    $rows = Database::getInstance()->query(
        'SELECT * FROM attachments WHERE site_path IS NOT NULL ORDER BY site_path'
    )->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $w = $widths($row);
        printf("#%d  %s%s\n", (int) $row['id'], (string) $row['site_path'], $w !== '' ? '  (widths ' . $w . ')' : '');
    }
    printf("%d file(s) of the site's in the library.\n", count($rows));
    exit(0);
}

$named = array_values(array_filter(array_slice($argv, 2), static fn (string $a): bool => $a !== ''));
if ($command !== 'add' || $named === []) {
    fwrite(STDERR, "Usage: php admin/bin/media-site-files.php add <file or folder> [...] | list\n");
    exit(2);
}

// What each argument names: a folder's files (its copies left out), or the file.
$files = [];
foreach ($named as $arg) {
    $dir = is_dir(PUBLIC_PATH . '/' . ltrim($arg, '/')) ? PUBLIC_PATH . '/' . ltrim($arg, '/') : (is_dir($arg) ? $arg : null);
    if ($dir === null) {
        $files[] = [$arg, true];
        continue;
    }
    $dir   = rtrim($dir, '/');
    $names = array_values(array_filter(scandir($dir) ?: [], static fn (string $n): bool => $n[0] !== '.' && is_file($dir . '/' . $n)));
    // A COPY OF ANOTHER FILE HERE (x-480.jpg beside x.jpg) is that file's
    // width, as `Sizes::siblings()` decides it, and is not added on its own.
    $copies = [];
    foreach ($names as $name) {
        $dims = @getimagesize($dir . '/' . $name);
        foreach (Sizes::siblings($dir . '/' . $name, $dims !== false ? (int) $dims[0] : null) as $copy) {
            $copies[$copy['file']] = true;
        }
    }
    foreach ($names as $name) {
        if (!isset($copies[$name])) {
            $files[] = [$dir . '/' . $name, false];
        }
    }
}

// A file is said as its address from the web root where it has one.
$root  = (string) realpath(PUBLIC_PATH);
$shown = static function (string $path) use ($root): string {
    $real = realpath($path);
    return $root !== '' && $real !== false && str_starts_with($real, $root . '/') ? substr($real, strlen($root)) : $path;
};

$added = $already = 0;
$failed = false;
foreach ($files as [$path, $own]) {
    try {
        $row = Media::find(Media::register($path));
        $w   = $row !== null ? $widths($row) : '';
        printf("Added #%d %s%s\n", (int) ($row['id'] ?? 0), (string) ($row['site_path'] ?? $path), $w !== '' ? ', with widths ' . $w : '');
        $added++;
    } catch (MediaRejected $e) {
        if ($e->getMessage() === Media::ALREADY_REGISTERED) {
            printf("Already in the library: %s\n", $shown($path));
            $already++;
        } elseif ($own) {
            printf("Refused: %s: %s\n", $shown($path), $e->getMessage());
            $failed = true;
        } else {
            printf("Left out: %s: %s\n", $shown($path), $e->getMessage());
        }
    }
}
printf("%d added, %d already in the library.\n", $added, $already);
exit($failed ? 1 : 0);
