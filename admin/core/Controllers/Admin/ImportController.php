<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Content\Import;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * TOOLS → IMPORT AND EXPORT, the import half.
 *
 * ── TWO STEPS, AND A PERSON BETWEEN THEM ────────────────────────────────────
 * Upload shows a PLAN — what would be created, what would be overwritten, what
 * would be skipped and why — and writes nothing. Confirm applies it. That is the
 * assistant's safety model pointed at a file upload, and for the same reason:
 * this is the one control in the admin that can overwrite a hundred rows from a
 * file whose contents nobody has read.
 *
 * ── THE FILE IS HELD IN THE SESSION BETWEEN THE TWO ─────────────────────────
 * Not re-uploaded, and not written to disk. Re-uploading means the file that is
 * APPLIED need not be the file that was PLANNED — the plan on screen would then
 * be a description of a different import, which is worse than no plan at all.
 *
 * A temp file on disk would work too and costs a cleanup path that nothing would
 * ever run; the session already expires on its own. The ceiling below is what
 * keeps that honest.
 */
class ImportController
{
    /** The session key the pending file sits under. */
    private const PENDING = 'import_pending';

    /**
     * WHAT WILL FIT IN A SESSION. PHP's own upload limits are megabytes, and a
     * session is serialised into the session store on every request — a 40 MB
     * export held there would be read and written on every click the admin makes
     * until it expires.
     *
     * An export of a real site is tens of kilobytes of JSON; 2 MB is roughly a
     * hundred thousand rows of it. A file over this is refused with the number,
     * not truncated.
     */
    private const CEILING = 2 * 1024 * 1024;

    /** POST /admin/tools/import — read the file, show what it would do. */
    public function plan(array $vars = []): void
    {
        Auth::requireRole('admin');

        $file = $_FILES['import_file'] ?? null;
        $error = null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $error = 'Choose a file first.';
        } elseif (($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) {
            $error = 'That file is larger than this server accepts for an upload.';
        } elseif (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            $error = 'The upload did not finish. Try again.';
        } elseif ((int) ($file['size'] ?? 0) > self::CEILING) {
            $error = sprintf('That file is %s. The limit is %s — an export of a whole site is normally a few hundred kilobytes.',
                self::size((int) $file['size']), self::size(self::CEILING));
        } elseif (!is_uploaded_file((string) $file['tmp_name'])) {
            // NOT A PATH THE REQUEST CHOSE. Without this, a crafted POST naming
            // `/etc/passwd` as `tmp_name` would have this controller read it and
            // print it back inside the plan.
            $error = 'That upload could not be read.';
        }

        if ($error !== null) {
            $this->render(null, null, $error);
            return;
        }

        $json   = (string) file_get_contents((string) $file['tmp_name']);
        $result = Import::plan($json);

        if (!($result['ok'] ?? false)) {
            $this->render(null, null, (string) ($result['error'] ?? 'That file could not be read.'));
            return;
        }

        $_SESSION[self::PENDING] = [
            'json' => $json,
            'name' => (string) ($file['name'] ?? 'import.json'),
        ];

        $this->render($result['plan'], (string) ($file['name'] ?? 'import.json'), null);
    }

    /** POST /admin/tools/import/apply — do what the plan said. */
    public function apply(array $vars = []): void
    {
        Auth::requireRole('admin');

        $pending = $_SESSION[self::PENDING] ?? null;
        if (!is_array($pending) || !isset($pending['json'])) {
            // The session expired, or Apply was reached without an upload. Say
            // which rather than importing nothing and reporting success.
            $this->render(null, null, 'There is no file waiting. Upload it again — the plan you saw has expired.');
            return;
        }

        $user   = Auth::user();
        $result = Import::apply(
            (string) $pending['json'],
            (int) $user['id'],
            !empty($_POST['import_settings'])
        );

        // THE FILE GOES THE MOMENT IT IS APPLIED. Left in the session, a second
        // press of Apply — or a refresh, which re-posts — runs the whole import
        // again. It is idempotent by slug, so the damage would be limited, but
        // "limited damage from a stray refresh" is not a design.
        unset($_SESSION[self::PENDING]);

        $this->render(null, null, null, $result);
    }

    /**
     * @param array<string, mixed>|null $plan
     * @param array<string, mixed>|null $done what `Import::apply()` actually did
     */
    private function render(?array $plan, ?string $filename, ?string $error, ?array $done = null): void
    {
        Auth::requireRole('admin');
        $user = Auth::user();

        $importPlan   = $plan;
        $importFile   = $filename;
        $importError  = $error;
        $importDone   = $done;
        // THE TEMPLATE SETS ITS OWN `$pageTitle` and wins — this is here so the
        // two never disagree, not because it is read. Worth knowing before
        // someone changes one of them and cannot find the effect.
        $pageTitle    = 'Import / Export';
        $currentPage  = 'import-export';

        // THE WHOLE SCREEN, not an import-only page. Export is the other half of
        // the same job and the thing you do first; splitting them would put the
        // backup you take before an import on a different page from the import.
        require ROOT_PATH . '/admin/templates/tools/import-export.php';
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? round($bytes / 1024 / 1024, 1) . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
