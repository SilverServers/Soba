<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Export\StaticCopy;
use InvalidArgumentException;
use RuntimeException;

/**
 * TOOLS → STATIC COPY: a copy of the site for a host that runs no CMS
 * (`Export\StaticCopy`), made from the admin and downloaded as one file.
 *
 * ── IN STEPS, POLLED ────────────────────────────────────────────────────────
 * A copy takes longer than a web request may run on shared hosting, so it is a
 * job on disk: the form starts it, and the screen's script asks `step()` to go
 * on for a few seconds at a time until it is done, as the assistant is polled
 * rather than streamed. A screen closed half way leaves a job the next visit
 * picks up where it stopped.
 *
 * ── WHERE THE ADMIN CANNOT MAKE ONE ─────────────────────────────────────────
 * A copy draws each page in a PHP process of its own. A host that does not let
 * the admin start one (`StaticCopy::cannotRun()`) gets the command instead of
 * the form: a button that could only fail is not drawn.
 */
final class StaticCopyController
{
    /** How long one step goes on, well inside a host's time limit for a request. */
    private const STEP_SECONDS = 8.0;

    public function index(array $vars = []): void
    {
        Auth::requireRole('admin');
        $this->render();
    }

    public function start(array $vars = []): void
    {
        Auth::requireRole('admin');
        $address  = trim((string) ($_POST['address'] ?? ''));
        $anywhere = !empty($_POST['anywhere']);
        $noPhp    = !empty($_POST['no_php']);
        $instead  = mb_substr(trim((string) ($_POST['instead'] ?? '')), 0, 500);
        $typed    = ['address' => $address, 'anywhere' => $anywhere, 'no_php' => $noPhp, 'instead' => $instead];

        if (StaticCopy::cannotRun() !== null) {
            $this->render($typed);
            return;
        }
        try {
            StaticCopy::start($address, !$noPhp, $instead, $anywhere);
        } catch (InvalidArgumentException | RuntimeException $e) {
            $this->render($typed, $e->getMessage());
            return;
        }
        header('Location: ' . ADMIN_URL . '/tools/static-copy', true, 303);
    }

    /** One step of a copy, as JSON for the screen's script. */
    public function step(array $vars = []): void
    {
        Auth::requireRole('admin');
        $copy = StaticCopy::open((string) ($vars['id'] ?? ''));
        header('Content-Type: application/json; charset=utf-8');
        if ($copy === null) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'That copy is no longer here.']);
            return;
        }
        // THE SESSION IS LET GO FIRST: a step runs for seconds, and every other
        // admin request of this person's would wait on its lock.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(120);
        try {
            $done = $copy->step(self::STEP_SECONDS);
        } catch (RuntimeException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            return;
        }
        $status = $copy->status();
        echo json_encode(['success' => true, 'data' => [
            'done'   => $done,
            'pages'  => $status['pages'],
            'files'  => $status['files'],
            'queued' => $status['queued'],
        ]]);
    }

    /** The finished copy's archive, as a download. */
    public function download(array $vars = []): void
    {
        Auth::requireRole('admin');
        $copy    = StaticCopy::open((string) ($vars['id'] ?? ''));
        $archive = $copy?->status()['archive'];
        if ($copy === null || !is_string($archive) || !is_file($archive)) {
            http_response_code(404);
            require ROOT_PATH . '/admin/templates/404.php';
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $name = basename($archive);
        header('Content-Type: ' . (str_ends_with($name, '.zip') ? 'application/zip' : 'application/gzip'));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize($archive));
        header('Cache-Control: no-store');
        readfile($archive);
    }

    /**
     * The screen: the newest copy (going, or done and ready), and the form for
     * a new one — what was typed, and why it was refused, after a refusal.
     *
     * @param array{address: string, anywhere: bool, no_php: bool, instead: string}|null $typed
     */
    private function render(?array $typed = null, ?string $refused = null): void
    {
        $cannot = StaticCopy::cannotRun();
        $ids    = StaticCopy::ids();
        $latest = $ids !== [] ? StaticCopy::open($ids[0]) : null;
        $copy   = $latest !== null ? ['id' => $latest->id()] + $latest->status() : null;
        $form   = $typed ?? [
            // The last copy's choices, else the site's own address.
            'address'  => $copy['address'] ?? rtrim((string) BASE_URL, '/'),
            'anywhere' => $copy !== null && $copy['anywhere'],
            'no_php'   => $copy !== null && !$copy['php'],
            'instead'  => $copy !== null && $latest !== null ? $latest->instead() : '',
        ];

        $pageTitle   = 'Static copy';
        $currentPage = 'static-copy';
        require ROOT_PATH . '/admin/templates/tools/static-copy.php';
    }
}
