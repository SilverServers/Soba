<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\ContentType\Registry;
use Cms\Models\Post;
use Cms\Models\Revision;

/**
 * Restore a post or page to an earlier revision.
 *
 * One route serves both object types — the revision row records which table it
 * came from, so the caller does not have to say. That also means the redirect
 * target is derived from the revision, never from user input.
 */
class RevisionController
{
    public function handleRestore(array $vars = []): void
    {
        Auth::requireAuth();
        $user = Auth::user();

        $revisionId = (int) ($vars['id'] ?? 0);
        $revision   = Revision::find($revisionId);

        if ($revision === null) {
            http_response_code(404);
            require ROOT_PATH . '/admin/templates/404.php';
            return;
        }

        $objectType = (string) $revision['object_type'];
        $objectId   = (int) $revision['object_id'];

        // ── WHO MAY ROLL IT BACK IS WHOEVER MAY CHANGE IT NOW ───────────────
        // The comment here said "Authors may only roll back their own content"
        // and the code refused EVERY author before reaching it — `requireRole`
        // at the top, then `hasRole('editor')` again. So the History panel
        // showed an author Restore buttons that bounced them to the dashboard:
        // a control that does nothing, on their own entry.
        //
        // An entry follows `Auth::canChange()`, the rule the entry screen uses.
        // A page stays editor-and-up, because every page screen is (see
        // PageController) — an author never sees a page's History at all.
        $live    = $objectType === Revision::TYPE_POST ? Post::find($objectId) : null;
        $allowed = $objectType === Revision::TYPE_POST
            ? $live !== null && Auth::canChange($live)
            : Auth::hasRole('editor');

        if (!$allowed) {
            http_response_code(403);
            $backUrl   = $this->editUrlFor($objectType, $objectId, $revision);
            $backLabel = 'the ' . ($objectType === Revision::TYPE_PAGE ? 'page' : 'entry');
            require ROOT_PATH . '/admin/templates/403.php';
            return;
        }

        Revision::restore($revisionId, $user['id'] ?? null);

        header('Location: ' . $this->editUrlFor($objectType, $objectId, $revision));
        exit;
    }

    /**
     * Where to send the user after a restore.
     *
     * Content Type entries live under their registered admin_slug, which we look
     * up from the snapshot's post_type rather than trusting anything in the request.
     */
    private function editUrlFor(string $objectType, int $objectId, array $revision): string
    {
        if ($objectType === Revision::TYPE_PAGE) {
            return ADMIN_URL . '/pages/' . $objectId . '/edit';
        }

        $contentType = Registry::get((string) ($revision['post_type'] ?? ''));
        $slug        = $contentType['admin_slug'] ?? null;

        // A Content Type that has since been unregistered leaves us nowhere
        // sensible to return to — the dashboard is the safe fallback.
        return $slug === null
            ? ADMIN_URL
            : ADMIN_URL . '/' . $slug . '/' . $objectId . '/edit';
    }
}
