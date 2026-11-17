<?php

declare(strict_types=1);

namespace Cms\Forms;

/**
 * A FORM'S ANSWERS, READ AND CHECKED: what each field posted, which fields
 * conditional logic showed, and what is wrong with what was sent.
 *
 * The site's own form handler (`Controllers\Public\FormController`) and a
 * static copy's (`Export\Receiver`, on a host with no CMS and no database)
 * both read their answers here, so the two cannot differ. Nothing in it needs
 * either: a file and a drawn signature are kept by whoever asks (`$keep`: the
 * site's media library, or an attachment to the copy's email), and a field
 * whose choices are the site's own content (`Sources`) is checked against the
 * list `$choices` gives.
 */
final class Answers
{
    /**
     * Reasons an upload was refused, keyed by field name.
     *
     * `read()` is the only place that can produce one — it is where the file
     * is kept — and checking comes after it, so the message has to be parked
     * rather than returned. A refused upload that reported nothing would be a
     * form that says "required" about a file the visitor can see they
     * attached.
     *
     * @var array<string, string>
     */
    private array $uploadErrors = [];

    /**
     * @param array<string, mixed> $post   the post, as valid text
     * @param array<string, mixed> $files  `$_FILES`
     * @param \Closure $keep    fn (string $type, mixed $posted, array $field, ?string &$why): ?string —
     *                          keeps a `file` (its `$_FILES` entry) or a `signature` (its data URL);
     *                          what the answer records it as, or null with `$why` set
     * @param \Closure $choices fn (array $field): list<string> — a sourced field's allowed values
     */
    public function __construct(
        private array $post,
        private array $files,
        private \Closure $keep,
        private \Closure $choices,
    ) {
    }

    /**
     * THREE PASSES, AND THE ORDER MATTERS.
     *
     *   1. read every field's posted value
     *   2. decide which fields conditional logic actually showed
     *   3. check and keep ONLY those
     *
     * It cannot be one pass. A rule may name a field that appears LATER in the
     * list, so visibility cannot be settled while the value map is still
     * half-built.
     *
     * @param  list<array<string, mixed>> $fields the EXPANDED list (`Embed::expand()`)
     * @return array{data: array<string, string>, raw: array<string, mixed>, errors: list<string>}
     *         data: what is stored and mailed, one string per field; raw: as read;
     *         errors: what to tell the sender, escaped for the JSON answer
     */
    public function check(array $fields): array
    {
        $errors = [];
        $data   = [];
        $raw    = [];

        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            $type = (string) ($field['type'] ?? 'text');
            if ($name === '' || !FieldTypes::collects($type)) {
                continue;   // a divider or a text block collects nothing
            }
            $raw[$name] = $this->read($field, $name, $type);
        }

        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            $type = (string) ($field['type'] ?? 'text');
            if ($name === '' || !FieldTypes::collects($type)) {
                continue;
            }

            // A FIELD LOGIC HID IS NOT CHECKED AND NOT KEPT: requiring
            // something the visitor never saw makes the form unsendable.
            if (!Logic::isVisible(Logic::parse($field['logic'] ?? null), $raw)) {
                continue;
            }

            $value = $raw[$name];
            $label = ($field['label'] ?? '') !== '' ? (string) $field['label'] : $name;
            $blank = is_array($value) ? $value === [] : trim((string) $value) === '';

            // AN UPLOAD THAT WAS REFUSED SAYS WHY, instead of "required".
            if (isset($this->uploadErrors[$name])) {
                $errors[] = htmlspecialchars($label) . ': '
                          . htmlspecialchars($this->uploadErrors[$name]);
                continue;
            }

            if (!empty($field['required']) && $blank) {
                // THE AUTHOR'S OWN WORDING WINS.
                $custom = trim((string) ($field['msg_required'] ?? ''));
                $errors[] = $custom !== ''
                    ? htmlspecialchars($custom)
                    : htmlspecialchars($label) . ' is required.';
            }

            if (!$blank) {
                foreach ($this->errors($field, $type, $value, $label) as $error) {
                    $errors[] = $error;
                }
            }

            // ONE STRING PER FIELD, joined the type's way: a name reads "Jane
            // Smith", an address "12 Mill Lane, Kamloops, BC", a repeater one
            // row per line, a Likert "Statement: Answer" per line.
            if ($type === 'repeater' && is_array($value)) {
                $data[$name] = implode("\n", $value);
            } elseif ($type === 'likert' && is_array($value)) {
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

        return ['data' => $data, 'raw' => $raw, 'errors' => $errors];
    }

    /**
     * One field's posted value, in the shape its type posts.
     *
     * A checkbox is present-or-absent. A list posts `name[]` and anything else
     * there is treated as empty rather than cast, because a scalar where a
     * list belongs means the request was hand-made.
     *
     * @return string|array<int|string, string>
     */
    public function read(array $field, string $name, string $type): array|string
    {
        $why = null;
        // A TOGGLE POSTS EXACTLY WHAT A CHECKBOX POSTS — '1' or nothing.
        if ($type === 'checkbox' || $type === 'toggle') {
            return isset($this->post[$name]) ? '1' : '';
        }

        // A FILE ARRIVES IN $_FILES, NOT THE POST. Kept on the spot and reduced
        // to what the answer records, so everything downstream deals with a
        // short scalar like every other field.
        if ($type === 'file') {
            $kept = ($this->keep)('file', $this->files[$name] ?? ['error' => UPLOAD_ERR_NO_FILE], $field, $why);
            if ($why !== null) {
                $this->uploadErrors[$name] = $why;
            }
            return $kept === null ? '' : (string) $kept;
        }

        // A SIGNATURE IS A PNG THE VISITOR DREW, posted as a data URL.
        if ($type === 'signature') {
            $posted = (string) ($this->post[$name] ?? '');
            if (trim($posted) === '') {
                return '';
            }
            $kept = ($this->keep)('signature', $posted, $field, $why);
            if ($why !== null) {
                $this->uploadErrors[$name] = $why;
            }
            return $kept === null ? '' : (string) $kept;
        }

        // A REPEATER POSTS `name[row][column]`. Empty ROWS are dropped, not
        // empty cells.
        if ($type === 'repeater') {
            $posted = $this->post[$name] ?? [];
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

        // A LIKERT IS A COMPOSITE WHOSE PARTS THE AUTHOR WROTE: `name[r0]`,
        // kept under the row's own words.
        if ($type === 'likert') {
            $posted = $this->post[$name] ?? [];
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

        // A COMPOSITE POSTS ONE ARRAY. Only the parts the type declares are
        // read, and empty parts are dropped.
        if (FieldTypes::isComposite($type)) {
            $posted = $this->post[$name] ?? [];
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
            $posted = $this->post[$name] ?? [];
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

        $value = $this->post[$name] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /**
     * What is wrong with one non-blank value. Every choice field checks its
     * value against its options: a `<select>` restricts the browser, never the
     * request.
     *
     * @param  string|array<int|string, string> $value
     * @return list<string>
     */
    public function errors(array $field, string $type, array|string $value, string $label): array
    {
        $errors = [];
        $label  = htmlspecialchars($label);

        // ONE CUSTOM MESSAGE COVERS EVERY FORMAT FAILURE ON A FIELD.
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
        // `H:i` is what `<input type="time">` posts; 25:00 is refused.
        if ($type === 'time') {
            $parsed = \DateTimeImmutable::createFromFormat('H:i', (string) $value);
            if ($parsed === false || $parsed->format('H:i') !== (string) $value) {
                $errors[] = $say($label . ' must be a valid time.');
            }
        }
        // A SLIDER CANNOT BE MISTYPED, BUT IT CAN BE FORGED.
        if ($type === 'range') {
            $min = (float) ($field['min'] ?? 0);
            $max = (float) ($field['max'] ?? 100);
            if (!is_numeric((string) $value)
                || (float) $value < $min || (float) $value > $max) {
                $errors[] = $say($label . ' must be between ' . $min . ' and ' . $max . '.');
            }
        }
        // A SCALE OR A RATING, within the bounds it was drawn with.
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
        // EVERY LIKERT ANSWER HAS TO BE ONE OF ITS COLUMNS.
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
        // `Y-m-d`, compared back, so 2026-02-31 is refused.
        if ($type === 'date') {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $value);
            if ($parsed === false || $parsed->format('Y-m-d') !== (string) $value) {
                $errors[] = $say($label . ' must be a valid date.');
            }
        }

        // A SOURCED FIELD IS CHECKED AGAINST THE SITE'S CONTENT.
        if (FieldTypes::isSourced($type)) {
            $allowed = ($this->choices)($field);
            if ($allowed !== []) {
                foreach ((array) $value as $one) {
                    if (!in_array((string) $one, $allowed, true)) {
                        $errors[] = $label . ' has an invalid selection.';
                        break;
                    }
                }
            }
        }

        // A CHOICE IS ONE OF ITS OPTIONS — not a Likert's or a repeater's,
        // whose Options list names their rows and columns.
        if (FieldTypes::hasChoices($type)
            && !in_array($type, ['likert', 'repeater'], true)) {
            // "label | value" posts the value, so that is what is allowed.
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

        // A MAXIMUM THE BUILDER OFFERS IS A MAXIMUM THE SERVER ENFORCES.
        $max = (int) ($field['maxlength'] ?? 0);
        if ($max > 0 && !is_array($value) && mb_strlen($value) > $max) {
            $errors[] = $label . ' must be ' . $max . ' characters or fewer.';
        }

        return $errors;
    }
}
