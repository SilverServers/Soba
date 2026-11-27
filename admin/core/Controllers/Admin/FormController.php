<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Forms\Embed;
use Cms\Forms\FieldTypes;
use Cms\Forms\Logic;
use Cms\Forms\Notify;
use Cms\Helpers\Dates;
use Cms\Helpers\Places;
use Cms\Helpers\Slug;
use Cms\Http\Cache;
use Cms\Models\Form;
use Cms\Models\FormSubmission;
use Cms\Models\Media;
use Cms\Models\Option;
use Cms\Ui\FieldWidth;

class FormController
{
    // ----------------------------------------------------------------
    // List all forms
    // ----------------------------------------------------------------

    public function index(array $vars = []): void
    {
        Auth::requireAuth();

        $forms       = Form::all();
        $placesList  = Places::declared() ? Places::all('form') : null;   // where each form is shown (change 34)
        $pageTitle   = 'Forms';
        $currentPage = 'forms';

        require ROOT_PATH . '/admin/templates/forms/list.php';
    }

    // ----------------------------------------------------------------
    // Create form
    // ----------------------------------------------------------------

    public function showCreate(array $vars = []): void
    {
        Auth::requireAuth();

        $form     = null;
        $formData = [];
        $errors   = [];
        [$placesList, $holders] = self::placesForForm();
        $pageTitle   = 'Create Form';
        $currentPage = 'forms';

        require ROOT_PATH . '/admin/templates/forms/edit.php';
    }

    public function handleCreate(array $vars = []): void
    {
        Auth::requireAuth();

        $title  = trim($_POST['title'] ?? '');
        $errors = [];

        if ($title === '') {
            $errors[] = 'Form title is required.';
        }
        self::postedEvent($errors);
        self::postedTest($errors);
        self::postedPage($errors);
        $placeKey = self::postedPlace($errors, null);

        if (!empty($errors)) {
            $form     = null;
            $formData = $_POST;
            [$placesList, $holders] = self::placesForForm();
            $pageTitle   = 'Create Form';
            $currentPage = 'forms';

            require ROOT_PATH . '/admin/templates/forms/edit.php';
            return;
        }

        $fields   = $this->parseFieldsJson($_POST['fields_json'] ?? '[]');
        $settings = $this->parseSettings($_POST);

        $formId = Form::create($title, $fields, $settings);
        $had    = $placeKey !== null ? Form::place($formId, $placeKey) : null;

        header('Location: ' . ADMIN_URL . '/forms/' . $formId . '/edit?saved=1' . ($had !== null ? '&moved=' . (int) $had['id'] : ''));
        exit;
    }

    // ----------------------------------------------------------------
    // Edit form
    // ----------------------------------------------------------------

    public function showEdit(array $vars = []): void
    {
        Auth::requireAuth();

        $form = Form::find((int) ($vars['id'] ?? 0));
        if (!$form) {
            header('Location: ' . ADMIN_URL . '/forms');
            exit;
        }

        $formData = [];
        $errors   = [];
        $saved    = isset($_GET['saved']);
        [$placesList, $holders] = self::placesForForm();
        // A save that took this form's place from another says so (change 34).
        $movedFrom = isset($_GET['moved']) ? Form::find((int) $_GET['moved']) : null;
        $pageTitle   = 'Edit Form: ' . $form['title'];
        $currentPage = 'forms';

        require ROOT_PATH . '/admin/templates/forms/edit.php';
    }

    public function handleUpdate(array $vars = []): void
    {
        Auth::requireAuth();

        $formId = (int) ($vars['id'] ?? 0);
        $form   = Form::find($formId);
        if (!$form) {
            header('Location: ' . ADMIN_URL . '/forms');
            exit;
        }

        $title  = trim($_POST['title'] ?? '');
        $errors = [];

        if ($title === '') {
            $errors[] = 'Form title is required.';
        }
        self::postedEvent($errors);
        self::postedTest($errors);
        self::postedPage($errors);
        $placeKey = self::postedPlace($errors, $form['place_key'] ?? null);

        if (!empty($errors)) {
            $formData = $_POST;
            [$placesList, $holders] = self::placesForForm();
            $pageTitle   = 'Edit Form: ' . $form['title'];
            $currentPage = 'forms';

            require ROOT_PATH . '/admin/templates/forms/edit.php';
            return;
        }

        $fields   = $this->parseFieldsJson($_POST['fields_json'] ?? '[]');
        $settings = $this->parseSettings($_POST);

        Form::update($formId, $title, $fields, $settings);

        // Where it is shown (change 34). A save without the field — a site
        // with no places — leaves the place alone.
        $had = null;
        if (Places::declared() && array_key_exists('place_key', $_POST) && $placeKey !== ($form['place_key'] ?? null)) {
            $had = Form::place($formId, $placeKey);
        }

        header('Location: ' . ADMIN_URL . '/forms/' . $formId . '/edit?saved=1' . ($had !== null ? '&moved=' . (int) $had['id'] : ''));
        exit;
    }

    // ----------------------------------------------------------------
    // Copy form
    // ----------------------------------------------------------------

    /**
     * A COPY TO START THE NEXT FORM FROM: every field, condition and setting,
     * as a form of its own, opened for editing. Not its submissions, and not
     * its place on the site: a place shows one form, and the copy takes it
     * only when somebody chooses that on its screen.
     */
    public function handleCopy(array $vars = []): void
    {
        Auth::requireAuth();

        $form = Form::find((int) ($vars['id'] ?? 0));
        if (!$form) {
            header('Location: ' . ADMIN_URL . '/forms');
            exit;
        }

        $copyId = Form::create(
            mb_substr((string) $form['title'], 0, 193) . ' (copy)',
            is_array($form['fields']) ? $form['fields'] : [],
            is_array($form['settings']) ? $form['settings'] : []
        );

        header('Location: ' . ADMIN_URL . '/forms/' . $copyId . '/edit', true, 303);
        exit;
    }

    // ----------------------------------------------------------------
    // Delete form
    // ----------------------------------------------------------------

    public function handleDelete(array $vars = []): void
    {
        Auth::requireAuth();

        $formId = (int) ($vars['id'] ?? 0);
        $form   = Form::find($formId);
        if ($form) {
            Form::delete($formId);
        }

        header('Location: ' . ADMIN_URL . '/forms');
        exit;
    }

    // ----------------------------------------------------------------
    // Submissions: one form's, every form's, and Spam — a page each
    // ----------------------------------------------------------------

    public function submissions(array $vars = []): void
    {
        Auth::requireAuth();

        $formId = (int) ($vars['id'] ?? 0);
        $form   = Form::find($formId);
        if (!$form) {
            header('Location: ' . ADMIN_URL . '/forms');
            exit;
        }

        $list        = self::listPage(FormSubmission::RECEIVED, $formId, 'forms/' . $formId . '/submissions');
        $pageTitle   = 'Submissions: ' . $form['title'];
        $currentPage = 'forms-entries';

        require ROOT_PATH . '/admin/templates/forms/submissions.php';
    }

    /**
     * Every form's submissions, newest first — ONE query a page, with the
     * form's title joined in. It was `allForForm()` once per form, merged and
     * sorted in PHP; the template that drew it said so, and said it would not
     * hold at ten thousand rows.
     */
    public function allSubmissions(array $vars = []): void
    {
        Auth::requireAuth();

        $list        = self::listPage(FormSubmission::RECEIVED, null, 'forms/submissions');
        $pageTitle   = 'All submissions';
        $currentPage = 'forms-entries';

        require ROOT_PATH . '/admin/templates/forms/all-submissions.php';
    }

    /**
     * What the forms turned away and kept: the suspicious ones (`Forms\Guard`).
     * A batch of the purge runs each time it opens — bounded, so a month-old
     * flood never comes due inside one request — and the line above the list
     * says how much certain spam was discarded, counted rather than kept.
     */
    public function spam(array $vars = []): void
    {
        Auth::requireAuth();

        $spamDays = self::spamDays();
        FormSubmission::purgeSpam($spamDays);

        $list        = self::listPage(FormSubmission::SPAM, null, 'forms/spam');
        $discarded   = FormSubmission::discards(30);
        $keepAll     = Option::get('forms_spam_keep', '0') === '1';
        $saved       = isset($_GET['saved']);
        $pageTitle   = 'Spam';
        $currentPage = 'forms-spam';

        require ROOT_PATH . '/admin/templates/forms/spam.php';
    }

    /**
     * One page of a list and what its template needs to draw it: the rows,
     * where the pager is, and what the last bulk action did HERE (a report
     * left for another list is left for it).
     *
     * 20 A PAGE UNLESS THE OWNER SAYS OTHERWISE — the entry list's default
     * too — kept in the session, 1 to 999.
     *
     * TESTS (`?test=only` or `?test=none`): a list of received submissions
     * can show tests alone or leave them out. `hasTests` says whether this
     * list holds any, so the choice is offered only where it does something.
     *
     * @return array<string, mixed>
     */
    private static function listPage(string $status, ?int $formId, string $path): array
    {
        $test = $status === FormSubmission::SPAM ? null : match ((string) ($_GET['test'] ?? '')) {
            'only'  => true,
            'none'  => false,
            default => null,
        };
        $perPage = (int) ($_SESSION['submissions_per_page'] ?? 20);
        if (ctype_digit((string) ($_GET['per_page'] ?? ''))) {
            $perPage = (int) $_GET['per_page'];
        }
        $perPage = max(1, min(999, $perPage));
        $_SESSION['submissions_per_page'] = $perPage;

        $page  = ctype_digit((string) ($_GET['page'] ?? '')) ? max(1, (int) $_GET['page']) : 1;
        $found = FormSubmission::page($status, $formId, $page, $perPage, $test);
        $pages = max(1, (int) ceil($found['total'] / $perPage));
        // PAST THE LAST PAGE — rows left since the link was drawn (a bulk
        // delete on the last page, an Empty spam in another tab): the last
        // page, rather than an empty list that says there is nothing here.
        if ($page > $pages) {
            $page  = $pages;
            $found = FormSubmission::page($status, $formId, $page, $perPage, $test);
        }

        $report = $_SESSION['submissions_bulk_report'] ?? null;
        unset($_SESSION['submissions_bulk_report']);
        if (!is_array($report) || ($report['path'] ?? '') !== $path) {
            $report = null;
        }

        return [
            'items'   => $found['items'],
            'total'   => $found['total'],
            'page'    => $page,
            'perPage' => $perPage,
            'pages'   => $pages,
            'path'    => $path,
            'status'  => $status,
            'formId'  => $formId,
            'report'  => $report,
            'test'    => $test,
            'hasTests' => $status === FormSubmission::RECEIVED && FormSubmission::hasTests($formId),
        ];
    }

    // ----------------------------------------------------------------
    // CSV export
    // ----------------------------------------------------------------

    /** One form's received submissions, newest first, under the form's own labels. */
    public function exportCsv(array $vars = []): void
    {
        Auth::requireAuth();

        $formId = (int) ($vars['id'] ?? 0);
        $form   = Form::find($formId);
        if (!$form) {
            header('Location: ' . ADMIN_URL . '/forms');
            exit;
        }

        $name = Slug::clean((string) $form['title']);
        self::sendCsv(($name !== '' ? $name : 'form') . '-submissions-' . Dates::format(time(), 'Y-m-d') . '.csv',
            [$formId => $form], $formId);
    }

    /** Every form's received submissions in one file, newest first, each with its form. */
    public function exportAllCsv(array $vars = []): void
    {
        Auth::requireAuth();

        $forms = [];
        foreach (Form::all() as $f) {
            $full = Form::find((int) $f['id']);
            if ($full !== null) {
                $forms[(int) $f['id']] = $full;
            }
        }
        self::sendCsv('all-submissions-' . Dates::format(time(), 'Y-m-d') . '.csv', $forms, null);
    }

    /**
     * Send a list as a CSV attachment: the received submissions, as the list
     * shows them — Spam is not in it — newest first.
     *
     * THE COLUMNS: Submitted, then (every form) Form, then one per answer,
     * then Page, From and Email sent, and Test when the file holds a test
     * (`Forms\Notify::testInbox()`): a file with none is as it always was. The answers are every collecting field
     * of the forms in form order, then any name a submission carries that
     * the form no longer defines — a renamed field's answers are never
     * dropped. One form's file heads them with its labels; every form's with
     * the field NAMES, since the same label on two forms can mean two fields.
     *
     * WRITTEN AS IT IS READ. A table can hold hundreds of thousands of rows,
     * and reading a whole list into memory is how a Spam view stops loading;
     * the rows come a batch at a time (`FormSubmission::eachReceived()`).
     *
     * UTF-8 with a byte-order mark, so a spreadsheet reads accents right, and
     * RFC 4180 quoting. What a visitor typed reaches a spreadsheet here, so a
     * cell that would run as a formula (=, +, -, @, a tab or a return first)
     * gets a leading apostrophe. An upload is its file's address, as the
     * submission screen makes it a link, never the bare attachment number.
     *
     * @param array<int, array<string, mixed>> $forms id => form
     */
    private static function sendCsv(string $filename, array $forms, ?int $formId): never
    {
        $columns = [];
        $uploads = [];
        foreach ($forms as $form) {
            foreach (Embed::expand(is_array($form['fields'] ?? null) ? $form['fields'] : []) as $f) {
                $name = (string) ($f['name'] ?? '');
                $type = (string) ($f['type'] ?? 'text');
                if ($name === '' || !FieldTypes::collects($type) || isset($columns[$name])) {
                    continue;
                }
                $label          = trim((string) ($f['label'] ?? ''));
                $columns[$name] = $formId !== null && $label !== '' ? $label : $name;
                if (in_array($type, ['file', 'signature'], true)) {
                    $uploads[$name] = true;
                }
            }
        }
        foreach (FormSubmission::answerKeys($formId) as $key) {
            $columns[$key] ??= $key;
        }

        // Nothing below needs the session, and a long export holding its lock
        // would stall every other tab the owner has open.
        session_write_close();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        Cache::noStore();

        $out   = fopen('php://output', 'w');
        $safe  = static fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'" . $v : $v;
        $line  = static fn (array $cells) => fputcsv($out, array_map($safe, $cells), ',', '"', '');
        $tests = FormSubmission::hasTests($formId);

        fwrite($out, "\xEF\xBB\xBF");
        $line(array_merge(['Submitted'], $formId === null ? ['Form'] : [], array_values($columns), ['Page', 'From', 'Email sent'], $tests ? ['Test'] : []));
        FormSubmission::eachReceived($formId, static function (array $s) use ($line, $columns, $uploads, $forms, $formId, $tests): void {
            $row = [Dates::format((string) $s['submitted_at'], 'Y-m-d H:i')];
            if ($formId === null) {
                $row[] = (string) ($forms[(int) $s['form_id']]['title'] ?? '');
            }
            foreach (array_keys($columns) as $name) {
                $v = $s['data'][$name] ?? '';
                if (isset($uploads[$name]) && is_scalar($v) && ctype_digit((string) $v)) {
                    $v = Media::getUrl((int) $v) ?: $v;
                }
                $row[] = is_array($v)
                    ? implode(', ', array_map(static fn ($x): string => is_scalar($x) ? (string) $x : (string) json_encode($x), $v))
                    : (string) $v;
            }
            $sent  = $s['notified'];
            $row[] = (string) ($s['source_page'] ?? '');
            $row[] = (string) ($s['ip_address'] ?? '');
            $row[] = $sent === null ? '' : ((int) $sent === 1 ? 'Yes' : 'No');
            if ($tests) {
                $row[] = (int) ($s['is_test'] ?? 0) === 1 ? 'Yes' : '';
            }
            $line($row);
        });
        fclose($out);
        exit;
    }

    // ----------------------------------------------------------------
    // Many at once
    // ----------------------------------------------------------------

    /**
     * A bulk action from any submissions list. The list says which it was —
     * `status` and `form` — and `FormSubmission::bulk()` acts only on ticked
     * rows that are still in it, so a forged id or a row another tab already
     * moved is skipped and counted, never touched.
     */
    public function handleBulk(array $vars = []): void
    {
        Auth::requireAuth();

        $status = ($_POST['status'] ?? '') === FormSubmission::SPAM ? FormSubmission::SPAM : FormSubmission::RECEIVED;
        $formId = ctype_digit((string) ($_POST['form'] ?? '')) && (int) $_POST['form'] > 0 ? (int) $_POST['form'] : null;
        $action = (string) ($_POST['bulk_action'] ?? '');
        $ids    = is_array($_POST['ids'] ?? null) ? array_map('intval', $_POST['ids']) : [];
        $path   = $status === FormSubmission::SPAM ? 'forms/spam'
                : ($formId !== null ? 'forms/' . $formId . '/submissions' : 'forms/submissions');

        if ($action === 'resend' && $status === FormSubmission::RECEIVED) {
            $_SESSION['submissions_bulk_report'] = ['path' => $path, 'action' => $action] + self::resendMany($ids, $formId);
            header('Location: ' . self::returnTo($path));
            exit;
        }

        $result = FormSubmission::bulk($action, $ids, $status, $formId, self::markedBy());

        $_SESSION['submissions_bulk_report'] = [
            'path'    => $path,
            'action'  => $action,
            'done'    => $result['done'],
            'skipped' => $result['skipped'],
        ];
        header('Location: ' . self::returnTo($path));
        exit;
    }

    /**
     * Every spam row, gone — a batch at a time for up to twenty seconds. A
     * pile bigger than that reports what is left, and the button takes the
     * rest on the next press.
     */
    public function handleEmptySpam(array $vars = []): void
    {
        Auth::requireAuth();

        $result = FormSubmission::emptySpam(null);
        $_SESSION['submissions_bulk_report'] = [
            'path'    => 'forms/spam',
            'action'  => 'empty',
            'done'    => $result['deleted'],
            'skipped' => 0,
            'left'    => $result['left'],
        ];
        header('Location: ' . ADMIN_URL . '/forms/spam');
        exit;
    }

    /** The Spam view's two settings: how long spam is kept, and whether certain spam is kept at all. */
    public function handleSpamSettings(array $vars = []): void
    {
        Auth::requireRole('admin');

        $days = (int) ($_POST['spam_days'] ?? 0);
        if ($days >= 1 && $days <= 3650) {
            Option::set('forms_spam_days', (string) $days);
        }
        Option::set('forms_spam_keep', ($_POST['spam_keep'] ?? '0') === '1' ? '1' : '0');

        header('Location: ' . ADMIN_URL . '/forms/spam?saved=1');
        exit;
    }

    /** One received submission a person says is spam. Back to the list it left. */
    public function handleMarkSpam(array $vars = []): void
    {
        Auth::requireAuth();

        $submissionId = (int) ($vars['sid'] ?? 0);
        $submission   = FormSubmission::find($submissionId);
        FormSubmission::markSpam($submissionId, self::markedBy());

        header('Location: ' . ADMIN_URL . ($submission !== null
            ? '/forms/' . (int) $submission['form_id'] . '/submissions'
            : '/forms/submissions'));
        exit;
    }

    /** A turned-away submission that was a real one: received again. */
    public function handleNotSpam(array $vars = []): void
    {
        Auth::requireAuth();

        $submissionId = (int) ($vars['sid'] ?? 0);
        FormSubmission::markNotSpam($submissionId);

        header('Location: ' . ADMIN_URL . '/forms/submissions/' . $submissionId);
        exit;
    }

    /**
     * SEND THE EMAIL AGAIN, for one submission whose email could not be sent
     * (`Forms\Notify::resend()`). Back to the submission, which says what came
     * of it, once.
     */
    public function handleResend(array $vars = []): void
    {
        Auth::requireAuth();

        $submissionId = (int) ($vars['sid'] ?? 0);
        $submission   = FormSubmission::find($submissionId);
        $form         = $submission !== null ? Form::find((int) $submission['form_id']) : null;
        if ($submission === null || $form === null) {
            header('Location: ' . ADMIN_URL . '/forms/submissions');
            exit;
        }
        $result = (int) ($submission['notified'] ?? -1) === 0
            ? self::resendOne($form, $submission)
            : ['sent' => false, 'to' => '', 'why' => 'its email was not one that could not be sent'];
        $_SESSION['submission_resend'] = ['id' => $submissionId] + $result;

        header('Location: ' . ADMIN_URL . '/forms/submissions/' . $submissionId, true, 303);
        exit;
    }

    /**
     * The bulk "Send the email again": the ticked submissions in the list whose
     * email could not be sent, oldest first, for up to twenty seconds — each is
     * a message through the site's mail, and a slow one takes its time. What is
     * left is reported, and Apply sends the rest.
     *
     * @param  list<int> $ids
     * @return array{done: int, failed: int, why: string, skipped: int, left: int}
     */
    private static function resendMany(array $ids, ?int $formId): array
    {
        $hits   = FormSubmission::failedIn($ids, $formId);
        $until  = microtime(true) + 20.0;
        $out    = ['done' => 0, 'failed' => 0, 'why' => '', 'skipped' => count(array_unique(array_filter($ids, static fn (int $i): bool => $i > 0))) - count($hits), 'left' => 0];
        $forms  = [];
        foreach ($hits as $i => $id) {
            if (microtime(true) >= $until) {
                $out['left'] = count($hits) - $i;
                break;
            }
            $submission = FormSubmission::find($id);
            $formRow    = $submission !== null ? ($forms[(int) $submission['form_id']] ??= Form::find((int) $submission['form_id'])) : null;
            if ($submission === null || $formRow === null) {
                $out['skipped']++;
                continue;
            }
            $result = self::resendOne($formRow, $submission);
            if ($result['sent']) {
                $out['done']++;
            } else {
                $out['failed']++;
                $out['why'] = $out['why'] !== '' ? $out['why'] : $result['why'];
            }
        }

        return $out;
    }

    /**
     * One submission's email sent again (`Forms\Notify::resend()`), and what
     * became of it kept with the submission (`FormSubmission::recordMail()`)
     * when a message was tried: not when it was refused before that, as spam,
     * or with nobody to send it to.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $submission
     * @return array{sent: bool, to: string, why: string}
     */
    private static function resendOne(array $form, array $submission): array
    {
        \Cms\Mail\Mailer::clearResult();
        $result = Notify::resend($form, $submission);
        $mailed = \Cms\Mail\Mailer::lastResult();
        if ($mailed['transport'] !== '' || $mailed['error'] !== '') {
            FormSubmission::recordMail((int) $submission['id'], 'resend', $mailed);
        }
        return $result;
    }

    /** The reason a person's own "Mark as spam" is stored with. */
    private static function markedBy(): string
    {
        $user = Auth::user();
        return 'marked as spam by ' . ($user !== null ? \Cms\Models\User::displayName($user) : 'staff');
    }

    /**
     * Where a bulk action goes back to: the page it was made from, which the
     * form posts as `return`. Only that list's own address is accepted, with
     * or without a query; anything else is the list itself, so the field can
     * never send a signed-in editor somewhere else after a click.
     */
    private static function returnTo(string $path): string
    {
        $base = ADMIN_URL . '/' . $path;
        $want = is_string($_POST['return'] ?? null) ? (string) $_POST['return'] : '';
        if (preg_match('/[\r\n]/', $want) !== 1 && ($want === $base || str_starts_with($want, $base . '?'))) {
            return $want;
        }
        return $base;
    }

    /** "Days to keep spam": 30 unless the Spam view says otherwise. */
    private static function spamDays(): int
    {
        return max(1, (int) (Option::get('forms_spam_days', null) ?? 30));
    }

    // ----------------------------------------------------------------
    // Single submission view
    // ----------------------------------------------------------------

    public function showSubmission(array $vars = []): void
    {
        Auth::requireAuth();

        $submissionId = (int) ($vars['sid'] ?? 0);
        $submission   = FormSubmission::find($submissionId);
        if (!$submission) {
            header('Location: ' . ADMIN_URL . '/forms/submissions');
            exit;
        }

        $form = Form::find((int) $submission['form_id']);
        if (!$form) {
            header('Location: ' . ADMIN_URL . '/forms/submissions');
            exit;
        }

        // Mark as read when viewing — a received one. Spam is never unread,
        // and "Not spam" makes it unread on purpose.
        if (($submission['status'] ?? FormSubmission::RECEIVED) === FormSubmission::RECEIVED
            && !(bool) $submission['is_read']) {
            FormSubmission::markRead($submissionId);
            $submission['is_read'] = 1;
        }

        // WHAT "SEND THE EMAIL AGAIN" DID, for this submission, once.
        $resent = is_array($_SESSION['submission_resend'] ?? null) && (int) ($_SESSION['submission_resend']['id'] ?? 0) === $submissionId
            ? $_SESSION['submission_resend'] : null;
        unset($_SESSION['submission_resend']);

        // WHAT BECAME OF EACH EMAIL IT SENT, oldest first (migration 121).
        $mails = FormSubmission::mails($submissionId);

        $pageTitle   = 'Submission #' . $submissionId;
        $currentPage = ($submission['status'] ?? '') === FormSubmission::SPAM ? 'forms-spam' : 'forms-entries';

        require ROOT_PATH . '/admin/templates/forms/submission.php';
    }

    // ----------------------------------------------------------------
    // Mark all read for a form
    // ----------------------------------------------------------------

    public function handleMarkAllRead(array $vars = []): void
    {
        Auth::requireAuth();

        $formId = (int) ($vars['id'] ?? 0);
        FormSubmission::markAllRead($formId);

        header('Location: ' . ADMIN_URL . '/forms/' . $formId . '/submissions');
        exit;
    }

    // ----------------------------------------------------------------
    // Delete single submission
    // ----------------------------------------------------------------

    public function handleDeleteSubmission(array $vars = []): void
    {
        Auth::requireAuth();

        $submissionId = (int) ($vars['sid'] ?? 0);
        $submission   = FormSubmission::find($submissionId);
        $formId       = $submission ? (int) $submission['form_id'] : 0;

        FormSubmission::delete($submissionId);

        // BACK TO THE LIST IT WAS IN: a spam row deleted from Spam returns to
        // Spam, not to a form's inbox it was never part of.
        $redirect = $submission !== null && ($submission['status'] ?? '') === FormSubmission::SPAM
            ? ADMIN_URL . '/forms/spam'
            : ($formId > 0 ? ADMIN_URL . '/forms/' . $formId . '/submissions' : ADMIN_URL . '/forms/submissions');
        header('Location: ' . $redirect);
        exit;
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * The site's form places, and which form each shows (change 34): [null, []]
     * when the site has none.
     *
     * @return array{0: array<string, array<string, mixed>>|null, 1: array<string, array<string, mixed>>}
     */
    private static function placesForForm(): array
    {
        if (!Places::declared() || Places::all('form') === []) {
            return [null, []];
        }
        $holders = [];
        foreach (Form::all() as $f) {
            if (($f['place_key'] ?? null) !== null) {
                $holders[(string) $f['place_key']] = $f;
            }
        }
        return [Places::all('form'), $holders];
    }

    /**
     * The posted place: null for none; a form place the site lists; or the one
     * the form already has, even if the list has since dropped it. Anything
     * else is an error.
     *
     * @param list<string> $errors
     */
    private static function postedPlace(array &$errors, ?string $current): ?string
    {
        $key = $_POST['place_key'] ?? '';
        $key = is_string($key) ? trim($key) : '';
        if ($key === '' || !Places::declared()) {
            return null;
        }
        if ($key !== $current && (Places::find($key)['kind'] ?? null) !== 'form') {
            $errors[] = 'No place has the key "' . $key . '"; the list may have changed. Pick one again.';
            return $current;
        }
        return $key;
    }

    /**
     * Parse the fields JSON from the hidden input.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseFieldsJson(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $fields = [];
        foreach ($decoded as $field) {
            // THE TYPE MUST BE ONE THE RENDERER HANDLES. It used to be enough
            // for the key to be non-empty, so a hand-made POST could store
            // `type: "signature"` and every entry form would silently render it
            // as a text box — the builder promising a control nobody gets.
            // `FieldTypes` is the one list, and the palette is drawn from it.
            if (!is_array($field) || !FieldTypes::exists((string) ($field['type'] ?? ''))) {
                continue;
            }
            $type = (string) $field['type'];

            $out = [
                'name'        => trim($field['name'] ?? ''),
                'label'       => trim($field['label'] ?? ''),
                'type'        => $type,
                'required'    => !empty($field['required']),
                'placeholder' => trim($field['placeholder'] ?? ''),
                'options'     => trim($field['options'] ?? ''),
                // FOUR WIDTHS, AND THE LIST IS NOT HERE. A whitelist written
                // as `=== 'half' ? 'half' : 'full'` silently downgrades every
                // width added anywhere else to full — the builder would offer
                // One quarter, the save would swallow it, and the field would
                // come back full with nothing logged. `normalize()` is the one
                // list; a value it does not know still becomes full.
                'width'       => FieldWidth::normalize($field['width'] ?? null),
            ];

            // ── EVERYTHING THE BUILDER GAINED, AND THE REASON THIS LIST EXISTS
            // This method is a WHITELIST, which is right — a form definition is
            // rendered into every visitor's page and nothing unreviewed belongs
            // in it. But a whitelist silently DROPS what it does not name, and
            // that is exactly what happened the first time conditional logic was
            // saved: the builder posted the rules, the editor showed them, the
            // reload showed them gone, and every guard was green because a field
            // that lost a key still renders perfectly.
            //
            // Each of these is stored only when it has content, so a definition
            // does not carry six empty keys per field.
            // `min`, `max`, `step` are shared by Number, Slider, Scale and
            // Star rating; `columns` is the Likert's answer list; the two
            // `scale_*` are the words at either end of a graded row. All of
            // them are read by `Shortcode::renderForm()` and all of them would
            // be silently dropped here if they were not named — which is the
            // failure this list's own comment is about.
            foreach (['description' => 'description', 'text' => 'text',
                      'default' => 'default', 'msg_required' => 'msg_required',
                      'msg_format' => 'msg_format', 'min' => 'min',
                      'max' => 'max', 'step' => 'step', 'columns' => 'columns',
                      'scale_low' => 'scale_low', 'scale_high' => 'scale_high',
                      'source' => 'source', 'embed_form' => 'embed_form',
                      // How it is drawn and what it says (`Helpers\Shortcode`):
                      // a browser's autofill word, a select's empty choice,
                      // and the words for an answer over its length.
                      'autocomplete' => 'autocomplete', 'blank_label' => 'blank_label', 'msg_long' => 'msg_long',
                      // The appointment field's availability shape.
                      'appt_days' => 'appt_days', 'appt_from' => 'appt_from',
                      'appt_to' => 'appt_to', 'appt_minutes' => 'appt_minutes',
                      'appt_ahead' => 'appt_ahead'] as $key => $_) {
                // THE AVAILABILITY BELONGS TO AN APPOINTMENT FIELD ONLY. The
                // builder posted its working-week defaults on every field, so
                // every save copied `appt_*` onto a text box, an email box, a
                // checkbox — harmless to render, but noise in every definition.
                if (str_starts_with($key, 'appt_') && $type !== 'appointment') {
                    continue;
                }
                $value = trim((string) ($field[$key] ?? ''));
                if ($value !== '') {
                    $out[$key] = $value;
                }
            }

            $max = (int) ($field['maxlength'] ?? 0);
            if ($max > 0) {
                $out['maxlength'] = $max;
            }
            // A TEXTAREA'S LINES, 1 to 50; blank: 5.
            $rows = (int) ($field['rows'] ?? 0);
            if ($type === 'textarea' && $rows >= 1 && $rows <= 50) {
                $out['rows'] = $rows;
            }
            // AUTOFILL IS WORDS, NOT TEXT: one to four of HTML's tokens.
            if (isset($out['autocomplete']) && preg_match('/^[a-z0-9-]+(?: [a-z0-9-]+){0,3}$/D', strtolower($out['autocomplete'])) !== 1) {
                unset($out['autocomplete']);
            }

            // A FILE FIELD'S OWN KINDS, as the kinds a form takes that it names
            // ("jpg, png, webp"); a kind no form takes is dropped, and empty
            // is every kind (`Forms\Upload::allowedFor()`).
            if ($type === 'file') {
                $kinds = \Cms\Forms\Upload::extensions((string) ($field['accept'] ?? ''));
                if ($kinds !== []) {
                    $out['accept'] = implode(', ', $kinds);
                }
            }

            // THROUGH THE SHARED PARSER, never stored raw. `Logic::parse()` drops
            // rules with no subject and operators this install does not
            // implement, and returns null when nothing survives — so a half-built
            // rule set cannot be saved as one that evaluates.
            $logic = Logic::parse($field['logic'] ?? null);
            if ($logic !== null) {
                $out['logic'] = $logic;
            }

            $fields[] = $out;
        }
        return $fields;
    }

    /**
     * Parse form settings from POST data.
     */
    private function parseSettings(array $post): array
    {
        return [
            'recipients'     => trim($post['recipients'] ?? ''),
            'subject'        => trim($post['subject'] ?? ''),
            'reply_to'       => trim($post['reply_to'] ?? ''),
            // A confirmation to the sender (`Forms\Notify::confirm()`): the
            // message is the switch, blank sends none.
            'confirm_subject' => mb_substr(trim((string) ($post['confirm_subject'] ?? '')), 0, 200),
            'confirm_message' => mb_substr(trim(str_replace(["\r\n", "\r"], "\n", (string) ($post['confirm_message'] ?? ''))), 0, 5000),
            'success_message' => trim($post['success_message'] ?? 'Thank you! Your submission has been received.'),
            'submit_label'   => trim($post['submit_label'] ?? 'Submit'),
            // Where a browser without JavaScript goes after sending (change
            // 35); blank: back to the page it came from, marked sent.
            'redirect'       => trim((string) ($post['redirect'] ?? '')),
            // '1': a send by script goes there too, its answer naming it
            // (`Public\FormController::answer()`). Unticked, and on a form
            // saved before there was a box, the thank-you shows in place.
            'redirect_scripted' => ($post['redirect_scripted'] ?? '') === '1' ? '1' : '',
            // A test: a submission whose reply-to field holds this address
            // is emailed to the test inbox alone (`Forms\Notify::testInbox()`).
            // Blank: the site's, from Settings → Email.
            'test_address'   => trim((string) ($post['test_address'] ?? '')),
            'test_inbox'     => trim((string) ($post['test_inbox'] ?? '')),
            // The most links a message may carry (change 30, `Forms\Guard`
            // reads it); blank: 4. Nothing saved it, so it was never set.
            'max_links'      => preg_match('/^\d{1,3}$/', trim((string) ($post['max_links'] ?? ''))) === 1 ? trim((string) $post['max_links']) : '',
            // The seconds its page must be open before it sends (`Forms\Guard::
            // minSeconds()`); blank: 3. Within the range the guard keeps to.
            'min_seconds'    => preg_match('/^\d{1,3}$/', trim((string) ($post['min_seconds'] ?? ''))) === 1
                ? (string) max(\Cms\Forms\Guard::MIN_SECONDS_RANGE[0], min(\Cms\Forms\Guard::MIN_SECONDS_RANGE[1], (int) $post['min_seconds'])) : '',
            // 'thanks': a post the rules turn away is answered as one sent
            // (`Forms\Guard::answersSent()`); blank: its sender is told.
            'spam_answer'    => ($post['spam_answer'] ?? '') === 'thanks' ? 'thanks' : '',
            // HOW IT IS DRAWN ON A PAGE (`Helpers\Shortcode`, form.js): its
            // element's id (`Form::elementId()`) and classes, its button's
            // classes, its fields' ids, where its messages show, whether it
            // checks its own answers, and its words while sending and when it
            // cannot. Class names only; an id refused is said (`postedPage()`).
            'html_id'        => preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', trim((string) ($post['html_id'] ?? ''))) === 1 ? trim((string) $post['html_id']) : '',
            'html_class'     => self::classNames((string) ($post['html_class'] ?? '')),
            'button_class'   => self::classNames((string) ($post['button_class'] ?? '')),
            'bare_ids'       => ($post['bare_ids'] ?? '') === '1' ? '1' : '',
            'status_place'   => ($post['status_place'] ?? '') === 'above' ? 'above' : '',
            'own_checks'     => ($post['own_checks'] ?? '') === '1' ? '1' : '',
            'sending_label'  => mb_substr(trim((string) ($post['sending_label'] ?? '')), 0, 200),
            'msg_fix'        => mb_substr(trim((string) ($post['msg_fix'] ?? '')), 0, 200),
            'msg_offline'    => mb_substr(trim((string) ($post['msg_offline'] ?? '')), 0, 200),
            // The event a real submission sends to Google (`Site\Google`);
            // blank: none. A form saved before there was one sends the default.
            'conversion_event' => trim((string) ($post['conversion_event'] ?? \Cms\Site\Google::DEFAULT_EVENT)),
        ];
    }

    /** Class names as a site writes them, the rest dropped; at most twelve. */
    private static function classNames(string $typed): string
    {
        $keep = array_filter(preg_split('/\s+/', trim($typed)) ?: [],
            static fn (string $c): bool => preg_match('/^-?[A-Za-z_][A-Za-z0-9_-]{0,63}$/D', $c) === 1);
        return implode(' ', array_slice(array_values(array_unique($keep)), 0, 12));
    }

    /**
     * The form's id, when typed, is one an element can have: said rather
     * than dropped, as the test address is.
     *
     * @param list<string> $errors
     */
    private static function postedPage(array &$errors): void
    {
        $typed = trim((string) ($_POST['html_id'] ?? ''));
        if ($typed !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $typed) !== 1) {
            $errors[] = "The form's id is a letter, then letters, digits, - or _, at most 64 in all.";
        }
    }

    /**
     * A test address and a test inbox, when typed, are addresses.
     *
     * @param list<string> $errors
     */
    private static function postedTest(array &$errors): void
    {
        foreach (['test_address' => 'The test address', 'test_inbox' => 'The test inbox'] as $key => $name) {
            $typed = trim((string) ($_POST[$key] ?? ''));
            if ($typed !== '' && filter_var($typed, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = $name . ' must be an email address.';
            }
        }
        // A CONFIRMATION GOES TO THE REPLY-TO FIELD'S ADDRESS: with no such
        // field there is nobody to send it to, and saying so beats a message
        // that never goes.
        if (trim((string) ($_POST['confirm_message'] ?? '')) !== '' && trim((string) ($_POST['reply_to'] ?? '')) === '') {
            $errors[] = 'The confirmation goes to the address in the Reply-to field: choose that field, or leave the confirmation blank.';
        }
    }

    /**
     * The event a form sends to Google, when one is typed, must be one GA4
     * takes (`Site\Google::eventName()`).
     *
     * @param list<string> $errors
     */
    private static function postedEvent(array &$errors): void
    {
        $event = trim((string) ($_POST['conversion_event'] ?? ''));
        if ($event !== '' && \Cms\Site\Google::eventName($event) === null) {
            $errors[] = 'The event sent to Google is a letter, then letters, digits or underscores, at most 40 in all, '
                . 'and not a name Google keeps for itself, such as form_submit or page_view.';
        }
    }
}
