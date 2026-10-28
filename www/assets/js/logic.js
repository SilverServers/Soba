/**
 * logic.js — the browser half of conditional logic.
 *
 * ── THIS IS A MIRROR OF admin/core/Forms/Logic.php ──────────────────────────
 * Same operators, same definition of "empty", same array handling, same
 * always-visible default. Its copy under `www/assets/js/` must stay
 * byte-identical to this file, and its operator list must match the PHP one.
 *
 * THE SERVER STILL DECIDES. Nothing here is a security control: a visitor can
 * post whatever they like, so `FormController`/`Shortcode` re-evaluate every
 * rule before validating or storing. This file exists so the form REACTS —
 * a field that only appears once you tick a box has to appear as you tick it,
 * not after a round trip.
 *
 * ── WHY IT IS NOT A COPY OF THE PHP CONTROL FLOW ────────────────────────────
 * It is the same DECISIONS, expressed for a live DOM: PHP is handed a finished
 * array of values, this reads them out of the form on every input event. What
 * must not diverge is the answer, and that is what the identical copies and the
 * shared operator list protect.
 *
 * ── PUBLIC API ──────────────────────────────────────────────────────────────
 *     Logic.isVisible(logic, values)      -> bool
 *     Logic.matches(logic, values)        -> bool
 *     Logic.evaluate(actual, op, want)    -> bool
 *     Logic.bind(root, {prefix})          -> live wiring for a rendered form
 *
 * `bind()` is what both the public form and the entry form call. `opts.prefix`
 * is stripped from every control's name before the rules are evaluated — see
 * `readValues()` for why the entry form needs it and the public form does not.
 * It reads every named control under `root`, evaluates each element carrying
 * `data-logic`, and hides or shows it — hiding by TOGGLING THE `hidden`
 * ATTRIBUTE, never by removing the node, so the field keeps its place in the
 * DOM and reappears in the right position.
 *
 * A HIDDEN FIELD'S CONTROLS ARE DISABLED as well as hidden. Without that the
 * browser still posts them, and the server would receive a value for a field
 * the visitor never saw — which the PHP side then has to throw away. Disabling
 * makes the two sides agree on the wire instead of after it.
 */
(function (global) {
    'use strict';

    var UNARY = ['empty', 'not_empty'];

    /** One rule. Mirrors Logic::evaluate() — see that method for why comparison
        is by string except for gt/lt. */
    function evaluate(actual, op, expected) {
        expected = expected == null ? '' : String(expected);

        if (Array.isArray(actual)) {
            if (op === 'empty')     { return actual.length === 0; }
            if (op === 'not_empty') { return actual.length !== 0; }
            // Negations hold only when NO member matches the positive form.
            if (op === 'is_not')       { return !anyMatches(actual, 'is', expected); }
            if (op === 'not_contains') { return !anyMatches(actual, 'contains', expected); }
            return anyMatches(actual, op, expected);
        }

        var value = actual == null ? '' : String(actual);

        switch (op) {
            case 'is':           return value === expected;
            case 'is_not':       return value !== expected;
            case 'contains':     return expected !== '' && value.indexOf(expected) !== -1;
            case 'not_contains': return expected === '' || value.indexOf(expected) === -1;
            case 'gt':           return isNum(value) && isNum(expected) && parseFloat(value) >  parseFloat(expected);
            case 'lt':           return isNum(value) && isNum(expected) && parseFloat(value) <  parseFloat(expected);
            case 'empty':        return value.trim() === '';
            case 'not_empty':    return value.trim() !== '';
            // An unknown operator cannot be honoured and guessing is worse.
            default:             return true;
        }
    }

    function isNum(v) { return v !== '' && !isNaN(parseFloat(v)) && isFinite(v); }

    function anyMatches(values, op, expected) {
        for (var i = 0; i < values.length; i++) {
            if (evaluate(values[i], op, expected)) { return true; }
        }
        return false;
    }

    function matches(logic, values) {
        var rules = (logic && logic.rules) || [];
        if (!rules.length) { return true; }
        var any = (logic.match || 'all') === 'any';

        for (var i = 0; i < rules.length; i++) {
            var ok = evaluate(values[rules[i].field], rules[i].op || 'is', rules[i].value || '');
            if (any && ok)   { return true; }
            if (!any && !ok) { return false; }
        }
        return !any;
    }

    function isVisible(logic, values) {
        // NO RULES MEANS VISIBLE — the same default the PHP takes, for the same
        // reason: treating a malformed definition as "hide" silently deletes
        // fields from a live form.
        if (!logic || !logic.rules || !logic.rules.length) { return true; }
        var m = matches(logic, values);
        return (logic.action || 'show') === 'hide' ? !m : m;
    }

    /* ── Reading the form ──────────────────────────────────────────────────
       One pass over every named control, producing `{name: value}` — a scalar
       for most, an ARRAY for anything posting `name[]` or a set of checkboxes.
       Unchecked boxes contribute '' rather than being absent, because a rule
       reading "is empty" has to be true for a box nobody ticked. */
    function readValues(root, prefix) {
        var out = {};
        var els = root.querySelectorAll('input[name], select[name], textarea[name]');

        for (var i = 0; i < els.length; i++) {
            var el   = els[i];
            var name = el.name.replace(/\[\]$/, '');

            // THE RULE NAMES THE FIELD'S OWN KEY, not the control's name. A
            // public form posts `company`; the entry form posts
            // `field_start_date` for a field whose key is `start_date`, because
            // that is the convention `FieldRenderer` uses to keep custom fields
            // out of the base columns' namespace. The rule stored in the editor
            // says `start_date` — which is what the editor shows and what a
            // person would type — so the prefix is stripped here rather than
            // baked into every saved rule, where it would be one more thing to
            // get wrong and impossible to change later.
            if (prefix && name.indexOf(prefix) === 0) {
                name = name.slice(prefix.length);
            }

            if (el.type === 'checkbox') {
                var multi = /\[\]$/.test(el.name) ||
                            root.querySelectorAll('input[name="' + cssEscape(el.name) + '"]').length > 1;
                if (multi) {
                    if (!Array.isArray(out[name])) { out[name] = []; }
                    if (el.checked) { out[name].push(el.value); }
                } else {
                    out[name] = el.checked ? (el.value || '1') : '';
                }
                continue;
            }
            if (el.type === 'radio') {
                if (el.checked) { out[name] = el.value; }
                else if (!(name in out)) { out[name] = ''; }
                continue;
            }
            out[name] = el.value;
        }
        return out;
    }

    // CSS.escape is not everywhere and this admin ships no polyfills, so the one
    // character that actually appears in a field name here is handled directly.
    function cssEscape(s) { return String(s).replace(/(["\\])/g, '\\$1'); }

    /* ── Live wiring ─────────────────────────────────────────────────────── */

    function bind(root, opts) {
        opts = opts || {};
        var wraps = root.querySelectorAll('[data-logic]');
        if (!wraps.length) { return function () {}; }

        function apply() {
            var values = readValues(root, opts.prefix);
            for (var i = 0; i < wraps.length; i++) {
                var wrap  = wraps[i];
                var logic = wrap.__logic;
                if (logic === undefined) {
                    try { logic = JSON.parse(wrap.getAttribute('data-logic') || 'null'); }
                    catch (e) { logic = null; }
                    wrap.__logic = logic;
                }
                var show = isVisible(logic, values);
                wrap.hidden = !show;

                // DISABLE WHAT IS HIDDEN. A hidden control still posts, and a
                // value for a field the visitor never saw is one the server has
                // to discard — better that it never leaves.
                var ctrls = wrap.querySelectorAll('input, select, textarea');
                for (var j = 0; j < ctrls.length; j++) { ctrls[j].disabled = !show; }
            }
            if (typeof opts.onChange === 'function') { opts.onChange(values); }
        }

        root.addEventListener('input', apply);
        root.addEventListener('change', apply);
        apply();
        return apply;   // callers re-run it after they rebuild the form
    }

    global.Logic = {
        OPERATORS: {
            is: 'is', is_not: 'is not',
            contains: 'contains', not_contains: 'does not contain',
            gt: 'is greater than', lt: 'is less than',
            empty: 'is empty', not_empty: 'is not empty'
        },
        UNARY: UNARY,
        evaluate: evaluate,
        matches: matches,
        isVisible: isVisible,
        readValues: readValues,
        bind: bind
    };
}(window));
