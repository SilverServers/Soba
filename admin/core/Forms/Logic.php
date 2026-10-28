<?php

declare(strict_types=1);

namespace Cms\Forms;

/**
 * CONDITIONAL LOGIC — one engine, both editors, both sides of the wire.
 *
 * ── WHY ONE ENGINE ──────────────────────────────────────────────────────────
 * Conditional logic is a first-class tab in both editors: the form builder and
 * the field-group editor. They are the same idea twice:
 *
 *     show (or hide) THIS, if [another field][operator][value]
 *     · `all` — every rule must hold      · `any` — one is enough
 *
 * Two engines would mean two operator lists, two definitions of "empty", and a
 * form that behaves one way in the browser and another on the server. That last
 * one is not a cosmetic bug: it is how a required field the visitor never saw
 * blocks a submission with an error pointing at nothing.
 *
 * So: this class is the authority. `logic.js` mirrors it for the live preview
 * and the public form, and the two copies of that script (admin and public)
 * must stay identical. THE SERVER IS STILL THE ONE THAT DECIDES — the JS only
 * saves the visitor a round trip.
 *
 * ── THE SHAPE ON DISK ───────────────────────────────────────────────────────
 *     "logic": {
 *       "action": "show" | "hide",
 *       "match":  "all"  | "any",
 *       "rules":  [ {"field": "<name key>", "op": "is", "value": "…"} ]
 *     }
 *
 * A field with no `logic` key, or with an empty `rules` list, is ALWAYS
 * VISIBLE. That is deliberate and it is the only safe default: the opposite —
 * treating a malformed rule as "hide" — makes a corrupt definition silently
 * delete fields from a live form, and nobody finds out until the submissions
 * stop arriving.
 *
 * ── WHY THE VALIDATOR HAS TO ASK ────────────────────────────────────────────
 * `required` and `visible` interact. A hidden field is not filled in, so
 * enforcing `required` on it makes the form unsubmittable; and a value posted
 * for a field that logic hid is a value the visitor could not have seen, so it
 * is dropped rather than stored. Both of those are one call to `isVisible()`.
 */
final class Logic
{
    /**
     * Every operator, and what it prints in the builder's dropdown.
     *
     * ONE LIST. The builder renders its `<select>` from this, `evaluate()`
     * switches on it, and `logic.js` carries the same keys — an operator that is
     * offered but not implemented is a rule that silently never matches.
     *
     * @var array<string, string>
     */
    public const OPERATORS = [
        'is'           => 'is',
        'is_not'       => 'is not',
        'contains'     => 'contains',
        'not_contains' => 'does not contain',
        'gt'           => 'is greater than',
        'lt'           => 'is less than',
        'empty'        => 'is empty',
        'not_empty'    => 'is not empty',
    ];

    /** Operators that ignore the value box, so the builder can hide it. */
    public const UNARY = ['empty', 'not_empty'];

    /**
     * Normalise whatever came off the wire into the stored shape, or null.
     *
     * Returns NULL rather than an empty structure when there is nothing to
     * store: a `"logic": {"rules": []}` key on every field would bloat every
     * definition and make "has logic?" a two-step check at every call site.
     *
     * @param  mixed $raw
     * @return array{action: string, match: string, rules: list<array{field: string, op: string, value: string}>}|null
     */
    public static function parse(mixed $raw): ?array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return null;
        }

        $rules = [];
        foreach ((array) ($raw['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $field = trim((string) ($rule['field'] ?? ''));
            $op    = (string) ($rule['op'] ?? 'is');

            // A RULE WITH NO SUBJECT IS DROPPED, not stored as a blank. Kept, it
            // would evaluate against a field named "" — which is never present,
            // so `is empty` on it is permanently true and the field it guards
            // would appear or vanish for reasons nobody can trace.
            if ($field === '' || !isset(self::OPERATORS[$op])) {
                continue;
            }

            $rules[] = [
                'field' => $field,
                'op'    => $op,
                'value' => trim((string) ($rule['value'] ?? '')),
            ];
        }

        if ($rules === []) {
            return null;
        }

        return [
            'action' => ((string) ($raw['action'] ?? 'show')) === 'hide' ? 'hide' : 'show',
            'match'  => ((string) ($raw['match']  ?? 'all'))  === 'any'  ? 'any'  : 'all',
            'rules'  => $rules,
        ];
    }

    /**
     * Should the thing carrying `$logic` be shown, given the values so far?
     *
     * @param array<string, mixed>|null $logic
     * @param array<string, mixed>      $values  name key => posted value
     */
    public static function isVisible(?array $logic, array $values): bool
    {
        // NO RULES MEANS VISIBLE. See the class header — the opposite default
        // deletes fields on a malformed definition.
        if ($logic === null || empty($logic['rules'])) {
            return true;
        }

        $matched = self::matches($logic, $values);

        return ((string) ($logic['action'] ?? 'show')) === 'hide'
            ? !$matched
            : $matched;
    }

    /**
     * Do the rules hold? `action` is NOT considered here — that is `isVisible`'s
     * job, and keeping them apart is what lets the builder show "these rules
     * currently match" independently of what matching does.
     *
     * @param array<string, mixed> $logic
     * @param array<string, mixed> $values
     */
    public static function matches(array $logic, array $values): bool
    {
        $rules = (array) ($logic['rules'] ?? []);
        if ($rules === []) {
            return true;
        }
        $any = ((string) ($logic['match'] ?? 'all')) === 'any';

        foreach ($rules as $rule) {
            $ok = self::evaluate(
                $values[(string) ($rule['field'] ?? '')] ?? null,
                (string) ($rule['op'] ?? 'is'),
                (string) ($rule['value'] ?? '')
            );
            if ($any && $ok) {
                return true;
            }
            if (!$any && !$ok) {
                return false;
            }
        }

        // Fell through: for `any` nothing matched, for `all` everything did.
        return !$any;
    }

    /**
     * One rule.
     *
     * ── EVERY COMPARISON IS A STRING COMPARISON, EXCEPT gt/lt ───────────────
     * `is` on a checkbox posting "1" against the value "1" has to be true, and
     * so does `is` on a dropdown posting "Yes". PHP's `==` would make "0" equal
     * "" and "1e2" equal "100"; the values here come from a text box a site
     * owner typed, so loose comparison is a source of results nobody can
     * predict. `gt` and `lt` are the two that genuinely mean arithmetic, and
     * they cast explicitly.
     *
     * A MULTI-VALUE FIELD MATCHES IF ANY OF ITS VALUES DOES. Checkboxes post an
     * array; "is Blue" should hold when Blue is one of three ticked boxes,
     * because that is what the sentence in the builder says.
     */
    public static function evaluate(mixed $actual, string $op, string $expected): bool
    {
        if (is_array($actual)) {
            // `empty` is about the WHOLE field, so it cannot be answered per
            // value — an empty array is empty, and a non-empty one is not.
            if ($op === 'empty')     { return $actual === []; }
            if ($op === 'not_empty') { return $actual !== []; }
            // `is_not` / `not_contains` are negations: they hold only if NO
            // member matches the positive form. Mapping them through "any
            // member matches" would make "is not Blue" true for [Blue, Red].
            if ($op === 'is_not') {
                return !self::anyMatches($actual, 'is', $expected);
            }
            if ($op === 'not_contains') {
                return !self::anyMatches($actual, 'contains', $expected);
            }
            return self::anyMatches($actual, $op, $expected);
        }

        $value = $actual === null ? '' : (string) $actual;

        return match ($op) {
            'is'           => $value === $expected,
            'is_not'       => $value !== $expected,
            'contains'     => $expected !== '' && str_contains($value, $expected),
            'not_contains' => $expected === '' || !str_contains($value, $expected),
            'gt'           => is_numeric($value) && is_numeric($expected) && (float) $value >  (float) $expected,
            'lt'           => is_numeric($value) && is_numeric($expected) && (float) $value <  (float) $expected,
            'empty'        => trim($value) === '',
            'not_empty'    => trim($value) !== '',
            // An operator this class does not know cannot be honoured, and
            // guessing would be worse. `parse()` already refuses to store one,
            // so reaching here means the row predates the operator list.
            default        => true,
        };
    }

    /** @param array<int|string, mixed> $values */
    private static function anyMatches(array $values, string $op, string $expected): bool
    {
        foreach ($values as $one) {
            if (self::evaluate($one, $op, $expected)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A one-line English rendering, for the collapsed summary in both builders.
     *
     * The builder prints this on the field's row so you can see that a field is
     * conditional WITHOUT opening it. A bare "Logic" tag tells you a rule
     * exists; this tells you what it is, which is the difference between a badge
     * and a summary.
     *
     * @param array<string, mixed>|null $logic
     * @param array<string, string>     $labels  name key => that field's label
     */
    public static function describe(?array $logic, array $labels = []): string
    {
        if ($logic === null || empty($logic['rules'])) {
            return '';
        }
        $rules = (array) $logic['rules'];
        $first = (array) $rules[0];
        $key   = (string) ($first['field'] ?? '');
        $name  = $labels[$key] ?? $key;
        $op    = self::OPERATORS[(string) ($first['op'] ?? 'is')] ?? 'is';

        $text = ($logic['action'] ?? 'show') === 'hide' ? 'Hide if ' : 'Show if ';
        $text .= $name . ' ' . $op;

        if (!in_array((string) ($first['op'] ?? ''), self::UNARY, true)) {
            $text .= ' "' . (string) ($first['value'] ?? '') . '"';
        }

        $more = count($rules) - 1;
        if ($more > 0) {
            $text .= ($logic['match'] ?? 'all') === 'any'
                ? ' or ' . $more . ' other' . ($more === 1 ? '' : 's')
                : ' and ' . $more . ' other' . ($more === 1 ? '' : 's');
        }

        return $text;
    }
}
