<?php

declare(strict_types=1);

namespace Cms\Forms;

/**
 * THE FIELD TYPE TABLE — one source, three consumers.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 * The builder's palette, the public renderer's switch, and the controller's
 * whitelist all have to agree on what a field type IS. A type list kept in
 * each of them means adding a type by editing every copy and hoping. Offer one
 * the renderer does not handle and it falls through to a plain text box — the
 * builder promises a control the visitor never sees, silently, forever.
 *
 * So the table is here and everything reads it:
 *
 *   · `forms/edit.php`            draws the palette FROM this
 *   · `Shortcode::renderForm()`   asks it what to emit
 *   · `FormController`            rejects a type that is not in it
 *   · `forms-builder.js`          receives it as JSON, for the live canvas
 *
 * A type that is not here cannot be created, cannot be saved and cannot be
 * rendered. That is the point.
 *
 * ── WHAT EACH FLAG MEANS ────────────────────────────────────────────────────
 *   label        what the palette and the summary bar call it
 *   icon         a slug in `www/admin/assets/icon/` — `Icons::render()` logs a
 *                miss by name, so a typo here is reported rather than silently
 *                rendering an invisible button
 *   group        which palette section it sits in
 *   input        false for the two types that collect nothing (note, divider);
 *                the validator and the submission store both skip those
 *   choices      true if it needs the Options textarea
 *   placeholder  true if a placeholder is meaningful — it is not, on anything
 *                the visitor picks rather than types into
 *   multiple     true if it posts an ARRAY of values (checkboxes)
 *   parts        OPTIONAL. A composite: sub-key => sub-label. Name is First and
 *                Last, Address is six lines. Present only on the types that
 *                have them — `parts()` returns [] otherwise, rather than
 *                padding fourteen simple rows with an empty key each to keep a
 *                shape tidy.
 *   join         OPTIONAL. How a composite's answers read back as ONE value:
 *                "Jane Smith", but "12 Mill Lane, Kamloops, BC". The store
 *                holds one value per key and the submissions screen and the
 *                notification email both print it, so the separator is the
 *                field's own business rather than every reader's guess.
 *
 * ── EVERYTHING IN THIS TABLE WORKS ──────────────────────────────────────────
 * Nothing here is greyed out behind an upsell: a control you cannot use is not
 * a feature, it is an advertisement, and this admin has nothing to sell.
 *
 * That is not a reason to lack the fields. Each one arrives working or not at
 * all.
 *
 * THREE FIELDS CANNOT BE BUILT THE USUAL WAY, and that is a decision to take
 * rather than a gap to quietly leave:
 *
 *   Rich Text  needs a rich-text editor in the form itself, AUTHORED here —
 *              not a CDN drop-in.
 *   Captcha    the usual one is reCAPTCHA: a Google script running on every
 *              page the form is embedded in, which the CMS does not ship. The
 *              default is an in-house challenge instead (`Forms\Captcha`).
 *   AI         a per-submission call to an LLM provider is a third-party
 *              request at runtime on a PUBLIC page. The assistant is an
 *              admin-side, budgeted, human-approved path and is not the same
 *              thing.
 *
 * `User ID` is a fourth: it records the SIGNED-IN user, and the CMS has no
 * public accounts, so the control would be empty on every submission of every
 * form. That is the "advertisement" failure wearing a working control's
 * clothes.
 */
final class FieldTypes
{
    /**
     * @var array<string, array{
     *     label: string, icon: string, group: string, input: bool,
     *     choices: bool, placeholder: bool, multiple: bool,
     *     parts?: array<string, string>, join?: string
     * }>
     */
    private const TABLE = [
        // ── Basic: things the visitor types into ────────────────────────────
        'text'     => ['label' => 'Text',        'icon' => 'pen',        'group' => 'basic',  'input' => true,  'choices' => false, 'placeholder' => true,  'multiple' => false],
        'textarea' => ['label' => 'Paragraph',   'icon' => 'align-left', 'group' => 'basic',  'input' => true,  'choices' => false, 'placeholder' => true,  'multiple' => false],
        'email'    => ['label' => 'Email',       'icon' => 'envelope',   'group' => 'basic',  'input' => true,  'choices' => false, 'placeholder' => true,  'multiple' => false],
        'tel'      => ['label' => 'Phone',       'icon' => 'phone',      'group' => 'basic',  'input' => true,  'choices' => false, 'placeholder' => true,  'multiple' => false],
        'url'      => ['label' => 'Website',     'icon' => 'link',       'group' => 'basic',  'input' => true,  'choices' => false, 'placeholder' => true,  'multiple' => false],
        'number'   => ['label' => 'Number',      'icon' => 'hashtag',    'group' => 'basic',  'input' => true,  'choices' => false, 'placeholder' => true,  'multiple' => false],
        'date'     => ['label' => 'Date',        'icon' => 'calendar',   'group' => 'basic',  'input' => true,  'choices' => false, 'placeholder' => false, 'multiple' => false],

        'time'     => ['label' => 'Time',     'icon' => 'clock',         'group' => 'basic', 'input' => true, 'choices' => false, 'placeholder' => false, 'multiple' => false],
        'password' => ['label' => 'Password', 'icon' => 'shield-halved', 'group' => 'basic', 'input' => true, 'choices' => false, 'placeholder' => true,  'multiple' => false],

        // ── Composite: ONE field, several boxes ─────────────────────────────
        // Name and Address are composites. Without them, the only way to ask
        // for a name is two Text fields — which stores two answers, prints
        // them as two rows of the submissions table, and leaves every reader
        // to know that they belong together.
        //
        // `parts` is what makes them one field: one label, one required rule,
        // one stored answer, and a box per part with its own quiet sub-label
        // underneath. Those sub-labels are the reason a composite beats two
        // fields — "First" and "Last" under two boxes says what the boxes are
        // for without spending a heading on each.
        'name'    => ['label' => 'Name',    'icon' => 'user',         'group' => 'basic', 'input' => true, 'choices' => false, 'placeholder' => true,  'multiple' => false,
                      'parts' => ['first' => 'First', 'last' => 'Last'],
                      'join'  => ' '],
        'address' => ['label' => 'Address', 'icon' => 'location-dot', 'group' => 'basic', 'input' => true, 'choices' => false, 'placeholder' => false, 'multiple' => false,
                      'parts' => ['line1' => 'Street address', 'line2' => 'Line 2',
                                  'city' => 'City', 'region' => 'Province or state',
                                  'postcode' => 'Postal code', 'country' => 'Country'],
                      'join'  => ', '],

        // ── Choice: things the visitor picks ────────────────────────────────
        // NO PLACEHOLDER on any of them. A placeholder on a control you cannot
        // type into is either invisible or a fake first option, and the second
        // one is how a required dropdown ends up submittable while empty.
        'select'     => ['label' => 'Dropdown',      'icon' => 'caret-down', 'group' => 'choice', 'input' => true, 'choices' => true,  'placeholder' => false, 'multiple' => false],
        'radio'      => ['label' => 'Radio buttons', 'icon' => 'radio',      'group' => 'choice', 'input' => true, 'choices' => true,  'placeholder' => false, 'multiple' => false],
        'checkbox'   => ['label' => 'Checkbox',      'icon' => 'check',      'group' => 'choice', 'input' => true, 'choices' => false, 'placeholder' => false, 'multiple' => false],
        'checkboxes' => ['label' => 'Checkboxes',    'icon' => 'list-check', 'group' => 'choice', 'input' => true, 'choices' => true,  'placeholder' => false, 'multiple' => true],

        // A TOGGLE IS NOT A CHECKBOX WEARING A SKIN. A checkbox is "tick this
        // if it applies"; a toggle is a setting that is on or off and reads as
        // a state either way. Both ship for that reason, and the stored value
        // is the same '1' or '' so nothing downstream has to care.
        'toggle'     => ['label' => 'Toggle',        'icon' => 'toggle-on',  'group' => 'choice', 'input' => true, 'choices' => false, 'placeholder' => false, 'multiple' => false],

        // A SLIDER IS A NUMBER YOU CANNOT MISTYPE. Min, max and step come from
        // the same three boxes the Number field uses, so there is no second set
        // of controls to keep in step.
        'range'      => ['label' => 'Slider',        'icon' => 'sliders',    'group' => 'choice', 'input' => true, 'choices' => false, 'placeholder' => false, 'multiple' => false],

        // ── Graded choices: pick one point on a scale ───────────────────────
        // All three are radios underneath, which is what makes them honest:
        // one answer, forgeable only into a value the server already checks,
        // and readable with no JavaScript at all. What differs is the SHAPE —
        // a row of numbers, a row of stars, a grid of statements — and the
        // shape is the whole point, because "how much" is a question people
        // answer faster by position than by reading.
        'scale'      => ['label' => 'Scale',         'icon' => 'list-ol',     'group' => 'choice', 'input' => true, 'choices' => false, 'placeholder' => false, 'multiple' => false],
        'rating'     => ['label' => 'Star rating',   'icon' => 'star',        'group' => 'choice', 'input' => true, 'choices' => false, 'placeholder' => false, 'multiple' => false],

        // A LIKERT IS A SCALE ASKED SEVERAL TIMES. Each ROW is a statement and
        // posts its own answer — `name[row]` — so `parts` describes it exactly
        // as it describes Name, and the composite machinery carries it. The
        // rows come from the Options list, which is the control the author
        // already knows.
        'likert'     => ['label' => 'Likert scale',  'icon' => 'table-cells', 'group' => 'choice', 'input' => true, 'choices' => true,  'placeholder' => false, 'multiple' => false],

        // RANKING IS A DRAG, AND IT IS THE SAME DRAG. The visitor puts the
        // options in their order, driven by the reorder.js the builder and the
        // gallery already use — served publicly for this, identical to the
        // admin copy. A second implementation for the front end would be one
        // more drag system to keep in step with the first.
        //
        // It posts an ARRAY, in order, like checkboxes — `multiple` is what
        // tells the validator and the store to expect one.
        'ranking'    => ['label' => 'Ranking',       'icon' => 'grip-lines',  'group' => 'choice', 'input' => true, 'choices' => true,  'placeholder' => false, 'multiple' => true],

        // ── OPTIONS FROM THE SITE'S OWN CONTENT ─────────────────────────────
        // Dynamic list and Lookup both ask a Content Type for its entries
        // instead of making the author retype them: "which event are you
        // booking" is a list that changes every week, and a hand-typed one is
        // wrong the moment somebody adds an event.
        //
        // `source` names the type — the same key the Registry uses — so this
        // reads what the site HAS rather than a second copy of it. There is no
        // Options textarea, which is the point: `choices` is false because the
        // choices are not the author's to type.
        //
        // The two differ only in how many you may pick. They are separate
        // fields because "one of these" and "as many as apply" are different
        // questions and the author should not have to notice a checkbox to ask
        // the right one.
        'dynamic'    => ['label' => 'Dynamic list',  'icon' => 'folder-tree', 'group' => 'choice', 'input' => true, 'choices' => false, 'placeholder' => false, 'multiple' => false],
        'lookup'     => ['label' => 'Lookup',        'icon' => 'list-ul',     'group' => 'choice', 'input' => true, 'choices' => false, 'placeholder' => false, 'multiple' => true],

        // ── REPEATING ROWS ──────────────────────────────────────────────────
        // "List everyone attending" is one question with an unknown number of
        // answers. Asked as five Text fields it is wrong for the party of two
        // and wrong for the party of nine; asked as one textarea it arrives as
        // prose nobody can sort.
        //
        // The Options list names the COLUMNS — one label per line — and the
        // visitor adds as many rows as they need. It posts `name[row][column]`,
        // which is the array shape the composites already established.
        'repeater'   => ['label' => 'Repeating rows', 'icon' => 'table-cells', 'group' => 'choice', 'input' => true, 'choices' => true, 'placeholder' => false, 'multiple' => true],

        // ── AN APPOINTMENT ──────────────────────────────────────────────────
        // The visitor picks a time the owner is free, and that time stops being
        // offered to anybody else. `Forms\Appointments` derives the slots from
        // a shape the owner sets — which weekdays, between which hours, how
        // long each one is — and stores only the BOOKINGS, because a booking is
        // a fact about the world and an empty slot is not.
        //
        // Radios rather than a date picker plus a time dropdown: the second
        // needs the first to have been chosen, which means a round trip or a
        // script, and this way the whole calendar is on the page and works
        // with neither.
        'appointment' => ['label' => 'Appointment', 'icon' => 'calendar', 'group' => 'basic', 'input' => true, 'choices' => false, 'placeholder' => false, 'multiple' => false],

        // TAGS — several short answers in one field, typed rather than picked.
        // A free-text chip box: the visitor types and presses Enter or comma.
        // It posts an ARRAY like checkboxes, and it has NO options list, which
        // is the difference: a checkbox set is a closed question and this is
        // an open one.
        'tags'       => ['label' => 'Tags',          'icon' => 'tags',        'group' => 'choice', 'input' => true, 'choices' => false, 'placeholder' => true,  'multiple' => true],

        // ── A FILE, FROM A STRANGER ─────────────────────────────────────────
        // `Forms\Upload` owns what is allowed and why: a narrower list than the
        // admin's (no SVG, no archives), a smaller ceiling, and PRIVATE
        // storage, because somebody attaching a CV to a job form has not
        // agreed to publish it.
        'file'       => ['label' => 'File upload',   'icon' => 'paperclip',   'group' => 'basic', 'input' => true, 'choices' => false, 'placeholder' => false, 'multiple' => false],

        // A SIGNATURE IS A FILE THE VISITOR DRAWS. It is captured on a canvas,
        // posted as a PNG data URL and stored through the same path — so it is
        // in the media library, private, beside every other uploaded file,
        // rather than a base64 blob wedged into the submissions table.
        'signature'  => ['label' => 'Signature',     'icon' => 'pen',         'group' => 'basic', 'input' => true, 'choices' => false, 'placeholder' => false, 'multiple' => false],

        // ── Layout and hidden ───────────────────────────────────────────────
        // `note` IS ESCAPED TEXT, NOT HTML. An HTML field in a form builder is
        // a stored-XSS surface that fires on every visitor. Every user-supplied
        // value is escaped at output, and an admin is still a user. The text
        // is escaped and newlines become breaks, which covers what a note is
        // for.
        'note'    => ['label' => 'Text block', 'icon' => 'quote-left', 'group' => 'layout', 'input' => false, 'choices' => false, 'placeholder' => false, 'multiple' => false],
        'divider' => ['label' => 'Divider',    'icon' => 'minus',      'group' => 'layout', 'input' => false, 'choices' => false, 'placeholder' => false, 'multiple' => false],

        // A SECTION IS A DIVIDER THAT SAYS WHAT COMES NEXT — a heading, and
        // optionally a line of explanation, for the block of fields under it.
        // A long form without them is one undifferentiated column; `divider`
        // draws the line but cannot name what it separates.
        'section' => ['label' => 'Section',    'icon' => 'bars',       'group' => 'layout', 'input' => false, 'choices' => false, 'placeholder' => false, 'multiple' => false],

        // SUMMARY — the answers read back before the visitor commits. It
        // collects nothing itself, which is why `input` is false: it is a
        // VIEW of the other fields, filled in by `summary.js` as they are
        // typed. On a long form it is the difference between submitting and
        // scrolling back up to check.
        'summary' => ['label' => 'Summary',    'icon' => 'list-ul',    'group' => 'layout', 'input' => false, 'choices' => false, 'placeholder' => false, 'multiple' => false],

        // A PAGE BREAK CUTS THE FORM IN TWO. Everything after it is a second
        // step, with Back and Next between them and a bar saying where you
        // are. A twenty-field form asked all at once is a wall; the same form
        // in three steps is three questions.
        //
        // `input: false` — it collects nothing. Its whole effect is on layout,
        // and `pages.js` is what enacts it.
        'pagebreak' => ['label' => 'Page break', 'icon' => 'grip-lines', 'group' => 'layout', 'input' => false, 'choices' => false, 'placeholder' => false, 'multiple' => false],

        // EMBED FORM — another form's fields, inline. A block of questions
        // reused across five forms (a full address, a set of consent
        // questions) is maintained once and appears everywhere, rather than
        // being copied and then diverging.
        //
        // `input: false`: it collects nothing ITSELF. The fields it pulls in
        // do, under their own names, so the submission has no idea it was
        // assembled from two definitions — which is the point.
        'embed'   => ['label' => 'Embed form', 'icon' => 'list-check', 'group' => 'layout', 'input' => false, 'choices' => false, 'placeholder' => false, 'multiple' => false],
        'hidden'  => ['label' => 'Hidden',     'icon' => 'lock',       'group' => 'layout', 'input' => true,  'choices' => false, 'placeholder' => false, 'multiple' => false],

        // A CAPTCHA COLLECTS NOTHING WORTH KEEPING. Its answer proves the
        // visitor is a person and is then thrown away — storing it would put a
        // column of "7"s in the submissions table. `input: false` is what
        // keeps it out of the store; `Forms\Captcha` does the checking, before
        // any field is looked at.
        'captcha' => ['label' => 'Captcha',    'icon' => 'shield-halved', 'group' => 'layout', 'input' => false, 'choices' => false, 'placeholder' => false, 'multiple' => false],
    ];

    /** @var array<string, string> group key => the heading the palette prints */
    public const GROUPS = [
        'basic'  => 'Basic fields',
        'choice' => 'Choices',
        'layout' => 'Layout',
    ];

    /**
     * The whole table, in palette order — minus the types of a switched-off
     * module (`Helpers\Modules`): with `appointments` off, the builder does
     * not offer the Appointment field and will not save a new one.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $table = self::TABLE;
        if (!\Cms\Helpers\Modules::enabled('appointments')) {
            unset($table['appointment']);
        }

        return $table;
    }

    public static function exists(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    /**
     * One type's row, falling back to `text`.
     *
     * The FALLBACK IS THE POINT: a form saved before a type was removed still
     * has to render. Returning null here would mean every caller writing the
     * same null check, and the one that forgot would fatal on a live page.
     *
     * @return array<string, mixed>
     */
    public static function get(string $type): array
    {
        return self::TABLE[$type] ?? self::TABLE['text'];
    }

    public static function label(string $type): string
    {
        return (string) self::get($type)['label'];
    }

    /** Does this type collect a value at all? `note` and `divider` do not. */
    public static function collects(string $type): bool
    {
        return (bool) self::get($type)['input'];
    }

    /** Does this type post an array rather than a scalar? */
    public static function isMultiple(string $type): bool
    {
        return (bool) self::get($type)['multiple'];
    }

    /** Does this type read the Options list? */
    public static function hasChoices(string $type): bool
    {
        return (bool) self::get($type)['choices'];
    }

    /** The choice types whose options can store a value other than their label. */
    public const LABELLED = ['select', 'radio', 'checkboxes'];

    /**
     * A choice's options as label and value:
     * one per line, "label | value" when what is stored differs from what is
     * shown ("Kamloops + region | Kamloops + Thompson-Okanagan"), otherwise the
     * line is both. The first bar splits; a blank value is the label. Blank
     * lines are dropped.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function optionPairs(string $options): array
    {
        $pairs = [];
        foreach (explode("\n", $options) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $bar   = strpos($line, '|');
            $label = $bar === false ? $line : trim(substr($line, 0, $bar));
            $value = $bar === false ? '' : trim(substr($line, $bar + 1));
            if ($label === '') {
                $label = $value;
            }
            $pairs[] = ['label' => $label, 'value' => $value !== '' ? $value : $label];
        }

        return $pairs;
    }

    /**
     * A composite's sub-fields, sub-key => sub-label, or [] for a simple type.
     *
     * @return array<string, string>
     */
    public static function parts(string $type): array
    {
        $parts = self::get($type)['parts'] ?? [];

        return is_array($parts) ? $parts : [];
    }

    /**
     * `isComposite()` is deliberately "has parts" rather than its own flag: two
     * facts that must agree are one fact somebody forgets to update.
     */
    public static function isComposite(string $type): bool
    {
        return self::parts($type) !== [];
    }

    /**
     * Does this type take its options from the site's own content?
     *
     * Its `source` names a Content Type, and `Forms\Sources` turns that into a
     * list. Kept here rather than at each call site so the renderer, the
     * validator and the builder cannot disagree about which types are which.
     */
    public static function isSourced(string $type): bool
    {
        return in_array($type, ['dynamic', 'lookup'], true);
    }

    /** How a composite's answers read back as one stored value. */
    public static function join(string $type): string
    {
        return (string) (self::get($type)['join'] ?? ', ');
    }

    /**
     * The table as JSON for the builder.
     *
     * The palette, the summary bars and the live canvas all need to know a
     * type's label and flags, and the alternative — a second copy of this table
     * in JavaScript — is the drift this class exists to prevent.
     */
    public static function toJson(): string
    {
        return (string) json_encode(self::TABLE);
    }
}
