<?php

declare(strict_types=1);

namespace Cms\Controllers\Public;

use Cms\Auth\Auth;
use Cms\Models\Media;

/**
 * FileController — serves files by attachment ID.
 *
 * Public files are normally served directly by Apache. This controller is the
 * only access path for private files and requires authentication.
 */
class FileController
{
    /**
     * GET /files/{id}
     */
    public function serve(array $vars = []): void
    {
        $id         = (int) ($vars['id'] ?? 0);
        $attachment  = Media::find($id);

        if ($attachment === null) {
            http_response_code(404);
            echo '404 File Not Found';
            exit;
        }

        $visibility = $attachment['visibility'] ?? 'public';

        if ($visibility === 'private') {
            if (!Auth::check()) {
                http_response_code(403);
                echo '403 Forbidden';
                exit;
            }
        }

        $filePath = Media::getFilePath($attachment);

        if (!file_exists($filePath)) {
            http_response_code(404);
            echo '404 File Not Found';
            exit;
        }

        // Send appropriate headers
        header('Content-Type: ' . $attachment['mime_type']);
        header('Content-Length: ' . filesize($filePath));
        header('Content-Disposition: inline; filename="' . addslashes($attachment['original_name']) . '"');

        if ($visibility === 'private') {
            header('Cache-Control: no-store, private');
        } else {
            header('Cache-Control: public, max-age=86400');
        }

        readfile($filePath);
        exit;
    }
}
