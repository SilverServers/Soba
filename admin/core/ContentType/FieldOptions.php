<?php

declare(strict_types=1);

namespace Cms\ContentType;

use Cms\Forms\Logic;

/**
 * EVERY OPTION A FIELD WRITTEN IN CODE MAY TAKE, in one list, and the check
 * that says in the log when a field names one the CMS does not know.
 *
 * A field is written in code in three places: a content type's `'fields'`
 * (`Registry::register()`), a taxonomy's, for its terms (`TaxonomyRegistry::
 * register()`), and a settings page's sections (`Site\SettingsPages::
 * register()`), each field's repeater columns in its `'sub_fields'`. The
 * renderer reads each option by its name and passes over a name it does not
 * know, so `'placholder'` or `'type' => 'textare'` was a field that simply
 * looked wrong, with nothing anywhere saying why.
 *
 * So each definition is checked as it is registered: an option that is not
 * one of `NAMES`, a type that is not one of `TYPES`, and a logic rule the
 * engine cannot read (`Forms\Logic`) are said in the log in one line for the
 * definition, as a site tool's unknown place is. The field is drawn as it
 * always was. Since every request registers the site's definitions, the line
 * is said once, then again an hour later while it is still there.
 *
 * A name that starts with `_` is a fact the CMS writes on a field itself (the
 * group it came from, a table cell's label), never an option, and is passed
 * over.
 */
final class FieldOptions
{
    /** Every field type, by the word a definition gives it. A type that is not one is drawn as a text box. */
    public const TYPES = [
        'text', 'textarea', 'rich_text', 'number', 'phone', 'email', 'date', 'datetime', 'select', 'checkbox',
        'image', 'file', 'gallery', 'repeater', 'relationship', 'taxonomy',
    ];

    /**
     * Every option a field may take, by its name, with the types that read it
     * ('*' for every type). Whatever the renderer learns to read is named here
     * too, or the log says a working option does nothing.
     */
    public const NAMES = [
        // Every type.
        'key'          => '*',
        'label'        => '*',
        'type'         => '*',
        'required'     => '*',
        'msg_required' => '*',
        'instructions' => '*',
        'description'  => '*',          // the same as `instructions`, and read first
        'width'        => '*',
        'logic'        => '*',
        // A type's own.
        'placeholder'  => 'text, textarea, phone, email',
        'rows'         => 'textarea, rich_text',
        'min'          => 'number',
        'max'          => 'number',
        'step'         => 'number',
        'decimals'     => 'number',
        'prefix'       => 'number',
        'options'      => 'select',
        'accept'       => 'file',
        'layout'       => 'gallery, repeater',
        'columns'      => 'gallery',
        'lightbox'     => 'gallery',
        'sub_fields'   => 'repeater',
        'preset_rows'  => 'repeater',
        'content_type' => 'relationship',
        'taxonomy_key' => 'taxonomy',
        'field_mode'   => 'taxonomy',
        'multiple'     => 'taxonomy',
        // THE SAME OPTIONS UNDER THE NAMES THE FIELD GROUP EDITOR KEEPS, and
        // an older name for a relationship's type: read wherever they are.
        'content_type_target' => 'relationship',
        'post_type'           => 'relationship',
        'post_type_target'    => 'relationship',
    ];

    /**
     * The options that take one of a few words, on the types that read them,
     * and what a field is when it gives another.
     *
     * @var list<array{0: string, 1: '*'|list<string>, 2: list<string>, 3: string}>
     */
    private const WORDS = [
        ['width',      '*',            ['full', 'three-quarters', 'two-thirds', 'half', 'third', 'quarter'], 'full width'],
        ['field_mode', ['taxonomy'],   ['select', 'checkbox', 'radio'], 'a select'],
        ['layout',     ['repeater'],   ['table'], 'drawn in stacked rows'],
        ['layout',     ['gallery'],    ['grid', 'masonry'], 'drawn as a grid'],
    ];

    /** A logic rule set's own options, and each rule's. */
    private const LOGIC = ['action', 'match', 'rules'];

    private const RULE = ['field', 'op', 'value'];

    /** Where the lines said are remembered, by what each said, so each is said at most once an hour. */
    private const NOTED = 'fields_noted';

    /**
     * Check a definition's fields and say in the log what is not one, in one
     * line for the definition ("the type \"event\"").
     *
     * @param array<mixed> $fields
     */
    public static function note(string $owner, array $fields): void
    {
        $problems = self::problems($fields);
        if ($problems === []) {
            return;
        }
        self::say('Fields in code: on ' . $owner . ', ' . implode('; ', $problems) . '.');
    }

    /**
     * What is not one in these fields, a phrase for each, in order: an option
     * the CMS does not know, a type it does not know, a field with no key, and
     * a logic rule it cannot read; then each repeater's columns the same way.
     * Pure.
     *
     * @param array<mixed> $fields
     * @return list<string>
     */
    public static function problems(array $fields, string $in = ''): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                $out[] = 'a field' . $in . ' is not a list of options';
                continue;
            }
            $key  = is_string($field['key'] ?? null) ? trim($field['key']) : '';
            $name = $key !== '' ? '"' . $key . '"' . $in : 'a field' . $in;
            if ($key === '') {
                $out[] = $name . ' has no key';
            }

            $unknown = [];
            foreach (array_keys($field) as $option) {
                if (!is_string($option) || (!isset(self::NAMES[$option]) && !str_starts_with($option, '_'))) {
                    $unknown[] = '"' . $option . '"';
                }
            }
            if ($unknown !== []) {
                $out[] = $name . ' has ' . (count($unknown) === 1 ? 'an option' : 'options') . ' the CMS does not know ('
                       . implode(', ', $unknown) . '), which ' . (count($unknown) === 1 ? 'does' : 'do') . ' nothing';
            }

            $type = $field['type'] ?? 'text';
            if (!is_string($type) || !in_array($type, self::TYPES, true)) {
                $out[] = $name . ' is of a type the CMS does not know ("' . (is_scalar($type) ? (string) $type : gettype($type)) . '"), and is drawn as a text box';
            }
            // THE OPTIONS WITH A FEW WORDS EACH, which fall back quietly on any other.
            foreach (self::WORDS as [$option, $types, $words, $else]) {
                $given = $field[$option] ?? null;
                if ($given !== null && $given !== '' && ($types === '*' || in_array($type, $types, true))
                    && !in_array($given, $words, true)) {
                    $out[] = $name . '\'s ' . $option . ' "' . (is_scalar($given) ? (string) $given : gettype($given)) . '" is not one of '
                           . implode(', ', $words) . ', so it is ' . $else;
                }
            }

            if (array_key_exists('logic', $field)) {
                foreach (self::logicProblems($field['logic']) as $problem) {
                    $out[] = 'the logic of ' . $name . ' ' . $problem;
                }
            }

            if ($type === 'repeater' && is_array($field['sub_fields'] ?? null)) {
                array_push($out, ...self::problems($field['sub_fields'], ' in ' . ($key !== '' ? '"' . $key . '"' : 'a repeater') . $in));
            }
        }

        return $out;
    }

    /**
     * What `Forms\Logic` cannot read in a field's rule set, a phrase for each.
     * A rule it cannot read is left out, so a field shown only "if Format
     * equals Online" shows always.
     *
     * @return list<string>
     */
    private static function logicProblems(mixed $logic): array
    {
        if (is_string($logic)) {
            $logic = json_decode($logic, true);
        }
        if ($logic === null) {
            return [];
        }
        if (!is_array($logic)) {
            return ['is not a rule set, so the field always shows'];
        }

        $out = [];
        foreach (array_keys($logic) as $option) {
            if (!in_array($option, self::LOGIC, true)) {
                $out[] = 'has an option the CMS does not know ("' . $option . '")';
            }
        }
        $action = $logic['action'] ?? 'show';
        if (!in_array($action, ['show', 'hide'], true)) {
            $out[] = 'says "' . (is_scalar($action) ? (string) $action : gettype($action)) . '", which is not show or hide, so it shows';
        }
        $match = $logic['match'] ?? 'all';
        if (!in_array($match, ['all', 'any'], true)) {
            $out[] = 'matches "' . (is_scalar($match) ? (string) $match : gettype($match)) . '", which is not all or any, so it matches all';
        }
        if (!is_array($logic['rules'] ?? [])) {
            return [...$out, 'has rules that are not a list, so the field always shows'];
        }
        foreach ((array) ($logic['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                $out[] = 'has a rule that is not one, so it is left out';
                continue;
            }
            foreach (array_keys($rule) as $option) {
                if (!in_array($option, self::RULE, true)) {
                    $out[] = 'has a rule with an option the CMS does not know ("' . $option . '")';
                }
            }
            if (trim(is_scalar($rule['field'] ?? null) ? (string) $rule['field'] : '') === '') {
                $out[] = 'has a rule that names no field, so it is left out';
            }
            $op = $rule['op'] ?? 'is';
            if (!is_string($op) || !isset(Logic::OPERATORS[$op])) {
                $out[] = 'has a rule whose operator "' . (is_scalar($op) ? (string) $op : gettype($op)) . '" is not one of '
                       . implode(', ', array_keys(Logic::OPERATORS)) . ', so it is left out';
            }
        }

        return $out;
    }

    /**
     * Say a line in the log, unless it was said in the last hour: every
     * request registers the site's fields. Where the line cannot be
     * remembered, as before the CMS is installed, it is said each time.
     */
    private static function say(string $line): void
    {
        $hash = md5($line);
        try {
            $noted = json_decode((string) \Cms\Models\Option::get(self::NOTED, ''), true);
            $noted = is_array($noted) ? array_filter($noted, static fn (mixed $at): bool => is_int($at) && time() - $at < 3600) : [];
            if (isset($noted[$hash])) {
                return;
            }
            $noted[$hash] = time();
            \Cms\Models\Option::set(self::NOTED, (string) json_encode($noted));
        } catch (\Throwable) {
            // Not remembered: said again next time.
        }
        error_log($line);
    }
}
