<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Commerce\Product;
use Cms\Commerce\ProductSheet;
use Cms\Content\Import;
use Cms\Content\WordPressImport;
use Cms\Content\Wxr;
use Cms\ContentType\Registry;
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

    /**
     * A WORDPRESS EXPORT IS NOT HELD IN THE SESSION but in admin/imports/
     * (`WordPressImport::hold()`), and read a piece at a time, so it may be
     * as large as ten years of writing: everything but the media library's
     * files, which it only names.
     */
    private const WORDPRESS_CEILING = 64 * 1024 * 1024;

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
        } elseif (!is_uploaded_file((string) $file['tmp_name'])) {
            // NOT A PATH THE REQUEST CHOSE. Without this, a crafted POST naming
            // `/etc/passwd` as `tmp_name` would have this controller read it and
            // print it back inside the plan.
            $error = 'That upload could not be read.';
        }

        // A WORDPRESS EXPORT, known by its first bytes, whatever it is called.
        $wordpress = $error === null && Wxr::is((string) file_get_contents((string) $file['tmp_name'], false, null, 0, 8192));
        $ceiling   = $wordpress ? self::WORDPRESS_CEILING : self::CEILING;
        if ($error === null && (int) ($file['size'] ?? 0) > $ceiling) {
            $error = sprintf($wordpress
                ? 'That export is %s. The limit is %s: export the posts and the pages separately from WordPress, and import each.'
                : 'That file is %s. The limit is %s — an export of a whole site is normally a few hundred kilobytes.',
                self::size((int) $file['size']), self::size($ceiling));
        }

        if ($error !== null) {
            $this->render(null, null, $error);
            return;
        }

        $name = (string) ($file['name'] ?? 'import.json');
        if ($wordpress) {
            $this->planWordPress(WordPressImport::hold((string) $file['tmp_name']), $name, []);
            return;
        }

        $json = (string) file_get_contents((string) $file['tmp_name']);

        // ── A SPREADSHEET OF PRODUCTS ─────────────────────────────────────
        // A .csv, or a file that is not JSON: read as products, into the type
        // that sells chosen beside the upload (`Commerce\ProductSheet`), and
        // planned and applied in the same two steps.
        if (self::isSheet($name, $json)) {
            $type = (string) ($_POST['import_into'] ?? '');
            if (Registry::get($type) === null || !Product::isSellableType($type)) {
                $this->render(null, null, $type === ''
                    ? 'Choose what the spreadsheet\'s products are, beside the file, and read it again.'
                    : 'A spreadsheet is read as products, and this site has no type of entry that sells for them to go into.');
                return;
            }
            $result = ProductSheet::plan($json, $type);
            if (!($result['ok'] ?? false)) {
                $this->render(null, null, (string) ($result['error'] ?? 'That file could not be read.'));
                return;
            }
            $_SESSION[self::PENDING] = ['csv' => $json, 'type' => $type, 'name' => $name];
            $this->render($result['plan'], $name, null);
            return;
        }

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

    /**
     * POST /admin/tools/import/wordpress — the waiting WordPress export's
     * plan again, with the choices beside it: what goes where, and whether to
     * publish.
     */
    public function replan(array $vars = []): void
    {
        Auth::requireRole('admin');
        $pending = $_SESSION[self::PENDING] ?? null;
        if (!is_array($pending) || !isset($pending['wxr'])) {
            $this->render(null, null, 'There is no export waiting. Upload it again: the plan you saw has expired.');
            return;
        }
        $this->planWordPress((string) $pending['wxr'], (string) ($pending['name'] ?? 'export.xml'), self::askedWordPress());
    }

    /** @return array{types: array<string, string>, categories: string, tags: string, publish: bool} the choices posted */
    private static function askedWordPress(): array
    {
        $types = [];
        foreach ((array) ($_POST['types'] ?? []) as $kind => $to) {
            if (is_string($kind) && is_string($to)) {
                $types[$kind] = $to;
            }
        }
        return [
            'types'      => $types,
            'categories' => (string) ($_POST['categories'] ?? ''),
            'tags'       => (string) ($_POST['tags'] ?? ''),
            'publish'    => !empty($_POST['publish']),
        ];
    }

    /**
     * @param array<string, mixed> $asked
     * @param array<string, mixed> $planned
     */
    private static function sameChoices(array $asked, array $planned): bool
    {
        $types = (array) ($planned['types'] ?? []);
        ksort($types);
        ksort($asked['types']);
        return $asked['types'] == $types
            && $asked['categories'] === (string) ($planned['categories'] ?? '')
            && $asked['tags'] === (string) ($planned['tags'] ?? '')
            && $asked['publish'] === (bool) ($planned['publish'] ?? false);
    }

    /**
     * GET /admin/tools/import/wordpress/{token} — what an import of a
     * WordPress export did, and its files while they are fetched.
     */
    public function report(array $vars = []): void
    {
        Auth::requireRole('admin');
        $job = WordPressImport::job((string) ($vars['token'] ?? ''));
        if ($job === null) {
            $this->render(null, null, 'That import\'s report is gone: a report is kept for a day.');
            return;
        }
        $this->render(null, null, null, null, $job);
    }

    /** POST /admin/tools/import/wordpress/{token}/step — a few seconds more of its files. */
    public function step(array $vars = []): void
    {
        Auth::requireRole('admin');
        header('Content-Type: application/json; charset=utf-8');
        $token = (string) ($vars['token'] ?? '');
        if (WordPressImport::job($token) === null) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'That import is no longer under way.']);
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(120);
        try {
            $done = WordPressImport::step($token, WordPressImport::STEP_SECONDS);
        } catch (\RuntimeException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            return;
        }
        $job = WordPressImport::job($token) ?? [];
        echo json_encode(['success' => true, 'data' => [
            'done'   => $done,
            'pages'  => 0,
            'files'  => (int) ($job['offset'] ?? 0),
            'queued' => $done ? 0 : max(0, count($job['files'] ?? []) - (int) ($job['offset'] ?? 0)),
        ]]);
    }

    /**
     * The plan of a held WordPress export, kept with its choices for Apply.
     *
     * @param array<string, mixed> $asked
     */
    private function planWordPress(string $token, string $name, array $asked, ?string $said = null): void
    {
        $path   = WordPressImport::file($token);
        $result = $path !== null ? WordPressImport::plan($path, $asked) : ['ok' => false, 'error' => 'The export could not be kept on the server.'];
        if (!($result['ok'] ?? false)) {
            WordPressImport::forget($token);
            unset($_SESSION[self::PENDING]);
            $this->render(null, null, (string) ($result['error'] ?? 'That file could not be read.'));
            return;
        }
        $_SESSION[self::PENDING] = ['wxr' => $token, 'name' => $name, 'choices' => $result['plan']['choices']];
        $this->render($result['plan'] + ['said' => $said], $name, null);
    }

    /** POST /admin/tools/import/apply — do what the plan said. */
    public function apply(array $vars = []): void
    {
        Auth::requireRole('admin');

        $pending = $_SESSION[self::PENDING] ?? null;
        if (is_array($pending) && isset($pending['wxr'])) {
            // THE CHOICES ON SCREEN ARE THE ONES APPLIED. Changed beside the
            // Import button and not read again first, they get their plan
            // instead of an import the plan on screen did not describe.
            $asked = self::askedWordPress();
            if (!self::sameChoices($asked, (array) ($pending['choices'] ?? []))) {
                $this->planWordPress((string) $pending['wxr'], (string) ($pending['name'] ?? 'export.xml'), $asked,
                    'The choices changed, so here is their plan. Nothing has been imported yet.');
                return;
            }
            unset($_SESSION[self::PENDING]);
            $user   = Auth::user();
            $result = WordPressImport::apply((string) $pending['wxr'], (array) ($pending['choices'] ?? []), (int) $user['id']);
            if (!($result['ok'] ?? false)) {
                $this->render(null, null, (string) ($result['error'] ?? 'The import did not finish.'));
                return;
            }
            // ITS OWN ADDRESS, so a reload shows the report rather than
            // posting the import again, and the files' bar has a page to be on.
            header('Location: ' . Url::admin('tools/import/wordpress/' . $result['token']), true, 303);
            return;
        }
        if (!is_array($pending) || (!isset($pending['json']) && !isset($pending['csv']))) {
            // The session expired, or Apply was reached without an upload. Say
            // which rather than importing nothing and reporting success.
            $this->render(null, null, 'There is no file waiting. Upload it again — the plan you saw has expired.');
            return;
        }

        $user   = Auth::user();
        $result = isset($pending['csv'])
            ? ProductSheet::apply((string) $pending['csv'], (string) $pending['type'], (int) $user['id'],
                (string) ($user['name'] ?? $user['username'] ?? ''))
            : Import::apply(
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
     * @param array<string, mixed>|null $job  a WordPress import's (`WordPressImport::job()`)
     */
    private function render(?array $plan, ?string $filename, ?string $error, ?array $done = null, ?array $job = null): void
    {
        Auth::requireRole('admin');
        $user = Auth::user();

        $importPlan   = $plan;
        $importFile   = $filename;
        $importError  = $error;
        $importDone   = $done;
        $importJob    = $job;
        // THE TEMPLATE SETS ITS OWN `$pageTitle` and wins — this is here so the
        // two never disagree, not because it is read. Worth knowing before
        // someone changes one of them and cannot find the effect.
        $pageTitle    = 'Import and export';
        $currentPage  = 'import-export';

        // THE WHOLE SCREEN, not an import-only page. Export is the other half of
        // the same job and the thing you do first; splitting them would put the
        // backup you take before an import on a different page from the import.
        require ROOT_PATH . '/admin/templates/tools/import-export.php';
    }

    /** A spreadsheet rather than an export: named .csv, or not JSON at all. */
    private static function isSheet(string $name, string $text): bool
    {
        $start = ltrim((string) preg_replace('/^\xEF\xBB\xBF/', '', $text));

        return str_ends_with(mb_strtolower($name), '.csv') || ($start !== '' && !in_array($start[0], ['{', '['], true));
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? round($bytes / 1024 / 1024, 1) . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
