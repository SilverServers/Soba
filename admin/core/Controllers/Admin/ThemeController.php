<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Helpers\Theme;
use Cms\Security\SecurityLog;
use Cms\Security\TwoStep;

/**
 * APPEARANCE → THEMES — which look the public site wears (`Helpers\Theme`).
 *
 * Every theme the site has, the active one first, Activate on each of the
 * others, and the ones that are there but cannot be used listed with what is
 * wrong. Administrators only — a theme decides every public page.
 *
 * The switch is one option written, so `Security\Audit` puts it in the
 * activity log like any other change an administrator makes here.
 */
final class ThemeController
{
    /**
     * `/admin/appearance` — the section opens on Themes. A redirect rather
     * than the screen at a second address: the activity log names a change
     * by the address it was made from (`Security\Audit::screen()`), and this
     * one has no row of its own.
     */
    public function section(array $vars = []): void
    {
        Auth::requireRole('admin');
        header('Location: ' . ADMIN_URL . '/appearance/themes', true, 302);
        exit;
    }

    public function index(array $vars = []): void
    {
        Auth::requireRole('admin');

        Theme::reset();
        $active = Theme::active();
        $usable = [];
        $broken = [];
        foreach (Theme::all() as $theme) {
            if ($theme['problem'] !== null) {
                $broken[] = $theme;
            } elseif ($theme['key'] !== $active['key']) {
                $usable[] = $theme;
            }
        }
        // The active theme first, the rest by name.
        usort($usable, static fn (array $a, array $b): int => strnatcasecmp((string) $a['name'], (string) $b['name']));
        array_unshift($usable, $active);

        $activated = isset($_GET['activated']) ? (Theme::all()[(string) $_GET['activated']] ?? null) : null;
        $failed    = isset($_GET['failed']);
        // FROM THE SESSION, NOT THE ADDRESS: a deleted theme has no entry left
        // to name it from, and a name taken from a link would let anyone put
        // a sentence on this screen.
        $deleted = isset($_SESSION['themes_deleted']) && is_array($_SESSION['themes_deleted']) ? $_SESSION['themes_deleted'] : null;
        unset($_SESSION['themes_deleted']);

        $pageTitle   = 'Themes';
        $currentPage = 'themes';
        require ROOT_PATH . '/admin/templates/appearance/themes.php';
    }

    /** POST: make a theme the site's. Back to the screen, which says what happened. */
    public function activate(array $vars = []): void
    {
        Auth::requireRole('admin');

        $key = (string) ($_POST['theme'] ?? '');
        $ok  = Theme::activate($key);

        header('Location: ' . ADMIN_URL . '/appearance/themes?' . ($ok ? 'activated=' . rawurlencode($key) : 'failed=1'));
        exit;
    }

    /**
     * POST: delete a theme's folder. The password is asked again first:
     * files leave the server, and nothing here can bring them back. A file
     * is not a table, so `Security\Audit` would not see it — it is logged
     * here, in the same shape.
     */
    public function delete(array $vars = []): void
    {
        Auth::requireRole('admin');
        TwoStep::requireConfirmed('/admin/appearance/themes');

        $key     = (string) ($_POST['theme'] ?? '');
        $theme   = Theme::all()[$key] ?? null;
        $problem = Theme::deleteProblem($key);
        $done    = $theme !== null && $problem === null && Theme::delete($key);

        if ($theme !== null && $problem === null) {
            $user = Auth::user();
            SecurityLog::record(SecurityLog::CHANGE, (string) ($user['username'] ?? ''), (int) ($user['id'] ?? 0),
                'Appearance › Themes · POST /admin/appearance/themes/delete · the folder ' . $theme['where']
                . ($done ? '' : ' (not all of it would go)'));
        }
        $_SESSION['themes_deleted'] = [
            'name'    => (string) ($theme['name'] ?? $key),
            'done'    => $done,
            'problem' => $problem ?? ($done ? null : 'Some of its files would not go; what is left is listed here.'),
        ];

        header('Location: ' . ADMIN_URL . '/appearance/themes', true, 303);
        exit;
    }

    /**
     * A theme's `screenshot.*`, for its card. Only that one file of a theme the
     * registry found — never a path from the request — and only to an
     * administrator, who is the only one shown the card.
     */
    public function screenshot(array $vars = []): void
    {
        Auth::requireRole('admin');

        $file  = Theme::screenshot((string) ($vars['key'] ?? ''));
        $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];
        $type  = $file !== null ? ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null) : null;
        if ($file === null || $type === null || !is_file($file)) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: ' . $type);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        header('Content-Length: ' . (string) filesize($file));
        readfile($file);
        exit;
    }
}
