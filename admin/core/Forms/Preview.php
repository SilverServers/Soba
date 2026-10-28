<?php

declare(strict_types=1);

namespace Cms\Forms;

use Cms\Models\Form;

/**
 * THE LINE A SUBMISSION IS LISTED BY.
 *
 * A form's submissions, All submissions and Spam show one line per row, and
 * that line is what tells you whether this is the one you are looking for. It
 * is WHO SENT IT, when the form asks: the name (a Name field, or first and last
 * name) and the email address (an Email field), found by the field's type or
 * by the names `Guard::NAME_FIELDS` reads as a person's (`your-name` and
 * `your_name` among them). A form that asks neither shows its first two
 * answers, and one that asks only one of them fills in with its first answer.
 *
 * ── NEVER A HIDDEN FIELD ────────────────────────────────────────────────────
 * The page sets a hidden field, not the visitor, so it reads the same on every
 * row of the form. A lead form that begins with two (the site and the
 * service) would read "example.com · General Inquiry" on every row: "the
 * first two answers" would become the list of identical rows it is meant to
 * prevent.
 *
 * An answer to a field the form no longer has is still an answer, and counts.
 */
final class Preview
{
    /** Called this, a field holds part of a person's name; the rest of `Guard::NAME_FIELDS` hold all of it. */
    private const NAME_PARTS = ['first_name', 'last_name'];

    /** @var array<int, list<array<string, mixed>>> each form's expanded fields, for this request */
    private static array $fields = [];

    /** The line for a submission of form $formId (its fields read once a request). */
    public static function forForm(int $formId, mixed $data): string
    {
        if (!isset(self::$fields[$formId])) {
            $form = Form::find($formId);
            self::$fields[$formId] = Embed::expand(is_array($form['fields'] ?? null) ? $form['fields'] : []);
        }
        return self::line($data, self::$fields[$formId]);
    }

    /**
     * @param mixed                      $data   the answers (array, or its JSON)
     * @param list<array<string, mixed>> $fields the form's fields, expanded
     */
    public static function line(mixed $data, array $fields): string
    {
        $data = is_string($data) ? json_decode($data, true) : $data;
        if (!is_array($data)) {
            return '';
        }
        $types = self::types($fields);
        $shown = static fn (string $key): bool => ($types[$key] ?? '') !== 'hidden';
        $text  = [self::class, 'text'];

        // Who sent it (`sender()`), then their address.
        [$name, $used] = self::sender($data, $fields);
        $email = '';
        foreach ($types as $key => $type) {
            if ($type === 'email' && $text($data[$key] ?? '') !== '') {
                $email = $text($data[$key]);
                $used[$key] = true;
                break;
            }
        }

        // Then its answers, as stored, to two in all.
        $parts = array_values(array_filter([$name, $email], static fn (string $s): bool => $s !== ''));
        foreach ($data as $key => $value) {
            if (count($parts) >= 2) {
                break;
            }
            if (!isset($used[$key]) && $shown((string) $key) && ($value = $text($value)) !== '') {
                $parts[] = $value;
            }
        }
        return implode(' · ', array_map(static fn (string $p): string => mb_strimwidth($p, 0, 60, '…'), $parts));
    }

    /**
     * WHO SENT IT — one rule, for this line and for the notification email's
     * Reply-To name (`Notify::senderName()`), so the email names the person
     * the list does: a Name field, else first and last name, else the first
     * of the other names `Guard::NAME_FIELDS` reads as a person's. A hidden
     * field is never the sender.
     *
     * @param array<string, mixed>       $data   the answers
     * @param list<array<string, mixed>> $fields the form's fields, expanded
     * @return array{0: string, 1: array<string, true>} the name, and the answers it was made of
     */
    public static function sender(array $data, array $fields): array
    {
        $types = self::types($fields);
        $shown = static fn (string $key): bool => ($types[$key] ?? '') !== 'hidden';
        foreach ($types as $key => $type) {
            if ($type === 'name' && self::text($data[$key] ?? '') !== '') {
                return [self::text($data[$key]), [$key => true]];
            }
        }
        $bits = [];
        $used = [];
        foreach (self::NAME_PARTS as $key) {
            if ($shown($key) && self::text($data[$key] ?? '') !== '') {
                $bits[] = self::text($data[$key]);
                $used[$key] = true;
            }
        }
        if ($bits !== []) {
            return [implode(' ', $bits), $used];
        }
        foreach (array_diff(Guard::NAME_FIELDS, self::NAME_PARTS) as $key) {
            if ($shown($key) && self::text($data[$key] ?? '') !== '') {
                return [self::text($data[$key]), [$key => true]];
            }
        }
        return ['', []];
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array<string, string> each field's name => its type
     */
    private static function types(array $fields): array
    {
        $types = [];
        foreach ($fields as $f) {
            if (($f['name'] ?? '') !== '') {
                $types[(string) $f['name']] = (string) ($f['type'] ?? 'text');
            }
        }
        return $types;
    }

    /** An answer as one line: a list joined by commas, anything that is not text left out. */
    private static function text(mixed $v): string
    {
        return trim(is_array($v)
            ? implode(', ', array_map(static fn (mixed $x): string => is_scalar($x) ? (string) $x : '', $v))
            : (is_scalar($v) ? (string) $v : ''));
    }
}
