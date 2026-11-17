<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Models\Media;
use Cms\Models\MediaFolder;

class MediaController
{
    /** Files on one page of the library. */
    private const PER_PAGE = 40;

    /**
     * GET /admin/media — Media library page with type tabs, folder filter,
     * search and sort.
     */
    public function index(array $vars = []): void
    {
        Auth::requireAuth();

        $page     = max(1, (int) ($_GET['page'] ?? 1));
        $type     = in_array($_GET['type'] ?? '', ['image', 'document'], true)
                  ? $_GET['type'] : null;
        // "ANY FOLDER" IS AN EMPTY VALUE, and an empty value is not 0: read as
        // `(int) ''`, pressing Filter with Any folder chosen listed the unfiled
        // files alone, and the select then said Unfiled.
        $folderId = is_string($_GET['folder_id'] ?? null) && ctype_digit($_GET['folder_id'])
                  ? (int) $_GET['folder_id'] : null;
        $search   = is_string($_GET['search'] ?? null) && trim($_GET['search']) !== ''
                  ? trim($_GET['search']) : null;
        // THE ORDER IS IN THE ADDRESS, as the filters are, so a sorted library
        // can be sent on and Back returns to it. Anything not on the list is
        // the default, never SQL.
        $sort     = is_string($_GET['sort'] ?? null) && isset(Media::SORTS[$_GET['sort']])
                  ? $_GET['sort'] : Media::DEFAULT_SORT;

        $result = Media::all($page, self::PER_PAGE, $type, $folderId, null, $search, $sort);
        $total  = $result['total'];
        $totalPages = (int) ceil($total / self::PER_PAGE);

        // PAST THE LAST PAGE IS THE LAST PAGE. A link to page 4 of a library
        // that now has three drew an empty grid, and with no filter on, "No
        // files yet" over a library that has files.
        if ($totalPages > 0 && $page > $totalPages) {
            $page   = $totalPages;
            $result = Media::all($page, self::PER_PAGE, $type, $folderId, null, $search, $sort);
        }
        $attachments = $result['items'];
        $folders     = MediaFolder::all();
        $tally       = Media::tally();

        $isEditorPlus = Auth::hasRole('editor');

        require ROOT_PATH . '/admin/templates/media/list.php';
    }

    /**
     * GET /admin/media/folders — Files and media → Manage folders.
     *
     * ── THE ONE PLACE A FOLDER IS MADE, RENAMED AND DELETED ─────────────────
     * The library's folder row only filters: each chip a name and a count. Its
     * chips used to carry a Delete each, the loudest thing on the row every
     * time anyone opened the library, and there was no rename at all. Managing
     * folders is a deliberate errand, so it has a screen of its own, reached
     * from the library's header, the way a site's categories have theirs.
     *
     * Forms and links throughout: it works with no script, and the delete's
     * confirm is the admin's own dialog over a plain form.
     */
    public function folders(array $vars = []): void
    {
        Auth::requireRole('editor');

        $folders = MediaFolder::all();

        // What the last action did, or why it was refused (with what was typed,
        // so a refused rename keeps the words to correct). One of each, once.
        $said    = $_SESSION['media_folders_said'] ?? null;
        $refused = $_SESSION['media_folders_refused'] ?? null;
        unset($_SESSION['media_folders_said'], $_SESSION['media_folders_refused']);

        require ROOT_PATH . '/admin/templates/media/folders.php';
    }

    /**
     * POST /admin/media/folders/new — make a folder.
     *
     * A PLAIN FORM, not a fetch. Every other create in this admin is a form that
     * works with no script, and the JSON endpoint stays for the media dialogs
     * that already use it.
     */
    public function newFolder(array $vars = []): void
    {
        Auth::requireRole('editor');
        $this->requireCsrf();

        $name    = trim((string) ($_POST['folder_name'] ?? ''));
        $refusal = MediaFolder::refusal($name);

        if ($refusal !== null) {
            $_SESSION['media_folders_refused'] = ['id' => null, 'name' => $name, 'message' => $refusal];
        } else {
            MediaFolder::create($name);
            $_SESSION['media_folders_said'] = 'Folder "' . $name . '" created.';
        }

        $this->backToFolders();
    }

    /**
     * POST /admin/media/folders/{id}/rename — the same name rules as making
     * one (`MediaFolder::refusal()`), its own name excepted.
     */
    public function renameFolder(array $vars = []): void
    {
        Auth::requireRole('editor');
        $this->requireCsrf();

        $id     = (int) ($vars['id'] ?? 0);
        $folder = MediaFolder::find($id);
        $name   = trim((string) ($_POST['folder_name'] ?? ''));

        if ($folder === null) {
            $_SESSION['media_folders_refused'] = ['id' => null, 'name' => '', 'message' => 'That folder is not there any more.'];
        } elseif ($name !== (string) $folder['name']) {
            $refusal = MediaFolder::refusal($name, $id);
            if ($refusal !== null) {
                $_SESSION['media_folders_refused'] = ['id' => $id, 'name' => $name, 'message' => $refusal];
            } else {
                MediaFolder::update($id, $name);
                $_SESSION['media_folders_said'] = 'Folder "' . $folder['name'] . '" is now "' . $name . '".';
            }
        }

        $this->backToFolders();
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
     * what the confirm dialog says, and what the message after it says again.
     */
    public function deleteFolder(array $vars = []): void
    {
        Auth::requireRole('editor');
        $this->requireCsrf();

        $id = (int) ($vars['id'] ?? 0);
        foreach (MediaFolder::all() as $folder) {
            if ((int) $folder['id'] !== $id) {
                continue;
            }
            MediaFolder::delete($id);
            $n = (int) $folder['file_count'];
            $_SESSION['media_folders_said'] = 'Folder "' . $folder['name'] . '" deleted.'
                . ($n === 0 ? '' : ' Its ' . ($n === 1 ? 'file is' : $n . ' files are') . ' in the library, unfiled.');
            break;
        }

        $this->backToFolders();
    }

    private function backToFolders(): never
    {
        header('Location: ' . ADMIN_URL . '/media/folders');
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
