<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Models\Option;
use Cms\Site\Backups;
use RuntimeException;

/**
 * TOOLS → BACKUPS (`Site\Backups`): how often one is made and how many are
 * kept, the ones there are, each to download or delete, and Back up now.
 *
 * Back up now starts a job and the screen asks it to go on a few seconds at a
 * time until it is done (`static-copy.js`, the copy's own stepping), so a
 * large site's uploads never outlast a request. Putting one back is the
 * command line's (`admin/bin/backup.php restore`), and the screen says how.
 */
final class BackupsController
{
    private const STEP_SECONDS = 8.0;

    public function index(array $vars = []): void
    {
        Auth::requireRole('admin');
        $this->render();
    }

    /** POST /admin/tools/backups — the timetable and how many are kept. */
    public function save(array $vars = []): void
    {
        Auth::requireRole('admin');
        $every = (string) ($_POST['backup_every'] ?? '');
        $keep  = trim((string) ($_POST['backup_keep'] ?? ''));
        $errors = [];
        if (!array_key_exists($every, Backups::EVERY)) {
            $errors[] = 'Choose how often a backup is made.';
        }
        if (preg_match('/^\d{1,2}$/', $keep) !== 1 || (int) $keep < 1 || (int) $keep > Backups::MAX_KEEP) {
            $errors[] = 'Keep between 1 and ' . Backups::MAX_KEEP . ' backups.';
        }
        if ($errors !== []) {
            $this->render($errors, ['every' => $every, 'keep' => $keep]);
            return;
        }
        Option::set(Backups::EVERY_KEY, $every);
        Option::set(Backups::KEEP_KEY, (string) (int) $keep);
        header('Location: ' . ADMIN_URL . '/tools/backups?saved=1', true, 303);
        exit;
    }

    /** POST /admin/tools/backups/now — start one; the screen makes it. */
    public function now(array $vars = []): void
    {
        Auth::requireRole('admin');
        try {
            Backups::start('by hand');
        } catch (RuntimeException $e) {
            $this->render([$e->getMessage()]);
            return;
        }
        header('Location: ' . ADMIN_URL . '/tools/backups', true, 303);
        exit;
    }

    /** POST /admin/tools/backups/{id}/step — a few seconds more, as JSON. */
    public function step(array $vars = []): void
    {
        Auth::requireRole('admin');
        header('Content-Type: application/json; charset=utf-8');
        $job = Backups::running();
        if ($job === null || (string) $job['id'] !== (string) ($vars['id'] ?? '')) {
            $done = Backups::file((string) ($vars['id'] ?? '')) !== null;
            http_response_code($done ? 200 : 404);
            echo json_encode($done
                ? ['success' => true, 'data' => ['done' => true, 'pages' => 0, 'files' => 0, 'queued' => 0]]
                : ['success' => false, 'error' => 'That backup is no longer being made.']);
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(120);
        try {
            $done = Backups::step($job, self::STEP_SECONDS);
        } catch (RuntimeException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            return;
        }
        $job = Backups::running() ?? $job;
        echo json_encode(['success' => true, 'data' => [
            'done'   => $done,
            'pages'  => 0,
            'files'  => (int) $job['offset'],
            'queued' => $done ? 0 : max(0, (int) $job['count'] - (int) $job['offset']),
        ]]);
    }

    /** GET /admin/tools/backups/{id}/download — the backup's file. */
    public function download(array $vars = []): void
    {
        Auth::requireRole('admin');
        $file = Backups::file((string) ($vars['id'] ?? ''));
        if ($file === null) {
            http_response_code(404);
            require ROOT_PATH . '/admin/templates/404.php';
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $host = (string) (parse_url(BASE_URL, PHP_URL_HOST) ?? 'site');
        header('Content-Type: application/x-tar');
        header('Content-Disposition: attachment; filename="' . $host . '-backup-' . basename($file) . '"');
        header('Content-Length: ' . (string) filesize($file));
        header('Cache-Control: no-store');
        readfile($file);
    }

    /** POST /admin/tools/backups/{id}/delete */
    public function delete(array $vars = []): void
    {
        Auth::requireRole('admin');
        if (Backups::file((string) ($vars['id'] ?? '')) !== null) {
            Backups::remove((string) $vars['id']);
        }
        header('Location: ' . ADMIN_URL . '/tools/backups', true, 303);
        exit;
    }

    /**
     * @param list<string>              $errors
     * @param array<string, string>|null $typed
     */
    private function render(array $errors = [], ?array $typed = null): void
    {
        $user    = Auth::user();
        $backups = Backups::all();
        $running = Backups::running();
        $every   = $typed['every'] ?? Backups::every();
        $keep    = $typed['keep'] ?? (string) Backups::keep();
        $saved   = isset($_GET['saved']) && $errors === [];

        $pageTitle   = 'Backups';
        $currentPage = 'backups';
        require ROOT_PATH . '/admin/templates/tools/backups.php';
    }
}
