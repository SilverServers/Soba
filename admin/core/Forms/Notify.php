<?php

declare(strict_types=1);

namespace Cms\Forms;

use Cms\Helpers\Theme;
use Cms\Mail\Mailer;

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
 * meta): ?array. For a form it composes it returns ['subject' (ready for
 * mail()), 'headers', 'body', 'envelope_from'], and the message goes out
 * exactly as written, through the server's own mail program
 * (`Mailer::sendRaw()`); for any other form it returns null and the CMS writes
 * its plain message. A site's own lead forms can go out as its approved
 * lead email this way.
 *
 * `answers` are as posted (a checkbox group is a list), because a composer
 * that maps a choice to its label needs the choices, not a joined string.
 */
final class Notify
{
    /**
     * Email a submission to the form's recipients. True when it went.
     *
     * @param array<string, mixed>  $form
     * @param array<string, mixed>  $data     what was stored, name => text
     * @param array<string, mixed>  $answers  as posted, name => text or list
     * @param array<string, string> $meta     ip, user_agent, source_page
     */
    public static function send(array $form, array $data, array $answers, array $meta): bool
    {
        $settings   = is_array($form['settings'] ?? null) ? $form['settings'] : [];
        $recipients = array_values(array_filter(array_map('trim', explode(',', (string) ($settings['recipients'] ?? ''))), 'strlen'));
        if ($recipients === []) {
            return false;
        }

        $fields    = Embed::expand(is_array($form['fields'] ?? null) ? $form['fields'] : []);
        $subject   = self::render((string) ($settings['subject'] ?? ''), $data, $form);
        $replyTo   = self::replyTo($settings, $data);
        $replyName = self::senderName($data, $fields);

        $compose = self::composer();
        if ($compose !== null) {
            $message = $compose($form, $answers, $meta + [
                'subject' => $subject, 'reply_to' => $replyTo, 'reply_to_name' => $replyName, 'recipients' => implode(', ', $recipients),
            ]);
            if (is_array($message)) {
                return Mailer::sendRaw($recipients, (string) ($message['subject'] ?? ''), (string) ($message['headers'] ?? ''),
                    (string) ($message['body'] ?? ''), (string) ($message['envelope_from'] ?? ''));
            }
        }

        return Mailer::send($recipients, $subject !== '' ? $subject : 'New submission: ' . $form['title'],
            self::plainBody($form, $data, $fields, $meta), ['reply_to' => $replyTo, 'reply_to_name' => $replyName]);
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
        foreach ($fields as $f) {
            if (!empty($f['name'])) {
                $labels[(string) $f['name']] = (string) (($f['label'] ?? '') !== '' ? $f['label'] : $f['name']);
            }
        }
        $body = 'New submission from: ' . $form['title'] . "\n" . str_repeat('-', 50) . "\n\n";
        foreach ($data as $key => $value) {
            $body .= ($labels[$key] ?? $key) . ': ' . (is_array($value) ? implode(', ', $value) : $value) . "\n";
        }
        $body .= "\n" . str_repeat('-', 50) . "\n";
        $body .= 'Submitted at: ' . gmdate('Y-m-d H:i:s') . " UTC\n";
        $body .= 'IP: ' . ((string) ($meta['ip'] ?? '') !== '' ? $meta['ip'] : 'unknown') . "\n";

        return $body;
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
