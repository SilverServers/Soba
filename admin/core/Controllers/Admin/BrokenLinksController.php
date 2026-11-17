<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\ContentType\Registry;
use Cms\Export\StaticCopy;
use Cms\Models\Page;
use Cms\Models\Post;
use RuntimeException;

/**
 * TOOLS → BROKEN LINKS: every link on the site to one of its own addresses
 * that finds nothing, with the pages it is on.
 *
 * The check is the static copy's crawl writing nothing (`StaticCopy::
 * startCheck()`): every page drawn as a visitor gets it, every link to the
 * site followed, a page not found or a file not there kept with each page
 * that links to it. Like a copy it is a job that goes on a step at a time,
 * asked by the screen (`static-copy.js`), so a large site never outlasts a
 * request. Links to other sites are not followed: nothing is fetched from
 * anywhere else.
 */
final class BrokenLinksController
{
    private const STEP_SECONDS = 8.0;

    public function index(array $vars = []): void
    {
        Auth::requireRole('admin');
        $this->render();
    }

    /** POST /admin/tools/broken-links — start a check. */
    public function start(array $vars = []): void
    {
        Auth::requireRole('admin');
        $cannot = StaticCopy::cannotRun();
        if ($cannot !== null) {
            $this->render($cannot);
            return;
        }
        try {
            StaticCopy::startCheck();
        } catch (RuntimeException $e) {
            $this->render($e->getMessage());
            return;
        }
        header('Location: ' . ADMIN_URL . '/tools/broken-links', true, 303);
        exit;
    }

    /** POST /admin/tools/broken-links/{id}/step — a few seconds more, as JSON. */
    public function step(array $vars = []): void
    {
        Auth::requireRole('admin');
        $check = StaticCopy::openCheck((string) ($vars['id'] ?? ''));
        header('Content-Type: application/json; charset=utf-8');
        if ($check === null) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'That check is no longer here.']);
            return;
        }
        // The session is let go first, as the copy's step does: a step runs
        // for seconds, and the person's other requests would wait on it.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(120);
        try {
            $done = $check->step(self::STEP_SECONDS);
        } catch (RuntimeException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            return;
        }
        $status = $check->status();
        echo json_encode(['success' => true, 'data' => [
            'done'   => $done,
            'pages'  => $status['pages'],
            'files'  => $status['files'],
            'queued' => $status['queued'],
        ]]);
    }

    /**
     * Where a page that links somewhere broken is edited: a page's or an
     * entry's screen, or null for a page the CMS draws from no one row (an
     * archive, a term, the front page's list).
     */
    public static function editorFor(string $path): ?string
    {
        $parts = array_values(array_filter(explode('/', trim(rawurldecode($path), '/')), 'strlen'));
        if (count($parts) === 1) {
            $page = Page::findBySlug($parts[0]);
            if ($page !== null) {
                return ADMIN_URL . '/pages/' . (int) $page['id'] . '/edit';
            }
        }
        if (count($parts) === 2) {
            foreach (Registry::all() as $key => $type) {
                if ((string) ($type['public_slug'] ?? '') !== $parts[0]) {
                    continue;
                }
                $post = Post::findBySlug($parts[1]);
                if ($post !== null && (string) $post['post_type'] === (string) $key) {
                    return ADMIN_URL . '/' . $type['admin_slug'] . '/' . (int) $post['id'] . '/edit';
                }
            }
        }
        return null;
    }

    private function render(?string $refused = null): void
    {
        $user   = Auth::user();
        $ids    = StaticCopy::checkIds();
        $check  = $ids !== [] ? StaticCopy::openCheck($ids[0]) : null;
        $status  = $check?->status();
        $checkId = $check?->id();
        $going  = $check !== null && !$check->done();
        $broken = $check !== null && !$going ? $check->broken() : [];
        $cannot = StaticCopy::cannotRun();

        $pageTitle   = 'Broken links';
        $currentPage = 'broken-links';
        require ROOT_PATH . '/admin/templates/tools/broken-links.php';
    }
}
