<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Models\Post;

class ToolsController
{
    /** Tools → the section's own landing page. See SettingsController::index(). */
    public function index(array $vars = []): void
    {
        Auth::requireRole('admin');

        $sectionTitle = 'Tools';
        $sectionLede  = 'Maintenance jobs: moving content in and out, fixing old links, and checking the server.';
        $sections     = [
            ['label' => 'Import and export', 'icon' => 'import-export', 'url' => ADMIN_URL . '/tools/import-export',
             'text'  => 'Take a copy of your content, or bring content in from elsewhere.'],
            ['label' => 'Redirects', 'icon' => 'redirects',       'url' => ADMIN_URL . '/tools/redirects',
             'text'  => 'Send an old web address to a new one, so links to a page you moved still land.'],
            ['label' => 'Static copy', 'icon' => 'static-copy',   'url' => ADMIN_URL . '/tools/static-copy',
             'text'  => 'The whole site as files, for a web host that runs no CMS.'],
            ['label' => 'Broken links', 'icon' => 'broken-links', 'url' => ADMIN_URL . '/tools/broken-links',
             'text'  => 'Every link on the site to one of its own pages or files that finds nothing.'],
            ['label' => 'Backups', 'icon' => 'backups', 'url' => ADMIN_URL . '/tools/backups',
             'text'  => 'The database and the uploads, kept on the server every day or every week.'],
            ['label' => 'System health', 'icon' => 'system-health',   'url' => ADMIN_URL . '/tools/system-health',
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
     * Tools → System health: what this site runs on (`Site\Health`), what
     * going to production changes (`Site\GoingLive`), its sample entries and
     * what it holds. `php admin/bin/health.php` prints the same rows.
     */
    public function systemHealth(array $vars = []): void
    {
        Auth::requireRole('admin');
        // The address this is opened at, should the site have moved (`Site\OldAddresses`).
        \Cms\Site\OldAddresses::note();

        $checks    = \Cms\Site\Health::environment();
        $goingLive = \Cms\Site\GoingLive::rows();
        $liveTitle = \Cms\Site\GoingLive::title();
        $samples   = Post::samples();
        $counts    = \Cms\Site\Health::counts();
        // The site's own scheduled tasks, and a line for each of their runs.
        $tasks     = \Cms\Site\Tasks::rows();
        $taskRuns  = $tasks !== [] ? \Cms\Site\Tasks::runs(null, 20) : [];
        $problems  = \Cms\Site\Health::attention($checks, $goingLive, $tasks);

        // What a button did, on the way back here.
        $trashed  = isset($_GET['trashed']) && ctype_digit((string) $_GET['trashed']) ? (int) $_GET['trashed'] : null;
        $replaced = isset($_GET['replaced']) && ctype_digit((string) $_GET['replaced']) ? (int) $_GET['replaced'] : null;

        $pageTitle   = 'System health';
        $currentPage = 'system-health';
        require ROOT_PATH . '/admin/templates/tools/system-health.php';
    }

    /**
     * POST /admin/tools/system-health/samples — every sample entry to the
     * Trash (`Post::trashSamples()`), whichever copy this is. Each can be
     * restored from its type's Trash, and comes back Private.
     */
    public function trashSamples(array $vars = []): void
    {
        Auth::requireRole('admin');
        $n = Post::trashSamples();
        header('Location: ' . ADMIN_URL . '/tools/system-health?trashed=' . $n, true, 303);
        exit;
    }

    /**
     * POST /admin/tools/system-health/addresses — every earlier address of
     * the site replaced with its own, wherever System health found one
     * (`Site\OldAddresses::replace()`), each page and entry changed given a
     * revision first.
     */
    public function replaceAddresses(array $vars = []): void
    {
        Auth::requireRole('admin');
        $n = \Cms\Site\OldAddresses::replace((int) (Auth::user()['id'] ?? 0));
        header('Location: ' . ADMIN_URL . '/tools/system-health?replaced=' . $n, true, 303);
        exit;
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

        $pageTitle   = 'Import and export';
        $currentPage = 'import-export';
        require ROOT_PATH . '/admin/templates/tools/import-export.php';
    }
}
