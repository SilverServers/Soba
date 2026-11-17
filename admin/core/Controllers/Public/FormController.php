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

        // ── The answers, read and checked (`Forms\Answers`) ─────────────
        // Every field's posted value, then which fields conditional logic
        // showed, then those checked and kept: the reading a static copy's
        // handler makes too, in one place, so the two cannot differ. A file or
        // a drawn signature is kept in the media library, private, and the
        // answer records its id; a field whose choices are the site's own
        // content is checked against them (`Sources`).
        // `$fields` is the EXPANDED list, settled above: the same one the
        // renderer drew from. If the two ever differ, the page collects
        // answers the server never validates or stores.
        $checked = (new Answers(
            $_POST,
            $_FILES,
            static function (string $type, mixed $posted, array $field, ?string &$why): ?string {
                $id = $type === 'file'
                    ? Upload::accept(is_array($posted) ? $posted : ['error' => UPLOAD_ERR_NO_FILE], $field, $why)
                    : self::storeSignature((string) $posted, $field, $why);

                return $id === null ? null : (string) $id;
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
        // and a lead whose email bounced must still be findable as one.
        $settings   = $form['settings'] ?? [];
        $recipients = trim($settings['recipients'] ?? '');

        if ($recipients !== '' || $testInbox !== null) {
            FormSubmission::setNotified($submissionId, $this->sendNotification($form, $data, $recipients, $settings, $raw,
                $meta + ['submission_id' => (string) $submissionId], $testInbox));
        }
        // A CONFIRMATION TO THE SENDER, when the form has one: here, past
        // every spam check above, so no address a bot typed is written to.
        Notify::confirm($form, $data, $testInbox);
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

        header('Cache-Control: no-store');
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
