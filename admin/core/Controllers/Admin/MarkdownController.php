<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Content\MarkdownImport;
use Cms\Html\Sanitizer;
use Cms\Http\Cache;

/**
 * IMPORT MARKDOWN — the editor's button posts a document here and gets back
 * the body it makes (`Content\MarkdownImport`), cleaned as a save would clean
 * it, so what the editor shows is what will be stored. Nothing is saved here:
 * the editor puts the HTML where the caret was, and the entry's own Save keeps
 * it, with its revision, as for anything typed.
 *
 * Any signed-in account, as the entry editor itself: this writes nothing. The
 * front controller checks the CSRF token on every `/admin/` POST.
 */
final class MarkdownController
{
    /** A long article is about 100 KB of Markdown; twenty of them is not one article. */
    private const MAX_BYTES = 2_000_000;

    public function convert(array $vars = []): void
    {
        Auth::requireAuth();

        $markdown = (string) ($_POST['markdown'] ?? '');
        if (strlen($markdown) > self::MAX_BYTES) {
            self::json(['success' => false, 'error' => 'That document is over 2 MB, which is more than one article. Import it a part at a time.'], 413);
            return;
        }
        if (trim($markdown) === '') {
            self::json(['success' => false, 'error' => 'There was no Markdown to import.'], 422);
            return;
        }

        $out = MarkdownImport::convert($markdown);
        self::json(['success' => true, 'data' => [
            'html'    => Sanitizer::clean($out['html']),
            'title'   => $out['title'],
            'missing' => $out['missing'],
        ]]);
    }

    private static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        Cache::noStore();
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
