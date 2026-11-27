<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\ContentType\Registry;
use Cms\Database\Database;
use Cms\Models\Page;
use Cms\Models\Post;
use Cms\Models\User;
use Cms\Security\FileCheck;
use Cms\Update\Manifest;
use Cms\Update\Release;

/**
 * WHAT SYSTEM HEALTH SAYS, AS ROWS: what this site runs on (`environment()`),
 * what going to production changes (`GoingLive`) and what it holds
 * (`counts()`). Tools → System health draws them and `php admin/bin/health.php`
 * prints them, so the screen and the command cannot disagree.
 *
 * A row is a label, a value, a status, a hint and, where it names things,
 * `items`: each a line of text and the address of its screen. The status is
 * 'ok', 'info', 'warning' or 'error', and only a warning or an error needs
 * attention (`ATTENTION`): 'info' is a fact with nothing to judge, such as how
 * much the uploads take up, and counting it would open a healthy site on a
 * problem.
 */
final class Health
{
    /** The statuses that need attention. */
    public const ATTENTION = ['warning', 'error'];

    /**
     * What this site runs on: the release, PHP and its extensions, the
     * database, the uploads folder, the modules and the migrations.
     *
     * @return array<string, array{label: string, value: string, status: string, hint: string}>
     */
    public static function environment(): array
    {
        $checks = [];

        // Which release this is, first: the question behind most visits.
        $checks['cms'] = Release::health(Release::version(), FileCheck::site() . '/' . Manifest::FILE);

        $phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');
        $checks['php'] = [
            'label'  => 'PHP version',
            'value'  => PHP_VERSION,
            'status' => $phpOk ? 'ok' : 'error',
            'hint'   => $phpOk ? 'PHP 8.2+ required' : 'PHP 8.2+ required — current version is too old',
        ];

        foreach (['pdo', 'pdo_mysql', 'mbstring', 'json', 'fileinfo', 'gd'] as $ext) {
            $loaded = extension_loaded($ext);
            $checks['ext_' . $ext] = [
                'label'  => 'Extension: ' . $ext,
                'value'  => $loaded ? 'Loaded' : 'Missing',
                'status' => $loaded ? 'ok' : 'error',
                'hint'   => '',
            ];
        }

        try {
            $checks['db'] = ['label' => 'Database', 'value' => (string) Database::getInstance()->query('SELECT VERSION()')->fetchColumn(),
                             'status' => 'ok', 'hint' => ''];
        } catch (\Throwable $e) {
            $checks['db'] = ['label' => 'Database', 'value' => 'Connection failed', 'status' => 'error', 'hint' => $e->getMessage()];
        }

        $uploads  = PUBLIC_PATH . '/uploads';
        $writable = is_dir($uploads) && is_writable($uploads);
        $checks['uploads_writable'] = [
            'label'  => 'Uploads folder',
            'value'  => $writable ? 'Writable' : 'Not writable',
            'status' => $writable ? 'ok' : 'error',
            'hint'   => $uploads,
        ];
        $checks['disk_usage'] = ['label' => 'Uploads disk usage', 'value' => self::diskUsage($uploads), 'status' => 'info', 'hint' => ''];

        // ── MODULE SWITCHES, as config.php's `modules` has them ─────────────
        // Which are off, and any switch that is neither yes nor no, which
        // leaves its module on (`Helpers\Modules::notes()`).
        $config   = ConfigFile::read(ROOT_PATH . '/config/config.php');
        $switches = is_array($config) && is_array($config['modules'] ?? null) ? $config['modules'] : [];
        $off      = array_values(array_filter(array_keys(\Cms\Helpers\Modules::ALL),
            static fn (string $m): bool => !\Cms\Helpers\Modules::enabled($m)));
        $notes    = \Cms\Helpers\Modules::notes($switches);
        $checks['modules'] = [
            'label'  => 'Modules',
            'value'  => $off === [] ? 'All on' : 'Switched off: ' . implode(', ', $off),
            'status' => $notes !== [] ? 'warning' : 'info',
            'hint'   => implode(' ', $notes),
        ];

        // ── MIGRATIONS, BY NAME — the CMS's and the site's own ──────────────
        // Asked of the Migrator that runs them, which knows both directories
        // (`admin/site/migrations/`). Counting the files in the CMS's
        // directory against the rows in the table could not say WHICH were
        // waiting, and on a site with migrations of its own read "9 applied /
        // 7 available". A row recorded with no file is named too: nothing in
        // a release removes a migration, so it means code rolled back
        // without its database.
        try {
            $migrator = \Cms\Database\Migrator::forInstall(Database::getInstance(), ROOT_PATH);
            $pending  = $migrator->pending();
            $orphans  = $migrator->orphans();
            $applied  = count($migrator->applied());
            $checks['migrations'] = [
                'label'  => 'Migrations',
                'value'  => $applied . ' applied' . ($pending !== [] ? ', ' . count($pending) . ' waiting' : ''),
                'status' => $pending === [] && $orphans === [] ? 'ok' : 'warning',
                'hint'   => $pending !== []
                    ? 'Waiting: ' . implode(', ', $pending) . '.'
                    : ($orphans !== []
                        ? 'Recorded as applied but not in this release: ' . implode(', ', $orphans)
                          . '. Was the code rolled back without its database?'
                        : ''),
            ];
        } catch (\Throwable $e) {
            // A site migration named like one of the CMS's own (Migrator::
            // files() refuses it) — the message says which to rename.
            $checks['migrations'] = ['label' => 'Migrations', 'value' => 'Cannot be read', 'status' => 'error', 'hint' => $e->getMessage()];
        }

        return $checks;
    }

    /**
     * How many of each thing the site holds: each type's entries out of the
     * Trash, the pages, the accounts.
     *
     * @return array<string, int> label => count
     */
    public static function counts(): array
    {
        $counts = [];
        foreach (Registry::all() as $key => $ct) {
            $counts[(string) $ct['label']] = count(Post::all((string) $key));
        }
        if (\Cms\Helpers\Modules::enabled('pages')) {
            $counts['Pages'] = count(Page::all());
        }
        $counts['Users'] = User::count();

        return $counts;
    }

    /**
     * How many of these rows need attention.
     *
     * @param array<array-key, array{status?: string}> ...$groups
     */
    public static function attention(array ...$groups): int
    {
        $n = 0;
        foreach ($groups as $rows) {
            foreach ($rows as $row) {
                if (in_array((string) ($row['status'] ?? 'ok'), self::ATTENTION, true)) {
                    $n++;
                }
            }
        }

        return $n;
    }

    /** The uploads' size, in words: '—' where there is no folder. */
    private static function diskUsage(string $dir): string
    {
        if (!is_dir($dir)) {
            return '—';
        }
        $bytes = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $bytes += $file->getSize();
            }
        }

        return match (true) {
            $bytes < 1024 * 1024        => round($bytes / 1024, 1) . ' KB',
            $bytes < 1024 * 1024 * 1024 => round($bytes / (1024 * 1024), 1) . ' MB',
            default                     => round($bytes / (1024 * 1024 * 1024), 2) . ' GB',
        };
    }
}
