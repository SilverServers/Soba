<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Models\Media;
use Cms\Models\MediaFolder;

class MediaController
{
    /**
     * GET /admin/media — Media library page with type tabs, folder filter, search.
     */
    public function index(array $vars = []): void
    {
        Auth::requireAuth();

        $page     = max(1, (int) ($_GET['page'] ?? 1));
        $type     = in_array($_GET['type'] ?? '', ['image', 'document'], true)
                  ? $_GET['type'] : null;
        $folderId = isset($_GET['folder_id']) ? (int) $_GET['folder_id'] : null;
        $search   = isset($_GET['search']) && trim($_GET['search']) !== ''
                  ? trim($_GET['search']) : null;

        $result      = Media::all($page, 40, $type, $folderId, null, $search);
        $attachments = $result['items'];
        $total       = $result['total'];
        $totalPages  = (int) ceil($total / 40);
        $folders     = MediaFolder::all();

        $isEditorPlus = Auth::hasRole('editor');

        // A refusal from `newFolder()` comes back through here rather than as
        // its own page: the folder list this names is on THIS screen, and a
        // one-field form deserves no page of its own.
        $folderError = $_SESSION['media_folder_error'] ?? null;
        $folderMade  = $_SESSION['media_folder_made'] ?? null;
        unset($_SESSION['media_folder_error'], $_SESSION['media_folder_made']);

        require ROOT_PATH . '/admin/templates/media/list.php';
    }

    /**
     * POST /admin/media/folders/new — make a folder.
     *
     * The library screen offers a folder FILTER; without this, folders could be
     * chosen and never created — a select whose options no screen can add to.
     *
     * A PLAIN FORM, not a fetch. Every other create in this admin is a form that
     * works with no script, and the JSON endpoint stays for the media dialogs
     * that already use it.
     */
    public function newFolder(array $vars = []): void
    {
        Auth::requireRole('editor');
        $this->requireCsrf();

        $name = trim((string) ($_POST['folder_name'] ?? ''));

        if ($name === '') {
            $_SESSION['media_folder_error'] = 'Give the folder a name.';
        } elseif (MediaFolder::nameExists($name)) {
            // NAMED, not "already exists". The owner is looking at the folder
            // list while reading this.
            $_SESSION['media_folder_error'] = 'There is already a folder called "' . $name . '".';
        } else {
            MediaFolder::create($name);

            // ── SAY IT WORKED, BECAUSE NOTHING ELSE ON THE SCREEN WILL ──────
            // The folder list lives in the filter bar, and that bar is hidden
            // over an empty library — deliberately, since filters over nothing
            // can only return nothing. So on a fresh install an owner who made a
            // folder would get back a page identical to the one they left.
            $_SESSION['media_folder_made'] = $name;
        }

        header('Location: ' . ADMIN_URL . '/media');
        exit;
    }

    /**
     * POST /admin/media/folders/{id}/delete
     *
     * ── A FOLDER YOU CAN MAKE AND CANNOT REMOVE ─────────────────────────────
     * Without a route to `MediaFolder::delete()`, the admin could create a row
     * it could never get rid of: a management screen that offers half an act.
     *
     * THE FILES SURVIVE. `attachments.folder_id` is ON DELETE SET NULL, so
     * removing a folder unfiles what was in it and destroys nothing — which is
     * what the confirm dialog says, because "delete folder" reads like it takes
     * the pictures with it.
     */
    public function deleteFolder(array $vars = []): void
    {
        Auth::requireRole('editor');
        $this->requireCsrf();

        MediaFolder::delete((int) ($vars['id'] ?? 0));

        header('Location: ' . ADMIN_URL . '/media');
        exit;
    }

    /** The same four lines `CouponController` and `OrderController` carry. */
    private function requireCsrf(): void
    {
        if (!\Cms\Security\Csrf::validate()) {
            http_response_code(400);
            exit('Invalid request.');
        }
    }

    /**
     * GET /admin/media/add — Redirects to the media library (upload is on that page).
     */
    public function add(array $vars = []): void
    {
        Auth::requireAuth();
        header('Location: ' . ADMIN_URL . '/media');
        exit;
    }
}
