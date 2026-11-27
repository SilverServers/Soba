<?php

declare(strict_types=1);

namespace Cms\Controllers\Public;

use Cms\Auth\Auth;
use Cms\Forms\Answers;
use Cms\Forms\Captcha;
use Cms\Forms\Appointments;
use Cms\Forms\Embed;
use Cms\Forms\Notify;
use Cms\Forms\FieldTypes;
use Cms\Forms\Guard;
use Cms\Forms\Sources;
use Cms\Forms\Upload;
use Cms\Helpers\Template;
use Cms\Helpers\Theme;
use Cms\Models\Form;
use Cms\Models\FormSubmission;
use Cms\Models\Option;
use Cms\SEO\Head;
use Cms\Security\ClientIp;
use Cms\Http\Cache;

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
            $turn = Guard::screen($_POST, $_SERVER, is_array($form['settings'] ?? null) ? $form['settings'] : []);
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
            $this->refuse($form, $fields, 'That answer was not right. Please try again.', 422, [], $meta);
            return;
        }

        // ── The answers, read and checked (`Forms\Answers`) ─────────────
        // Every field's posted value, then which fields conditional logic
        // showed, then those checked and kept: the reading a static copy's
        // handler makes too, in one place, so the two cannot differ. A field
        // whose choices are the site's own content is checked against them
        // (`Sources`).
        // A FILE OR A DRAWN SIGNATURE IS CHECKED HERE AND KEPT ONLY ONCE THE
        // POST IS ACCEPTED, below: until then the answer is the file's own
        // name, and a post refused or turned away leaves no file behind.
        // `$fields` is the EXPANDED list, settled above: the same one the
        // renderer drew from. If the two ever differ, the page collects
        // answers the server never validates or stores.
        /** @var array<string, array{kind: string, admitted: array<string, mixed>}> $admitted field name => checked, not yet kept */
        $admitted = [];
        $checked  = (new Answers(
            $_POST,
            $_FILES,
            static function (string $type, mixed $posted, array $field, ?string &$why) use (&$admitted): ?string {
                $one = $type === 'file'
                    ? Upload::admit(is_array($posted) ? $posted : ['error' => UPLOAD_ERR_NO_FILE], $field, $why)
                    : self::admitSignature((string) $posted, $field, $why);
                if ($one === null) {
                    return null;
                }
                $admitted[(string) ($field['name'] ?? '')] = ['kind' => $type, 'admitted' => $one];

                return (string) $one['name'];
            },
            static fn (array $field): array => Sources::choicesFor($field),
        ))->check($fields);
        $errors = $checked['errors'];
        $data   = $checked['data'];
        $raw    = $checked['raw'];

        // A REQUIRED ANSWER LEFT BLANK IS NOT SPAM EITHER. The visitor is shown
        // what to fix; storing each attempt would fill the Spam view with
        // people who got it right on the second try.
        if (!empty($errors)) {
            $this->refuse($form, $fields, implode(' ', $errors), 422, $errors, $meta);
            return;
        }

        // ── The content checks, on the answers as stored (Forms\Guard) ──
        // And as read: a Name field's first and last are compared apart. The
        // CMS's rules, then the site's own (`form-spam.php`).
        if (!$staff) {
            $turn = Guard::content($data, $fields, $form['settings'] ?? [], $raw)
                ?? Guard::siteRule($form, $data, $meta);
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
                $this->refuse($form, $fields, (string) $why, 422, [$fname => htmlspecialchars((string) $why)], $meta);
                return;
            }
            $bookedKeys[] = $key;
            // AND THE STORED ANSWER IS THE TIME, IN WORDS. The key is a
            // machine string; a submissions table full of
            // `7:appointment:2026-09-14 14:30` is a table nobody can read.
            $data[$fname] = Appointments::describe($formId, $fname, $key);
        }

        // ── The files, kept now that the post is accepted ───────────
        // Each upload and drawn signature in an answer the form keeps (not one
        // in a field conditional logic hid) goes to the private store and the
        // media library, and the answer becomes its id. One that cannot be
        // kept refuses the post as a wrong answer would: the times it took are
        // given back and the files kept before it deleted, so nothing is left.
        $keptFiles = [];
        foreach ($admitted as $fname => $one) {
            if (!array_key_exists($fname, $data)) {
                continue;
            }
            $why = null;
            $id  = $one['kind'] === 'file' ? Upload::store($one['admitted'], $why) : self::storeSignature($one['admitted'], $why);
            if ($id === null) {
                foreach ($bookedKeys as $held) {
                    Appointments::release($held);
                }
                foreach ($keptFiles as $keptId) {
                    \Cms\Models\Media::delete($keptId);
                }
                $label = $fname;
                foreach ($fields as $field) {
                    if (($field['name'] ?? '') === $fname && ($field['label'] ?? '') !== '') {
                        $label = (string) $field['label'];
                    }
                }
                $message = htmlspecialchars($label) . ': ' . htmlspecialchars((string) $why);
                $this->refuse($form, $fields, $message, 422, [$fname => $message], $meta);
                return;
            }
            $keptFiles[]  = $id;
            $data[$fname] = (string) $id;
            $raw[$fname]  = (string) $id;
        }

        // ── A TEST, from the form's test address ────────────────────
        // Everything above and below happens as for any submission; only the
        // notification differs: it goes to the test inbox alone. Stored
        // marked, so the inbox can show it and leave it out.
        $testInbox = Notify::testInbox($form, $data);

        // ── Save submission ─────────────────────────────────────────
        // With the token it carried, so the same page cannot send it twice
        // (`Guard::screen()` refuses a token a received submission holds).
        $submissionId = FormSubmission::create($formId, $data, $meta['ip'] !== '' ? $meta['ip'] : null, [
            'source_page' => $meta['source_page'],
            'user_agent'  => $meta['user_agent'],
            'token_hash'  => Guard::postedTokenHash($_POST),
            'is_test'     => $testInbox !== null,
        ]);

        // Tie each booking to the row that now exists, so deleting the
        // submission frees the slot — see migration 040's ON DELETE CASCADE.
        foreach ($bookedKeys as $held) {
            Appointments::attach($held, $submissionId);
        }

        // ── Send email notification ─────────────────────────────────
        // WHETHER IT WENT IS RECORDED: the submission is stored either way,
        // and a lead whose email bounced must still be findable as one. So is
        // what became of it, the message's own id or why it was refused
        // (`FormSubmission::recordMail()`), for the inbox to show.
        $settings   = $form['settings'] ?? [];
        $recipients = trim($settings['recipients'] ?? '');

        if ($recipients !== '' || $testInbox !== null) {
            \Cms\Mail\Mailer::clearResult();
            FormSubmission::setNotified($submissionId, $this->sendNotification($form, $data, $recipients, $settings, $raw,
                $meta + ['submission_id' => (string) $submissionId], $testInbox));
            FormSubmission::recordMail($submissionId, 'notification', \Cms\Mail\Mailer::lastResult());
        }
        // A CONFIRMATION TO THE SENDER, when the form has one: here, past
        // every spam check above, so no address a bot typed is written to.
        \Cms\Mail\Mailer::clearResult();
        if (Notify::confirm($form, $data, $testInbox) !== null) {
            FormSubmission::recordMail($submissionId, 'confirmation', \Cms\Mail\Mailer::lastResult());
        }
        self::maybePurge();

        $successMessage = $settings['success_message'] ?? 'Thank you! Your submission has been received.';
        // THE CONVERSION IS THIS ONE'S ALONE: a real submission, received,
        // and named by its stored id. A caught bot's thank-you (`turnAway()`)
        // and a refusal send none.
        $this->answer(true, (string) $successMessage, 200, $form, $meta['source_page'], \Cms\Site\Google::conversionFor($form, $submissionId));
    }

    /**
     * A post the guard turned away: CERTAIN is counted and discarded, anything
     * else is kept as spam with its reason — then the sender is answered the
     * way the guard says, "thank you" to a caught bot and the rule's message to
     * anyone who might be a person, discarded or not: a quick hand told to try
     * once more is answered the same either way. Spam is never emailed, and
     * never sends a conversion.
     *
     * KEEP ALL SPAM (Spam view, "What is kept") stores the certain ones too,
     * for review. A form answering like a sent message (`spam_answer`) keeps
     * what its speed or content turned away and answers it as sent, and
     * `Forms\Guard` decides both (`keeps()`, `answersSent()`), for the site
     * and a static copy alike.
     *
     * @param array<string, mixed>       $form
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed>       $turn   code, reason, status, fake, certain
     * @param array<string, string>      $meta
     */
    private function turnAway(int $formId, array $form, array $fields, array $turn, array $meta): void
    {
        $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
        if (!Guard::keeps($turn, $settings, Option::get('forms_spam_keep', '0') === '1')) {
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

        if (Guard::answersSent($turn, $settings)) {
            $this->answer(true, (string) ($settings['success_message'] ?? 'Thank you! Your submission has been received.'),
                200, $form, $meta['source_page']);
            return;
        }
        $this->refuse($form, $fields, Guard::message((string) $turn['code']), (int) $turn['status'], [], $meta, (string) $turn['code']);
    }

    /**
     * POST /forms/{id}/token — A NEW TOKEN FOR A PAGE LEFT OPEN (form.js).
     *
     * Given the page's own token (`Guard::renew()`), one signed here and at
     * least the form's fewest seconds old, it answers with one good for as
     * long again, so a visitor who took longer than that over a message still
     * sends it. Anything else is refused (400), and the page keeps its own.
     */
    public function handleToken(array $vars = []): void
    {
        Cache::noStore();
        header('Content-Type: application/json; charset=utf-8');
        $form  = Form::find((int) ($vars['id'] ?? 0));
        $fresh = $form !== null
            ? Guard::renew((string) ($_POST[Guard::TOKEN] ?? ''), is_array($form['settings'] ?? null) ? $form['settings'] : [])
            : null;
        if ($fresh === null) {
            http_response_code(400);
            echo json_encode(['success' => false]);
            return;
        }
        echo json_encode(['success' => true, 'token' => $fresh]);
    }

    /**
     * A REFUSAL, IN THE SHAPE THE SENDER CAN READ.
     *
     * By script (`Accept: application/json`): the status, {success: false,
     * message}, `errors` naming each field refused with its message as text
     * (form.js puts each under its field), and `code` for a rule the guard
     * turned it away by (form.js renews an expired page's token and sends
     * again).
     *
     * Without a script, from a form this CMS drew that checks its own
     * answers (it posts its page, `FormReturn::RETURN_FIELD`): a 303 back to
     * that page, at the form, with
     * every answer as typed and each message under its field carried there
     * (`Models\FormReturn`); the form's own message above or below it says to
     * look at them, or what the guard said. Not for a post from another site
     * or with no token of this site's, which no page of the site's sent, nor
     * for a flood. Otherwise, the refusal page as before (`answer()`).
     *
     * @param array<string, mixed>       $form
     * @param list<array<string, mixed>> $fields
     * @param array<string, string>      $errors name => message, escaped (`Forms\Answers`)
     * @param array<string, string>      $meta
     */
    private function refuse(array $form, array $fields, string $message, int $status, array $errors, array $meta, string $code = ''): void
    {
        $plain  = array_map(static fn ($m): string => html_entity_decode((string) $m, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $errors);
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $ajax   = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if ($ajax || str_contains($accept, 'application/json')) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            Cache::noStore();
            $answer = ['success' => false, 'message' => $message];
            if ($plain !== []) {
                $answer['errors'] = $plain;
            }
            if ($code !== '') {
                $answer['code'] = $code;
            }
            echo json_encode($answer);
            return;
        }

        $page = trim((string) ($_POST[\Cms\Models\FormReturn::RETURN_FIELD] ?? ''));
        $page = explode('#', $page, 2)[0];
        if ($page !== '' && self::onSite($page) && !in_array($code, ['cross_site', 'token_invalid', 'flood'], true)) {
            $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
            $fix      = trim((string) ($settings['msg_fix'] ?? ''));
            $plain[''] = $plain !== []
                ? ($fix !== '' ? $fix : 'Please check the highlighted fields and try again.')
                : html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (\Cms\Models\FormReturn::keep((int) ($form['id'] ?? 0), self::typed($fields), $plain)) {
                Cache::noStore();
                header('Location: ' . $page . '#' . Form::elementId($form), true, 303);
                return;
            }
        }
        $this->answer(false, $message, $status, $form, $meta['source_page']);
    }

    /**
     * What was typed into each of the form's fields, as posted, for the page
     * to draw again: text, or a list of it; never a file, a drawing, a
     * password or a captcha's answer.
     *
     * @param  list<array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private static function typed(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            $type = (string) ($field['type'] ?? 'text');
            if ($name === '' || !FieldTypes::collects($type) || in_array($type, ['file', 'signature', 'password', 'captcha'], true)
                || !array_key_exists($name, $_POST)) {
                continue;
            }
            $value = $_POST[$name];
            $out[$name] = is_array($value)
                ? array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $value)
                : (string) $value;
        }
        return $out;
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
     * A drawn signature, checked: the PNG to keep once the post is accepted
     * (`storeSignature()`), or null with a reason.
     *
     * ── THE DATA URL IS DECODED, NOT TRUSTED ────────────────────────────────
     * A `data:` URL is a string the client composed, so its declared type is
     * worth exactly as much as `$_FILES['type']` — nothing. The bytes are
     * sniffed with finfo, as an upload's are, and held to the field's limit. A
     * visitor who posts a PHP file base64 encoded with `image/png` in the
     * prefix gets the same refusal as one who uploads it directly.
     *
     * @param  array<string, mixed> $field
     * @return array{bytes: string, name: string}|null
     */
    private static function admitSignature(string $dataUrl, array $field, ?string &$error): ?array
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

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo !== false ? (string) finfo_buffer($finfo, $bytes) : '';
        if ($finfo !== false) {
            finfo_close($finfo);
        }
        if ($mime !== 'image/png' || strlen($bytes) > Upload::limitFor($field)) {
            $error = 'That signature could not be stored.';
            return null;
        }

        return ['bytes' => $bytes, 'name' => 'signature.png'];
    }

    /**
     * A signature `admitSignature()` took, kept: written to the private store
     * under a name of its own and registered in the media library. Not through
     * `Upload::store()`, which insists on `is_uploaded_file`, correctly: it is
     * the thing that separates a real upload from a path the request named.
     *
     * @param array{bytes: string, name: string} $admitted
     */
    private static function storeSignature(array $admitted, ?string &$error): ?int
    {
        $error  = null;
        $stored = bin2hex(random_bytes(16)) . '.png';
        $dir    = PUBLIC_PATH . '/uploads/private';
        if ((!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir))
            || @file_put_contents($dir . '/' . $stored, $admitted['bytes']) === false) {
            $error = 'That signature could not be stored.';
            return null;
        }
        // Readable by the site's group (a backup job, say), as an upload is.
        @chmod($dir . '/' . $stored, 0640);

        $size = @getimagesize($dir . '/' . $stored);

        return \Cms\Models\Media::create([
            'filename'      => $stored,
            'original_name' => $admitted['name'],
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
    protected function sendNotification(array $form, array $data, string $recipients, array $settings, array $answers = [], array $meta = [], ?string $testInbox = null): bool
    {
        // `Forms\Notify`: the subject as a
        // template of the answers, Reply-To under the sender's name, and a
        // site's own `form-mail.php` composing the whole message when it has
        // one. `$recipients` and `$settings` are the form's own, read there;
        // a test's goes to `$testInbox` alone.
        return Notify::send($form, $data, $answers !== [] ? $answers : $data, $meta, $testInbox);
    }

    // ----------------------------------------------------------------
    // The answer
    // ----------------------------------------------------------------

    /**
     * THE ANSWER, IN THE SHAPE THE SENDER CAN READ.
     *
     * A script that asks for JSON — `Accept: application/json`, or
     * `X-Requested-With: XMLHttpRequest` — gets {success, message}; the form
     * this CMS draws asks that way, and so may a site's own script. When the
     * form names an after-sending address and its box says a send by script
     * goes there too, an accepted one (and a caught bot, told the same) gets
     * {success, message, redirect}: `redirect` is that address as a path on
     * the host the visitor used, and the script goes there. A browser posting
     * the form itself, with no JavaScript, gets a page rather than that raw
     * JSON:
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
     * A real submission's conversion (`Site\Google`) goes with whichever
     * answer it is: in the JSON, in a cookie for the after-sending page (a
     * redirect's, or the one a JSON answer sends its script to), or on the
     * "Sent" page itself.
     *
     * @param array<string, mixed>|null $form
     * @param array{event: string, form: int, id: string}|null $conversion
     */
    private function answer(bool $success, string $message, int $status, ?array $form, string $sourcePage, ?array $conversion = null): void
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $ajax   = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if ($ajax || str_contains($accept, 'application/json')) {
            // THE FORM'S BOX SAYS SO ("Go there after a send by script
            // too"): a form saved before there was one shows its thank-you in
            // place, as it always did.
            $to = $success && (string) ((($form ?? [])['settings'] ?? [])['redirect_scripted'] ?? '') === '1'
                ? self::ownAddress($form ?? []) : null;
            if ($to !== null) {
                // ITS PAGE SENDS IT, so the answer does not: carried in the
                // cookie, as after a redirect, and in the JSON not at all.
                if ($conversion !== null) {
                    \Cms\Site\Google::sendAfterRedirect($conversion);
                }
                $this->jsonResponse(true, $message, $status, null, $to);
                return;
            }
            $this->jsonResponse($success, $message, $status, $success ? $conversion : null);
            return;
        }

        Cache::noStore();
        if ($success) {
            $to = self::afterSend($form ?? [], $sourcePage);
            if ($to !== null) {
                if ($conversion !== null) {
                    \Cms\Site\Google::sendAfterRedirect($conversion);
                }
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

        if ($success && $conversion !== null) {
            \Cms\Site\Google::sentHere($conversion);
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
     * whatever the post says it was. AS A PATH: the browser stays on the host
     * it sent from, so a site answering on both `www.` and its bare name keeps
     * the visitor, and a conversion's cookie, on the name they used.
     *
     * @param array<string, mixed> $form
     */
    private static function afterSend(array $form, string $sourcePage): ?string
    {
        $own = self::ownAddress($form);
        if ($own !== null) {
            return $own;
        }
        $base = rtrim((string) BASE_URL, '/');
        // A #fragment goes: what follows it is never sent to the server, so
        // the mark has to come before it to be read at all.
        $sourcePage = explode('#', $sourcePage, 2)[0];
        if (self::onSite($sourcePage)) {
            return self::pathOf($base . $sourcePage . (str_contains($sourcePage, '?') ? '&' : '?')
                . 'sent=1&form=' . (int) ($form['id'] ?? 0));
        }
        return null;
    }

    /**
     * The form's own after-sending address (Settings → After submitting), as
     * a path on this site, or null when it names none that is on this site.
     *
     * @param array<string, mixed> $form
     */
    private static function ownAddress(array $form): ?string
    {
        $base = rtrim((string) BASE_URL, '/');
        $own  = trim((string) (($form['settings'] ?? [])['redirect'] ?? ''));
        if ($own === '') {
            return null;
        }
        if (self::onSite($own)) {
            return self::pathOf($base . $own);
        }
        $host = (string) parse_url($base, PHP_URL_HOST);
        if ($host !== '' && preg_match('~^https?://[^\\\\\s]+$~i', $own) === 1
            && strcasecmp((string) parse_url($own, PHP_URL_HOST), $host) === 0) {
            return self::pathOf($own);
        }
        return null;
    }

    /** An address on this site, as its path, query and fragment. */
    private static function pathOf(string $address): string
    {
        $part = parse_url($address);
        return ($part['path'] ?? '/') . (isset($part['query']) ? '?' . $part['query'] : '')
            . (isset($part['fragment']) ? '#' . $part['fragment'] : '');
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

    /**
     * @param array{event: string, form: int, id: string, submission: int}|null $conversion a real submission's, for its script to send
     * @param string|null $redirect the form's after-sending page, a path, for its script to go to
     */
    protected function jsonResponse(bool $success, string $message, int $httpCode = 200, ?array $conversion = null, ?string $redirect = null): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        Cache::noStore();
        $answer = [
            'success' => $success,
            'message' => $message,
        ];
        if ($conversion !== null) {
            $answer['conversion'] = $conversion;
        }
        if ($redirect !== null) {
            $answer['redirect'] = $redirect;
        }
        echo json_encode($answer);
    }
}
