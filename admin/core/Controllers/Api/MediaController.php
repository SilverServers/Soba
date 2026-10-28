<?php

declare(strict_types=1);

namespace Cms\Controllers\Api;

use Cms\Auth\Auth;
use Cms\Media\Optimizer;
use Cms\Models\Media;
use Cms\Models\MediaRejected;
use Cms\Models\MediaFolder;
use Cms\Models\MediaVersion;

class MediaController
{


    // ----------------------------------------------------------------
    // GET /api/v1/media
    // ----------------------------------------------------------------

    public function list(array $vars = []): void
    {
        Auth::requireAuth();

        $page       = max(1, (int) ($_GET['page'] ?? 1));
        $type       = in_array($_GET['type'] ?? '', ['image', 'document'], true)
                    ? $_GET['type'] : null;
        $folderId   = isset($_GET['folder_id']) ? (int) $_GET['folder_id'] : null;
        $visibility = in_array($_GET['visibility'] ?? '', ['public', 'private'], true)
                    ? $_GET['visibility'] : null;
        $search     = isset($_GET['search']) && trim($_GET['search']) !== ''
                    ? trim($_GET['search']) : null;

        $result = Media::all($page, 40, $type, $folderId, $visibility, $search);

        $items = array_map(
            fn(array $a): array => $this->attachmentPayload($a),
            $result['items']
        );

        $this->json(['success' => true, 'data' => [
            'items'       => $items,
            'total'       => $result['total'],
            'page'        => $page,
            'total_pages' => (int) ceil($result['total'] / 40),
        ]]);
    }

    // ----------------------------------------------------------------
    // POST /api/v1/media/upload
    // ----------------------------------------------------------------

    public function upload(array $vars = []): void
    {
        Auth::requireAuth();

        if (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
            $this->json(['success' => false, 'error' => 'No file provided.'], 400);
            return;
        }

        $file  = $_FILES['file'];
        $error = $file['error'];

        if ($error !== UPLOAD_ERR_OK) {
            $this->json(['success' => false, 'error' => $this->uploadErrorMessage($error)], 400);
            return;
        }

        // Determine visibility and folder. The FILE's rules — size, type,
        // stored name, where it lives — are `Media::store()`'s, the same for
        // every door into the library.
        $folderId   = isset($_POST['folder_id']) && is_numeric($_POST['folder_id'])
                    ? (int) $_POST['folder_id'] : null;
        $visibility = in_array($_POST['visibility'] ?? '', ['public', 'private'], true)
                    ? $_POST['visibility'] : 'public';
        $altText    = isset($_POST['alt_text']) ? trim($_POST['alt_text']) : null;

        // Only editor+ can upload as private
        if ($visibility === 'private' && !Auth::hasRole('editor')) {
            $visibility = 'public';
        }

        try {
            $id = Media::store((string) $file['tmp_name'], (string) $file['name'], [
                'folder_id'  => $folderId,
                'visibility' => $visibility,
                'alt_text'   => $altText,
            ], true);
        } catch (MediaRejected $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 400);
            return;
        } catch (\RuntimeException $e) {
            $this->json(['success' => false, 'error' => 'Failed to save the uploaded file.'], 500);
            return;
        }

        $attachment = Media::find($id);
        $this->json(['success' => true, 'data' => $this->attachmentPayload($attachment)]);
    }

    // ----------------------------------------------------------------
    // POST /api/v1/media/{id}/update
    // ----------------------------------------------------------------

    public function update(array $vars = []): void
    {
        Auth::requireAuth();

        $id         = (int) ($vars['id'] ?? 0);
        $attachment = Media::find($id);

        if ($attachment === null) {
            $this->json(['success' => false, 'error' => 'Attachment not found.'], 404);
            return;
        }

        $data = [];

        // Title (stored as original_name)
        if (isset($_POST['title'])) {
            $data['original_name'] = trim($_POST['title']);
        }

        // Alt text
        if (isset($_POST['alt_text'])) {
            $data['alt_text'] = trim($_POST['alt_text']);
        }

        // Folder (editor+ only)
        if (array_key_exists('folder_id', $_POST)) {
            if (!Auth::hasRole('editor')) {
                $this->json(['success' => false, 'error' => 'Insufficient permissions.'], 403);
                return;
            }
            $data['folder_id'] = ($_POST['folder_id'] === '' || $_POST['folder_id'] === null)
                ? null : (int) $_POST['folder_id'];
        }

        // Visibility (editor+ only) — uses changeVisibility for physical file move
        if (isset($_POST['visibility'])) {
            if (!Auth::hasRole('editor')) {
                $this->json(['success' => false, 'error' => 'Insufficient permissions.'], 403);
                return;
            }
            $newVisibility = $_POST['visibility'];
            if (in_array($newVisibility, ['public', 'private'], true)) {
                Media::changeVisibility($id, $newVisibility);
            }
        }

        if (!empty($data)) {
            Media::update($id, $data);
        }

        $updated = Media::find($id);
        $this->json(['success' => true, 'data' => $this->attachmentPayload($updated)]);
    }

    // ----------------------------------------------------------------
    // POST /api/v1/media/{id}/replace
    // ----------------------------------------------------------------

    public function replace(array $vars = []): void
    {
        Auth::requireAuth();

        $id         = (int) ($vars['id'] ?? 0);
        $attachment = Media::find($id);

        if ($attachment === null) {
            $this->json(['success' => false, 'error' => 'Attachment not found.'], 404);
            return;
        }

        if (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
            $this->json(['success' => false, 'error' => 'No file provided.'], 400);
            return;
        }

        $file  = $_FILES['file'];
        $error = $file['error'];

        if ($error !== UPLOAD_ERR_OK) {
            $this->json(['success' => false, 'error' => $this->uploadErrorMessage($error)], 400);
            return;
        }

        // THE UPLOADER'S RULES, not a second copy of them (`Media::admit()`).
        try {
            $facts = Media::admit((string) $file['tmp_name']);
        } catch (MediaRejected $e) {
            $this->json(['success' => false, 'error' => $e->getMessage()], 400);
            return;
        }
        $mimeType = $facts['mime_type'];

        // ── THE ADDRESS MUST NOT CHANGE. THAT IS THE WHOLE FEATURE. ─────────
        // Minting a fresh `uniqid()` filename and pointing the row at it would
        // silently change the file's public URL, and every page, entry and
        // gallery already embedding the old address would 404 — while the
        // panel displays the new URL and looks correct. This replaces *Enable
        // Media Replace*, and keeping the URL stable is the only thing that
        // plugin exists to do.
        //
        // So: the stored filename is REUSED, and the outgoing bytes are copied
        // aside to a version filename first.
        $storedFilename = (string) $attachment['filename'];

        $visibility = $attachment['visibility'] ?? 'public';
        $uploadsDir = $visibility === 'private'
            ? PUBLIC_PATH . '/uploads/private'
            : PUBLIC_PATH . '/uploads';
        $destPath   = $uploadsDir . '/' . $storedFilename;

        // ── AND THE EXTENSION HAS TO MATCH, or the promise is a lie in a
        // different way: Apache types a public file from its extension, so a
        // PNG served from a `.jpg` name goes out as `image/jpeg`. Refusing is
        // honest; silently renaming would change the address after all.
        // A replacement for an image stored as WebP is stored as WebP too
        // (`Optimizer`), under the same .webp name. An older JPEG or PNG keeps
        // its type, and so its address.
        $oldExt = strtolower((string) pathinfo($storedFilename, PATHINFO_EXTENSION));
        $webp   = $oldExt === 'webp' ? Optimizer::webp((string) $file['tmp_name'], $mimeType) : null;
        $newExt = $webp !== null ? 'webp' : strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if ($oldExt !== '' && $newExt !== $oldExt) {
            $this->json([
                'success' => false,
                'error'   => 'A replacement has to be the same file type (.' . $oldExt . '), '
                           . 'because the address stays the same. Upload it as a new file instead.',
            ], 400);
            return;
        }

        // COPY THE OUTGOING BYTES ASIDE BEFORE OVERWRITING. The bytes are
        // overwritten in place, so a version row naming the live filename would
        // point at the NEW file and the old one would simply be gone.
        $versionFilename = pathinfo($storedFilename, PATHINFO_FILENAME)
            . '.v' . (MediaVersion::countFor($id) + 1)
            . ($oldExt === '' ? '' : '.' . $oldExt);
        $versionsDir = $uploadsDir . '/versions';
        if (!is_dir($versionsDir)) {
            @mkdir($versionsDir, 0755, true);
        }
        if (is_file($destPath) && !@copy($destPath, $versionsDir . '/' . $versionFilename)) {
            $this->json(['success' => false, 'error' => 'Could not archive the current file.'], 500);
            return;
        }

        MediaVersion::create([
            'attachment_id' => $id,
            'filename'      => 'versions/' . $versionFilename,
            'original_name' => $attachment['original_name'],
            'file_size'     => (int) $attachment['file_size'],
            'mime_type'     => $attachment['mime_type'],
            'width'         => $attachment['width'],
            'height'        => $attachment['height'],
        ]);

        $originalName = $file['name'];

        $saved = $webp !== null
            ? @file_put_contents($destPath, $webp['bytes']) === strlen($webp['bytes'])
            : move_uploaded_file($file['tmp_name'], $destPath);
        if (!$saved) {
            $this->json(['success' => false, 'error' => 'Failed to save the uploaded file.'], 500);
            return;
        }
        if ($webp !== null) {
            $mimeType = 'image/webp';
            $facts    = ['file_size' => strlen($webp['bytes']), 'width' => $webp['width'], 'height' => $webp['height']];
        }

        // Measured by `admit()` above — the same bytes, before they moved.
        Media::replace($id, [
            'filename'      => $storedFilename,
            'original_name' => $originalName,
            'mime_type'     => $mimeType,
            'file_size'     => $facts['file_size'],
            'width'         => $facts['width'],
            'height'        => $facts['height'],
        ]);

        $updated = Media::find($id);
        $this->json(['success' => true, 'data' => $this->attachmentPayload($updated)]);
    }

    // ----------------------------------------------------------------
    // GET /api/v1/media/{id}/versions
    // ----------------------------------------------------------------

    public function versions(array $vars = []): void
    {
        Auth::requireAuth();

        $id       = (int) ($vars['id'] ?? 0);
        $versions = MediaVersion::allForAttachment($id);

        $this->json(['success' => true, 'data' => $versions]);
    }

    // ----------------------------------------------------------------
    // POST /api/v1/media/{id}/delete
    // ----------------------------------------------------------------

    public function destroy(array $vars = []): void
    {
        Auth::requireAuth();

        $id         = (int) ($vars['id'] ?? 0);
        $attachment = Media::find($id);

        if ($attachment === null) {
            $this->json(['success' => false, 'error' => 'Attachment not found.'], 404);
            return;
        }

        Media::delete($id);
        $this->json(['success' => true, 'data' => null]);
    }

    // ----------------------------------------------------------------
    // Folder endpoints
    // ----------------------------------------------------------------

    public function listFolders(array $vars = []): void
    {
        Auth::requireAuth();
        $this->json(['success' => true, 'data' => MediaFolder::all()]);
    }

    public function createFolder(array $vars = []): void
    {
        Auth::requireRole('editor');

        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $this->json(['success' => false, 'error' => 'Folder name is required.'], 400);
            return;
        }
        if (MediaFolder::nameExists($name)) {
            $this->json(['success' => false, 'error' => 'A folder with that name already exists.'], 400);
            return;
        }

        $id     = MediaFolder::create($name);
        $folder = MediaFolder::find($id);
        $this->json(['success' => true, 'data' => $folder]);
    }

    public function updateFolder(array $vars = []): void
    {
        Auth::requireRole('editor');

        $id   = (int) ($vars['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');

        if ($name === '') {
            $this->json(['success' => false, 'error' => 'Folder name is required.'], 400);
            return;
        }
        if (MediaFolder::nameExists($name, $id)) {
            $this->json(['success' => false, 'error' => 'A folder with that name already exists.'], 400);
            return;
        }

        MediaFolder::update($id, $name);
        $this->json(['success' => true, 'data' => MediaFolder::find($id)]);
    }

    public function deleteFolder(array $vars = []): void
    {
        Auth::requireRole('editor');

        $id = (int) ($vars['id'] ?? 0);
        MediaFolder::delete($id);
        $this->json(['success' => true, 'data' => null]);
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function attachmentPayload(array $attachment): array
    {
        $visibility = $attachment['visibility'] ?? 'public';
        $url = $visibility === 'private'
            ? BASE_URL . '/files/' . $attachment['id']
            : BASE_URL . '/uploads/' . $attachment['filename'];

        return [
            'id'            => (int) $attachment['id'],
            'url'           => $url,
            'filename'      => $attachment['filename'],
            'original_name' => $attachment['original_name'],
            'mime_type'     => $attachment['mime_type'],
            'file_size'     => (int) $attachment['file_size'],
            'width'         => $attachment['width'] !== null ? (int) $attachment['width'] : null,
            'height'        => $attachment['height'] !== null ? (int) $attachment['height'] : null,
            'alt_text'      => $attachment['alt_text'] ?? '',
            'folder_id'     => isset($attachment['folder_id']) ? (int) $attachment['folder_id'] : null,
            'visibility'    => $visibility,
            'is_image'      => $this->isImage($attachment['mime_type']),
            'created_at'    => $attachment['created_at'],
        ];
    }

    private function isImage(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/');
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large.',
            UPLOAD_ERR_PARTIAL                        => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_TMP_DIR                     => 'No temporary directory available.',
            UPLOAD_ERR_CANT_WRITE                     => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION                      => 'Upload blocked by server extension.',
            default                                   => 'Unknown upload error.',
        };
    }

    private function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        exit;
    }
}
