<?php

declare(strict_types=1);

/**
 * One setting of config.php, from the command line, the rest of the file kept
 * as it is: its other settings, its comments and its layout
 * (`Cms\Site\ConfigWriter`).
 *
 *   php admin/bin/config.php get <key>                the value, as JSON; exit 1 when it is not set
 *   php admin/bin/config.php set <key> <value>        a text value
 *   php admin/bin/config.php set <key> <json> --json  any other: true, 7200, ["a","b"], {"pages":false}
 *   php admin/bin/config.php unset <key>
 *
 *   --config=<path>   a config.php other than admin/config/config.php
 *
 * The changed file is written beside config.php and read back by a PHP of its
 * own; it takes config.php's place only when it compiles and gives the
 * settings config.php gave with that one changed. Otherwise config.php is left
 * as it was, and the reason is said.
 *
 * Exit status: 0 done (or already so), 1 refused or not set, 2 bad usage.
 *
 * One of the operator scripts in admin/bin/ that ship in a package (listed in
 * admin/core/Update/ownership.php).
 */

use Cms\Site\ConfigWriter;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$admin = dirname(__DIR__);
require $admin . '/vendor/autoload.php';

$config = $admin . '/config/config.php';
$json   = false;
$words  = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--config=')) {
        $config = substr($arg, strlen('--config='));
    } elseif ($arg === '--json') {
        $json = true;
    } else {
        $words[] = $arg;
    }
}
$usage = static function (): never {
    fwrite(STDERR, "Usage: php admin/bin/config.php get <key> | set <key> <value> [--json] | unset <key> [--config=<path>]\n");
    exit(2);
};
[$command, $key, $value] = array_pad($words, 3, null);
if (!in_array($command, ['get', 'set', 'unset'], true) || $key === null
    || ($command === 'set') !== ($value !== null) || count($words) > 3) {
    $usage();
}
$name  = str_ends_with($config, '/admin/config/config.php') ? 'admin/config/config.php' : $config;
$shown = static fn (mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

try {
    if ($command === 'get') {
        $settings = ConfigWriter::readApart($config);
        if (!array_key_exists($key, $settings)) {
            fwrite(STDERR, "{$name}: {$key} is not set.\n");
            exit(1);
        }
        echo $shown($settings[$key]), "\n";
        exit(0);
    }
    if ($command === 'set') {
        if ($json) {
            try {
                $value = json_decode((string) $value, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                fwrite(STDERR, "That is not JSON: {$e->getMessage()}.\n");
                exit(2);
            }
            if ($value === null) {
                fwrite(STDERR, "To take a setting out, use unset.\n");
                exit(2);
            }
        }
        echo ConfigWriter::set($config, (string) $key, $value)
            ? "{$name}: {$key} is now {$shown($value)}.\n"
            : "{$name}: {$key} is already {$shown($value)}; nothing changed.\n";
        exit(0);
    }
    echo ConfigWriter::set($config, (string) $key, null)
        ? "{$name}: {$key} is no longer set.\n"
        : "{$name}: {$key} was not set; nothing changed.\n";
    exit(0);
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
