<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Forms\Embed;
use Cms\Forms\FieldTypes;
use Cms\Forms\Logic;
use Cms\Helpers\Dates;
use Cms\Helpers\Places;
use Cms\Helpers\Slug;
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
     * @return array<string, mixed>
     */
    private static function listPage(string $status, ?int $formId, string $path): array
    {
        $perPage = (int) ($_SESSION['submissions_per_page'] ?? 20);
        if (ctype_digit((string) ($_GET['per_page'] ?? ''))) {
            $perPage = (int) $_GET['per_page'];
        }
        $perPage = max(1, min(999, $perPage));
        $_SESSION['submissions_per_page'] = $perPage;

        $page  = ctype_digit((string) ($_GET['page'] ?? '')) ? max(1, (int) $_GET['page']) : 1;
        $found = FormSubmission::page($status, $formId, $page, $perPage);
        $pages = max(1, (int) ceil($found['total'] / $perPage));
        // PAST THE LAST PAGE — rows left since the link was drawn (a bulk
        // delete on the last page, an Empty spam in another tab): the last
        // page, rather than an empty list that says there is nothing here.
        if ($page > $pages) {
            $page  = $pages;
            $found = FormSubmission::page($status, $formId, $page, $perPage);
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
     * then Page, From and Email sent. The answers are every collecting field
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
        header('Cache-Control: no-store');

        $out  = fopen('php://output', 'w');
        $safe = static fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'" . $v : $v;
        $line = static fn (array $cells) => fputcsv($out, array_map($safe, $cells), ',', '"', '');

        fwrite($out, "\xEF\xBB\xBF");
        $line(array_merge(['Submitted'], $formId === null ? ['Form'] : [], array_values($columns), ['Page', 'From', 'Email sent']));
        FormSubmission::eachReceived($formId, static function (array $s) use ($line, $columns, $uploads, $forms, $formId): void {
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
            'success_message' => trim($post['success_message'] ?? 'Thank you! Your submission has been received.'),
            'submit_label'   => trim($post['submit_label'] ?? 'Submit'),
            // Where a browser without JavaScript goes after sending (change
            // 35); blank: back to the page it came from, marked sent.
            'redirect'       => trim((string) ($post['redirect'] ?? '')),
            // The most links a message may carry (change 30, `Forms\Guard`
            // reads it); blank: 4. Nothing saved it, so it was never set.
            'max_links'      => preg_match('/^\d{1,3}$/', trim((string) ($post['max_links'] ?? ''))) === 1 ? trim((string) $post['max_links']) : '',
            // The event a real submission sends to Google (`Site\Google`);
            // blank: none. A form saved before there was one sends the default.
            'conversion_event' => trim((string) ($post['conversion_event'] ?? \Cms\Site\Google::DEFAULT_EVENT)),
        ];
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
