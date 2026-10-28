<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Database\Database;
use Cms\ContentType\Registry;
use Cms\Models\Media;
use Cms\Models\Page;
use Cms\Models\Post;
use Cms\Models\User;

class ToolsController
{
    /** Tools → the section's own landing page. See SettingsController::index(). */
    public function index(array $vars = []): void
    {
        Auth::requireRole('admin');

        $sectionTitle = 'Tools';
        $sectionLede  = 'Maintenance jobs: moving content in and out, fixing old links, and checking the server.';
        $sections     = [
            ['label' => 'Import / Export', 'icon' => 'import-export', 'url' => ADMIN_URL . '/tools/import-export',
             'text'  => 'Take a copy of your content, or bring content in from elsewhere.'],
            ['label' => 'Redirects', 'icon' => 'redirects',       'url' => ADMIN_URL . '/tools/redirects',
             'text'  => 'Send an old web address to a new one, so links to a page you moved still land.'],
            ['label' => 'System Health', 'icon' => 'system-health',   'url' => ADMIN_URL . '/tools/system-health',
             'text'  => 'What this site is running on, and whether anything needs attention.'],
        ];

        $pageTitle   = 'Tools';
        $currentPage = 'tools-index';
        require ROOT_PATH . '/admin/templates/partials/section-index.php';
    }

    // TOOLS → REDIRECTS IS `RedirectsController`, not a method here. It was one
    // line — `header('Location: …/settings/seo?tab=redirects')` — so the section
    // landing card and the sidebar both offered an entry that navigated out of
    // Tools entirely. See that controller's header.

    /**
     * Tools → System Health — read-only diagnostic page.
     */
    public function systemHealth(array $vars = []): void
    {
        Auth::requireRole('admin');

        $checks = [];

        // PHP version
        $phpVersion = PHP_VERSION;
        $phpOk      = version_compare($phpVersion, '8.2.0', '>=');
        $checks['php'] = [
            'label'  => 'PHP Version',
            'value'  => $phpVersion,
            'status' => $phpOk ? 'ok' : 'error',
            'hint'   => $phpOk ? 'PHP 8.2+ required' : 'PHP 8.2+ required — current version is too old',
        ];

        // Required extensions
        $requiredExts = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'fileinfo', 'gd'];
        foreach ($requiredExts as $ext) {
            $loaded = extension_loaded($ext);
            $checks['ext_' . $ext] = [
                'label'  => 'Extension: ' . $ext,
                'value'  => $loaded ? 'Loaded' : 'Missing',
                'status' => $loaded ? 'ok' : 'error',
                'hint'   => '',
            ];
        }

        // MariaDB / MySQL version
        try {
            $pdo       = Database::getInstance();
            $dbVersion = $pdo->query("SELECT VERSION()")->fetchColumn();
            $checks['db'] = [
                'label'  => 'Database',
                'value'  => (string) $dbVersion,
                'status' => 'ok',
                'hint'   => '',
            ];
        } catch (\Throwable $e) {
            $checks['db'] = [
                'label'  => 'Database',
                'value'  => 'Connection failed',
                'status' => 'error',
                'hint'   => $e->getMessage(),
            ];
        }

        // Uploads directory
        $uploadsPath = PUBLIC_PATH . '/uploads';
        $uploadsWritable = is_dir($uploadsPath) && is_writable($uploadsPath);
        $checks['uploads_writable'] = [
            'label'  => 'Uploads Directory',
            'value'  => $uploadsWritable ? 'Writable' : 'Not writable',
            'status' => $uploadsWritable ? 'ok' : 'error',
            'hint'   => $uploadsPath,
        ];

        // Disk usage on uploads/
        $diskUsage = '—';
        if (is_dir($uploadsPath)) {
            $bytes = 0;
            $iter  = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($uploadsPath, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $file) {
                if ($file->isFile()) {
                    $bytes += $file->getSize();
                }
            }
            if ($bytes < 1024 * 1024) {
                $diskUsage = round($bytes / 1024, 1) . ' KB';
            } elseif ($bytes < 1024 * 1024 * 1024) {
                $diskUsage = round($bytes / (1024 * 1024), 1) . ' MB';
            } else {
                $diskUsage = round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
            }
        }
        $checks['disk_usage'] = [
            'label'  => 'Uploads Disk Usage',
            'value'  => $diskUsage,
            'status' => 'info',
            'hint'   => '',
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
            $checks['migrations'] = [
                'label'  => 'Migrations',
                'value'  => 'Cannot be read',
                'status' => 'error',
                'hint'   => $e->getMessage(),
            ];
        }

        // Content counts
        $counts = [];
        foreach (Registry::all() as $key => $ct) {
            $counts[$ct['label']] = count(Post::all($key));
        }
        if (\Cms\Helpers\Modules::enabled('pages')) {
            $counts['Pages'] = count(Page::all());
        }
        $counts['Users'] = User::count();

        $pageTitle   = 'System Health';
        $currentPage = 'system-health';
        require ROOT_PATH . '/admin/templates/tools/system-health.php';
    }

    /**
     * Tools → Import / Export — export UI (import is a placeholder for v1).
     */
    public function importExport(array $vars = []): void
    {
        Auth::requireRole('admin');

        // THE SAME FOUR THE IMPORT CONTROLLER SETS. One template, two entry
        // points — this one for arriving, `ImportController` for the two steps
        // of an import — and a template that reads an undefined variable is a
        // notice in the log on the screen everyone lands on first.
        $importPlan  = null;
        $importFile  = null;
        $importError = null;
        $importDone  = null;

        $pageTitle   = 'Import / Export';
        $currentPage = 'import-export';
        require ROOT_PATH . '/admin/templates/tools/import-export.php';
    }
}
