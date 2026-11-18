<?php

declare(strict_types=1);

/**
 * form-numbers — the number the next form submission takes, from the command line.
 *
 *   php admin/bin/form-numbers.php
 *   php admin/bin/form-numbers.php next <number>
 *
 * A submission's number is what a site sends with its conversion and what its
 * owner quotes back, so a site moving its form here from a system that numbered
 * its own leads carries the numbers on: its history is imported with its own
 * (`FormSubmission::create()`'s `id`), and new submissions start past whatever
 * the old system may still give out while visitors' open pages post to it.
 *
 *   (none)  the highest number a submission has, and the number the next new
 *           one takes
 *   next    the next new submission is <number>, and those after it follow on.
 *           Refused at or below the highest number a submission has, so two
 *           submissions never share one; history imported later below it keeps
 *           its own. Kept until a submission takes it, a restart of the
 *           database included (`FormSubmission::setNextNumber()`).
 *
 * Exit status: 0 done, 1 refused, 2 bad usage.
 *
 * One of the operator scripts in admin/bin/ that ship in a package (listed in
 * admin/core/Update/ownership.php).
 */

use Cms\Database\Database;
use Cms\Models\FormSubmission;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ROOT_PATH', dirname(__DIR__));
define('PUBLIC_PATH', dirname(__DIR__, 2) . '/www');
require ROOT_PATH . '/vendor/autoload.php';

const USAGE = "Usage:\n"
    . "  php admin/bin/form-numbers.php                  the highest number used, and the next\n"
    . "  php admin/bin/form-numbers.php next <number>    the next new submission is <number>\n";

$args = array_slice($argv, 1);
if (!($args === [] || (count($args) === 2 && $args[0] === 'next'))) {
    fwrite(STDERR, USAGE);
    exit(2);
}

$config = ROOT_PATH . '/config/config.php';
if (!is_file($config)) {
    fwrite(STDERR, "No config at {$config}.\n");
    exit(1);
}
$settings = \Cms\Site\ConfigFile::read($config);
Database::init(is_array($settings) ? $settings : []);
Database::runMigrations(ROOT_PATH . '/core/Database/migrations', ROOT_PATH . '/site/migrations');

if ($args !== []) {
    if (preg_match('/^[1-9]\d{0,9}$/', $args[1]) !== 1) {
        fwrite(STDERR, "A submission number is a whole number from 1, such as 1001.\n");
        exit(2);
    }
    try {
        FormSubmission::setNextNumber((int) $args[1]);
    } catch (\InvalidArgumentException | \RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . " Nothing was changed.\n");
        exit(1);
    }
}

$highest = FormSubmission::highestNumber();
$ahead   = FormSubmission::numberAhead();
echo $highest > 0 ? "The highest submission number is #{$highest}.\n" : "No submission has a number yet.\n";
echo $ahead !== null
    ? "The next new submission is #{$ahead}, as set.\n"
    : "The next new submission follows the highest number given out.\n";
exit(0);
