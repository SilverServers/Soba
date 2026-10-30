<?php

declare(strict_types=1);

namespace Cms\Controllers\Public;

use Cms\Auth\Auth;
use Cms\Forms\Captcha;
use Cms\Forms\Appointments;
use Cms\Forms\Embed;
use Cms\Forms\Notify;
use Cms\Forms\FieldTypes;
use Cms\Forms\Guard;
use Cms\Forms\Sources;
use Cms\Forms\Upload;
use Cms\Forms\Logic;
use Cms\Helpers\Template;
use Cms\Helpers\Theme;
use Cms\Models\Form;
use Cms\Models\FormSubmission;
use Cms\Models\Option;
use Cms\SEO\Head;
use Cms\Security\ClientIp;

class FormController
{
    /**
     * Handle a public form submission.
     *
     * POST /forms/{id}/submit
     */
    public function handleSubmit(array $vars = []): void
    {
        $formId = (int) ($vars['id'] ?? 0);
        $form   = Form::find($formId);

        if ($form === null) {
            $this->answer(false, 'Form not found.', 404, null, self::sourcePage());
            return;
        }

        // ── AS VALID TEXT ───────────────────────────────────────────
        // A byte that is not UTF-8 made the stored answers invalid JSON, the
        // database refused the row, and the visitor got a 500 with nothing
        // kept. Each such byte becomes U+FFFD
        // before anything reads the post, so the checks, the stored answers
        // and the email all see the same text.
        $_POST = self::validText($_POST);

        // EXPANDED FIRST, because the captcha may live inside an embedded
        // form — scanning the unexpanded list would miss it and let every
        // submission through unchallenged, on a form that visibly asks a
        // question. The validation below and `turnAway()` read the same list.
        $fields = Embed::expand(is_array($form['fields'] ?? null) ? $form['fields'] : []);
        $meta   = [
            'ip'          => ClientIp::get(),
            'user_agent'  => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'source_page' => self::sourcePage(),
        ];

        // ── The protections that come before the answers (Forms\Guard) ──
        //
        // NOT FOR STAFF. A signed-in member of staff testing their own form is
        // never screened, and their test lands in the inbox, where they will
        // look for it.
        $staff = Auth::check();
        if (!$staff) {
            $turn = Guard::screen($_POST, $_SERVER);
            if ($turn !== null) {
                $this->turnAway($formId, $form, $fields, $turn, $meta);
                return;
            }
        }

        // ── The captcha, if this form has one ───────────────────────
        //
        // BEFORE ANY FIELD IS READ. A challenge checked after validation tells
        // a bot which fields it got wrong before telling it that it failed the
        // challenge at all — free reconnaissance on every attempt.
        //
        // AND UNLIKE THE HONEYPOT, THIS SAYS SO. The honeypot lies on purpose
        // (a caught bot is told "thank you", so it does not learn to fill the
        // field differently); a captcha is a control the visitor can SEE, so a
        // person who mistypes the answer has to be told, or the form silently
        // does nothing and they try again identically.
        // A WRONG ANSWER IS NOT SPAM. The person is told, and tries again; a
        // bot that cannot do the sum is told the same and stored nowhere.
        $wantsCaptcha = false;
        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'captcha') {
                $wantsCaptcha = true;
                break;
            }
        }
        if ($wantsCaptcha && !Captcha::passes()) {
            $this->answer(false, 'That answer was not right. Please try again.', 422, $form, $meta['source_page']);
            return;
        }

        // ── Validate fields against schema ──────────────────────────
        //
        // THREE PASSES, AND THE ORDER MATTERS.
        //
        //   1. read every field's posted value
        //   2. decide which fields conditional logic actually showed
        //   3. validate and store ONLY those
        //
        // It cannot be one pass. A rule may name a field that appears LATER in
        // the list — "show Company if Enquiry type is Business" reads fine in
        // either order in the builder — so visibility cannot be settled while
        // the value map is still half-built. Deciding as you go would evaluate
        // half the rules against a missing value, which reads as "empty" and
        // quietly flips the answer.
        // `$fields` is the EXPANDED list, settled above — the same one the
        // renderer drew from. If the two ever differ, the page collects
        // answers the server never validates or stores.
        $errors  = [];
        $data    = [];
        $raw     = [];

        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            $type = (string) ($field['type'] ?? 'text');
            if ($name === '' || !FieldTypes::collects($type)) {
                continue;   // a divider or a text block collects nothing
            }
            $raw[$name] = $this->readValue($field, $name, $type);
        }

        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            $type = (string) ($field['type'] ?? 'text');
            if ($name === '' || !FieldTypes::collects($type)) {
                continue;
            }

            // A FIELD LOGIC HID IS NOT VALIDATED AND NOT STORED. Enforcing
            // `required` on something the visitor never saw makes the form
            // permanently unsubmittable with an error pointing at nothing —
            // and a value posted for a hidden field was not offered to them,
            // so keeping it would record an answer nobody gave.
            if (!Logic::isVisible(Logic::parse($field['logic'] ?? null), $raw)) {
                continue;
            }

            $value = $raw[$name];
            $label = ($field['label'] ?? '') !== '' ? (string) $field['label'] : $name;
            $blank = is_array($value) ? $value === [] : trim((string) $value) === '';

            // AN UPLOAD THAT WAS REFUSED SAYS WHY, and says it instead of
            // "required" — the visitor attached a file and can see that they
            // did, so being told it is missing is the form arguing with them.
            if (isset($this->uploadErrors[$name])) {
                $errors[] = htmlspecialchars($label) . ': '
                          . htmlspecialchars($this->uploadErrors[$name]);
                continue;
            }

            if (!empty($field['required']) && $blank) {
                // THE AUTHOR'S OWN WORDING WINS. The builder's Validation
                // messages fold offers "When it is left blank"; until this read
                // it, the box collected a string nothing ever printed.
                $custom = trim((string) ($field['msg_required'] ?? ''));
                $errors[] = $custom !== ''
                    ? htmlspecialchars($custom)
                    : htmlspecialchars($label) . ' is required.';
            }

            if (!$blank) {
                foreach ($this->formatErrors($field, $type, $value, $label) as $error) {
                    $errors[] = $error;
                }
            }

            // Stored as a string either way: `FormSubmission` holds one value
            // per key, and the submissions screen and the notification email
            // both print it. Joining here beats every reader guessing.
            //
            // THE SEPARATOR IS THE TYPE'S. A name reads "Jane Smith" and an
            // address reads "12 Mill Lane, Kamloops, BC"; a hard-coded ", "
            // gave "Jane, Smith". Checkboxes keep the comma, which is
            // `FieldTypes::join()`'s default.
            // A REPEATER'S ROWS READ BACK ONE PER LINE. Joined with commas
            // they run together into a sentence nobody can pick apart.
            if ($type === 'repeater' && is_array($value)) {
                $data[$name] = implode("\n", $value);
            } elseif ($type === 'likert' && is_array($value)) {
                // "Statement: Answer", one per line — the only shape in which a
                // grid of answers reads back as anything at all.
                $lines = [];
                foreach ($value as $rowText => $answer) {
                    $lines[] = $rowText . ': ' . $answer;
                }
                $data[$name] = implode("\n", $lines);
            } else {
                $data[$name] = is_array($value)
                    ? implode(FieldTypes::join($type), $value)
                    : (string) $value;
            }
        }

        // A REQUIRED ANSWER LEFT BLANK IS NOT SPAM EITHER. The visitor is shown
        // what to fix; storing each attempt would fill the Spam view with
        // people who got it right on the second try.
        if (!empty($errors)) {
            $this->answer(false, implode(' ', $errors), 422, $form, $meta['source_page']);
            return;
        }

        // ── The content checks, on the answers as stored (Forms\Guard) ──
        // And as read: a Name field's first and last are compared apart.
        if (!$staff) {
            $turn = Guard::content($data, $fields, $form['settings'] ?? [], $raw);
            if ($turn !== null) {
                $this->turnAway($formId, $form, $fields, $turn, $meta);
                return;
            }
        }

        // ── Take the appointment, BEFORE the submission is written ──
        //
        // The slot is the scarce thing. Written after the submission, a slot
        // that went while the visitor was typing would leave a stored
        // submission claiming a time somebody else has — a double booking that
        // looks like a successful form. Taken first, the failure is a 422 and
        // nothing is stored.
        //
        // The UNIQUE index is what refuses it; there is no check-then-write
        // here, because that race is exactly the one that matters on a popular
        // time.
        $bookedKeys = [];
        foreach ($fields as $field) {
            if (($field['type'] ?? '') !== 'appointment') {
                continue;
            }
            $fname = (string) ($field['name'] ?? '');
            $key   = trim((string) ($data[$fname] ?? ''));
            if ($key === '') {
                continue;   // not required, and not chosen
            }
            if (!Appointments::book($formId, $field, $key, $why)) {
                // RELEASE WHAT THIS REQUEST ALREADY TOOK. A form with two
                // appointment fields whose second one clashes must not leave
                // the first held by a submission that never happened.
                foreach ($bookedKeys as $held) {
                    Appointments::release($held);
                }
                $this->answer(false, (string) $why, 422, $form, $meta['source_page']);
                return;
            }
            $bookedKeys[] = $key;
            // AND THE STORED ANSWER IS THE TIME, IN WORDS. The key is a
            // machine string; a submissions table full of
            // `7:appointment:2026-09-14 14:30` is a table nobody can read.
            $data[$fname] = Appointments::describe($formId, $fname, $key);
        }

        // ── Save submission ─────────────────────────────────────────
        // With the token it carried, so the same page cannot send it twice
        // (`Guard::screen()` refuses a token a received submission holds).
        $submissionId = FormSubmission::create($formId, $data, $meta['ip'] !== '' ? $meta['ip'] : null, [
            'source_page' => $meta['source_page'],
            'user_agent'  => $meta['user_agent'],
            'token_hash'  => Guard::postedTokenHash($_POST),
        ]);

        // Tie each booking to the row that now exists, so deleting the
        // submission frees the slot — see migration 040's ON DELETE CASCADE.
        foreach ($bookedKeys as $held) {
            Appointments::attach($held, $submissionId);
        }

        // ── Send email notification ─────────────────────────────────
        // WHETHER IT WENT IS RECORDED: the submission is stored either way,
        // and a lead whose email bounced must still be findable as one.
        $settings   = $form['settings'] ?? [];
        $recipients = trim($settings['recipients'] ?? '');

        if ($recipients !== '') {
            FormSubmission::setNotified($submissionId, $this->sendNotification($form, $data, $recipients, $settings, $raw, $meta));
        }
        self::maybePurge();

        $successMessage = $settings['success_message'] ?? 'Thank you! Your submission has been received.';
        $this->answer(true, (string) $successMessage, 200, $form, $meta['source_page']);
    }

    /**
     * A post the guard turned away: CERTAIN is counted and discarded, anything
     * else is kept as spam with its reason — then the sender is answered the
     * way the guard says, "thank you" to a caught bot and the rule's message to
     * anyone who might be a person, discarded or not: a quick hand told to try
     * once more is answered the same either way. Spam is never emailed.
     *
     * KEEP ALL SPAM (Spam view, "What is kept") stores the certain ones too,
     * for review.
     *
     * @param array<string, mixed>       $form
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed>       $turn   code, reason, status, fake, certain
     * @param array<string, string>      $meta
     */
    private function turnAway(int $formId, array $form, array $fields, array $turn, array $meta): void
    {
        if (!empty($turn['certain']) && Option::get('forms_spam_keep', '0') !== '1') {
            FormSubmission::countDiscard($formId, (string) $turn['code']);
        } else {
            // What was typed, as sent: every field the form defines, as text.
            // Never a file: a bot's upload is not kept.
            $data = [];
            foreach ($fields as $field) {
                $name = (string) ($field['name'] ?? '');
                $type = (string) ($field['type'] ?? 'text');
                if ($name === '' || !FieldTypes::collects($type) || in_array($type, ['file', 'signature'], true) || !isset($_POST[$name])) {
                    continue;
                }
                $value = $_POST[$name];
                $text  = is_array($value)
                    ? implode(', ', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $value))
                    : (string) $value;
                if (trim($text) !== '') {
                    $data[$name] = mb_substr(trim($text), 0, 5000);
                }
            }
            FormSubmission::create($formId, $data, $meta['ip'] !== '' ? $meta['ip'] : null, [
                'status'      => FormSubmission::SPAM,
                'spam_reason' => (string) $turn['reason'],
                'source_page' => $meta['source_page'],
                'user_agent'  => $meta['user_agent'],
                // Kept on spam too: a second replay of the same page is told
                // apart from the first by counting these (`Guard::screen()`).
                'token_hash'  => Guard::postedTokenHash($_POST),
            ]);
            self::maybePurge();
        }

        if (!empty($turn['fake'])) {
            $this->answer(true, (string) (($form['settings'] ?? [])['success_message'] ?? 'Thank you! Your submission has been received.'),
                200, $form, $meta['source_page']);
            return;
        }
        $this->answer(false, Guard::message((string) $turn['code']), (int) $turn['status'], $form, $meta['source_page']);
    }

    /**
     * The page the form was sent from: the form's own `source_page` field
     * (a path on this site), else the Referer's path.
     */
    private static function sourcePage(): string
    {
        $posted = trim((string) ($_POST['source_page'] ?? ''));
        if ($posted !== '' && str_starts_with($posted, '/') && !str_starts_with($posted, '//')) {
            return mb_substr($posted, 0, 500);
        }
        $path = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
        return str_starts_with($path, '/') ? mb_substr($path, 0, 500) : '';
    }

    /** Posted text with every byte that is not UTF-8 replaced by U+FFFD, lists included. */
    private static function validText(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'validText'], $value);
        }
        if (!is_string($value) || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $was = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $value = mb_scrub($value, 'UTF-8');
        mb_substitute_character($was);
        return $value;
    }

    /**
     * Spam older than the Spam view's "Days to keep spam" goes, a thousand
     * rows at a time, on one stored post in fifty — `Security\SecurityLog`'s
     * lottery, because shared hosting has no cron to rely on. A purge on EVERY
     * post with one unbounded DELETE is harmless until a month after a flood,
     * when a quarter of a million rows come due at once inside a visitor's
     * request. The Spam view takes a batch too, each time it opens.
     */
    private static function maybePurge(): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }
        FormSubmission::purgeSpam(max(1, (int) (Option::get('forms_spam_days', null) ?? 30)));
        FormSubmission::pruneDiscards();
    }

    // ----------------------------------------------------------------
    // Reading and checking one posted value
    // ----------------------------------------------------------------

    /**
     * One field's posted value, in the shape its type posts.
     *
     * A checkbox is present-or-absent, not a value: an unticked box sends
     * nothing at all, so `$_POST[$name] ?? ''` is the whole test. A `checkboxes`
     * field posts `name[]` and arrives as an array — anything else is treated as
     * empty rather than cast, because a scalar there means the request was
     * hand-made and there is no right way to interpret it.
     *
     * @return string|list<string>
     */
    /**
     * A drawn signature, turned into a stored PNG.
     *
     * ── THE DATA URL IS DECODED, NOT TRUSTED ────────────────────────────────
     * A `data:` URL is a string the client composed, so its declared type is
     * worth exactly as much as `$_FILES['type']` — nothing. The bytes are
     * written to a temp file and handed to `Forms\Upload`, which sniffs them
     * with finfo like any other upload. A visitor who posts a PHP file base64
     * encoded with `image/png` in the prefix gets the same refusal as one who
     * uploads it directly.
     *
     * @param  array<string, mixed> $field
     */
    private static function storeSignature(string $dataUrl, array $field, ?string &$error): ?int
    {
        $error = null;

        if (!preg_match('~^data:image/png;base64,([A-Za-z0-9+/=]+)$~', trim($dataUrl), $m)) {
            $error = 'That signature could not be read.';
            return null;
        }
        $bytes = base64_decode($m[1], true);
        if ($bytes === false || $bytes === '') {
            $error = 'That signature could not be read.';
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'sig');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            $error = 'That signature could not be stored.';
            return null;
        }

        // `Upload::accept()` insists on `is_uploaded_file`, correctly — it is
        // the thing that separates a real upload from a path the request named.
        // This is not one, so the file is placed and registered here, through
        // the same allow-list and the same private store.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo !== false ? (string) finfo_file($finfo, $tmp) : '';
        if ($finfo !== false) {
            finfo_close($finfo);
        }
        if ($mime !== 'image/png' || strlen($bytes) > Upload::limitFor($field)) {
            @unlink($tmp);
            $error = 'That signature could not be stored.';
            return null;
        }

        $stored = bin2hex(random_bytes(16)) . '.png';
        $dir    = PUBLIC_PATH . '/uploads/private';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            @unlink($tmp);
            $error = 'That signature could not be stored.';
            return null;
        }
        if (!@rename($tmp, $dir . '/' . $stored)) {
            @unlink($tmp);
            $error = 'That signature could not be stored.';
            return null;
        }
        // tempnam() made it 0600 and rename() kept that, which leaves the
        // site's group (a backup job, say) unable to read it.
        @chmod($dir . '/' . $stored, 0640);

        $size = @getimagesize($dir . '/' . $stored);

        return \Cms\Models\Media::create([
            'filename'      => $stored,
            'original_name' => 'signature.png',
            'mime_type'     => 'image/png',
            'file_size'     => (int) filesize($dir . '/' . $stored),
            'width'         => is_array($size) ? (int) $size[0] : null,
            'height'        => is_array($size) ? (int) $size[1] : null,
            'folder_id'     => null,
            'visibility'    => 'private',
            'alt_text'      => 'Signature drawn on a form',
        ]);
    }

    /**
     * Reasons an upload was refused, keyed by field name.
     *
     * `readValue()` is the only place that can produce one — it is where the
     * file is actually stored — and validation runs after it, so the message
     * has to be parked rather than returned. A refused upload that reported
     * nothing would be a form that says "required" about a file the visitor
     * can see they attached.
     *
     * @var array<string, string>
     */
    private array $uploadErrors = [];

    private function readValue(array $field, string $name, string $type): array|string
    {
        $why = null;
        // A TOGGLE POSTS EXACTLY WHAT A CHECKBOX POSTS — '1' or nothing. It
        // is a different CONTROL, not a different value, so it shares this
        // branch and nothing downstream has to know which one the author
        // picked.
        if ($type === 'checkbox' || $type === 'toggle') {
            return isset($_POST[$name]) ? '1' : '';
        }

        // A COMPOSITE POSTS ONE ARRAY — `name[first]`, `name[last]`. Only the
        // parts the type declares are read, so a hand-made POST cannot smuggle
        // a seventh line into an address, and EMPTY PARTS ARE DROPPED: that is
        // what makes an all-blank composite come back as `[]` and satisfy the
        // same `$blank` test every other type uses, rather than needing its own
        // branch in the required check.
        // ── A FILE ARRIVES IN $_FILES, NOT $_POST ───────────────────────────
        // Stored on the spot and reduced to the attachment's id, so everything
        // downstream — required, logic, the stored string — is dealing with a
        // short scalar like every other field. `Forms\Upload` owns what is
        // allowed; the message it writes is the one the visitor is shown.
        if ($type === 'file') {
            $id = Upload::accept($_FILES[$name] ?? ['error' => UPLOAD_ERR_NO_FILE],
                                 $field, $why);
            if ($why !== null) {
                $this->uploadErrors[$name] = $why;
            }
            return $id === null ? '' : (string) $id;
        }

        // A SIGNATURE IS A PNG THE VISITOR DREW. It posts as a data URL, which
        // is turned back into a file through the same path — so it lands in the
        // media library, private, beside every other uploaded file, rather
        // than as a base64 blob wedged into the submissions table.
        if ($type === 'signature') {
            $raw = (string) ($_POST[$name] ?? '');
            if (trim($raw) === '') {
                return '';
            }
            $id = self::storeSignature($raw, $field, $why);
            if ($why !== null) {
                $this->uploadErrors[$name] = $why;
            }
            return $id === null ? '' : (string) $id;
        }

        // A REPEATER POSTS `name[row][column]` — rows of the author's own
        // columns. Empty ROWS are dropped, not empty cells: somebody who filled
        // in a name and left the phone blank answered the question, and
        // discarding the row would discard the answer.
        if ($type === 'repeater') {
            $posted = $_POST[$name] ?? [];
            if (!is_array($posted)) {
                return [];
            }
            $cols = array_values(array_filter(
                array_map('trim', explode("\n", (string) ($field['options'] ?? ''))),
                static fn(string $o): bool => $o !== ''
            ));
            if ($cols === []) {
                $cols = ['Item'];
            }

            $out = [];
            foreach ($posted as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $cells = [];
                foreach ($cols as $col) {
                    $one = $row[$col] ?? '';
                    if (is_string($one) && trim($one) !== '') {
                        $cells[] = $col . ': ' . trim($one);
                    }
                }
                if ($cells !== []) {
                    $out[] = implode('; ', $cells);
                }
            }
            return $out;
        }

        // A LIKERT IS A COMPOSITE WHOSE PARTS THE AUTHOR WROTE. Its rows come
        // from the Options list, so the keys are not in the type table — but
        // the SHAPE is identical to Name's, `name[row]`, and it is stored the
        // same way. The stored value carries the row's own words rather than
        // `r0`, because "r0: Agree" in the submissions table and the
        // notification email is a row nobody can read.
        if ($type === 'likert') {
            $posted = $_POST[$name] ?? [];
            if (!is_array($posted)) {
                return [];
            }
            $rows = array_values(array_filter(
                array_map('trim', explode("\n", (string) ($field['options'] ?? ''))),
                static fn(string $o): bool => $o !== ''
            ));
            $out = [];
            foreach ($rows as $i => $rowText) {
                $one = $posted['r' . $i] ?? '';
                if (is_string($one) && trim($one) !== '') {
                    $out[$rowText] = trim($one);
                }
            }
            return $out;
        }

        if (FieldTypes::isComposite($type)) {
            $posted = $_POST[$name] ?? [];
            if (!is_array($posted)) {
                return [];
            }
            $out = [];
            foreach (array_keys(FieldTypes::parts($type)) as $key) {
                $one = $posted[$key] ?? '';
                if (is_string($one) && trim($one) !== '') {
                    $out[$key] = trim($one);
                }
            }
            return $out;
        }

        if (FieldTypes::isMultiple($type)) {
            $posted = $_POST[$name] ?? [];
            if (!is_array($posted)) {
                return [];
            }
            $out = [];
            foreach ($posted as $one) {
                if (is_string($one) && trim($one) !== '') {
                    $out[] = trim($one);
                }
            }
            return $out;
        }

        $value = $_POST[$name] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Format errors for one non-blank value.
     *
     * EVERY CHOICE FIELD CHECKS ITS VALUE AGAINST ITS OPTIONS, not just the
     * dropdown. A `<select>` restricts the browser, never the request; a radio
     * group and a checkbox set are exactly as forgeable. Before this, only
     * `select` was checked, so a hand-made POST could store any string at all
     * for a radio field and the submissions screen would print it as though the
     * visitor had picked it.
     *
     * @param  array<string, mixed> $field
     * @param  string|list<string>  $value
     * @return list<string>
     */
    private function formatErrors(array $field, string $type, array|string $value, string $label): array
    {
        $errors = [];
        $label  = htmlspecialchars($label);

        // ONE CUSTOM MESSAGE COVERS EVERY FORMAT FAILURE ON A FIELD. The builder
        // offers "When the format is wrong" as a single box, because a text
        // field has exactly one format rule and offering four boxes for four
        // types would be four boxes of which three are always dead.
        $fmt = trim((string) ($field['msg_format'] ?? ''));
        $say = static fn(string $fallback): string
            => $fmt !== '' ? htmlspecialchars($fmt) : $fallback;

        if ($type === 'email' && !filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $say($label . ' must be a valid email address.');
        }
        if ($type === 'url' && !filter_var((string) $value, FILTER_VALIDATE_URL)) {
            $errors[] = $say($label . ' must be a valid web address.');
        }
        if ($type === 'number' && !is_numeric((string) $value)) {
            $errors[] = $say($label . ' must be a number.');
        }
        // `Y-m-d` because that is what `<input type="date">` posts. Comparing
        // the reformatted date back to the input rejects 2026-02-31, which
        // strtotime() happily rolls forward to March.
        // `H:i` is what `<input type="time">` posts. Same shape as the date
        // check below and for the same reason: comparing the reformatted value
        // back to the input rejects 25:00, which a looser parse accepts.
        if ($type === 'time') {
            $parsed = \DateTimeImmutable::createFromFormat('H:i', (string) $value);
            if ($parsed === false || $parsed->format('H:i') !== (string) $value) {
                $errors[] = $say($label . ' must be a valid time.');
            }
        }
        // A SLIDER CANNOT BE MISTYPED, BUT IT CAN BE FORGED. The control
        // restricts the browser, never the request — the same reasoning the
        // choice fields carry above.
        if ($type === 'range') {
            $min = (float) ($field['min'] ?? 0);
            $max = (float) ($field['max'] ?? 100);
            if (!is_numeric((string) $value)
                || (float) $value < $min || (float) $value > $max) {
                $errors[] = $say($label . ' must be between ' . $min . ' and ' . $max . '.');
            }
        }
        // A SCALE OR A RATING RESTRICTS THE BROWSER, NEVER THE REQUEST — the
        // same reasoning every choice field above carries. The bounds are the
        // ones the renderer drew with, so a forged 99 on a 1-5 rating is
        // rejected rather than stored as though somebody picked it.
        if ($type === 'scale' || $type === 'rating') {
            $lo = $type === 'rating' ? 1 : (int) ($field['min'] ?? 1);
            $hi = (int) ($field['max'] ?? 5);
            if ($hi < $lo) { $hi = $lo; }
            if ($hi - $lo > 20) { $hi = $lo + 20; }
            $n = (string) $value;
            if ($n !== (string) (int) $n || (int) $n < $lo || (int) $n > $hi) {
                $errors[] = $say($label . ' must be between ' . $lo . ' and ' . $hi . '.');
            }
        }
        // AND EVERY LIKERT ANSWER HAS TO BE ONE OF ITS COLUMNS.
        if ($type === 'likert' && is_array($value)) {
            $cols = array_values(array_filter(
                array_map('trim', explode("\n", (string) ($field['columns'] ?? ''))),
                static fn(string $o): bool => $o !== ''
            ));
            if ($cols === []) {
                $cols = ['Strongly disagree', 'Disagree', 'Neutral',
                         'Agree', 'Strongly agree'];
            }
            foreach ($value as $answer) {
                if (!in_array($answer, $cols, true)) {
                    $errors[] = $say($label . ' has an answer that is not one of its options.');
                    break;
                }
            }
        }
        if ($type === 'date') {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $value);
            if ($parsed === false || $parsed->format('Y-m-d') !== (string) $value) {
                $errors[] = $say($label . ' must be a valid date.');
            }
        }

        // EVERY CHOICE FIELD CHECKS ITS VALUE AGAINST ITS OPTIONS — except the
        // Likert, whose Options list is its ROWS and whose answers come from
        // its Columns. Checking a Likert here compares "Strongly agree"
        // against "The room was clean" and rejects every valid submission,
        // which is exactly what it did: a 422 on a form with nothing wrong
        // with it. The Likert has its own check above, against the right list.
        // A SOURCED FIELD IS CHECKED AGAINST THE SITE'S CONTENT, and it has
        // to be: a `<select>` restricts the browser, never the request, and
        // these are the fields whose valid answers are not written down
        // anywhere in the form definition. Same list the renderer drew with.
        if (FieldTypes::isSourced($type)) {
            $allowed = Sources::choicesFor($field);
            if ($allowed !== []) {
                foreach ((array) $value as $one) {
                    if (!in_array((string) $one, $allowed, true)) {
                        $errors[] = $label . ' has an invalid selection.';
                        break;
                    }
                }
            }
        }

        // A REPEATER IS EXCLUDED FOR THE SAME REASON THE LIKERT IS: its
        // Options list names its COLUMNS, and its values are whatever the
        // visitor typed into them. Checking a typed row against the column
        // headings would reject every submission — the Likert taught this
        // exact lesson one field earlier.
        if (FieldTypes::hasChoices($type)
            && !in_array($type, ['likert', 'repeater'], true)) {
            // A DROPDOWN, RADIO OR CHECKBOX OPTION IS ITS VALUE: "label | value"
            // posts the value, so that is what is allowed.
            $options = in_array($type, FieldTypes::LABELLED, true)
                ? array_column(FieldTypes::optionPairs((string) ($field['options'] ?? '')), 'value')
                : array_values(array_filter(
                    array_map('trim', explode("\n", (string) ($field['options'] ?? ''))),
                    static fn(string $o): bool => $o !== ''
                ));
            if ($options !== []) {
                foreach ((array) $value as $one) {
                    if (!in_array((string) $one, $options, true)) {
                        $errors[] = $label . ' has an invalid selection.';
                        break;
                    }
                }
            }
        }

        // A MAXIMUM THE BUILDER OFFERS IS A MAXIMUM THE SERVER ENFORCES. The
        // `maxlength` attribute stops typing, not posting.
        $max = (int) ($field['maxlength'] ?? 0);
        if ($max > 0 && !is_array($value) && mb_strlen($value) > $max) {
            $errors[] = $label . ' must be ' . $max . ' characters or fewer.';
        }

        return $errors;
    }

    // ----------------------------------------------------------------
    // Email notification
    // ----------------------------------------------------------------

    /**
     * Email a submission to the form's recipients.
     *
     * ── THE TRANSPORT IS `Mail\Mailer`'S; THE MESSAGE IS HERE ──────────────
     * Orders send mail too, so the PHPMailer setup — SMTP host, credentials,
     * encryption, sender — lives in `Mail\Mailer`, the one mailer Settings →
     * Email configures, rather than inline here. `Mail\Mailer` owns the
     * envelope; what is left here is the part that is actually about a form
     * submission.
     *
     * Two parts look like accidents and are not: a blank SMTP host means "use
     * the server's own mail program", and a failure is swallowed rather than
     * failing the submission — the answer has already been stored by the time
     * this runs, so an exception would show the visitor an error for something
     * that worked.
     */
    protected function sendNotification(array $form, array $data, string $recipients, array $settings, array $answers = [], array $meta = []): bool
    {
        // `Forms\Notify`: the subject as a
        // template of the answers, Reply-To under the sender's name, and a
        // site's own `form-mail.php` composing the whole message when it has
        // one. `$recipients` and `$settings` are the form's own, read there.
        return Notify::send($form, $data, $answers !== [] ? $answers : $data, $meta);
    }

    // ----------------------------------------------------------------
    // The answer
    // ----------------------------------------------------------------

    /**
     * THE ANSWER, IN THE SHAPE THE SENDER CAN READ.
     *
     * A script that asks for JSON — `Accept: application/json`, or
     * `X-Requested-With: XMLHttpRequest` — gets {success, message}; the form
     * this CMS draws asks that way. A browser posting the form itself, with no
     * JavaScript, gets a page rather than that raw JSON:
     *
     *   accepted (and a caught bot, told the same): a 303 to the form's own
     *     "after sending" address, else back to the page it was sent from,
     *     marked `sent=1&form={id}` — the form this CMS draws shows its
     *     thank-you there, and a site's own page reads `sent`;
     *   refused: a page saying why, under the refusal's status — the site's
     *     `form-refused.php` (the active theme's, or `admin/site/templates/`)
     *     when it has one, the whole page in its own words, handed $message,
     *     $status and $sourcePage; else `form-answer.php` in the site's chrome.
     *
     * @param array<string, mixed>|null $form
     */
    private function answer(bool $success, string $message, int $status, ?array $form, string $sourcePage): void
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $ajax   = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if ($ajax || str_contains($accept, 'application/json')) {
            $this->jsonResponse($success, $message, $status);
            return;
        }

        header('Cache-Control: no-store');
        if ($success) {
            $to = self::afterSend($form ?? [], $sourcePage);
            if ($to !== null) {
                header('Location: ' . $to, true, 303);
                return;
            }
        }

        http_response_code($success ? 200 : $status);
        header('Content-Type: text/html; charset=utf-8');
        $hook = $success ? null : Theme::file('form-refused.php');
        if ($hook !== null) {
            Theme::render($hook, ['message' => $message, 'status' => $status, 'sourcePage' => $sourcePage]);
            return;
        }

        Head::set(['title' => $success ? 'Sent' : 'Not sent', 'robots' => 'noindex, nofollow']);
        $answer = [
            'success' => $success,
            // THE REASON, ESCAPED ONCE. The validator's messages come escaped
            // for the JSON answer (a field's label is inside them), so they
            // are unescaped here and escaped by the template: "Company's
            // name" read "Company&#039;s name" on the page.
            'message' => html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            // BACK TO THE FORM BY ITS ADDRESS. This is the page a browser
            // without JavaScript sees, so `history.back()` would be a link
            // that does nothing; the browser's own Back keeps what was typed.
            'back'    => self::onSite($sourcePage) ? rtrim((string) BASE_URL, '/') . $sourcePage : rtrim((string) BASE_URL, '/') . '/',
        ];
        ob_start();
        require Template::locate('form-answer.php');
        $pageContent = ob_get_clean();
        require Template::locate('base.php');
    }

    /**
     * Where a browser goes after sending: the form's own address (Settings →
     * After submitting), else the page it was sent from, marked; null when
     * there is neither. Only ever an address on this site — a path, or a full
     * address on this site's own host — since the page it was sent from is
     * whatever the post says it was.
     *
     * @param array<string, mixed> $form
     */
    private static function afterSend(array $form, string $sourcePage): ?string
    {
        $base = rtrim((string) BASE_URL, '/');
        $own  = trim((string) (($form['settings'] ?? [])['redirect'] ?? ''));
        if ($own !== '') {
            if (self::onSite($own)) {
                return $base . $own;
            }
            $host = (string) parse_url($base, PHP_URL_HOST);
            if ($host !== '' && preg_match('~^https?://[^\\\\\s]+$~i', $own) === 1
                && strcasecmp((string) parse_url($own, PHP_URL_HOST), $host) === 0) {
                return $own;
            }
        }
        // A #fragment goes: what follows it is never sent to the server, so
        // the mark has to come before it to be read at all.
        $sourcePage = explode('#', $sourcePage, 2)[0];
        if (self::onSite($sourcePage)) {
            return $base . $sourcePage . (str_contains($sourcePage, '?') ? '&' : '?')
                . 'sent=1&form=' . (int) ($form['id'] ?? 0);
        }
        return null;
    }

    /**
     * A path on this site: one leading slash, then no second slash or
     * backslash after it (`//host` and `/\host` are other sites to a
     * browser), and nothing that is not a plain address character.
     */
    private static function onSite(string $path): bool
    {
        return preg_match('~^/(?![/\\\\])[^\\\\\s<>"]*$~', $path) === 1;
    }

    // ----------------------------------------------------------------
    // JSON response helper
    // ----------------------------------------------------------------

    protected function jsonResponse(bool $success, string $message, int $httpCode = 200): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => $success,
            'message' => $message,
        ]);
    }
}
