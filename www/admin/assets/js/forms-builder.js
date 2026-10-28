/**
 * THE FORM BUILDER — palette, live canvas, options rail.
 *
 * ── ONE STATE ARRAY, TWO VIEWS ──────────────────────────────────────────────
 * `state.fields` is the form. The canvas draws it, the rail edits the selected
 * entry, and `fields_json` is rewritten from it after every change — that hidden
 * input is the whole definition as far as `FormController::parseFieldsJson()` is
 * concerned, and nothing else on the page contributes a field.
 *
 * Keeping each field's values IN the DOM, one set of inputs per accordion row,
 * works for an accordion and cannot work here: the same field is on screen
 * twice at once — as a preview in the canvas and as controls in the rail — and
 * two DOM copies of one value is a race, not a design. So the array is the
 * truth and both views render from it.
 *
 * ── THE CANVAS IS A PREVIEW, AND THAT IS A REAL RISK ────────────────────────
 * `Shortcode::renderForm()` is the renderer that matters; this file draws a
 * second version of the same fields for the editor. Two renderers drift, so
 * for EVERY type in `FieldTypes` the canvas's control tag must be the tag the
 * public page emits: a type that previews as a text box and renders as a
 * dropdown is a preview of a different form.
 *
 * The type table itself is NOT duplicated. It arrives as JSON from
 * `Cms\Forms\FieldTypes`, which the renderer and the controller also read.
 *
 * ── SELECTION IS THE WHOLE INTERACTION ──────────────────────────────────────
 * Click a field in the canvas, the rail switches to Field Options and shows it.
 * Pick from the palette, the field is appended, selected, and the caret goes to
 * its label. There is no "open this row" step, because the form itself is the
 * list — which is the thing the accordion could never do.
 *
 * ── REORDER IS reorder.js ───────────────────────────────────────────────────
 * The in-house drag system. `onEnd` reorders the ARRAY and redraws, rather
 * than trusting the DOM order it just produced: the array is the definition
 * and the server renders it in array order.
 */
(function () {
    'use strict';

    var doc  = document;
    var root = doc.querySelector('[data-form-builder]');
    if (!root) { return; }

    var hidden   = root.querySelector('[data-fields-json]');
    var canvas   = root.querySelector('[data-canvas]');
    var cTpl     = root.querySelector('[data-canvas-template]');
    var lTpl     = root.querySelector('[data-logic-template]');
    var rail     = root.querySelector('[data-rail]');
    var optsBox  = root.querySelector('[data-options]');
    var optsNone = root.querySelector('[data-options-empty]');
    if (!hidden || !canvas || !cTpl || !rail || !optsBox) { return; }

    /* Set by a completed palette drag (§7b), read and cleared by the palette's
       click handler (§8). DECLARED HERE, at the module scope both of them close
       over: assigned inside the drag closure with no `var`, in a file that is
       `'use strict'`, it throws a ReferenceError that aborts the drop handler
       before it can insert anything. The symptom is a drag that shows its
       proxy and its marker and then does nothing at all, which reads as "the
       drop zone did not accept it" rather than as an error; the console error
       is the only trace. */
    var suppressNextClick = false;

    var TYPES = {};
    var typeNode = doc.querySelector('[data-field-types]');
    if (typeNode) {
        try { TYPES = JSON.parse(typeNode.textContent || '{}'); } catch (e) { TYPES = {}; }
    }
    function typeOf(key) { return TYPES[key] || TYPES.text || {}; }

    /* THE WIDTH VOCABULARY, from `Ui\FieldWidth` through the page: name =>
       span of twelve. A literal map here would be a second copy of a list that
       exists in one place precisely so it cannot be copied — and the spans
       have to be the stylesheet's, or dragging snaps to a width that renders
       as a different one. */
    var SPANS = { full: 12, half: 6, third: 4, quarter: 3 };
    var widthNode = doc.querySelector('[data-field-widths]');
    if (widthNode) {
        try {
            var parsed = JSON.parse(widthNode.textContent || '{}');
            if (parsed && typeof parsed === 'object' && Object.keys(parsed).length) {
                SPANS = parsed;
            }
        } catch (e) { /* keep the fallback — a builder with no widths is worse */ }
    }
    var WIDTHS = Object.keys(SPANS);
    var COLUMNS = SPANS.full || 12;

    var state    = { fields: [], selected: -1 };
    var suppress = false;   // set while the rail is being FILLED, so writing a
                            // value into a control does not read straight back

    /* ── 1. NAME DERIVATION ────────────────────────────────────────────────
       A field with an empty name renders as `name=""` and its answer never
       arrives. Deriving from the label means that cannot happen by accident,
       while leaving the key editable for anyone who needs a specific one. */
    function slug(text) {
        return String(text)
            .toLowerCase()
            .replace(/['’]/g, '')
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .slice(0, 40);
    }

    // UNIQUE, because two fields sharing a key means the second silently
    // overwrites the first in every submission — a data loss with no error.
    function uniqueName(base, skipIndex) {
        var name = base || 'field';
        var n    = 1;
        while (state.fields.some(function (f, i) {
            return i !== skipIndex && f.name === name;
        })) {
            n += 1;
            name = base + '_' + n;
        }
        return name;
    }

    /* ── 2. THE MODEL ──────────────────────────────────────────────────────── */

    /** The SHAPE of a field, every key at its zero value. `hydrate()` copies a
     *  stored definition over this, so it must not invent anything. */
    function blank(type) {
        return {
            type: type, label: '', name: '', required: false,
            placeholder: '', options: '', description: '', text: '',
            'default': '', maxlength: 0, width: 'full',
            // The graded family's own settings. `min`/`max`/`step` are shared
            // with Slider and Number rather than invented per type, so one set
            // of three boxes serves all four.
            min: '', max: '', step: '', columns: '',
            scale_low: '', scale_high: '', source: '', embed_form: '',
            // The appointment's availability. Defaults that describe a normal
            // working week, so the field books something the moment it is
            // dropped rather than after five boxes have been filled in.
            // Only an Appointment field gets them — see `FormController`.
            appt_days: type === 'appointment' ? '1,2,3,4,5' : '',
            appt_from: type === 'appointment' ? '09:00' : '',
            appt_to: type === 'appointment' ? '17:00' : '',
            appt_minutes: type === 'appointment' ? '30' : '',
            appt_ahead: type === 'appointment' ? '14' : '',
            msg_required: '', msg_format: '', logic: null
        };
    }

    /**
     * A NEW FIELD, LABELLED WITH ITS TYPE.
     *
     * Drawn as "Untitled field" whatever its type — text, name, phone, email
     * — a canvas of four is four identical rows, and the one thing that tells
     * them apart, what each one IS, is the one thing not on screen.
     *
     * The type's own label is the honest default: an Email field says Email
     * until it is called something better. It also gives `sync()` a real key to
     * derive — `email` rather than the type name by fallback.
     *
     * ── SEPARATE FROM `blank()` ON PURPOSE ────────────────────────────────
     * `hydrate()` builds from `blank()` and copies a stored definition over it,
     * so a default put THERE would land on any saved field that has no `label`
     * key at all — silently relabelling somebody's live form on the next save,
     * and changing what its visitors see. This defaults new fields; it does not
     * rewrite old ones.
     */
    function fresh(type) {
        var f = blank(type);
        f.label = typeOf(type).label || '';
        return f;
    }

    // Fill in whatever a stored definition is missing, so every later read can
    // assume the whole shape. A form saved before a key existed must still open.
    function hydrate(raw) {
        var f = blank(TYPES[raw && raw.type] ? raw.type : 'text');
        Object.keys(f).forEach(function (k) {
            if (raw && raw[k] !== undefined && raw[k] !== null) { f[k] = raw[k]; }
        });
        f.required  = !!f.required;
        f.maxlength = parseInt(f.maxlength, 10) || 0;
        if (f.logic && (!f.logic.rules || !f.logic.rules.length)) { f.logic = null; }
        return f;
    }

    function sync() {
        // Names are settled HERE rather than as you type, so the definition that
        // is saved always has a usable key for every field even if nobody ever
        // opened the Advanced fold.
        state.fields.forEach(function (f, i) {
            if (!f.name) { f.name = uniqueName(slug(f.label) || f.type, i); }
        });
        hidden.value = JSON.stringify(state.fields);
    }

    /* ── 3. THE CANVAS ─────────────────────────────────────────────────────
       One preview control per type. The tags here are the ones
       `Shortcode::renderForm()` emits — see the header for how that is kept
       honest. Every control is `disabled`: the canvas is a picture of the form,
       and a preview you can type into invites you to type an answer into a form
       that has no submissions yet. */
    function control(f) {
        var t    = typeOf(f.type);
        var opts = String(f.options || '').split('\n')
                        .map(function (s) { return s.trim(); })
                        .filter(function (s) { return s !== ''; });
        // "label | value": a dropdown, radio or checkbox option shows its
        // label, as the visitor sees it (FieldTypes::optionPairs()).
        if (f.type === 'select' || f.type === 'radio' || f.type === 'checkboxes') {
            opts = opts.map(function (s) {
                var bar = s.indexOf('|');
                return bar < 0 ? s : (s.slice(0, bar).trim() || s.slice(bar + 1).trim());
            });
        }

        function el(tag, cls) {
            var n = doc.createElement(tag);
            if (cls) { n.className = cls; }
            return n;
        }

        // A COMPOSITE PREVIEWS AS ITS BOXES, with the same quiet sub-labels
        // underneath that the visitor gets. `t.parts` arrives from
        // `FieldTypes` with the rest of the table, so the canvas cannot know a
        // different set of parts from the renderer — which is the whole reason
        // that table is one table.
        if (t.parts) {
            var wrap = el('div', 'cfield__parts');
            Object.keys(t.parts).forEach(function (key) {
                var part = el('div', 'cfield__part');
                var box  = el('input', 'cfield__input');
                box.type = 'text';
                box.disabled = true;
                var cap = el('span', 'cfield__part-label');
                cap.textContent = t.parts[key];
                part.appendChild(box);
                part.appendChild(cap);
                wrap.appendChild(part);
            });
            return wrap;
        }

        var node;
        switch (f.type) {
            case 'textarea':
                node = el('textarea', 'cfield__input cfield__input--area');
                node.rows = 4;
                node.placeholder = f.placeholder || '';
                node.value = f['default'] || '';
                break;

            case 'select':
                node = el('select', 'cfield__input');
                node.appendChild(new Option('— Select —', ''));
                opts.forEach(function (o) { node.appendChild(new Option(o, o)); });
                break;

            case 'radio':
            case 'checkboxes':
                node = el('div', 'cfield__choices');
                // A CHOICE FIELD WITH NO CHOICES IS A BUG YOU SHOULD SEE. Drawing
                // nothing leaves a labelled gap that reads as a rendering fault;
                // saying so points at the Options box in the rail.
                if (!opts.length) {
                    var hint = el('span', 'cfield__hint');
                    hint.textContent = 'No options yet — add them in the rail.';
                    node.appendChild(hint);
                    break;
                }
                opts.forEach(function (o) {
                    var row = el('label', 'cfield__choice');
                    var box = doc.createElement('input');
                    box.type = f.type === 'radio' ? 'radio' : 'checkbox';
                    box.disabled = true;
                    var txt = doc.createElement('span');
                    txt.textContent = o;
                    row.appendChild(box);
                    row.appendChild(txt);
                    node.appendChild(row);
                });
                break;

            case 'checkbox':
                node = el('label', 'cfield__choice');
                var one = doc.createElement('input');
                one.type = 'checkbox';
                one.disabled = true;
                var oneTxt = doc.createElement('span');
                oneTxt.textContent = f.label || 'Untitled field';
                node.appendChild(one);
                node.appendChild(oneTxt);
                break;

            case 'scale':
            case 'rating':
                // A ROW OF STEPS, previewed as the visitor gets it. A rating
                // shows stars and a scale shows numbers, and both are radios —
                // the star is a label, never a mechanism.
                node = el('div', 'cfield__' + f.type);
                var lo = f.type === 'rating' ? 1 : (parseInt(f.min, 10) || 1);
                var hi = parseInt(f.max, 10) || 5;
                if (hi < lo) { hi = lo; }
                if (hi - lo > 20) { hi = lo + 20; }
                for (var sn = lo; sn <= hi; sn++) {
                    // A REAL (DISABLED) RADIO, like every other preview in this
                    // canvas. A span would look identical and would make the
                    // canvas the one place in the builder that previews a
                    // control with something that is not one — the two
                    // renderers disagreeing.
                    var step = el('label', 'cfield__' + f.type + '-step');
                    var dot  = doc.createElement('input');
                    dot.type = 'radio';
                    dot.disabled = true;
                    var mark = el('span', 'cfield__' + f.type + '-mark');
                    mark.textContent = f.type === 'rating' ? '\u2605' : String(sn);
                    step.appendChild(dot);
                    step.appendChild(mark);
                    node.appendChild(step);
                }
                break;

            case 'tags':
                node = el('div', 'cfield__tags');
                ['Example', 'Another'].forEach(function (t) {
                    var chip = el('span', 'cfield__tag');
                    chip.textContent = t;
                    node.appendChild(chip);
                });
                var tin = doc.createElement('input');
                tin.type = 'text';
                tin.className = 'cfield__input';
                tin.disabled = true;
                tin.placeholder = f.placeholder || 'Type and press Enter';
                node.appendChild(tin);
                break;

            case 'appointment':
                // THE REAL CONTROL, with EXAMPLE times. The canvas cannot know
                // what is already booked — that is on the server — but the
                // slots follow from the shape the author is setting right here,
                // so the first three are computable and honest. Previewing this
                // as a line of prose would make the canvas draw a div where the
                // page draws radios: the two renderers disagreeing.
                node = el('div', 'cfield__appt');
                var when = el('span', 'cfield__appt-shape');
                when.textContent = dayNames(f.appt_days) + ', every '
                                 + (f.appt_minutes || '30') + ' minutes';
                node.appendChild(when);

                var slotRow = el('div', 'cfield__appt-slots');
                var step = parseInt(f.appt_minutes, 10) || 30;
                var startAt = String(f.appt_from || '09:00').split(':');
                var mins = (parseInt(startAt[0], 10) || 9) * 60 + (parseInt(startAt[1], 10) || 0);
                for (var sl = 0; sl < 3; sl++) {
                    var chip = el('label', 'cfield__appt-slot');
                    var pick = doc.createElement('input');
                    pick.type = 'radio';
                    pick.disabled = true;
                    var t = mins + step * sl;
                    var hh = Math.floor(t / 60) % 24;
                    var mm = t % 60;
                    var when12 = ((hh % 12) || 12) + ':' + (mm < 10 ? '0' : '') + mm
                               + ' ' + (hh < 12 ? 'AM' : 'PM');
                    var chipText = doc.createElement('span');
                    chipText.textContent = when12;
                    chip.appendChild(pick);
                    chip.appendChild(chipText);
                    slotRow.appendChild(chip);
                }
                var more = el('span', 'cfield__appt-shape');
                more.textContent = '…';
                slotRow.appendChild(more);
                node.appendChild(slotRow);
                break;

            case 'repeater':
                // ONE ROW OF THE AUTHOR'S COLUMNS, with the Add control — the
                // same shape the visitor gets, at preview scale.
                node = el('div', 'cfield__repeater');
                var rcols = opts.length ? opts : ['Item'];
                var rrow = el('div', 'cfield__rep-row');
                rcols.forEach(function (c) {
                    var cell = el('div', 'cfield__rep-cell');
                    var cin = doc.createElement('input');
                    cin.type = 'text';
                    cin.className = 'cfield__input';
                    cin.disabled = true;
                    var clab = el('span', 'cfield__part-label');
                    clab.textContent = c;
                    cell.appendChild(cin);
                    cell.appendChild(clab);
                    rrow.appendChild(cell);
                });
                node.appendChild(rrow);
                var radd = el('span', 'cfield__rep-add');
                radd.textContent = 'Add another';
                node.appendChild(radd);
                break;

            case 'dynamic':
            case 'lookup':
                // THE REAL CONTROL, saying where its choices come from. The
                // canvas cannot list the entries — they live on the server —
                // but it must preview the CONTROL the visitor gets, or it is a
                // picture of a different form: a div here against a <select>
                // there.
                var says = f.source
                    ? (f.type === 'lookup' ? 'Any of the ' : 'One of the ') + f.source + ' entries'
                    : 'Choose a content type in the rail';

                if (f.type === 'dynamic') {
                    node = doc.createElement('select');
                    node.className = 'cfield__input';
                    node.disabled = true;
                    var only = doc.createElement('option');
                    only.textContent = says;
                    node.appendChild(only);
                } else if (!f.source) {
                    // NO SOURCE, NO CONTROL — which is exactly what the public
                    // page renders too: a lookup with nowhere to look has no
                    // boxes to tick, only a line saying so. Showing a tick box
                    // here would preview a control the visitor will not get.
                    node = el('div', 'cfield__sourced');
                    node.textContent = says;
                } else {
                    node = el('div', 'cfield__choices');
                    var row = el('label', 'cfield__choice');
                    var tick = doc.createElement('input');
                    tick.type = 'checkbox';
                    tick.disabled = true;
                    var lab = doc.createElement('span');
                    lab.textContent = says;
                    row.appendChild(tick);
                    row.appendChild(lab);
                    node.appendChild(row);
                }
                break;

            case 'file':
                node = doc.createElement('input');
                node.type = 'file';
                node.className = 'cfield__input';
                node.disabled = true;
                break;

            case 'signature':
                node = el('div', 'cfield__signature');
                node.textContent = 'Signed here';
                break;

            case 'captcha':
                // WHAT THE VISITOR WILL SEE depends on a SITE setting, not on
                // this field — so the preview says which one is in force
                // rather than drawing a question that may not be the one asked.
                node = el('div', 'cfield__captcha');
                node.textContent = 'A challenge the visitor answers. Which one is '
                                 + 'set in Settings → General.';
                break;

            case 'embed':
                // THE CANVAS CANNOT DRAW THE OTHER FORM'S FIELDS — they are on
                // the server and this preview has no copy of them. Saying which
                // form is pulled in beats drawing three fake rows that are not
                // the ones the visitor will see.
                node = el('div', 'cfield__sourced');
                node.textContent = f.embed_form
                    ? 'The fields of form #' + f.embed_form + ', inline'
                    : 'Choose a form in the rail';
                break;

            case 'pagebreak':
                node = el('div', 'cfield__pagebreak');
                node.textContent = 'Page break — everything after this is a new step';
                break;

            case 'summary':
                node = el('div', 'cfield__summary');
                node.textContent = 'The answers, read back before submitting.';
                break;

            case 'ranking':
                node = el('ol', 'cfield__ranking');
                var rk = opts.length ? opts : ['Add choices in the rail'];
                rk.forEach(function (o) {
                    var li = el('li', 'cfield__rank');
                    li.textContent = o;
                    node.appendChild(li);
                });
                break;

            case 'likert':
                // THE GRID, at preview scale. Rows are the Options list and
                // columns are the Columns list — both the author's own, both
                // shown, because a Likert previewed as a single radio row is a
                // picture of a different question.
                node = el('table', 'cfield__likert');
                var lrows = String(f.options || '').split('\n')
                                .map(function (x) { return x.trim(); })
                                .filter(function (x) { return x !== ''; });
                var lcols = String(f.columns || '').split('\n')
                                .map(function (x) { return x.trim(); })
                                .filter(function (x) { return x !== ''; });
                if (!lcols.length) {
                    lcols = ['Strongly disagree', 'Disagree', 'Neutral',
                             'Agree', 'Strongly agree'];
                }
                if (!lrows.length) { lrows = ['Add statements in the rail']; }
                var head = doc.createElement('tr');
                head.appendChild(doc.createElement('td'));
                lcols.forEach(function (c) {
                    var th = doc.createElement('th');
                    th.textContent = c;
                    head.appendChild(th);
                });
                node.appendChild(head);
                lrows.forEach(function (r) {
                    var tr = doc.createElement('tr');
                    var rh = doc.createElement('th');
                    rh.textContent = r;
                    tr.appendChild(rh);
                    lcols.forEach(function () {
                        var td = doc.createElement('td');
                        var rb = doc.createElement('input');
                        rb.type = 'radio';
                        rb.disabled = true;
                        td.appendChild(rb);
                        tr.appendChild(td);
                    });
                    node.appendChild(tr);
                });
                break;

            case 'toggle':
                // A TRACK AND A KNOB, not a tick box — the canvas is a picture
                // of the form, and previewing a toggle as a checkbox would be a
                // picture of the other control.
                node = el('label', 'cfield__toggle');
                var tg = doc.createElement('input');
                tg.type = 'checkbox';
                tg.disabled = true;
                node.appendChild(tg);
                node.appendChild(el('span', 'cfield__toggle-track'));
                break;

            case 'range':
                node = el('div', 'cfield__range');
                var rg = doc.createElement('input');
                rg.type = 'range';
                rg.disabled = true;
                rg.min = f.min || 0;
                rg.max = f.max || 100;
                rg.value = f['default'] || f.min || 0;
                var out = el('span', 'cfield__range-value');
                out.textContent = rg.value;
                node.appendChild(rg);
                node.appendChild(out);
                break;

            case 'section':
                node = el('div', 'cfield__section');
                var st = el('span', 'cfield__section-title');
                st.textContent = f.label || 'Section';
                node.appendChild(st);
                if (f.text) {
                    var sx = el('span', 'cfield__section-text');
                    sx.textContent = f.text;
                    node.appendChild(sx);
                }
                break;

            case 'note':
                node = el('p', 'cfield__note');
                node.textContent = f.text || 'Text block — write it in the rail.';
                break;

            case 'divider':
                node = el('hr', 'cfield__divider');
                break;

            case 'hidden':
                node = el('span', 'cfield__hint');
                node.textContent = 'Hidden — the visitor never sees this.';
                break;

            default:
                node = el('input', 'cfield__input');
                node.type = ({ email: 'email', tel: 'tel', url: 'url',
                               number: 'number', date: 'date' })[f.type] || 'text';
                node.placeholder = f.placeholder || '';
                node.value = f['default'] || '';
        }

        if (node.tagName === 'INPUT' || node.tagName === 'SELECT' || node.tagName === 'TEXTAREA') {
            node.disabled = true;
        }
        if (t.multiple) { node.setAttribute('data-multiple', '1'); }
        return node;
    }

    function labelsByName() {
        var map = {};
        state.fields.forEach(function (f) {
            if (f.name) { map[f.name] = f.label || f.name; }
        });
        return map;
    }

    // The one-line English rendering of a rule set. Mirrors Logic::describe() —
    // a badge saying "Logic" tells you a rule exists; this tells you what it is.
    function describeLogic(logic) {
        if (!logic || !logic.rules || !logic.rules.length) { return ''; }
        var labels = labelsByName();
        var first  = logic.rules[0];
        var ops    = (window.Logic && window.Logic.OPERATORS) || {};
        var unary  = (window.Logic && window.Logic.UNARY) || [];
        var text   = (logic.action === 'hide' ? 'Hide if ' : 'Show if ') +
                     (labels[first.field] || first.field) + ' ' +
                     (ops[first.op] || first.op);
        if (unary.indexOf(first.op) === -1) { text += ' "' + (first.value || '') + '"'; }
        var more = logic.rules.length - 1;
        if (more > 0) {
            text += (logic.match === 'any' ? ' or ' : ' and ') +
                    more + ' other' + (more === 1 ? '' : 's');
        }
        return text;
    }

    function drawCanvas() {
        canvas.textContent = '';

        state.fields.forEach(function (f, i) {
            var frag = cTpl.content.cloneNode(true);
            var el   = frag.querySelector('[data-cfield]');
            var t    = typeOf(f.type);

            el.dataset.index = String(i);
            el.classList.toggle('is-selected', i === state.selected);
            // FOUR WIDTHS, DRIVEN OFF THE STORED VALUE. `WIDTHS` comes from
            // `Ui\FieldWidth` through the page, so this cannot know about a
            // width the server does not, or miss one it does.
            WIDTHS.forEach(function (w) {
                el.classList.toggle('cfield--' + w, w !== 'full' && f.width === w);
            });
            el.classList.toggle('cfield--bare', t.input === false || f.type === 'checkbox');
            el.setAttribute('aria-label', (f.label || 'Untitled field') + ' — edit this field');

            el.querySelector('[data-cfield-id]').textContent = 'ID ' + (i + 1);

            // A CHECKBOX CARRIES ITS OWN LABEL and the renderer prints no
            // separate one, so drawing one here would preview a duplicate the
            // visitor never sees.
            var labelEl = el.querySelector('[data-cfield-label]');
            if (t.input === false || f.type === 'checkbox') {
                labelEl.hidden = true;
            } else {
                labelEl.textContent = f.label || 'Untitled field';
                labelEl.classList.toggle('is-empty', !f.label);
                if (f.required) {
                    var star = doc.createElement('span');
                    star.className = 'cfield__req';
                    star.textContent = '*';
                    labelEl.appendChild(doc.createTextNode(' '));
                    labelEl.appendChild(star);
                }
            }

            el.querySelector('[data-cfield-control]').appendChild(control(f));

            var desc = el.querySelector('[data-cfield-desc]');
            if (f.description) { desc.textContent = f.description; desc.hidden = false; }

            var lg = el.querySelector('[data-cfield-logic]');
            var lgText = describeLogic(f.logic);
            if (lgText) { lg.textContent = lgText; lg.hidden = false; }

            canvas.appendChild(frag);
        });

        var empty = root.querySelector('[data-canvas-empty]');
        if (empty) { empty.hidden = state.fields.length !== 0; }

        var submit = root.querySelector('[data-canvas-submit]');
        var label  = root.querySelector('[name="submit_label"]');
        if (submit && label) { submit.textContent = label.value || 'Submit'; }

        fillReplyTo();
    }

    /* ── 3b. THE REPLY-TO LIST IS BUILT FROM THE FIELDS ────────────────────
       It has to match a field's NAME exactly or the reply goes nowhere, and it
       was a free text box. Only email fields are offered, because that is the
       only kind whose value can be replied to. */
    function fillReplyTo() {
        var sel = root.querySelector('[data-reply-to]');
        if (!sel) { return; }
        var want = sel.value || sel.getAttribute('data-current') || '';
        sel.textContent = '';
        sel.appendChild(new Option('— None —', ''));
        state.fields.forEach(function (f) {
            if (f.type === 'email' && f.name) {
                sel.appendChild(new Option((f.label || f.name), f.name));
            }
        });
        // A SAVED VALUE WHOSE FIELD IS GONE IS KEPT AND MARKED, not dropped.
        // Silently selecting "None" would turn a deleted field into a form that
        // quietly stops replying to anyone.
        if (want && !Array.prototype.some.call(sel.options, function (o) { return o.value === want; })) {
            var lost = new Option(want + ' (no such field)', want);
            sel.appendChild(lost);
        }
        sel.value = want;
    }

    /* ── 4. THE RAIL ───────────────────────────────────────────────────────── */

    function railTab(which) {
        rail.querySelectorAll('[data-rail-tab]').forEach(function (b) {
            var on = b.getAttribute('data-rail-tab') === which;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        rail.querySelectorAll('[data-rail-pane]').forEach(function (p) {
            p.hidden = p.getAttribute('data-rail-pane') !== which;
        });
    }

    /** "1,2,3,4,5" → "Mon–Fri" for the canvas, without a second day table. */
    function dayNames(csv) {
        var names = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        var out = String(csv || '').split(',')
            .map(function (n) { return names[parseInt(n, 10)] || ''; })
            .filter(Boolean);
        return out.length ? out.join(', ') : 'no days';
    }

    /* THE TICK BOXES ARE A FACE ON ONE HIDDEN VALUE. `appt_days` is stored as a
       comma list of weekday numbers, which is the right thing to store and the
       wrong thing to ask anybody to type. These two keep the two in step, and
       the hidden input is what `readOptions()` reads — so there is still
       exactly one source of truth. */
    function paintDays(f) {
        var want = String(f.appt_days || '').split(',');
        optsBox.querySelectorAll('[data-opt-day]').forEach(function (box) {
            box.checked = want.indexOf(box.value) !== -1;
        });
    }

    optsBox.addEventListener('change', function (e) {
        if (!e.target.hasAttribute || !e.target.hasAttribute('data-opt-day')) { return; }
        var on = [];
        optsBox.querySelectorAll('[data-opt-day]').forEach(function (box) {
            if (box.checked) { on.push(box.value); }
        });
        var hidden = optEl('appt_days');
        if (hidden) {
            hidden.value = on.join(',');
            // The same path a typed control takes, so the canvas redraws and
            // `fields_json` is rewritten exactly as it would be for any option.
            hidden.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });

    function optEl(key) { return optsBox.querySelector('[data-opt="' + key + '"]'); }
    function wrap(key)  { return optsBox.querySelector('[data-opt-wrap="' + key + '"]'); }

    function show(key, on) {
        var el = wrap(key);
        if (el) { el.hidden = !on; }
    }

    function fillOptions() {
        var f = state.fields[state.selected];
        if (!f) {
            optsBox.hidden  = true;
            optsNone.hidden = false;
            return;
        }
        optsBox.hidden  = false;
        optsNone.hidden = true;

        var t = typeOf(f.type);

        suppress = true;
        optsBox.querySelector('[data-opt-kind]').textContent = (t.label || 'Text') + ' field';
        optsBox.querySelector('[data-opt-id]').textContent   = 'ID ' + (state.selected + 1);

        ['label', 'name', 'placeholder', 'options', 'description', 'text',
         'default', 'width', 'msg_required', 'msg_format',
         'min', 'max', 'step', 'columns', 'scale_low', 'scale_high',
         'source', 'embed_form', 'appt_days', 'appt_from', 'appt_to',
         'appt_minutes', 'appt_ahead'].forEach(function (k) {
            var el = optEl(k);
            if (el) { el.value = f[k] == null ? '' : String(f[k]); }
        });
        var maxEl = optEl('maxlength');
        if (maxEl) { maxEl.value = f.maxlength ? String(f.maxlength) : ''; }
        var reqEl = optEl('required');
        if (reqEl) { reqEl.checked = !!f.required; }

        // WHICH CONTROLS EXIST IS DECIDED BY THE TYPE TABLE, not by a list of
        // type names written here. Collecting a placeholder for a date picker,
        // or options for a text box, stores values nothing will ever read.
        show('placeholder', !!t.placeholder);
        show('options',     !!t.choices);
        show('description', t.input !== false);
        show('text',        f.type === 'note');
        show('width',       f.type !== 'hidden');
        show('default',     t.input !== false && !t.choices && f.type !== 'checkbox');
        show('maxlength',   ['text', 'textarea', 'email', 'tel', 'url'].indexOf(f.type) !== -1);
        show('validation',  t.input !== false);
        show('msg_format',  ['email', 'url', 'number', 'date', 'time'].indexOf(f.type) !== -1);

        // THE GRADED FAMILY'S CONTROLS. Bounds are shared with Number and
        // Slider — one set of boxes, four types — and a rating's low end is
        // always 1, so it offers a ceiling and nothing else.
        var bounded = ['number', 'range', 'scale', 'rating'].indexOf(f.type) !== -1;
        show('min',  bounded && f.type !== 'rating');
        show('max',  bounded);
        show('step', ['number', 'range'].indexOf(f.type) !== -1);
        // End labels answer "which way is good" on a bare row of numbers.
        show('scale_low',  ['scale', 'rating'].indexOf(f.type) !== -1);
        show('scale_high', ['scale', 'rating'].indexOf(f.type) !== -1);
        show('columns',    f.type === 'likert');
        // A SOURCED FIELD HAS NO OPTIONS TEXTAREA — its choices are the site's
        // content, which is the whole point — so it gets this instead.
        show('source',     ['dynamic', 'lookup'].indexOf(f.type) !== -1);
        show('embed_form', f.type === 'embed');

        // The appointment's four availability controls travel together — a
        // shape is only meaningful whole.
        var appt = f.type === 'appointment';
        ['appt_days', 'appt_from', 'appt_minutes'].forEach(function (k) { show(k, appt); });
        if (appt) { paintDays(f); }

        var flags = optsBox.querySelector('.rail__flags');
        if (flags) { flags.hidden = t.input === false; }

        fillLogic(f);
        suppress = false;
    }

    /* ── 4b. THE LOGIC PANEL ───────────────────────────────────────────────── */

    function fillLogic(f) {
        var enable = optsBox.querySelector('[data-logic-enable]');
        var panel  = optsBox.querySelector('[data-logic-panel]');
        var rules  = optsBox.querySelector('[data-logic-rules]');
        var tag    = optsBox.querySelector('[data-logic-tag]');
        var none   = optsBox.querySelector('[data-logic-nofields]');
        if (!enable || !panel || !rules) { return; }

        var on = !!(f.logic && f.logic.rules && f.logic.rules.length);
        enable.checked = on;
        panel.hidden   = !on;
        if (tag) { tag.hidden = !on; }

        optsBox.querySelector('[data-logic-action]').value = (f.logic && f.logic.action) || 'show';
        optsBox.querySelector('[data-logic-match]').value  = (f.logic && f.logic.match)  || 'all';

        rules.textContent = '';
        ((f.logic && f.logic.rules) || []).forEach(addRuleRow);

        // A RULE HAS TO TEST SOMETHING. With no other field on the form there is
        // nothing to test, and an empty dropdown is a dead end you have to work
        // out for yourself.
        if (none) { none.hidden = candidates().length !== 0; }
    }

    // Every field that could be tested: not this one, collects a value, has a
    // key. Testing a field against itself is a rule that can never settle.
    function candidates() {
        return state.fields.filter(function (f, i) {
            return i !== state.selected && f.name && typeOf(f.type).input !== false;
        });
    }

    function addRuleRow(rule) {
        if (!lTpl) { return; }
        var rules = optsBox.querySelector('[data-logic-rules]');
        var frag  = lTpl.content.cloneNode(true);
        var row   = frag.querySelector('[data-logic-rule]');
        var sel   = row.querySelector('[data-logic-field]');

        candidates().forEach(function (f) {
            sel.appendChild(new Option(f.label || f.name, f.name));
        });
        if (rule && rule.field) {
            if (!Array.prototype.some.call(sel.options, function (o) { return o.value === rule.field; })) {
                sel.appendChild(new Option(rule.field + ' (no such field)', rule.field));
            }
            sel.value = rule.field;
        }
        row.querySelector('[data-logic-op]').value    = (rule && rule.op)    || 'is';
        row.querySelector('[data-logic-value]').value = (rule && rule.value) || '';
        paintRule(row);

        rules.appendChild(frag);
    }

    // `is empty` and `is not empty` read nothing from the value box, so it goes
    // away rather than sitting there collecting a string that is discarded.
    function paintRule(row) {
        var op    = row.querySelector('[data-logic-op]').value;
        var unary = (window.Logic && window.Logic.UNARY) || ['empty', 'not_empty'];
        row.querySelector('[data-logic-value]').hidden = unary.indexOf(op) !== -1;
    }

    function readLogic() {
        var f = state.fields[state.selected];
        if (!f) { return; }
        var enable = optsBox.querySelector('[data-logic-enable]');
        if (!enable.checked) { f.logic = null; return; }

        var rules = [];
        optsBox.querySelectorAll('[data-logic-rule]').forEach(function (row) {
            var field = row.querySelector('[data-logic-field]').value;
            if (!field) { return; }
            rules.push({
                field: field,
                op:    row.querySelector('[data-logic-op]').value,
                value: row.querySelector('[data-logic-value]').value
            });
        });

        // MATCHES Logic::parse() — an empty rule list is stored as no logic at
        // all, so "has logic?" stays a one-step check everywhere.
        f.logic = rules.length ? {
            action: optsBox.querySelector('[data-logic-action]').value,
            match:  optsBox.querySelector('[data-logic-match]').value,
            rules:  rules
        } : null;
    }

    /* ── 5. SELECTION ──────────────────────────────────────────────────────── */

    function select(i, focusLabel) {
        state.selected = i;
        railTab('options');
        fillOptions();
        drawCanvas();
        if (focusLabel) {
            // THE CARET GOES TO THE LABEL — the whole point of picking from the
            // palette is that you can start typing what the field is called.
            //
            // Field label has no `[data-opt-wrap]`: it is shown for every type,
            // so it never needed one, and `closest()` returns NULL — without
            // the `!wrap` test, `null.hidden` throws a TypeError on every single
            // palette add. The throw would be the last statement in this
            // function, so everything visible would already have happened and
            // the only casualty would be the focus: no error anyone sees, just
            // a caret that is not where it should be.
            var el   = optEl(typeOf(state.fields[i].type).input === false ? 'text' : 'label');
            var wrap = el && el.closest('[data-opt-wrap]');
            if (el && (!wrap || !wrap.hidden)) { el.focus(); }
        }
    }

    /* ── 6. HYDRATE ────────────────────────────────────────────────────────── */
    (function () {
        var parsed;
        try { parsed = JSON.parse(hidden.value || '[]'); }
        catch (e) {
            // A definition that will not parse is left EXACTLY as it is: wiping
            // it to [] would silently delete a form's fields on first open.
            parsed = [];
        }
        if (!Array.isArray(parsed)) { parsed = []; }
        state.fields = parsed.map(hydrate);
        drawCanvas();
        fillOptions();
    }());

    /* ── 7. REORDER ────────────────────────────────────────────────────────
       The ARRAY is reordered from the DOM order reorder.js produced, and then
       everything is redrawn. Trusting the DOM alone would leave the previews
       right and `fields_json` wrong — and the server renders the array.

       ── DROPPING BESIDE SOMETHING MEANS BESIDE ────────────────────────────
       Picking up a field that is on its own row and dropping it beside another
       should split the row 50/50 by itself.

       A full-width field cannot share a row — it spans all twelve columns — so
       carrying one alongside another and letting go would otherwise produce a
       field on the NEXT row, every time, and the answer would be to go and set
       two widths by hand afterwards. The gesture says what is wanted.

       So the drop reads its own geometry. If the pointer was inside the
       vertical band of the field it landed against — beside it, rather than
       above or below it — then:

         · that field has spare columns  → take exactly the spare
         · that field is full width      → split the row, half and half

       ── ONLY A FULL-WIDTH FIELD IS RESIZED ────────────────────────────────
       A width the owner set is a decision, and a drag that silently overwrites
       one is worse than a drag that does nothing: they would have to notice it
       happened before they could undo it. `full` is the default nobody chose,
       so it is the only value this is allowed to change. Drag a quarter-width
       field anywhere and it stays a quarter. */
    if (window.Reorder && typeof window.Reorder.create === 'function') {
        window.Reorder.create(canvas, {
            handle: '[data-cfield-grip]',
            animation: 150,
            onEnd: function () {
                var order = Array.prototype.map.call(
                    canvas.querySelectorAll('[data-cfield]'),
                    function (el) { return parseInt(el.dataset.index, 10); }
                );
                var was = state.fields[state.selected];
                state.fields = order.map(function (i) { return state.fields[i]; });
                state.selected = state.fields.indexOf(was);
                sync();
                drawCanvas();
                fillOptions();
            }
        });

        fitOnDrop();
    }

    /**
     * A ROW IS SHARED EVENLY BY THE FIELDS ON IT.
     *
     * That is the whole rule. Drop a field into a row and the row re-splits
     * between everything now on it; drag one out and the row re-splits between
     * what is left. One field on a row is full width, two are halves, three are
     * thirds, four are quarters — 12 divides by all of them, which is why the
     * grid has twelve columns.
     *
     * ── WHY NOT "TAKE THE SPARE" ──────────────────────────────────────────
     * Measuring ONE neighbour and giving the incoming field whatever that
     * neighbour leaves over fails against a 50/50 pair: it computes
     * `12 - 6 = 6`, makes the newcomer a half, and the row — already full —
     * pushes it onto a line of its own at half width, where the three should
     * have been a 33/33/33 split.
     *
     * Both halves of that are the same mistake: the question is not what one
     * neighbour has spare, it is how many fields are sharing this row.
     *
     * ── WHAT IT DOES NOT TOUCH ────────────────────────────────────────────
     * Only the two rows a drag actually disturbs — the one it left and the one
     * it joined. Everything else on the canvas keeps whatever width it was
     * given. And a drag that reorders WITHIN one row changes no membership, so
     * it changes no widths.
     *
     * A count with no matching width — five on a row, and there is no
     * 12/5 — leaves every width alone rather than inventing something the
     * stylesheet cannot draw.
     */
    function fitOnDrop() {
        var carried   = null;   // the field entry being dragged
        var originRow = [];     // the entries it was sharing a row with, at pickup
        var targetRow = null;   // the entries on the row it is being dropped into

        /** The field entry an element stands for. */
        function entryOf(el) {
            return state.fields[parseInt(el.dataset.index, 10)] || null;
        }

        /** Laid-out fields — the carried one is out of the flow and not on any
         *  row while it is in the air. */
        function laidOut() {
            return Array.prototype.filter.call(
                canvas.querySelectorAll('[data-cfield]'),
                function (el) { return !el.hasAttribute('data-reorder-source'); });
        }

        /** Entries whose box crosses `y` — everything on that row. */
        function rowAt(y, except) {
            var out = [];
            laidOut().forEach(function (el) {
                if (el === except) { return; }
                var r = el.getBoundingClientRect();
                if (y >= r.top && y <= r.bottom) {
                    var f = entryOf(el);
                    if (f) { out.push(f); }
                }
            });
            return out;
        }

        /** The width that splits a row `n` ways, or null if none does. */
        function evenWidth(n) {
            var want = COLUMNS / n;
            for (var i = 0; i < WIDTHS.length; i++) {
                if (SPANS[WIDTHS[i]] === want) { return WIDTHS[i]; }
            }
            return null;
        }

        function apply(members) {
            var w = evenWidth(members.length);
            if (!w) { return false; }
            var moved = false;
            members.forEach(function (f) {
                if (f && f.width !== w) { f.width = w; moved = true; }
            });
            return moved;
        }

        canvas.addEventListener('pointerdown', function (e) {
            if (!e.target.closest('[data-cfield-grip]')) { return; }
            var el = e.target.closest('[data-cfield]');
            carried = el ? entryOf(el) : null;
            targetRow = null;
            originRow = [];
            if (!el || !carried) { return; }

            // THE ROW IT IS LEAVING, MEASURED BEFORE IT MOVES. The moment the
            // drag arms, the field is out of the flow and the row closes over
            // it — so "what else was on that row" is a question that can only
            // be answered now.
            var mine = el.getBoundingClientRect();
            originRow = rowAt(mine.top + mine.height / 2, el);
        });

        canvas.addEventListener('pointermove', function (e) {
            if (!carried) { return; }
            targetRow = null;
            if (!canvas.querySelector('[data-reorder-indicator]')) { return; }

            // ASK THE SAME FUNCTION THE LINE ASKED. A second derivation is a
            // second chance to disagree, and the line is the promise the drop
            // has to keep.
            var fields = laidOut();
            var plan = window.Reorder.dropPlan(canvas, fields, e.clientX, e.clientY);

            // VERTICAL MEANS BESIDE — it is joining that row. HORIZONTAL means
            // a row of its own, and a row of one is full width.
            targetRow = plan.vertical ? rowAt(e.clientY, null) : [];
        });

        document.addEventListener('pointerup', function () {
            if (!carried) { return; }
            var moved = carried, joined = targetRow, left = originRow;
            carried = null;
            targetRow = null;
            originRow = [];
            if (joined === null) { return; }   // never armed into a drop

            window.setTimeout(function () {
                if (state.fields.indexOf(moved) < 0) { return; }
                var live = function (f) { return state.fields.indexOf(f) >= 0; };
                joined = joined.filter(live);
                left   = left.filter(live);

                // REORDERED WITHIN ITS OWN ROW — same members, so nothing is
                // re-split. The rule is about which row a field is on, not
                // about having dragged something: a hand-built
                // quarter/three-quarters row must survive its two fields being
                // swapped, and re-applying the even split unconditionally
                // normalised it to halves.
                //
                // Comparing the SETS rather than the counts, because moving a
                // field between two rows that happen to be the same size is a
                // real change that a count would miss. Both empty — alone
                // before, alone after — counts as the same row too, which is
                // what lets a deliberately narrow field sit by itself.
                var same = joined.length === left.length &&
                           left.every(function (f) { return joined.indexOf(f) >= 0; });
                if (same) { return; }

                var changed = apply(joined.concat([moved]));
                if (left.length) { changed = apply(left) || changed; }
                if (!changed) { return; }

                sync();
                drawCanvas();
                fillOptions();
            }, 0);
        });
    }

    /* ── 7a. DRAG A FIELD'S EDGE TO SET ITS WIDTH ──────────────────────────
       Setting a field to 1/2, 1/3, 1/4 or full width should be one easy drag.

       ── IT SNAPS, IT DOES NOT RESIZE ──────────────────────────────────────
       A field's width is a span of twelve columns, not a pixel measurement.
       Dragging freely would let someone set 37% — a width the renderer has no
       class for, the stylesheet no rule for, and the whitelist no name for, so
       it would be silently rounded back to full on save. What the pointer
       chooses is a COLUMN; the column picks the nearest width that exists.

       ── THE PREVIEW IS THE CANVAS ITSELF ──────────────────────────────────
       The class goes on as you drag, so the row re-flows under the pointer and
       the neighbouring fields move to where they will actually be. There is no
       separate ghost outline to interpret, and no way for the preview to
       disagree with the result — it IS the result, applied early and reverted
       on Escape.

       ── AND FROM THE KEYBOARD ─────────────────────────────────────────────
       The handle is a real <button>: Left and Right step through the widths.
       A mouse-only way to set a layout property is the same trap a mouse-only
       reorder is, and the rail's select is a different control on a different
       screen region — reachable, but not the same gesture. */
    (function widthDrag() {
        var live = null;   // {index, el, was}

        function spanAt(el, x) {
            // WHICH COLUMN THE POINTER IS OVER, counted from the field's own
            // left edge — the field starts at a column boundary, so the span
            // is simply how many columns it now covers.
            var track = canvas.getBoundingClientRect();
            var gap   = parseFloat(window.getComputedStyle(canvas).columnGap) || 0;
            var col   = (track.width + gap) / COLUMNS;
            if (col <= 0) { return COLUMNS; }
            var left  = el.getBoundingClientRect().left;
            var n     = Math.round((x - left + gap) / col);
            return Math.max(1, Math.min(COLUMNS, n));
        }

        /** The width whose span is nearest `n`. */
        function nearest(n) {
            var best = WIDTHS[0], bestD = Infinity;
            WIDTHS.forEach(function (w) {
                var d = Math.abs(SPANS[w] - n);
                if (d < bestD) { bestD = d; best = w; }
            });
            return best;
        }

        function paint(el, width) {
            WIDTHS.forEach(function (w) {
                el.classList.toggle('cfield--' + w, w !== 'full' && w === width);
            });
        }

        function finish(commit) {
            if (!live) { return; }
            var f = state.fields[live.index];
            if (f) { f.width = commit ? live.width : live.was; }
            live = null;
            sync();
            drawCanvas();
            fillOptions();
        }

        canvas.addEventListener('pointerdown', function (e) {
            var grab = e.target.closest('[data-cfield-resize]');
            if (!grab || e.button !== 0) { return; }
            var el = grab.closest('[data-cfield]');
            if (!el) { return; }

            var i = parseInt(el.dataset.index, 10);
            live = { index: i, el: el, was: state.fields[i].width, width: state.fields[i].width };

            // Capture on the HANDLE: the canvas redraws on commit, but not
            // during the drag, so this element survives the whole gesture.
            try { grab.setPointerCapture(e.pointerId); } catch (err) { /* fine */ }
            e.preventDefault();
            e.stopPropagation();   // never let this start a reorder as well
        });

        canvas.addEventListener('pointermove', function (e) {
            if (!live) { return; }
            live.width = nearest(spanAt(live.el, e.clientX));
            paint(live.el, live.width);
        });

        canvas.addEventListener('pointerup', function () { finish(true); });
        canvas.addEventListener('pointercancel', function () { finish(false); });

        // ESCAPE ONLY. No arrow keys step the width from a focused handle, for
        // the same reason the reorder has none: keys that change the layout
        // while you believe you are moving the page. The Field width select in
        // the rail is the path that does not need a pointer, and it is a real
        // labelled control rather than behaviour hidden on a seam.
        doc.addEventListener('keydown', function (e) {
            if (live && e.key === 'Escape') { e.preventDefault(); finish(false); }
        });
    }());

    /* ── 7b. DRAG A TYPE OUT OF THE PALETTE ────────────────────────────────
       Click-only, every new field lands at the BOTTOM of the form and then has
       to be dragged up through the canvas — on a form of any length, that is
       the whole interaction. So the palette drags onto the canvas and drops
       where you point.

       ── WHY NOT reorder.js ────────────────────────────────────────────────
       That moves an element WITHIN one container and reports old/new index.
       This is a different gesture: it CREATES something, in a second container,
       from a source element that must stay where it is. Reusing it would have
       meant teaching it about cross-container drags for one call site.

       ── WHY POINTER EVENTS, NOT HTML5 DnD ─────────────────────────────────
       Same reasoning reorder.js's own header gives: HTML5 drag-and-drop does not
       fire on touch, its drag image cannot be styled, and its event model
       differs across engines. Pointer events unify mouse, touch and pen — and a
       tablet is exactly what a site owner is likely holding.

       CLICK STILL WORKS. The drag only starts after the pointer has travelled
       far enough to be a drag rather than a click (`THRESHOLD`), so the existing
       click-to-append path below is untouched and keyboard users lose nothing. */
    (function paletteDrag() {
        var THRESHOLD = 5;         // px before a press becomes a drag
        var origin = null, proxy = null, marker = null, type = null, armed = false;
        var hadStatic = false;

        function dropPlanAt(x, y) {
            // THE SAME ANSWER THE REORDER GIVES ITSELF. This was a y-only loop
            // — "first field whose centre is below the pointer" — which cannot
            // tell left from right, so on a canvas with half-width fields
            // sharing a row, dropping a new field to the RIGHT of one landed it
            // to the left. Two gestures on one screen disagreeing about where a
            // drop goes is the same class of fault as two drop markers.
            var els = canvas.querySelectorAll('[data-cfield]');
            return window.Reorder.dropPlan(
                canvas, Array.prototype.slice.call(els), x, y);
        }

        function showMarker(plan, y) {
            // THE SAME DOTTED LINE THE REORDER DRAWS. This drew its own
            // `.canvas__drop` — a 2px accent rule in the flow — while dragging
            // a field that already exists on the same canvas showed something
            // else entirely. Two drop markers of different shapes, on one
            // screen, for the same question.
            if (!marker) {
                marker = window.Reorder.indicator({});
                canvas.appendChild(marker);
            }
            window.Reorder.placeIndicator(
                canvas, marker,
                Array.prototype.slice.call(canvas.querySelectorAll('[data-cfield]')),
                plan, y);
        }

        function cleanup() {
            if (proxy) { proxy.remove(); proxy = null; }
            if (marker) { marker.remove(); marker = null; }
            if (hadStatic) { canvas.style.position = ''; hadStatic = false; }
            canvas.classList.remove('is-dropzone');
            origin = null; type = null; armed = false;
        }

        root.addEventListener('pointerdown', function (e) {
            var item = e.target.closest('[data-palette-add]');
            if (!item || e.button !== 0) { return; }
            origin = { x: e.clientX, y: e.clientY };
            type   = item.getAttribute('data-palette-add');
            armed  = false;
            item.setPointerCapture(e.pointerId);
        });

        root.addEventListener('pointermove', function (e) {
            if (!origin) { return; }

            if (!armed) {
                if (Math.abs(e.clientX - origin.x) < THRESHOLD &&
                    Math.abs(e.clientY - origin.y) < THRESHOLD) { return; }
                armed = true;
                // A LABELLED CHIP, not a ghost of the palette row: what you are
                // carrying is "a Text field", and it has to stay legible over a
                // canvas it does not match the width of. `Reorder.mirror()`'s
                // label form is exactly that, so the thing under the pointer is
                // the same object here as it is when you drag a field that
                // already exists — `.palette-proxy` was a second one.
                proxy = window.Reorder.mirror({ label: typeOf(type).label || type });
                canvas.classList.add('is-dropzone');

                // THE LINE IS POSITIONED INSIDE THE CANVAS, so the canvas has
                // to be a containing block for as long as the drag lasts. Set
                // ONCE, here, rather than wherever the marker happens to be
                // built: the marker is torn down and rebuilt every time the
                // pointer crosses the edge, and re-reading the position then
                // reads the `relative` this itself set — so the restore in
                // `cleanup()` would never fire and the canvas would keep a
                // positioning context it did not have before.
                hadStatic = window.getComputedStyle(canvas).position === 'static';
                if (hadStatic) { canvas.style.position = 'relative'; }
            }

            proxy.at(e.clientX + 12, e.clientY + 12);

            // THE DROP ZONE IS THE WHOLE CANVAS COLUMN, NOT THE FIELD LIST.
            // `[data-canvas]` is `.canvas__fields`, which is exactly as tall as
            // the fields in it — so the empty space below the last field, which
            // is the obvious place to aim for and most of what is on screen,
            // was OUTSIDE the drop zone. The marker disappeared and the drop
            // did nothing. Same shape as the reorder defect this file's sibling
            // just fixed: a hit test against a box that stops where the content
            // stops. `.canvas` is the scroll box the person sees.
            var zone = canvas.closest('.canvas') || canvas;
            var over = zone.getBoundingClientRect();
            if (e.clientY >= over.top && e.clientY <= over.bottom &&
                e.clientX >= over.left && e.clientX <= over.right) {
                showMarker(dropPlanAt(e.clientX, e.clientY), e.clientY);
            } else if (marker) {
                // Off the canvas: no line, because there is nowhere it would
                // land. `cleanup()` owns the canvas's positioning for the whole
                // drag, so only the node goes.
                marker.remove();
                marker = null;
            }
        });

        root.addEventListener('pointerup', function (e) {
            if (!origin) { return; }
            var wasArmed = armed, t = type;
            var dropped  = marker && marker.parentNode;
            var at       = dropped ? dropPlanAt(e.clientX, e.clientY).index : -1;
            cleanup();

            // Not a drag — let the click handler append it, exactly as before.
            if (!wasArmed) { return; }

            // A DRAG STILL FIRES A CLICK. The browser dispatches `click` after
            // `pointerup` on the element the press started on, so the palette's
            // click-to-append handler would run as well and the drag would add
            // the field TWICE — once where it was dropped and once at the
            // bottom: `[text, email, textarea]` becoming
            // `[text, tel, email, textarea, tel]`.
            //
            // Suppressed by flag rather than by `preventDefault()` on pointerup,
            // which does not stop the synthesised click, and rather than a
            // capture-phase `click` listener, which would have to know about
            // every other handler on the palette.
            suppressNextClick = true;

            // Armed but released off the canvas: nothing happens, which is what
            // dragging something out of a drop zone means everywhere else.
            if (at < 0) { return; }

            state.fields.splice(at, 0, fresh(t));
            sync();
            select(at, true);
        });

        // A drag interrupted by Escape or a lost pointer must not leave a proxy
        // pinned to the page — it would follow nothing and cover the screen.
        root.addEventListener('pointercancel', cleanup);
        doc.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && origin) { cleanup(); }
        });
    }());

    /* ── 8. EVENTS ─────────────────────────────────────────────────────────── */

    root.addEventListener('click', function (e) {
        // Top tabs — Build / Settings.
        var tab = e.target.closest('[data-tab]');
        if (tab) {
            var which = tab.getAttribute('data-tab');
            root.querySelectorAll('[data-tab]').forEach(function (b) {
                var on = b === tab;
                b.classList.toggle('is-active', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            root.querySelectorAll('[data-pane]').forEach(function (p) {
                p.hidden = p.getAttribute('data-pane') !== which;
            });
            return;
        }

        var rt = e.target.closest('[data-rail-tab]');
        if (rt) { railTab(rt.getAttribute('data-rail-tab')); return; }

        var add = e.target.closest('[data-palette-add]');
        if (add) {
            // The click the browser synthesises at the end of a drag — the field
            // has already been inserted where it was dropped. Appending a second
            // one here would add the field twice.
            if (suppressNextClick) { suppressNextClick = false; return; }
            var f = fresh(add.getAttribute('data-palette-add'));
            state.fields.push(f);
            sync();
            select(state.fields.length - 1, true);
            return;
        }

        // DELETE ON THE FIELD ITSELF. Checked BEFORE the select-the-field branch
        // below, because the button is inside the field and that branch would
        // otherwise swallow the click and merely select it.
        //
        // confirm.js owns the dialog and re-dispatches this click with
        // `data-confirmed` set, so reaching here means the question was answered.
        // The index comes off the FIELD, not off `state.selected` — you can hit a
        // field's bin without selecting it first, and deleting whatever happened
        // to be selected instead is the kind of destructive mis-target there is
        // no undo for.
        var kill = e.target.closest('[data-cfield-delete]');
        if (kill) {
            var kf = kill.closest('[data-cfield]');
            if (!kf) { return; }
            var ki = parseInt(kf.dataset.index, 10);
            state.fields.splice(ki, 1);
            if (state.selected === ki) {
                state.selected = -1;
            } else if (state.selected > ki) {
                state.selected -= 1;
            }
            sync();
            drawCanvas();
            fillOptions();
            return;
        }

        var cf = e.target.closest('[data-cfield]');
        if (cf && !e.target.closest('[data-cfield-grip]')) {
            select(parseInt(cf.dataset.index, 10), false);
            return;
        }

        if (e.target.closest('[data-opt-delete]')) {
            // confirm.js owns the dialog and re-clicks this button with
            // `data-confirmed` set, so reaching here means it was answered.
            state.fields.splice(state.selected, 1);
            state.selected = -1;
            sync();
            drawCanvas();
            fillOptions();
            return;
        }

        if (e.target.closest('[data-logic-add]')) {
            addRuleRow(null);
            readLogic(); sync(); drawCanvas();
            return;
        }

        var rm = e.target.closest('[data-logic-remove]');
        if (rm) {
            rm.closest('[data-logic-rule]').remove();
            readLogic(); sync(); drawCanvas();
            return;
        }

        var embed = e.target.closest('[data-embed-copy]');
        if (embed) {
            copy(embed.getAttribute('data-embed'), embed);
            return;
        }
    });

    function readOptions() {
        var f = state.fields[state.selected];
        if (!f) { return; }
        ['label', 'name', 'placeholder', 'options', 'description', 'text',
         'default', 'width', 'msg_required', 'msg_format',
         'min', 'max', 'step', 'columns', 'scale_low', 'scale_high',
         'source', 'embed_form', 'appt_days', 'appt_from', 'appt_to',
         'appt_minutes', 'appt_ahead'].forEach(function (k) {
            var el = optEl(k);
            if (el) { f[k] = el.value; }
        });
        var maxEl = optEl('maxlength');
        if (maxEl) { f.maxlength = parseInt(maxEl.value, 10) || 0; }
        var reqEl = optEl('required');
        if (reqEl) { f.required = reqEl.checked; }
        readLogic();
    }

    optsBox.addEventListener('input', function (e) {
        if (suppress || state.selected < 0) { return; }
        var f = state.fields[state.selected];

        // TYPING A LABEL FILLS THE KEY ONLY WHILE THE KEY IS UNTOUCHED. Once
        // someone edits it themselves, the label must stop overwriting it.
        if (e.target.matches('[data-opt="label"]')) {
            var nameEl = optEl('name');
            if (nameEl && !nameEl.dataset.touched) {
                nameEl.value = uniqueName(slug(e.target.value), state.selected);
            }
        }
        if (e.target.matches('[data-opt="name"]')) { e.target.dataset.touched = '1'; }

        readOptions();
        if (f.name) { f.name = uniqueName(f.name, state.selected); }
        sync();
        drawCanvas();
    });

    optsBox.addEventListener('change', function (e) {
        if (suppress || state.selected < 0) { return; }

        var enable = e.target.closest('[data-logic-enable]');
        if (enable) {
            var panel = optsBox.querySelector('[data-logic-panel]');
            panel.hidden = !enable.checked;
            // Turning it on with no rules yet gives you one to fill in, rather
            // than an empty panel and a button to hunt for.
            if (enable.checked && !optsBox.querySelector('[data-logic-rule]')) { addRuleRow(null); }
            var tag = optsBox.querySelector('[data-logic-tag]');
            if (tag) { tag.hidden = !enable.checked; }
        }
        if (e.target.matches('[data-logic-op]')) {
            paintRule(e.target.closest('[data-logic-rule]'));
        }

        readOptions();
        sync();
        drawCanvas();
        // The Options textarea changes which choices the canvas draws, and the
        // rail's own visibility rules never change on a value edit — so only the
        // canvas is redrawn, and the caret in the rail is left where it was.
    });

    /* ── 8b. THE PALETTE SEARCH ────────────────────────────────────────────
       Fourteen types is more than fits above the fold, and "which of these is
       the one for a phone number" is a question you answer by typing, not by
       scrolling. Matching on the LABEL only — the storage key (`tel`) is not
       something anyone should have to know to find the Phone field.

       A group whose every button is hidden hides too, so the headings do not
       stack up over an empty column. */
    var search = root.querySelector('[data-palette-search]');
    if (search) {
        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            var any = false;

            root.querySelectorAll('[data-palette-group]').forEach(function (group) {
                var shown = 0;
                group.querySelectorAll('[data-palette-add]').forEach(function (btn) {
                    var hit = q === '' ||
                              btn.getAttribute('data-palette-name').indexOf(q) !== -1;
                    btn.hidden = !hit;
                    if (hit) { shown += 1; }
                });
                group.hidden = shown === 0;
                if (shown) { any = true; }
            });

            var none = root.querySelector('[data-palette-none]');
            if (none) { none.hidden = any; }
        });
    }

    // The submit label and the reply-to list live on the other pane but are
    // previewed on this one.
    root.addEventListener('input', function (e) {
        if (e.target.matches('[name="submit_label"]')) { drawCanvas(); }
    });

    // Keyboard: the canvas fields are focusable buttons, so they answer to
    // Enter and Space. A grid you can only reach with a pointer is not reachable.
    canvas.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') { return; }
        var cf = e.target.closest('[data-cfield]');
        if (!cf) { return; }
        e.preventDefault();
        select(parseInt(cf.dataset.index, 10), false);
    });

    // A final sync before the POST, so a change made without an input event
    // (autofill, a paste handled oddly) still reaches the hidden field.
    root.addEventListener('submit', sync);

    /* ── 9. COPY THE SHORTCODE ─────────────────────────────────────────────
       The async clipboard API is unavailable on an insecure origin, and a
       button that silently does nothing is worse than one that is not there. */
    function copy(text, btn) {
        var was = btn.innerHTML;
        function done() {
            btn.textContent = 'Copied';
            setTimeout(function () { btn.innerHTML = was; }, 1500);
        }
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, fallback);
        } else {
            fallback();
        }
        function fallback() {
            var box = doc.createElement('textarea');
            box.value = text;
            box.setAttribute('readonly', '');
            box.className = 'copy-sink';
            doc.body.appendChild(box);
            box.select();
            try { doc.execCommand('copy'); done(); } catch (err) { /* nothing to say */ }
            box.remove();
        }
    }
}());
