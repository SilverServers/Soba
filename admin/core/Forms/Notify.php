<?php

declare(strict_types=1);

namespace Cms\Forms;

use Cms\Helpers\Dates;
use Cms\Helpers\Theme;
use Cms\Mail\Mailer;
use Cms\Models\Option;

/**
 * A form's notification email.
 *
 * THE SUBJECT IS A TEMPLATE. `{name}` is that field's answer; `{a|b}` is the
 * first of them that has one; `{first_name last_name}` is several answers
 * joined by a space; `{form}` is the form's title. So a lead form's subject
 * "{service} — {company|first_name last_name}" reads "Website Development —
 * Acme", or "Website Development — Pat Tester" when no business was given. An
 * empty subject is "New submission: <form>", as it always was.
 *
 * REPLY-TO is the answer in the field the form names (Settings → Reply-to
 * field), under the sender's name, so a reply goes to the person who wrote —
 * and only when that answer is an address.
 *
 * A SITE CAN WRITE THE WHOLE MESSAGE. A `form-mail.php` in its theme (or in
 * `admin/site/templates/`, `Theme::file()`) returns a callable(form, answers,
 * meta): ?array. For any form it does not compose it returns null and the CMS
 * writes its plain message. For one it composes it returns the message in one
 * of two forms:
 *
 *   AS FIELDS, sent like every other message the CMS sends (`Mailer::send()`,
 *   through Settings → Email or config.php's Brevo):
 *       ['subject'       => …, the subject, one line
 *        'text'          => …, the plain-text body
 *        'reply_to'      => …, 'reply_to_name' => …,
 *        'from_name'     => …, optional; the From address is Settings → Email's
 *        'to_names'      => [address => name, …]], optional
 *   A subject or Reply-To left out is the CMS's own for the form.
 *
 *   RAW, exactly as written, through the server's own mail program
 *   (`Mailer::sendRaw()`): ['subject' (ready for mail()), 'headers', 'body',
 *   'envelope_from']. A message with `headers` or `body` is this form.
 *
 * A site's own lead forms can go out as its approved lead email this way.
 * `meta` carries `recipients` (who it goes to) and `test` (true for a test).
 *
 * A TEST goes to the test inbox alone (`testInbox()`): a submission whose
 * reply-to field holds the form's test address, or the site's when the form
 * names none (Settings → Email).
 *
 * SENT AGAIN (`resend()`): a stored submission whose email could not be sent,
 * from the inbox, with its own time and a line saying it was sent again. A
 * site's composer gets its answers as stored, one string each, with
 * `submitted_at` and `resent` in `meta`.
 *
 * `answers` are as posted (a checkbox group is a list), because a composer
 * that maps a choice to its label needs the choices, not a joined string.
 *
 * A CONFIRMATION TO WHOEVER SENT IT (`confirm()`, the form's Confirmation
 * settings): the owner's subject and message, their `{field}` answers filled
 * in, to the address in the Reply-to field, and a test's to the test inbox.
 * Both handlers call it only for a submission that passed the spam checks,
 * so the site writes to no address a bot typed in, and a blank message sends
 * none.
 */
final class Notify
{
    /**
     * Email a submission to the form's recipients, or a test to the test
     * inbox alone. True when it went.
     *
     * @param array<string, mixed>  $form
     * @param array<string, mixed>  $data     what was stored, name => text
     * @param array<string, mixed>  $answers  as posted, name => text or list
     * @param array<string, string> $meta     ip, user_agent, source_page, and
     *                                        the stored submission's id
     * @param string|null           $testInbox a test's inbox (`testInbox()`)
     */
    public static function send(array $form, array $data, array $answers, array $meta, ?string $testInbox = null): bool
    {
        $settings   = is_array($form['settings'] ?? null) ? $form['settings'] : [];
        $recipients = $testInbox !== null ? [$testInbox]
            : array_values(array_filter(array_map('trim', explode(',', (string) ($settings['recipients'] ?? ''))), 'strlen'));
        if ($recipients === []) {
            return false;
        }

        $fields    = Embed::expand(is_array($form['fields'] ?? null) ? $form['fields'] : []);
        $subject   = self::render((string) ($settings['subject'] ?? ''), $data, $form);
        $ownTitle  = $subject !== '' ? $subject : 'New submission: ' . $form['title'];
        $replyTo   = self::replyTo($settings, $data);
        $replyName = self::senderName($data, $fields);

        $compose = self::composer();
        if ($compose !== null) {
            $message = $compose($form, $answers, $meta + [
                'subject' => $subject, 'reply_to' => $replyTo, 'reply_to_name' => $replyName, 'recipients' => implode(', ', $recipients),
                'test' => $testInbox !== null,
            ]);
            if (is_array($message) && !array_key_exists('headers', $message) && !array_key_exists('body', $message)
                && array_key_exists('text', $message)) {
                return self::sendFields($recipients, $message, $ownTitle, $replyTo, $replyName);
            }
            if (is_array($message)) {
                return Mailer::sendRaw($recipients, (string) ($message['subject'] ?? ''), (string) ($message['headers'] ?? ''),
                    (string) ($message['body'] ?? ''), (string) ($message['envelope_from'] ?? ''));
            }
        }

        return Mailer::send($recipients, $ownTitle,
            self::plainBody($form, $data, $fields, $meta), ['reply_to' => $replyTo, 'reply_to_name' => $replyName]);
    }

    /**
     * The confirmation to the person who sent a submission, when the form
     * has a message for one and the Reply-to field holds an address (a test's
     * goes to the test inbox). Null when none was to go; else whether it went.
     * Its Reply-To is the form's first recipient, so an answer reaches whoever
     * reads the form's mail.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $data what was stored, name => text
     */
    public static function confirm(array $form, array $data, ?string $testInbox = null): ?bool
    {
        $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
        $message  = trim((string) ($settings['confirm_message'] ?? ''));
        $to       = $testInbox ?? self::replyTo($settings, $data);
        if ($message === '' || $to === '') {
            return null;
        }
        $subject    = self::render((string) ($settings['confirm_subject'] ?? ''), $data, $form);
        $recipients = array_values(array_filter(array_map('trim', explode(',', (string) ($settings['recipients'] ?? ''))), 'strlen'));

        return Mailer::send([$to], $subject !== '' ? $subject : 'Received: ' . (string) ($form['title'] ?? ''),
            self::fill($message, $data, $form), $recipients !== [] ? ['reply_to' => $recipients[0]] : []);
    }

    /**
     * A message's `{field}` answers filled in as a subject's are, its own
     * lines kept: what `render()` makes of one placeholder, in its place.
     *
     * @param array<string, mixed> $data
     */
    private static function fill(string $template, array $data, array $form): string
    {
        return (string) preg_replace_callback('/\{([^{}]+)\}/',
            static fn (array $m): string => self::render('{' . $m[1] . '}', $data, $form),
            str_replace(["\r\n", "\r"], "\n", $template));
    }

    /**
     * SEND A STORED SUBMISSION'S EMAIL AGAIN — one whose email could not be
     * sent — and record what came of it. To the form's recipients as they are
     * now; a test to the test inbox alone, and never to the recipients: when
     * the form or the site no longer names a test inbox, a test is not sent.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $submission as `FormSubmission::find()` reads it
     * @return array{sent: bool, to: string, why: string}
     */
    public static function resend(array $form, array $submission): array
    {
        $no = static fn (string $why): array => ['sent' => false, 'to' => '', 'why' => $why];
        if (($submission['status'] ?? '') !== \Cms\Models\FormSubmission::RECEIVED) {
            return $no('it is spam, and spam is never emailed');
        }
        $data     = is_array($submission['data'] ?? null) ? $submission['data'] : [];
        $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
        $inbox    = null;
        if ((int) ($submission['is_test'] ?? 0) === 1) {
            $inbox = self::testInbox($form, $data);
            if ($inbox === null) {
                return $no('it is a test, and the form no longer has a test inbox for it; a test is never sent to the form\'s recipients');
            }
        } elseif (trim((string) ($settings['recipients'] ?? '')) === '') {
            return $no('the form names nobody to send to (its Notifications)');
        }

        $meta = [
            'ip'           => (string) ($submission['ip_address'] ?? ''),
            'user_agent'   => (string) ($submission['user_agent'] ?? ''),
            'source_page'  => (string) ($submission['source_page'] ?? ''),
            'submitted_at' => (string) ($submission['submitted_at'] ?? ''),
            'submission_id' => (string) (int) $submission['id'],
            'resent'       => '1',
        ];
        $sent = self::send($form, $data, $data, $meta, $inbox);
        \Cms\Models\FormSubmission::setNotified((int) $submission['id'], $sent);

        return ['sent' => $sent, 'to' => $inbox ?? trim((string) $settings['recipients']),
            'why' => $sent ? '' : (Mailer::lastError() !== '' ? Mailer::lastError() : 'the mail program gave no reason')];
    }

    /**
     * THE TEST INBOX, when this submission is a test: the answer in the
     * form's reply-to field is its test address, ignoring case. The form's
     * own test address and inbox, each else the site's (Settings → Email);
     * null when either is missing or is not an address, or the answer is any
     * other.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $data
     */
    public static function testInbox(array $form, array $data): ?string
    {
        $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
        $own      = static fn (string $key, string $option): string
            => ($v = trim((string) ($settings[$key] ?? ''))) !== '' ? $v : trim((string) Option::get($option, ''));
        $address  = $own('test_address', 'forms_test_address');
        $inbox    = $own('test_inbox', 'forms_test_inbox');
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false || filter_var($inbox, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        $sender = self::replyTo($settings, $data);

        return $sender !== '' && strcasecmp($sender, $address) === 0 ? $inbox : null;
    }

    /**
     * A composer's message as fields (see the class comment), through the
     * CMS's own transport. Its subject and Reply-To, else the CMS's.
     *
     * @param list<string>         $recipients
     * @param array<string, mixed> $message
     */
    private static function sendFields(array $recipients, array $message, string $subject, string $replyTo, string $replyName): bool
    {
        $own     = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) ($message['subject'] ?? '')));
        $ownTo   = trim((string) ($message['reply_to'] ?? ''));
        $opts    = [
            'reply_to'      => $ownTo !== '' ? $ownTo : $replyTo,
            'reply_to_name' => array_key_exists('reply_to_name', $message) ? (string) $message['reply_to_name'] : ($ownTo !== '' ? '' : $replyName),
        ];
        if (trim((string) ($message['from_name'] ?? '')) !== '') {
            $opts['from_name'] = trim((string) $message['from_name']);
        }
        if (is_array($message['to_names'] ?? null)) {
            $opts['to_names'] = array_map('strval', array_filter($message['to_names'], 'is_scalar'));
        }

        return Mailer::send($recipients, $own !== '' ? $own : $subject, (string) $message['text'], $opts);
    }

    /**
     * A subject template with the answers in it (see the class comment). One
     * line: control characters become spaces, and a separator left dangling
     * by an empty placeholder is trimmed off the ends.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $form
     */
    public static function render(string $template, array $data, array $form): string
    {
        $out = (string) preg_replace_callback('/\{([^{}]+)\}/', static function (array $m) use ($data, $form): string {
            foreach (explode('|', $m[1]) as $alternative) {
                $parts = [];
                foreach (preg_split('/\s+/', trim($alternative)) ?: [] as $name) {
                    if ($name === '') {
                        continue;
                    }
                    $value = $data[$name] ?? ($name === 'form' ? (string) ($form['title'] ?? '') : '');
                    $value = trim(is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value);
                    if ($value !== '') {
                        $parts[] = $value;
                    }
                }
                if ($parts !== []) {
                    return implode(' ', $parts);
                }
            }
            return '';
        }, $template);
        $out = trim((string) preg_replace(['/[\x00-\x1F\x7F]+/u', '/\s+/u'], ' ', $out));

        return trim($out, " \t—–-:|·");
    }

    /**
     * The sender's name — the one the submissions list gives them
     * (`Preview::sender()`): a Name field, else first and last name, else
     * `your_name` and the other names `Guard::NAME_FIELDS` reads as a
     * person's. This was a rule of its own and missed `your_name`, so the
     * list named a person the Reply-To did not.
     *
     * @param array<string, mixed>       $data
     * @param list<array<string, mixed>> $fields
     */
    public static function senderName(array $data, array $fields): string
    {
        return Preview::sender($data, $fields)[0];
    }

    /** The address in the field the form names for replies, when it is one. */
    private static function replyTo(array $settings, array $data): string
    {
        $field = (string) ($settings['reply_to'] ?? '');
        $value = $field !== '' ? trim((string) ($data[$field] ?? '')) : '';

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
    }

    /**
     * The CMS's own message: the answers under their labels, in plain text.
     *
     * @param list<array<string, mixed>> $fields
     * @param array<string, string>      $meta
     */
    private static function plainBody(array $form, array $data, array $fields, array $meta): string
    {
        $labels = [];
        $types  = [];
        foreach ($fields as $f) {
            if (!empty($f['name'])) {
                $labels[(string) $f['name']] = (string) (($f['label'] ?? '') !== '' ? $f['label'] : $f['name']);
                $types[(string) $f['name']]  = (string) ($f['type'] ?? '');
            }
        }
        $body = 'New submission from: ' . $form['title'] . "\n" . str_repeat('-', 50) . "\n\n";
        foreach ($data as $key => $value) {
            $text  = is_array($value) ? implode(', ', $value) : (string) $value;
            $text  = in_array($types[$key] ?? '', ['file', 'signature'], true) ? self::fileAnswer($text) : $text;
            $body .= ($labels[$key] ?? $key) . ': ' . $text . "\n";
        }
        $body .= "\n" . str_repeat('-', 50) . "\n";
        // THE SITE'S CLOCK, as the submissions screen shows it (`Dates`), and
        // named: in UTC a message sent at 1:48 pm read 20:48. A submission
        // sent again keeps its own time (`resend()`).
        $now   = time();
        $at    = ($stored = (string) ($meta['submitted_at'] ?? '')) !== '' && ($ts = strtotime($stored . ' UTC')) !== false ? $ts : $now;
        $body .= 'Submitted at: ' . Dates::stamp($at) . ' ' . Dates::format($at, 'T') . "\n";
        $body .= 'IP: ' . ((string) ($meta['ip'] ?? '') !== '' ? $meta['ip'] : 'unknown') . "\n";
        // WHERE IT IS KEPT, for whoever reads this: the submission in the
        // inbox, its files beside it. None in a static copy, which has no inbox.
        if (ctype_digit((string) ($meta['submission_id'] ?? '')) && defined('ADMIN_URL')) {
            $body .= 'In the inbox: ' . ADMIN_URL . '/forms/submissions/' . $meta['submission_id'] . "\n";
        }
        if (($meta['resent'] ?? '') === '1') {
            $body .= 'Sent again from the inbox: ' . Dates::stamp($now) . ' ' . Dates::format($now, 'T') . "\n";
        }

        return $body;
    }

    /**
     * AN UPLOAD IS A FILE, NOT A NUMBER. On the site an upload or a drawn
     * signature is stored as its media id, and the inbox shows it as the
     * file's name, linked; the message says the same, with the address the
     * inbox links to. A form's upload is private, so it opens for someone
     * signed in, and the message says so. A file deleted since is said to be
     * gone. An answer that is not an id is printed as it is: a static copy's
     * handler has no media library, and its answer is the file's name, with
     * the file attached.
     */
    private static function fileAnswer(string $value): string
    {
        if (!ctype_digit($value) || !class_exists(\Cms\Models\Media::class)) {
            return $value;
        }
        $file = \Cms\Models\Media::find((int) $value);
        if ($file === null) {
            return 'The file is no longer stored.';
        }
        $name = (string) (($file['original_name'] ?? '') !== '' ? $file['original_name'] : $file['filename']);
        $url  = \Cms\Models\Media::getUrl((int) $value);

        return ($file['visibility'] ?? 'public') === 'private'
            ? $name . ' (sign in to open it: ' . $url . ')'
            : $name . ' (' . $url . ')';
    }

    /** The site's composer (`form-mail.php`), or null. */
    private static function composer(): ?callable
    {
        static $loaded = false, $composer = null;
        if (!$loaded) {
            $loaded = true;
            $file   = Theme::file('form-mail.php');
            if ($file !== null) {
                $made     = (static fn (string $__f): mixed => require $__f)($file);
                $composer = is_callable($made) ? $made : null;
            }
        }

        return $composer;
    }
}
