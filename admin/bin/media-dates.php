<?php

declare(strict_types=1);

/**
 * media-dates — the library's two dates, from the command line.
 *
 *   php admin/bin/media-dates.php list [--folder=<id>] [--added-on=<YYYY-MM-DD>]
 *   php admin/bin/media-dates.php set <ids> [--added=<when>] [--taken=<when>|--taken=none]
 *   php admin/bin/media-dates.php taken <directory> [--write]
 *
 * Every date here is UTC, as stored: `list` prints it so, and `set` reads it so
 * unless it carries its own offset (2019-07-04T14:30:00-07:00). <ids> is one id,
 * a list (12,15,19) or a range (120-480), or a mix of them.
 *
 *   list    each file's id, date added, date taken and name, oldest first; with
 *           --added-on, the files added that day (UTC), which finds what one
 *           import brought in.
 *   set     the dates of the files named, printing each one's old dates with
 *           its new, so a change can be put back. An archive imported before
 *           imports kept their dates sits above everything added since, dated
 *           the day it came in; this puts it back where it belongs.
 *   taken   the date taken, read from the original files a library was
 *           imported from: each JPEG, PNG and WebP in <directory> and the
 *           directories in it, matched by its name to a picture in the library
 *           with no date taken (the library keeps a copy without its EXIF, so
 *           the original is the only place the date still is). Two originals
 *           of one name with different dates are left alone and named. Without
 *           --write it prints what it would set and changes nothing.
 *
 * Exit status: 0 done, 1 a date or a directory refused, 2 bad usage.
 *
 * One of the operator scripts in admin/bin/ that ship in a package (listed in
 * admin/core/Update/ownership.php).
 */

use Cms\Database\Database;
use Cms\Media\Exif;
use Cms\Models\Media;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ROOT_PATH', dirname(__DIR__));
define('PUBLIC_PATH', dirname(__DIR__, 2) . '/www');
require ROOT_PATH . '/vendor/autoload.php';

const USAGE = "Usage:\n"
    . "  php admin/bin/media-dates.php list [--folder=<id>] [--added-on=<YYYY-MM-DD>]\n"
    . "  php admin/bin/media-dates.php set <ids> [--added=<when>] [--taken=<when>|--taken=none]\n"
    . "  php admin/bin/media-dates.php taken <directory> [--write]\n"
    . "Dates are UTC (2019-07-04 21:30), or carry their own offset (2019-07-04T14:30:00-07:00).\n";

$args    = array_slice($argv, 1);
$command = array_shift($args) ?? '';
$opts    = [];
$plain   = [];
foreach ($args as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m) === 1) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        $plain[] = $arg;
    }
}
$allowed = ['list' => ['folder', 'added-on'], 'set' => ['added', 'taken'], 'taken' => ['write']];
if (!isset($allowed[$command]) || array_diff(array_keys($opts), $allowed[$command]) !== []) {
    fwrite(STDERR, USAGE);
    exit(2);
}

$config = ROOT_PATH . '/config/config.php';
if (!is_file($config)) {
    fwrite(STDERR, "No config at {$config}.\n");
    exit(1);
}
$settings = require $config;
Database::init($settings);
$pdo = Database::getInstance();

$name = static fn (array $row): string => (string) ($row['original_name'] !== '' ? $row['original_name'] : $row['filename']);

// ── list ──────────────────────────────────────────────────────────────────
if ($command === 'list') {
    if ($plain !== []
        || (isset($opts['folder']) && !ctype_digit((string) $opts['folder']))
        || (isset($opts['added-on']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $opts['added-on']) !== 1)) {
        fwrite(STDERR, USAGE);
        exit(2);
    }
    $where = [];
    $bind  = [];
    if (isset($opts['folder'])) {
        $where[] = 'folder_id = ?';
        $bind[]  = (int) $opts['folder'];
    }
    if (isset($opts['added-on'])) {
        $where[] = 'created_at >= ? AND created_at < ? + INTERVAL 1 DAY';
        $bind[]  = $opts['added-on'] . ' 00:00:00';
        $bind[]  = $opts['added-on'] . ' 00:00:00';
    }
    $stmt = $pdo->prepare('SELECT id, created_at, taken_at, original_name, filename FROM attachments'
        . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at ASC, id ASC');
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    printf("%6s  %-19s  %-19s  %s\n", 'id', 'added (UTC)', 'taken (UTC)', 'name');
    foreach ($rows as $row) {
        printf("%6d  %-19s  %-19s  %s\n", (int) $row['id'], $row['created_at'], $row['taken_at'] ?? '-', $name($row));
    }
    printf("%d file(s).\n", count($rows));
    exit(0);
}

// ── set ───────────────────────────────────────────────────────────────────
if ($command === 'set') {
    if (count($plain) !== 1 || preg_match('/^\d+(?:-\d+)?(?:,\d+(?:-\d+)?)*$/', $plain[0]) !== 1
        || (!isset($opts['added']) && !isset($opts['taken']))) {
        fwrite(STDERR, USAGE);
        exit(2);
    }
    $ids = [];
    foreach (explode(',', $plain[0]) as $part) {
        [$from, $to] = array_map('intval', explode('-', $part) + [1 => $part]);
        if ($to < $from || $to - $from > 100000) {
            fwrite(STDERR, "A range runs from its lower id to its higher, 100,000 at most: {$part}\n");
            exit(2);
        }
        array_push($ids, ...range($from, $to));
    }
    $ids = array_values(array_unique($ids));

    $data = [];
    try {
        if (isset($opts['added'])) {
            $data['created_at'] = Media::when((string) $opts['added']);
            if ($data['created_at'] === null) {
                throw new InvalidArgumentException('A file always has a date added: --added needs a date.');
            }
        }
        if (isset($opts['taken'])) {
            $data['taken_at'] = $opts['taken'] === 'none' ? null : Media::when((string) $opts['taken']);
        }
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }

    $changed = 0;
    $missing = 0;
    foreach (array_chunk($ids, 500) as $chunk) {
        $stmt = $pdo->prepare('SELECT id, created_at, taken_at, original_name, filename FROM attachments WHERE id IN ('
            . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY id');
        $stmt->execute($chunk);
        $rows    = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $missing += count($chunk) - count($rows);
        foreach ($rows as $row) {
            Media::update((int) $row['id'], $data);
            $changed++;
            printf("%6d  added %s -> %s  taken %s -> %s  %s\n", (int) $row['id'],
                $row['created_at'], $data['created_at'] ?? $row['created_at'],
                $row['taken_at'] ?? '-', array_key_exists('taken_at', $data) ? ($data['taken_at'] ?? '-') : ($row['taken_at'] ?? '-'),
                $name($row));
        }
    }
    printf("%d file(s) changed.%s\n", $changed, $missing > 0 ? " {$missing} id(s) named no file." : '');
    exit(0);
}

// ── taken ─────────────────────────────────────────────────────────────────
if (count($plain) !== 1 || ($opts['write'] ?? true) !== true) {
    fwrite(STDERR, USAGE);
    exit(2);
}
$dir = rtrim($plain[0], '/');
if (!is_dir($dir) || !is_readable($dir)) {
    fwrite(STDERR, "Not a directory this can read: {$dir}\n");
    exit(1);
}
$write = isset($opts['write']);

/**
 * THE NAME A FILE IS MATCHED BY, the way the library writes it into a stored
 * name (`Media::storedName()`): lower case, nothing but letters, digits, dots,
 * dashes and underscores, and no extension (a JPEG kept as a WebP changes it).
 * A stored name loses the unique prefix it was given first.
 */
$key = static function (string $file, bool $stored = false): string {
    $safe = ltrim((string) preg_replace('/[^a-z0-9._-]/', '', strtolower($file)), '.');
    if ($stored) {
        $safe = (string) preg_replace('/^[0-9a-f]{13,}\.\d+_/', '', $safe);
    }
    return (string) preg_replace('/\.[a-z0-9]+$/', '', $safe);
};

// Every original's date, by the name it is matched by.
$finfo  = finfo_open(FILEINFO_MIME_TYPE);
$dates  = [];   // key => [date => first path]
$read   = 0;
$dated  = 0;
$walker = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($walker as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile() || preg_match('/\.(jpe?g|png|webp)$/i', $file->getFilename()) !== 1) {
        continue;
    }
    $path = $file->getPathname();
    $mime = $finfo !== false ? (string) finfo_file($finfo, $path) : '';
    $read++;
    $when = Exif::taken($path, $mime);
    if ($when !== null) {
        $dated++;
        $dates[$key($file->getFilename())][$when] ??= substr($path, strlen($dir) + 1);
    }
}

$stmt = $pdo->query("SELECT id, original_name, filename FROM attachments
                     WHERE taken_at IS NULL AND mime_type LIKE 'image/%' ORDER BY id");
$undated = $stmt->fetchAll(PDO::FETCH_ASSOC);
$set = 0;
$torn = 0;
foreach ($undated as $row) {
    $found = $dates[$key((string) $row['filename'], true)] ?? $dates[$key((string) $row['original_name'])] ?? null;
    if ($found === null) {
        continue;
    }
    if (count($found) > 1) {
        $torn++;
        printf("%6d  %s: originals of this name disagree (%s); left as it is\n", (int) $row['id'], $name($row),
            implode(', ', array_map(static fn (string $d, string $p): string => "{$d} in {$p}", array_keys($found), $found)));
        continue;
    }
    $when = (string) array_key_first($found);
    if ($write) {
        Media::update((int) $row['id'], ['taken_at' => $when]);
    }
    $set++;
    printf("%6d  taken - -> %s  %s  (from %s)\n", (int) $row['id'], $when, $name($row), $found[$when]);
}
printf("%d picture(s) read in %s, %d with a date. %d of the library's %d undated picture(s) %s.%s\n",
    $read, $dir, $dated, $set, count($undated),
    $write ? 'now have their date taken' : 'would have their date taken: run again with --write to set them',
    $torn > 0 ? " {$torn} left alone: their originals disagree." : '');
exit(0);
