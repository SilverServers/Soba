<?php

declare(strict_types=1);

/**
 * Update an installed CMS to a newer release, or undo that.
 *
 * AN UPDATE, START TO FINISH
 *
 *   1. Unpack the update tarball in the site's project root (the directory
 *      holding admin/ and www/). It unpacks entirely inside
 *      admin/updates/incoming/<version>/, so it cannot overwrite anything:
 *
 *        tar -xzf cms-<profile>-<version>-update.tar.gz --keep-old-files
 *
 *   2. See what it would do. Changes nothing:
 *
 *        php admin/updates/incoming/<version>/admin/bin/update.php check
 *
 *   3. Do it:
 *
 *        php admin/updates/incoming/<version>/admin/bin/update.php apply
 *
 *      Maintenance on (visitors get a 503) → back up the database and every
 *      file it replaces → swap the CMS's paths → run the migrations → check the
 *      new release answers, through the command line and the web server → off.
 *      Any failure rolls everything back and proves the rollback.
 *
 * AFTERWARDS, FROM THE INSTALLED RELEASE
 *
 *   php admin/bin/update.php status             version, integrity, history
 *   php admin/bin/update.php rollback --yes     undo the last update (the
 *                                               database goes back to its
 *                                               backup: later saves are lost)
 *
 * OPTIONS
 *
 *   --discard-local-changes  update even though the site changed some of
 *                            The CMS's own files (they are kept in the backup)
 *   --reinstall              put a fresh copy of the same version back
 *   --probe-url=<url>        where the web server answers (default: base_url)
 *   --no-probe               do not check through the web server
 *   --settle=<seconds>       wait after the first swap for OPcache (default 3)
 *   --site=<path>            the site's root, if not the one this file is in
 *
 * Exit status: 0 done · 1 failed and rolled back · 2 refused, nothing changed ·
 * 3 refused because of local changes, nothing changed · 4 ROLLBACK FAILED.
 *
 * `check`, `apply` and `status` first check the site's own files in
 * admin/config/ (`php -l`, through `Cms\Site\ConfigFile`): one that does not
 * compile, such as a byte-order mark before `<?php` in a file that declares
 * strict_types, stops every page, and is named; `check` and `apply` refuse
 * until it is put right.
 *
 * What is the CMS's and what is the site's: admin/core/Update/ownership.php.
 * One of the operator scripts in admin/bin/ that ship in a package
 * (admin/core/Update/ownership.php lists them).
 */

use Cms\Update\Updater;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Where is this file? Inside an unpacked package
// (<site>/admin/updates/incoming/<version>/admin/bin/update.php), or inside
// the installed release (<site>/admin/bin/update.php).
$self   = dirname(__DIR__, 2);
$staged = basename(dirname($self)) === 'incoming'
    && basename(dirname($self, 2)) === 'updates'
    && basename(dirname($self, 3)) === 'admin';

$command = null;
$id      = null;
$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m) === 1) {
        $options[$m[1]] = $m[2] ?? true;
    } elseif ($command === null) {
        $command = $arg;
    } elseif ($command === 'rollback' && $id === null) {
        $id = $arg;
    } else {
        $command = 'help';
    }
}
$command ??= 'help';

$site = isset($options['site']) ? rtrim((string) realpath((string) $options['site']), '/') : ($staged ? dirname($self, 4) : $self);
if ($site === '' || !is_dir($site . '/admin')) {
    fwrite(STDERR, "No CMS site at " . ($options['site'] ?? $site) . "\n");
    exit(Updater::EXIT_BLOCKED);
}

require $self . '/admin/vendor/autoload.php';

$updater = new Updater($site, $staged ? $self : null, $options, static function (string $line): void {
    echo $line, "\n";
});

try {
    $code = match ($command) {
        'check'    => $updater->check(),
        'apply'    => $updater->apply(),
        'status'   => $updater->status(),
        'rollback' => $updater->rollback($id),
        default    => (static function (): int {
            fwrite(STDERR, "Usage: php update.php check|apply|status|rollback [<id>] [--yes] [options]\n"
                . "Read the top of this file for the whole procedure.\n");
            return Updater::EXIT_BLOCKED;
        })(),
    };
} catch (\Throwable $e) {
    fwrite(STDERR, 'update.php: ' . $e->getMessage() . "\n");
    $code = Updater::EXIT_BLOCKED;
}
exit($code);
