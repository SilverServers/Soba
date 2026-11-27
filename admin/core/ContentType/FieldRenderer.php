<?php

declare(strict_types=1);

namespace Cms\ContentType;

use Cms\Html\Sanitizer;
use Cms\Ui\FieldWidth;
use Cms\Ui\Icons;
use Cms\Forms\Logic;
use Cms\Ui\View;

/**
 * FieldRenderer — renders, sanitizes, and validates a Content Type's fields.
 *
 * Supports: text, textarea, rich_text, number, date, datetime, select, image,
 * repeater, relationship, taxonomy.
 *
 * Used by:
 *   - the generic edit template  (renderField  → the labelled control)
 *   - ContentTypeController      (sanitizeField → a clean value)
 *   - its validation pass        (validateField → an error string or null)
 *
 * POST key convention: `field_{key}` — e.g. `field_event_date`, `field_speakers`.
 *
 * ── WHY THE WHOLE RENDER HALF LIVES HERE ────────────────────────────────────
 * The alternative is to render the simple types (text, textarea, number, date,
 * select …) inline in `content-type/edit.php` and keep this class only for the
 * compound ones. But `renderField()`, `sanitizeField()` and `validateField()`
 * are three `match` statements over the SAME `$field['type']` vocabulary.
 * Moving one arm of one of them into a template creates a second registry
 * keyed on the same value, in a different file, that nothing forces to stay in
 * step. Add a field type here and all three arms are in front of you.
 *
 * ── THE MARKUP IT EMITS ─────────────────────────────────────────────────────
 * The admin stylesheets' own vocabulary: `.field` / `.label` / `.input` /
 * `.select` / `.textarea` / `.form-help`, plus the three compound controls in
 * `admin.css` §5. A class the stylesheets do not define renders structurally
 * correct and completely UNSTYLED, which is invisible rather than broken.
 *
 * `rich_text` is a textarea that `www/admin/assets/js/editor.js` binds a toolbar
 * and an editing surface to. The editor is authored here and pulls nothing from
 * anywhere. The textarea is what posts — see `renderRichText()`.
 */
class FieldRenderer
{
    // ----------------------------------------------------------------
    // Render
    // ----------------------------------------------------------------

    /**
     * The labelled control for a single field.
     *
     *     <?= FieldRenderer::renderField($field, $value) ?>
     *
     * @param array<string, mixed> $field Field definition from the Content Type
     * @param mixed                $value Current saved value (string, array, or null)
     */
    public static function renderField(array $field, mixed $value): string
    {
        return self::renderNamed($field, 'field_' . $field['key'], $value);
    }

    /**
     * The same labelled control, posting under a name of the caller's own: a
     * site's tool draws an entry's picture field this way (`Site\Tools::mediaPicker()`).
     *
     * @param array<string, mixed> $field
     */
    public static function renderNamed(array $field, string $name, mixed $value): string
    {
        return self::wrap($field, $name, self::control($field, $name, $value));
    }

    /**
     * A CHILD'S FIELD IN ITS ROW on its parent's editor (`Ui\ChildRows`),
     * posting under the row's own name. The row is saved by itself and checked
     * there by the child's own rules, so its boxes carry no `required` for the
     * parent's Save to stop at, though the label still says Required, and no
     * logic for the parent's form to apply to its own fields.
     *
     * @param array<string, mixed> $field
     */
    public static function renderRow(array $field, string $name, mixed $value): string
    {
        unset($field['logic']);
        $field['_row'] = true;

        return self::wrap($field, $name, self::control($field, $name, $value));
    }

    /**
     * `.field` + `.label` + the control + its `.form-help`.
     *
     * The label is a real `<label for>` only for the types that ARE one control.
     * A compound control — the media picker, a repeater, a chip-picker — has no
     * single element for `for` to point at, and a `for` aimed at the hidden
     * input inside a media picker is a click target that focuses nothing. Those
     * get a `<span class="label">`, which is the same 12px caps line and an
     * honest one.
     */
    private static function wrap(array $field, string $name, string $control): string
    {
        $label    = (string) ($field['label'] ?? $field['key']);
        $help     = trim((string) ($field['description'] ?? $field['instructions'] ?? ''));
        $compound = in_array($field['type'] ?? 'text', ['image', 'file', 'gallery', 'repeater', 'relationship'], true)
            || (($field['type'] ?? '') === 'taxonomy' && !self::taxonomyIsOneSelect($field));

        // ── CONDITIONAL LOGIC AND WIDTH ─────────────────────────────────────
        // `data-logic` carries the rule set as JSON and `logic.js` reads it, the
        // same attribute the public form's renderer emits — one engine, one wire
        // shape, two consumers.
        //
        // THE FIELD IS RENDERED VISIBLE and the script hides it on load, rather
        // than the server pre-hiding it. The answer depends on values the author
        // has not entered yet, and rendering hidden-then-revealed means an entry
        // form with JavaScript off is MISSING fields — a form that cannot be
        // completed. This way it shows all of them, which is one that can.
        $logic = Logic::parse($field['logic'] ?? null);
        $attrs = '';
        if ($logic !== null) {
            $attrs .= ' data-logic=\'' . View::escape((string) json_encode($logic)) . '\'';
        }
        // WIDTH IS A SPAN OF TWELVE, THE SAME TWELVE THE PUBLIC FORM USES.
        // A two-column grid, where full spans both columns and `half` is simply
        // the default cell, works for exactly two widths and cannot express a
        // third or a quarter, because halves and thirds share no denominator
        // below twelve. The container is `.form-grid--12` and the modifier is
        // the same word the public renderer appends, so one setting reads one
        // way on both surfaces.
        //
        // A COMPOUND CONTROL ALWAYS SPANS. A repeater or a media picker in a
        // half-width cell is a control squeezed into 300px with its own inner
        // grid inside it; the setting is honoured where it makes sense and
        // overruled where it does not, rather than offered and ignored. That
        // goes double at a quarter.
        $compoundWidth = in_array($field['type'] ?? 'text',
                                  ['repeater', 'image', 'file', 'gallery', 'relationship'], true);
        $modifier = $compoundWidth ? '' : FieldWidth::modifier($field['width'] ?? null);
        $class = 'field' . ($modifier === '' ? '' : ' field' . $modifier);

        $html = '<div class="' . $class . '"' . $attrs . '>';

        // ── A CHECKBOX CARRIES ITS OWN LABEL, BESIDE THE BOX ────────────────
        // Every other control here is a box under a caps-line label. A tickbox
        // is not: the word belongs NEXT to it, where it is also the click
        // target, which is what `.check-list__row` is for and what the payment
        // methods and the tax screen already look like. Printing the wrapper's
        // label as well would say "Needs delivering" twice, once above the row
        // and once in it.
        if (($field['type'] ?? '') === 'checkbox') {
            $html .= $control;
            if ($help !== '') {
                $html .= '<p class="form-help">' . View::escape($help) . '</p>';
            }

            return $html . '</div>';
        }

        $html .= $compound
            ? '<span class="label">'
            : '<label class="label" for="' . self::id($name) . '">';
        $html .= View::escape($label);

        // REQUIRED IS A WORD, NOT A RED ASTERISK. Alert colour is a standalone
        // surface and never a state, and a muted asterisk is a typed character
        // standing in for information nobody can see.
        if (!empty($field['required'])) {
            $html .= '<span class="label__req">required</span>';
        }

        $html .= $compound ? '</span>' : '</label>';
        $html .= $control;

        if ($help !== '') {
            $html .= '<p class="form-help">' . View::escape($help) . '</p>';
        }

        return $html . '</div>';
    }

    /**
     * A STORED PLAIN-TEXT VALUE AS THE OWNER TYPED IT.
     *
     * `sanitizeField()` used to store `htmlspecialchars(strip_tags(…))`, and
     * every place that printed the value escaped it again — this form's own
     * inputs included. So "Tom & Jerry's" came back into the box as
     * `Tom &amp; Jerry&#039;s`, and saving the form stored ANOTHER layer:
     * `&amp;amp;amp;` after three saves. Values are stored plain now and
     * escaped once, where they are printed.
     *
     * What was stored before is decoded here until it stops changing, so an
     * old value reads right everywhere and is saved back plain the next time
     * its form is saved. FOR PLAIN-TEXT TYPES ONLY — never rich text, which is
     * HTML (decoding its `&lt;` would make markup), and never a JSON value,
     * whose `&quot;` would break the JSON.
     */
    public static function plain(mixed $stored): string
    {
        $text = (string) ($stored ?? '');
        for ($i = 0; $i < 8; $i++) {
            $once = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($once === $text) {
                break;
            }
            $text = $once;
        }

        return $text;
    }

    /** The types whose stored value is HTML or JSON, which `plain()` must not touch. */
    private const NOT_PLAIN = ['rich_text', 'image', 'file', 'gallery', 'repeater', 'relationship', 'taxonomy'];

    /** Dispatch to the per-type control. Sub-fields of a repeater come through here too. */
    private static function control(array $field, string $name, mixed $value): string
    {
        // The box shows what was typed — see `plain()`.
        if ($value !== null && !in_array($field['type'] ?? 'text', self::NOT_PLAIN, true)) {
            $value = self::plain($value);
        }

        return match ($field['type'] ?? 'text') {
            'textarea'     => self::renderTextarea($name, $value, $field),
            'rich_text'    => self::renderRichText($name, $value, $field),
            'number'       => self::renderNumber($name, $value, $field),
            'phone'        => self::renderPhone($name, $value, $field),
            'email'        => self::renderEmail($name, $value, $field),
            'date'         => self::renderDate($name, $value, $field),
            'datetime'     => self::renderDatetime($name, $value, $field),
            'select'       => self::renderSelect($name, $value, $field),
            'checkbox'      => self::renderCheckbox($name, $value, $field),
            'image'        => self::renderImage($name, $value),
            'file'         => self::renderFile($name, $value, $field),
            'gallery'      => self::renderGallery($name, $value, $field),
            'repeater'     => self::renderRepeater($name, $value, $field),
            'relationship' => self::renderRelationship($name, $value, $field),
            'taxonomy'     => self::renderTaxonomy($name, $value, $field),
            default        => self::renderText($name, $value, $field),
        };
    }

    // ----------------------------------------------------------------
    // The single-control types — no new CSS, all of it is core.css §4
    // ----------------------------------------------------------------

    /**
     * A single yes/no.
     *
     * ── WITHOUT ITS OWN ARM, A CHECKBOX IS A TEXT BOX ───────────────────────
     * `ArchetypeDefaults` seeds `'type' => 'checkbox'` for
     * `product_manage_stock` and `product_needs_delivery`. With no arm for it,
     * the `match` above falls through to `default` and renders a TEXT BOX: an
     * owner turning stock counting on types "yes" into it, `Stock` compares
     * against `'1'`, and counting never comes on. Nothing is malformed, no
     * guard fires, and the screen looks finished.
     *
     * ── THE HIDDEN INPUT IS WHAT MAKES "OFF" REACH THE SERVER ───────────────
     * An unchecked box posts NOTHING. Without the `0` in front of it, unticking
     * would leave the previous `1` in the database — the value would be
     * write-once, and an owner could turn counting on and never off again.
     */
    private static function renderCheckbox(string $name, mixed $value, array $field): string
    {
        $on = (string) ($value ?? '') === '1';

        return '<input type="hidden" name="' . View::escape($name) . '" value="0">'
            . '<label class="check-list__row">'
            . '<input type="checkbox" class="checkbox" value="1"'
            . self::common($name, $field)
            . ($on ? ' checked' : '')
            . '>'
            . '<span>' . View::escape((string) ($field['label'] ?? '')) . '</span>'
            . '</label>';
    }

    private static function renderText(string $name, mixed $value, array $field): string
    {
        return '<input type="text" class="input"'
            . self::common($name, $field)
            . ' value="' . View::escape((string) ($value ?? '')) . '"'
            . self::placeholder($field)
            . '>';
    }

    /**
     * A PHONE NUMBER, as typed, in a box a phone opens its number pad for.
     * Checked on save by the rule Business details use, and printed as a link
     * to call it (`FieldDisplay`).
     */
    private static function renderPhone(string $name, mixed $value, array $field): string
    {
        return '<input type="tel" class="input"'
            . self::common($name, $field)
            . ' value="' . View::escape((string) ($value ?? '')) . '"'
            . self::placeholder($field)
            . '>';
    }

    /** AN EMAIL ADDRESS, checked as a form's email field is, and printed as a link to write to. */
    private static function renderEmail(string $name, mixed $value, array $field): string
    {
        return '<input type="email" class="input"'
            . self::common($name, $field)
            . ' value="' . View::escape((string) ($value ?? '')) . '"'
            . self::placeholder($field)
            . '>';
    }

    private static function renderTextarea(string $name, mixed $value, array $field): string
    {
        return '<textarea class="textarea" rows="' . (int) ($field['rows'] ?? 4) . '"'
            . self::common($name, $field)
            . self::placeholder($field)
            . '>' . View::escape((string) ($value ?? '')) . '</textarea>';
    }

    /**
     * A TEXTAREA THAT THE EDITOR BINDS TO.
     *
     * `data-editor` is the hook for the rich-text editor, which is authored
     * here — `www/admin/assets/js/editor.js`, no dependency.
     *
     * THE TEXTAREA IS WHAT POSTS. `editor.js` hides it and writes the editing
     * surface's HTML back into its value on every keystroke, so with JavaScript
     * off, or if the script fails to load, this is a plain textarea that works.
     *
     * The value is cleaned by `Html\Sanitizer` in `sanitizeValue()` below, not
     * by the editor. A browser control protects nobody.
     */
    private static function renderRichText(string $name, mixed $value, array $field): string
    {
        return '<textarea class="textarea" rows="' . (int) ($field['rows'] ?? 12) . '" data-editor'
            . self::common($name, $field)
            . '>' . View::escape((string) ($value ?? '')) . '</textarea>';
    }

    private static function renderNumber(string $name, mixed $value, array $field): string
    {
        // DECIMALS NAME THE STEP when the field names none: two places is 0.01,
        // so 1.25 is a number here and 1.255 is not, as the box says when the
        // form is checked.
        $step   = isset($field['step']) && $field['step'] !== '' ? (string) $field['step'] : null;
        $places = isset($field['decimals']) && is_numeric($field['decimals']) ? max(0, min(6, (int) $field['decimals'])) : null;
        // A PRICE THE SHOP READS takes the places its currency has, when it
        // names no step of its own (`Commerce\Product::pricePlaces()`): 24.99
        // in dollars, 1200 in yen. A box with no step takes whole numbers alone.
        if ($step === null && $places === null) {
            $places = \Cms\Commerce\Product::pricePlaces($field);
        }
        if ($step === null && $places !== null) {
            $step = $places === 0 ? '1' : '0.' . str_repeat('0', $places - 1) . '1';
        }
        $input = '<input type="number" class="input"'
            . self::common($name, $field)
            . ' value="' . View::escape((string) ($value ?? '')) . '"'
            . (isset($field['min']) ? ' min="' . View::escape((string) $field['min']) . '"' : '')
            . (isset($field['max']) ? ' max="' . View::escape((string) $field['max']) . '"' : '')
            . ($step !== null ? ' step="' . View::escape($step) . '"' : '')
            . '>';

        // A PREFIX, such as $, in front of the box: shown, never kept.
        $prefix = trim((string) ($field['prefix'] ?? ''));

        return $prefix === '' ? $input
            : '<div class="prefixed"><span class="prefixed__prefix">' . View::escape($prefix) . '</span>' . $input . '</div>';
    }

    private static function renderDate(string $name, mixed $value, array $field): string
    {
        return '<input type="date" class="input"'
            . self::common($name, $field)
            . ' value="' . View::escape((string) ($value ?? '')) . '">';
    }

    private static function renderDatetime(string $name, mixed $value, array $field): string
    {
        // The column is `Y-m-d H:i:s`; the control wants `Y-m-dTH:i`. Handing the
        // space-separated form to a datetime-local input makes it render EMPTY —
        // a saved value that silently disappears from its own edit form, and
        // then posts back as null on the next save.
        $v = (string) ($value ?? '');
        if ($v !== '' && str_contains($v, ' ')) {
            $v = substr(str_replace(' ', 'T', $v), 0, 16);
        }

        return '<input type="datetime-local" class="input"'
            . self::common($name, $field)
            . ' value="' . View::escape($v) . '">';
    }

    /** SINGLE-SELECT only. Multi-select is the chip-picker. */
    private static function renderSelect(string $name, mixed $value, array $field): string
    {
        $selected = (string) ($value ?? '');

        $html = '<select class="select"' . self::common($name, $field) . '>';

        // A LABELLED no-value option, not an empty one. An empty `<option>`
        // renders as a blank box, and a blank box does not distinguish "nothing
        // is chosen" from "there is nothing to choose" — which is exactly the
        // ambiguity `.placeholder`'s em-dash exists to remove everywhere else in
        // the system. `None` is a value, not an instruction.
        if (empty($field['required'])) {
            $html .= '<option value="">None</option>';
        }

        foreach (($field['options'] ?? []) as $optValue => $optLabel) {
            $html .= '<option value="' . View::escape((string) $optValue) . '"'
                . ($selected === (string) $optValue ? ' selected' : '') . '>'
                . View::escape((string) $optLabel) . '</option>';
        }

        return $html . '</select>';
    }

    // ----------------------------------------------------------------
    // The compound controls — admin.css §5, entry-form.js
    // ----------------------------------------------------------------

    /**
     * The media picker.
     *
     * A THUMBNAIL IS THE CONTROL. The value stored is an attachment id, and an
     * id is exactly the kind of fact a person cannot check by reading it: "42"
     * tells the owner of the site nothing about which photograph is on their
     * front page. So the picker shows the image and keeps the id in a hidden
     * input; when nothing is chosen it shows the KIND OF THING that goes here,
     * as a glyph, rather than an empty box.
     *
     * `entry-form.js` opens a `<dialog class="modal">` over `/api/v1/media`.
     * With JavaScript off the control degrades to "shows you what is currently
     * attached", which is more than the old numeric input managed with it on.
     */
    public static function renderImage(string $name, mixed $value): string
    {
        $attachmentId = (int) ($value ?? 0);
        $url          = $attachmentId > 0 ? \Cms\Models\Media::getUrl($attachmentId) : '';
        $chooseId     = self::id($name) . '-choose';

        // The glyph comes back when the picture is removed (`entry-form.js`).
        $html  = '<div class="media-picker" data-media-picker'
               . ' data-empty-glyph="' . View::escape(Icons::render('image', 'media-picker__glyph', '', 22)) . '">';
        $html .= '<input type="hidden" name="' . View::escape($name) . '"'
               . ' value="' . $attachmentId . '" data-media-value>';

        $html .= '<div class="media-picker__preview" data-media-preview>';
        $html .= $url !== ''
            ? '<img src="' . View::escape($url) . '" alt="">'
            : Icons::render('image', 'media-picker__glyph', '', 22);
        $html .= '</div>';

        $html .= '<div class="media-picker__actions">';
        $html .= '<button type="button" class="btn" id="' . $chooseId . '" data-media-choose>'
               . ($url !== '' ? 'Replace' : 'Choose') . '</button>';
        $html .= '<button type="button" class="btn btn--ghost" data-media-clear'
               . ($url !== '' ? '' : ' hidden') . '>Remove</button>';
        $html .= '</div>';

        return $html . '</div>';
    }

    /**
     * A FILE FROM THE LIBRARY: a PDF, an Office document, any kind the library
     * takes, narrowed by the field's File types (`accept`, as a form's file
     * field is), kept by its id so a file renamed in the library is followed.
     * Drawn as the file's name, a link to it, and its size, with Choose or
     * Replace and Remove; `entry-form.js` opens the library over every kind of
     * file, and uploads one, in pieces when it is large.
     *
     * @param array<string, mixed> $field
     */
    public static function renderFile(string $name, mixed $value, array $field): string
    {
        $id     = (int) ($value ?? 0);
        $file   = $id > 0 ? \Cms\Models\Media::file($id) : null;
        $kinds  = \Cms\Models\Media::kindsFor((string) ($field['accept'] ?? ''));
        $accept = $kinds === [] ? '' : implode(',', array_map(static fn (string $ext): string => '.' . $ext, $kinds));

        // THE KIND'S GLYPH WHERE A PICTURE'S PREVIEW IS, and the file's name,
        // a link to it, and its size over the buttons.
        $html  = '<div class="media-picker media-picker--file" data-media-picker data-media-kind="file"'
               . ' data-media-accept="' . View::escape($accept) . '">';
        $html .= '<input type="hidden" name="' . View::escape($name) . '" value="' . ($file !== null ? $id : 0) . '" data-media-value>';
        $html .= '<div class="media-picker__preview" data-media-preview>' . Icons::render('file', 'media-picker__glyph', '', 22) . '</div>';
        $html .= '<div class="media-picker__body">';
        $html .= '<p class="media-picker__name" data-media-name' . ($file !== null ? '' : ' hidden') . '>';
        if ($file !== null) {
            $html .= '<a class="link" href="' . View::escape($file['url']) . '" target="_blank" rel="noopener">' . View::escape($file['name']) . '</a>'
                   . ' <span class="media-picker__size">' . View::escape($file['size_words']) . '</span>';
        }
        $html .= '</p>';
        $html .= '<div class="media-picker__actions">';
        $html .= '<button type="button" class="btn" id="' . self::id($name) . '-choose" data-media-choose>' . ($file !== null ? 'Replace' : 'Choose') . '</button>';
        $html .= '<button type="button" class="btn btn--ghost" data-media-clear' . ($file !== null ? '' : ' hidden') . '>Remove</button>';
        $html .= '</div></div>';

        return $html . '</div>';
    }

    /** Was a gallery field drawn this request? Its page loads the tiles' script and gallery.css when one was. */
    private static bool $galleryDrawn = false;

    public static function drewGallery(): bool
    {
        return self::$galleryDrawn;
    }

    /**
     * A GALLERY FIELD: pictures chosen from the library or dropped in, many
     * at once, ordered by drag, each with a caption of its own, kept in the
     * entry's own value as `[{"id": 12, "caption": "…"}, …]`, in order.
     *
     * The Galleries screen's tiles and script (`Ui\GalleryTiles`,
     * `gallery-images.js`): it posts as that screen does, its pictures' ids in
     * order (`field_{key}[ids]`) and a caption for each by its id
     * (`field_{key}[caption][12]`), which `galleryItems()` reads. Its layout,
     * columns and full-size pictures are its definition's, as the page will
     * draw them (`FieldDisplay`, `Shortcode::galleryHtml()`).
     */
    private static function renderGallery(string $name, mixed $value, array $field): string
    {
        self::$galleryDrawn = true;
        $items   = self::galleryItems($value);
        $pattern = $name . '[{detail}][{id}]';
        $layout  = ($field['layout'] ?? '') === 'masonry' ? 'masonry' : 'grid';
        $columns = max(2, min(6, (int) ($field['columns'] ?? 3) ?: 3));
        $details = self::id($name) . '-details';

        $html  = '<div class="gallery-field" data-gallery data-gallery-pictures data-gallery-name="' . View::escape($pattern) . '">';
        $html .= '<input type="hidden" name="' . View::escape($name . '[ids]') . '" value="'
               . View::escape(implode(',', array_map(static fn (array $i): string => (string) $i['attachment_id'], $items))) . '" data-gallery-ids>';
        $html .= '<div class="cms-gallery cms-gallery--' . $layout . '" style="--gallery-columns: ' . $columns . '" data-gallery-tiles>';
        foreach ($items as $item) {
            $html .= \Cms\Ui\GalleryTiles::tile($item, $pattern, ['caption']);
        }
        $html .= '</div>';
        $html .= '<div class="empty" data-gallery-empty' . ($items === [] ? '' : ' hidden') . '>'
               . Icons::render('image', 'empty__glyph', '', 24) . '<p class="empty__title">No pictures yet</p></div>';
        // FILES DROPPED ON IT, OR UPLOADED, go one at a time; this says which and how far.
        $html .= '<div data-gallery-progress hidden><p class="form-help" data-gallery-progress-text></p>'
               . '<div class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="Upload">'
               . '<div class="progress-bar__fill" data-gallery-progress-fill></div></div></div>';
        $html .= '<div class="form-actions form-actions--bare">'
               . '<button type="button" class="btn" data-gallery-add>Add pictures</button>'
               . '<label class="btn">Upload<input type="file" multiple accept="image/*" hidden data-gallery-upload></label>'
               . '</div>';
        $html .= '<template data-gallery-template>' . \Cms\Ui\GalleryTiles::template($pattern, ['caption']) . '</template>';
        // A PICTURE'S CAPTION, opened by its pen; Done writes the tile's own
        // input, so nothing is kept until the entry is. No maxlength: Done says
        // how long a caption may be instead of cutting a paste short.
        $html .= '<dialog class="modal" data-gallery-details aria-labelledby="' . $details . '-title"><div class="modal__inner">'
               . '<h2 class="modal__title" id="' . $details . '-title">Caption</h2>'
               . '<div class="alert alert--danger" data-gd-error hidden></div>'
               // THE BOX IS NAMED BY THE DIALOG'S TITLE, not a label repeating it.
               . '<div class="field"><textarea class="textarea" id="' . $details . '-caption" rows="5" aria-labelledby="' . $details . '-title"'
               . ' data-gd-caption data-most="' . \Cms\Models\Gallery::CAPTION_MAX . '"></textarea></div>'
               . '<div class="modal__actions"><button type="button" class="btn" data-gd-cancel>Cancel</button>'
               . '<button type="button" class="btn btn--primary" data-gd-ok>Done</button></div>'
               . '</div></dialog>';

        return $html . '</div>';
    }

    /**
     * A GALLERY FIELD'S PICTURES, from what it keeps (`[{"id": 12, "caption":
     * "…"}, …]`) or what its form posted (its `ids` in order, and a `caption`
     * by id), each as `Gallery::getItems()` gives an item: its file's details
     * and its caption, in order, each picture once. A picture no longer in the
     * library, or a file that is not a picture, is left out.
     *
     * @return list<array<string, mixed>>
     */
    public static function galleryItems(mixed $value): array
    {
        $captions = [];
        if (is_array($value)) {
            $posted = is_array($value['caption'] ?? null) ? $value['caption'] : [];
            foreach (explode(',', is_scalar($value['ids'] ?? null) ? (string) $value['ids'] : '') as $id) {
                $id = (int) trim($id);
                if ($id > 0 && !isset($captions[$id])) {
                    $words          = $posted[$id] ?? '';
                    $captions[$id]  = is_scalar($words) ? str_replace(["\r\n", "\r"], "\n", trim((string) $words)) : '';
                }
            }
        } elseif (is_string($value) && $value !== '') {
            foreach ((array) json_decode($value, true) as $row) {
                $id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
                if ($id > 0 && !isset($captions[$id])) {
                    $captions[$id] = is_scalar($row['caption'] ?? null) ? (string) $row['caption'] : '';
                }
            }
        }

        $items = [];
        foreach ($captions as $id => $caption) {
            $file = \Cms\Models\Media::find($id);
            if ($file !== null && str_starts_with((string) $file['mime_type'], 'image/')) {
                $items[] = ['attachment_id' => $id, 'caption' => $caption] + $file;
            }
        }

        return $items;
    }

    /**
     * The repeater.
     *
     * The rows are the substance of the field, so they are SHARP boxes on the
     * inset tone (`--surface-2`), which is what makes a card-inside-a-card
     * legible without a second border.
     *
     * `__ROW__` in the `<template>` is the index `entry-form.js` substitutes.
     */
    private static function renderRepeater(string $name, mixed $value, array $field): string
    {
        $subFields = $field['sub_fields'] ?? [];
        $rows      = self::decodeRows($value);
        if (($field['layout'] ?? '') === 'table') {
            return self::renderRepeaterTable($name, $rows, array_values(array_filter((array) $subFields, 'is_array')), $field);
        }

        $html  = '<div class="repeater" data-repeater data-repeater-name="' . View::escape($name) . '">';
        $html .= '<div class="repeater__rows" data-repeater-rows>';

        foreach ($rows as $index => $rowData) {
            $html .= self::renderRepeaterRow($name, (int) $index, $subFields, is_array($rowData) ? $rowData : []);
        }

        $html .= '</div>';

        // A row you cannot get rid of is not a row you chose. The empty state is
        // "no rows", and Add row is the one control that ends it — same shape as
        // every other empty state in the admin.
        $html .= '<div class="form-actions--bare">';
        $html .= '<button type="button" class="btn" data-repeater-add>Add row</button>';
        $html .= '</div>';

        $blank  = array_fill_keys(array_column($subFields, 'key'), '');
        $html  .= '<template data-repeater-template>'
                . self::renderRepeaterRow($name, '__ROW__', $subFields, $blank)
                . '</template>';

        return $html . '</div>';
    }

    /**
     * THE TABLE LAYOUT (`'layout' => 'table'`): the sub-fields as columns and
     * each row as one line, a number in a narrow box, under the same names the
     * stacked rows post, so what is kept is the same JSON, byte for byte.
     * `entry-form.js` moves Enter down a column and spreads a block pasted from
     * a spreadsheet across the cells.
     *
     * WITH `'preset_rows' => ['field' => <a Choice column's key>]`, that
     * column's choices are the rows, in its order, each named by its choice and
     * posting it, with no Add row or Remove. A kept row whose value is no longer
     * a choice follows them, named by what it holds, with a Remove, so nothing
     * kept is lost until someone removes it.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $subFields
     * @param array<string, mixed>       $field
     */
    private static function renderRepeaterTable(string $name, array $rows, array $subFields, array $field): string
    {
        $presetKey = (string) ($field['preset_rows']['field'] ?? '');
        $preset    = null;
        foreach ($subFields as $sub) {
            if ($presetKey !== '' && (string) ($sub['key'] ?? '') === $presetKey && ($sub['type'] ?? '') === 'select') {
                $preset = $sub;
            }
        }
        $columns = $preset === null ? $subFields
            : array_values(array_filter($subFields, static fn (array $sub): bool => (string) ($sub['key'] ?? '') !== $presetKey));

        // Each line: [its name in the first column, or null; its values; can it be removed].
        $lines = [];
        if ($preset === null) {
            foreach ($rows as $row) {
                $lines[] = [null, is_array($row) ? $row : [], true];
            }
        } else {
            $left = array_values(array_filter($rows, 'is_array'));
            foreach ((array) ($preset['options'] ?? []) as $choice => $choiceLabel) {
                $data = [];
                foreach ($left as $i => $row) {
                    if ((string) ($row[$presetKey] ?? '') === (string) $choice) {
                        $data = $row;
                        unset($left[$i]);
                        break;
                    }
                }
                $lines[] = [(string) $choiceLabel, [$presetKey => (string) $choice] + $data, false];
            }
            foreach ($left as $row) {
                $kept    = (string) ($row[$presetKey] ?? '');
                $lines[] = [$kept !== '' ? $kept : '—', $row, true];
            }
        }

        $fixed = $preset !== null;
        $html  = '<div class="repeater repeater--table" data-repeater data-repeater-table data-repeater-name="' . View::escape($name) . '">'
               . '<div class="table-wrap"><table class="table"><thead><tr>';
        if ($fixed) {
            $html .= '<th scope="col">' . View::escape((string) ($preset['label'] ?? $presetKey)) . '</th>';
        }
        foreach ($columns as $sub) {
            $html .= '<th scope="col"' . (($sub['type'] ?? 'text') === 'number' ? ' class="num"' : '') . '>'
                   . View::escape((string) ($sub['label'] ?? $sub['key'] ?? '')) . '</th>';
        }
        $html .= '<th scope="col" aria-label="Remove"></th></tr></thead><tbody data-repeater-rows>';
        foreach ($lines as $index => [$rowName, $rowData, $removable]) {
            $html .= self::renderRepeaterTableRow($name, $index, $columns, $preset, $rowName, $rowData, $removable);
        }
        $html .= '</tbody></table></div>';

        if (!$fixed) {
            $html .= '<div class="form-actions--bare">'
                   . '<button type="button" class="btn" data-repeater-add>Add row</button>'
                   . '</div>'
                   . '<template data-repeater-template>'
                   . self::renderRepeaterTableRow($name, '__ROW__', $columns, null, null, array_fill_keys(array_column($columns, 'key'), ''), true)
                   . '</template>';
        }

        return $html . '</div>';
    }

    /**
     * One line of a table repeater: its name and the hidden value it posts when
     * its rows are preset, a cell per column, each control named by its column
     * and its row for a screen reader, and a Remove where it can go.
     *
     * @param list<array<string, mixed>>  $columns
     * @param array<string, mixed>|null   $preset
     * @param array<string, mixed>        $rowData
     */
    private static function renderRepeaterTableRow(
        string $name,
        int|string $index,
        array $columns,
        ?array $preset,
        ?string $rowName,
        array $rowData,
        bool $removable
    ): string {
        $html = '<tr data-repeater-row>';
        if ($preset !== null) {
            $presetKey = (string) $preset['key'];
            $html .= '<th scope="row">' . View::escape((string) $rowName)
                   . '<input type="hidden" name="' . View::escape($name . '[' . $index . '][' . $presetKey . ']') . '"'
                   . ' value="' . View::escape((string) ($rowData[$presetKey] ?? '')) . '"></th>';
        }
        foreach ($columns as $sub) {
            $subName = $name . '[' . $index . '][' . $sub['key'] . ']';
            $label   = (string) ($sub['label'] ?? $sub['key']);
            $cell    = $sub + ['_aria_label' => $rowName !== null ? $label . ', ' . $rowName : $label];
            $html   .= '<td' . (($sub['type'] ?? 'text') === 'number' ? ' class="num"' : '') . '>'
                     . self::control($cell, $subName, $rowData[$sub['key']] ?? '') . '</td>';
        }
        $html .= '<td>';
        if ($removable) {
            $html .= '<button type="button" class="icon-btn" data-repeater-remove'
                   . ' data-tip="Remove row" aria-label="Remove row">'
                   . Icons::render('x', 'icon') . '</button>';
        }

        return $html . '</td></tr>';
    }

    /**
     * @param array<int, array<string, mixed>> $subFields
     * @param array<string, mixed>             $rowData
     */
    private static function renderRepeaterRow(
        string $name,
        int|string $rowIndex,
        array $subFields,
        array $rowData
    ): string {
        $html  = '<div class="repeater__row" data-repeater-row>';
        $html .= '<div class="repeater__fields">';

        foreach ($subFields as $sub) {
            $subName = $name . '[' . $rowIndex . '][' . $sub['key'] . ']';
            // Sub-fields go through the SAME wrap() and the SAME dispatch as a
            // top-level field. A repeater whose rows render by a second, simpler
            // code path is how the two drift.
            $html .= self::wrap($sub, $subName, self::control($sub, $subName, $rowData[$sub['key']] ?? ''));
        }

        $html .= '</div>';

        // Glyph-only, so a tooltip is legal here.
        $html .= '<button type="button" class="icon-btn" data-repeater-remove'
               . ' data-tip="Remove row" aria-label="Remove row">'
               . Icons::render('x', 'icon') . '</button>';

        return $html . '</div>';
    }

    /**
     * A relationship — a chip-picker over the entries of another Content Type.
     *
     * Not `<select multiple size="6">` with "Hold Ctrl/Cmd to select multiple"
     * underneath: Cmd+click does not scale and does not exist on touch at all,
     * and a sentence explaining a control is the UI telling the user how to
     * work it.
     */
    private static function renderRelationship(string $name, mixed $value, array $field): string
    {
        $selectedIds = self::decodeIds($value);

        // THE TYPE WHOSE ENTRIES IT OFFERS: `content_type` in code, and
        // `content_type_target` as the field group editor keeps it, which this
        // never read, so a relationship made on screen offered nothing;
        // `post_type` and `post_type_target` are older names for each.
        $typeKey = 'post';
        foreach (['content_type', 'content_type_target', 'post_type', 'post_type_target'] as $option) {
            if (is_string($field[$option] ?? null) && trim($field[$option]) !== '') {
                $typeKey = trim($field[$option]);
                break;
            }
        }
        $type      = Registry::get($typeKey);
        $available = \Cms\Models\Post::all($typeKey);

        $options = [];
        foreach ($available as $entry) {
            $options[(int) $entry['id']] = (string) $entry['title'];
        }

        return self::chipPicker(
            $name,
            $options,
            $selectedIds,
            $type !== null ? (string) $type['label_single'] : $typeKey
        );
    }

    /**
     * The taxonomy FIELD — independent of the taxonomy sidebar panels.
     *
     * It stores a JSON array of term ids in post_meta, so a "Primary category"
     * style field can sit beside the panel that assigns real relationships.
     *
     * Settings: `taxonomy_key`, `field_mode` (select|checkbox|radio),
     * `multiple`, and `_post_id` injected by the controller.
     */
    private static function renderTaxonomy(string $name, mixed $value, array $field): string
    {
        $taxKey = (string) ($field['taxonomy_key'] ?? '');

        if ($taxKey === '') {
            // A field that cannot work says so where it would have been, rather
            // than rendering an empty control that looks like it has no options.
            return '<p class="form-help">This field has no taxonomy set.</p>';
        }

        $multiple       = !empty($field['multiple']);
        $selected       = self::decodeIds($value);
        $taxConfig      = TaxonomyRegistry::get($taxKey);
        $isHierarchical = (bool) ($taxConfig['hierarchical'] ?? false);
        $termLabel      = $taxConfig !== null ? (string) $taxConfig['label_single'] : $taxKey;

        $tree  = $isHierarchical ? \Cms\Models\Term::treeForTaxonomy($taxKey) : [];
        $flat  = $isHierarchical ? [] : \Cms\Models\Term::allForTaxonomy($taxKey);

        $mode  = (string) ($field['field_mode'] ?? 'select');
        $empty = $tree === [] && $flat === [];

        if ($mode === 'checkbox' || $mode === 'radio') {
            $inputType = $multiple ? 'checkbox' : 'radio';
            $inputName = $multiple ? $name . '[]' : $name;

            // ── ADD A TERM WITHOUT LEAVING THE ENTRY ────────────────────────
            // `Api\TaxonomyController::createTerm()` has existed the whole time
            // with NO caller — four JSON endpoints and no interface, which is
            // the same fault the media API had. Without it, an author who needs
            // a category that does not exist yet has to abandon a half-written
            // entry, walk to Structure → Taxonomies → Terms, create it, come
            // back and find their draft again. That is the single most common
            // thing anyone does on an entry form.
            //
            // AN EMPTY TAXONOMY GETS THE SAME CONTROL, not a link away. The
            // old empty state pointed at the terms screen — correct, and still
            // a round trip for the one case where you definitely need to make
            // a term.
            return '<div class="check-list" data-term-list>'
                . ($empty
                    ? ''
                    : ($isHierarchical
                        ? self::checkTree($tree, $selected, $inputType, $inputName)
                        : self::checkRows($flat, $selected, $inputType, $inputName)))
                . '</div>'
                . self::termAdder($taxKey, $termLabel, $inputType, $inputName, $empty);
        }

        if ($empty) {
            return self::noTerms($taxKey);
        }

        if ($multiple) {
            $options = [];
            foreach ($isHierarchical ? self::flatten($tree) : $flat as $term) {
                $options[(int) $term['id']] = (string) $term['name'];
            }

            // The picker makes a term only for whoever may add one (`StaffView`).
            return self::chipPicker($name, $options, $selected, $termLabel, \Cms\ContentType\StaffView::addsTerms($taxKey) ? $taxKey : '');
        }

        // Single select.
        $one  = $selected[0] ?? null;
        $html = '<select class="select"' . self::common($name, $field) . '>';
        $html .= '<option value="">None</option>';

        if ($isHierarchical) {
            $html .= \Cms\Helpers\TermRenderer::selectOptions($tree, $selected);
        } else {
            foreach ($flat as $term) {
                $html .= '<option value="' . (int) $term['id'] . '"'
                    . ($one === (int) $term['id'] ? ' selected' : '') . '>'
                    . View::escape((string) $term['name']) . '</option>';
            }
        }

        return $html . '</select>';
    }

    // ----------------------------------------------------------------
    // Shared pieces — also used by the taxonomy PANELS on the edit form
    // ----------------------------------------------------------------

    /**
     * The chip-picker: typeahead input + a filterable menu, chips for what is
     * chosen. `core.css` §9 owns the CSS; `entry-form.js` owns the behaviour.
     *
     * Each chip carries its own hidden input, so removing the chip removes the
     * value — there is no parallel list to keep in step.
     *
     * @param array<int, string> $options  id => label, in menu order
     * @param list<int>          $selected
     * @param array<int, string> $slugs    id => a term's slug, for a logic rule naming it (`tax:{key}`)
     */
    public static function chipPicker(
        string $name,
        array $options,
        array $selected,
        string $kindLabel,
        string $taxonomyKey = '',
        array $slugs = []
    ): string {
        $inputName = $name . '[]';

        // `data-chip-taxonomy` is what lets the picker CREATE a term rather than
        // only filtering the ones that exist. The API's own docblock has always
        // claimed `createTerm()` is "used by the tag-input JS when the user
        // types a new tag that doesn't exist yet" — it was not used by anything,
        // and typing a tag that did not exist simply emptied the menu and left
        // you with nowhere to go. A picker without it still works exactly as
        // before, which is why the argument is optional.
        $html  = '<div class="chip-picker" data-chip-picker'
               . ($taxonomyKey !== '' ? ' data-chip-taxonomy="' . View::escape($taxonomyKey) . '"' : '')
               . '>';
        $html .= '<div class="chip-picker__chips" data-chip-list>';

        // Render in the SELECTED order, not the option order, so a value whose
        // option has since disappeared still shows as a chip you can remove
        // rather than vanishing silently on the next save.
        foreach ($selected as $id) {
            $html .= self::chip($inputName, (int) $id, $options[(int) $id] ?? ('#' . (int) $id), (string) ($slugs[(int) $id] ?? ''));
        }

        $html .= '</div>';

        $html .= '<input type="text" class="input" data-chip-input autocomplete="off"'
               . ' role="combobox" aria-expanded="false" aria-autocomplete="list"'
               . ' placeholder="' . View::escape('Add ' . strtolower($kindLabel)) . '"'
               . ' aria-label="' . View::escape('Add ' . strtolower($kindLabel)) . '">';

        $html .= '<div class="chip-picker__menu" data-chip-menu role="listbox" hidden>';
        foreach ($options as $id => $label) {
            $html .= '<div class="chip-picker__option" role="option" data-value="' . (int) $id . '"'
                   . ' data-label="' . View::escape($label) . '"'
                   . (isset($slugs[(int) $id]) ? ' data-slug="' . View::escape((string) $slugs[(int) $id]) . '"' : '')
                   . (in_array((int) $id, $selected, true) ? ' class="chip-picker__option is-selected"' : '')
                   . '>' . View::escape($label) . '</div>';
        }
        // THE CREATE ROW, last in the menu and hidden until it is needed. It is
        // markup rather than a node the script builds, for the same reason the
        // chip itself is a <template>: one definition of the shape.
        if ($taxonomyKey !== '') {
            $html .= '<div class="chip-picker__option chip-picker__option--new"'
                   . ' role="option" data-chip-new hidden></div>';
        }

        $html .= '</div>';

        // The template the JS clones, so chip markup exists in exactly one place.
        $html .= '<template data-chip-template>'
               . self::chip($inputName, 0, '__LABEL__')
               . '</template>';

        return $html . '</div>';
    }

    private static function chip(string $inputName, int $id, string $label, string $slug = ''): string
    {
        return '<span class="chip" data-value="' . $id . '">'
            . '<span class="chip__label">' . View::escape($label) . '</span>'
            . '<button type="button" class="chip__remove" data-chip-remove'
            . ' aria-label="' . View::escape('Remove ' . $label) . '">'
            . Icons::render('x', 'icon icon--xs') . '</button>'
            . '<input type="hidden" name="' . View::escape($inputName) . '" value="' . $id . '"'
            . ($slug !== '' ? ' data-term-slug="' . View::escape($slug) . '"' : '') . '>'
            . '</span>';
    }

    /**
     * The "there are no terms yet" state for a taxonomy control.
     *
     * The fact AND the act, not a paragraph on mechanism — the same shape as an
     * `.alert`'s body, at field scale.
     */
    /**
     * The inline "add a term" row under a tick-box list.
     *
     * Nameless controls on purpose: the text box and the button are UI, and the
     * only thing that must ever post is the checkbox this creates. A named input
     * here would put a stray `name=` in every entry save.
     *
     * `.input-row` is admin.css's existing "field plus a button" shape — the
     * media panel's Copy row uses it — so this adds no geometry of its own.
     */
    public static function termAdder(
        string $taxKey,
        string $termLabel,
        string $inputType,
        string $inputName,
        bool $empty
    ): string {
        $article = preg_match('/^[aeiou]/i', $termLabel) ? 'an' : 'a';
        $tax     = \Cms\ContentType\TaxonomyRegistry::get($taxKey) ?? [];
        // THE REGISTRY'S PLURAL, not the singular with an "s" on it — which
        // would print "No categorys yet" under every empty category list.
        $plural  = strtolower((string) ($tax['label'] ?? ($termLabel . 's')));

        // NO ADD ROW FOR SOMEONE WHOSE ADD WOULD BE REFUSED. Who may add a term
        // is the taxonomy's (`StaffView::addsTerms()`): an editor on a
        // category-style one and any author on a tag-style one unless it says
        // otherwise, and the terms screen and the API ask the same. Anyone else
        // is offered the list to tick and, when there is nothing in it, the
        // truth about who fills it.
        if (!\Cms\ContentType\StaffView::addsTerms($taxKey)) {
            $who = \Cms\ContentType\StaffView::addTermsRole($taxKey);
            return $empty
                ? '<p class="form-help">No ' . View::escape($plural) . ' yet.'
                    . ($who === false ? '' : ' ' . View::escape(ucfirst(\Cms\ContentType\StaffView::who($who))) . ' adds them.') . '</p>'
                : '';
        }

        // WHERE THE NEW ONE SITS, on a nested taxonomy. Without a parent, every
        // term made here would be top-level, so in a two-level tree a new child
        // would land beside the pillars instead of under its parent. So the
        // control has a parent dropdown, indented by depth; the term screen's
        // own picker supplies the words.
        $parentPicker = '';
        if (!empty($tax['hierarchical'])) {
            $options = '';
            $walk = static function (array $nodes, int $depth) use (&$walk, &$options): void {
                foreach ($nodes as $node) {
                    $options .= '<option value="' . (int) $node['id'] . '" data-depth="' . $depth . '">'
                        . str_repeat("\u{00A0}\u{00A0}\u{00A0}", $depth)
                        . View::escape((string) $node['name']) . '</option>';
                    $walk($node['children'] ?? [], $depth + 1);
                }
            };
            $walk(\Cms\Models\Term::treeForTaxonomy($taxKey), 0);
            $parentPicker = '<label class="term-add__under"><span>Sits under</span>'
                . '<select class="select" data-term-parent>'
                . '<option value="0">Nothing — top level</option>' . $options
                . '</select></label>';
        }

        // THE FULL CONTROL HEIGHT, not the dense one. `--control-h-sm` is for
        // controls packed into a row with others — a logic rule, a filter bar.
        // This is the only text entry in the panel and it sits alone under a
        // list, so at 24px it would read as a scrap rather than a field, next
        // to a checkbox list on the same scale.
        $nameInput = '<input type="text" class="input" data-term-name'
            . ' placeholder="' . View::escape('New ' . strtolower($termLabel)) . '"'
            . ' aria-label="' . View::escape('Add ' . $article . ' ' . strtolower($termLabel)) . '"'
            . ' autocomplete="off">';
        $addButton = '<button type="button" class="btn" data-term-create>Add</button>';

        return '<div class="term-add" data-term-add="' . View::escape($taxKey) . '"'
            . ' data-input-type="' . View::escape($inputType) . '"'
            . ' data-input-name="' . View::escape($inputName) . '">'
            // IN THIS ORDER AND SHAPE on a nested taxonomy — the name,
            // where it sits, then Add on its own line — so the keyboard meets
            // the parent before the button. Sharing a row with Add, the picker
            // would be 86px in a 220px aside and read "Nothin".
            . ($parentPicker !== ''
                ? $nameInput . $parentPicker . $addButton
                : '<div class="input-row">' . $nameInput . $addButton . '</div>')
            . ($empty
                ? '<p class="form-help">No ' . View::escape($plural)
                    . ' yet. Adding one here creates it and ticks it.</p>'
                : '')
            // The failure line is an `.alert-flag` — a standalone surface, never
            // red text painted onto the form.
            . '<p class="term-add__msg" data-term-msg hidden></p>'
            . '</div>';
    }

    private static function noTerms(string $taxKey): string
    {
        return '<p class="form-help">No terms yet — '
            . '<a class="link link--xs" href="'
            . View::escape(\Cms\Helpers\Url::admin('taxonomies/' . $taxKey . '/terms/new'))
            . '">add the first one</a>.</p>';
    }

    /**
     * A flat list of checkbox/radio rows.
     *
     * @param list<array<string, mixed>> $terms
     * @param list<int>                  $selected
     */
    public static function checkRows(array $terms, array $selected, string $inputType, string $inputName): string
    {
        $html = '';

        foreach ($terms as $term) {
            $html .= self::checkRow($term, $selected, $inputType, $inputName);
        }

        return $html;
    }

    /**
     * A hierarchical checkbox/radio tree.
     *
     * NESTED ELEMENTS, not a depth counter times twenty pixels. An inline
     * `padding-left: {$depth * 20}px` does not come off the `--sp-*` scale, and
     * stops being adjustable from the stylesheet. `.check-list--sub` indents by
     * one scale step and nests to any depth.
     *
     * @param list<array<string, mixed>> $tree
     * @param list<int>                  $selected
     */
    public static function checkTree(array $tree, array $selected, string $inputType, string $inputName): string
    {
        $html = '';

        foreach ($tree as $term) {
            $html .= self::checkRow($term, $selected, $inputType, $inputName);

            if (!empty($term['children'])) {
                $html .= '<div class="check-list check-list--sub">'
                       . self::checkTree($term['children'], $selected, $inputType, $inputName)
                       . '</div>';
            }
        }

        return $html;
    }

    /** @param list<int> $selected */
    private static function checkRow(array $term, array $selected, string $inputType, string $inputName): string
    {
        $id = (int) $term['id'];

        return '<label class="check-list__row">'
            . '<input type="' . View::escape($inputType) . '"'
            . ' name="' . View::escape($inputName) . '" value="' . $id . '"'
            // ITS SLUG, for a logic rule that names the term by it (`tax:{key}`, `logic.js`).
            . (isset($term['slug']) ? ' data-term-slug="' . View::escape((string) $term['slug']) . '"' : '')
            . (in_array($id, $selected, true) ? ' checked' : '') . '>'
            . '<span>' . View::escape((string) $term['name']) . '</span>'
            . '</label>';
    }

    // ----------------------------------------------------------------
    // Small helpers
    // ----------------------------------------------------------------

    /** `name`, `id` and `required` — the three attributes every control shares. */
    private static function common(string $name, array $field): string
    {
        return ' name="' . View::escape($name) . '"'
            . ' id="' . self::id($name) . '"'
            . (!empty($field['required']) && empty($field['_row']) ? ' required' : '')
            // A CELL OF A TABLE has no label of its own: its column and row
            // name it (`renderRepeaterTable()`), as a fact the CMS writes on
            // the cell, never an option a definition gives.
            . (($field['_aria_label'] ?? '') !== '' ? ' aria-label="' . View::escape((string) $field['_aria_label']) . '"' : '');
    }

    private static function placeholder(array $field): string
    {
        $p = (string) ($field['placeholder'] ?? '');

        return $p !== '' ? ' placeholder="' . View::escape($p) . '"' : '';
    }

    /**
     * A DOM id from a field name.
     *
     * A repeater sub-field's name is `field_speakers[0][name]`, and brackets in
     * an id break every `querySelector` and every `label[for]` that touches it.
     */
    private static function id(string $name): string
    {
        return 'f-' . trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-');
    }

    /** Is this taxonomy field a single `<select>`, i.e. one labellable control? */
    private static function taxonomyIsOneSelect(array $field): bool
    {
        $mode = (string) ($field['field_mode'] ?? 'select');

        return $mode !== 'checkbox' && $mode !== 'radio' && empty($field['multiple']);
    }

    /** @return list<array<string, mixed>> */
    private static function flatten(array $tree): array
    {
        $out = [];

        foreach ($tree as $term) {
            $out[] = $term;

            if (!empty($term['children'])) {
                $out = array_merge($out, self::flatten($term['children']));
            }
        }

        return $out;
    }

    /** Repeater rows come back as a JSON string from post_meta, or an array on a failed save. */
    private static function decodeRows(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /** @return list<int> */
    private static function decodeIds(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('intval', $value)));
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return array_values(array_filter(array_map('intval', $decoded)));
            }

            if (is_numeric($value)) {
                return [(int) $value];
            }
        }

        return [];
    }

    // ----------------------------------------------------------------
    // Sanitize
    // ----------------------------------------------------------------

    /**
     * Extract and sanitize a field value from $_POST data.
     * Returns a string (or a JSON string for the complex types).
     */
    public static function sanitizeField(array $field, array $postData): mixed
    {
        $key  = 'field_' . $field['key'];
        $type = $field['type'] ?? 'text';

        return match ($type) {
            // ── AN EMPTY NUMBER BOX IS NOT A ZERO ───────────────────────────
            // `isset()` alone is not enough: an empty box IS set — it posts
            // `''`, which casts to `0.0` and would store the string `'0'`.
            //
            // On `product_sale_price` that is not a cosmetic difference. A sale
            // price BELOW the regular one is the sale price, and
            // `Commerce\Product` says out loud that zero is a legitimate one
            // (a free sample) — so opening any product, changing nothing and
            // pressing Save would make it FREE: a $45 membership would come
            // back as a $0.00 line in the basket.
            //
            // `null` means "no value" the whole way down: `Meta::set()` stores
            // it as such, `Money::parse('')` returns null, and `fromPost()`
            // treats a product with no price as not for sale rather than as
            // free. The same trap applies to `product_stock_qty`.
            'number' => isset($postData[$key]) && trim((string) $postData[$key]) !== ''
                ? (string) ((float) $postData[$key])
                : null,

            // THE ONLY FIELD TYPE WHOSE VALUE REACHES A PAGE AS MARKUP.
            // Every other type here is escaped at output; this one is not, by
            // design — it is the rich-text field. `Html\Sanitizer` is the
            // guarantee, applied on the way IN so what is stored is already
            // safe and a later reader cannot forget.
            'rich_text' => isset($postData[$key])
                ? Sanitizer::clean(trim((string) $postData[$key]))
                : null,

            'repeater' => self::sanitizeRepeater($postData[$key] ?? null, $field),

            // ITS PICTURES IN ORDER, each once and each still a picture in the
            // library, its caption as typed with any tags removed (`galleryItems()`).
            'gallery' => (string) json_encode(array_map(
                static fn (array $item): array => ['id' => (int) $item['attachment_id'], 'caption' => strip_tags((string) $item['caption'])],
                self::galleryItems(is_array($postData[$key] ?? null) ? $postData[$key] : [])
            ), JSON_UNESCAPED_UNICODE),

            'relationship' => self::sanitizeRelationship($postData[$key] ?? null),

            'taxonomy' => self::sanitizeTaxonomy($postData[$key] ?? null, $field),

            // ── ONE OF TWO VALUES, AND NEVER ANYTHING ELSE ──────────────────
            // The hidden `0` in front of the box means this key is always
            // posted, so "not present" cannot happen — but a hand-made POST can
            // still send anything, and `Stock` and `Shipping` both compare
            // against the exact string `'1'`. Normalising here means neither of
            // them has to know what an owner's browser sent.
            'checkbox' => ((string) ($postData[$key] ?? '0')) === '1' ? '1' : '0',

            // PLAIN TEXT, STORED AS TYPED — tags removed, nothing escaped.
            // Escaping belongs where the value is printed, once; stored
            // escaped, it was escaped again there (see `plain()`).
            default => isset($postData[$key])
                ? strip_tags(trim((string) $postData[$key]))
                : null,
        };
    }

    private static function sanitizeRepeater(mixed $rows, array $field): string
    {
        if (!is_array($rows)) {
            return json_encode([]);
        }

        $subFields = $field['sub_fields'] ?? [];
        $clean     = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cleanRow = [];
            foreach ($subFields as $sub) {
                $val = isset($row[$sub['key']]) ? trim((string) $row[$sub['key']]) : '';
                $subType = $sub['type'] ?? 'text';
                // A rich_text sub-field keeps its markup — CLEANED ON THE WAY
                // IN, as a rich-text field's own value is (`'rich_text'` in
                // sanitizeField()), not only at output. A row's text is the same
                // kind of value, and a stored `<script>` waits for the first
                // reader that forgets FieldDisplay::html().
                // All others strip tags.
                $cleanRow[$sub['key']] = $subType === 'rich_text'
                    ? Sanitizer::clean($val)
                    : strip_tags($val);   // plain, as a field's own value is
            }
            $clean[] = $cleanRow;
        }

        return json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    private static function sanitizeRelationship(mixed $ids): string
    {
        if (!is_array($ids)) {
            return json_encode([]);
        }
        $clean = array_values(array_filter(array_map('intval', $ids)));
        return json_encode($clean);
    }

    private static function sanitizeTaxonomy(mixed $ids, array $field): string
    {
        $multiple = !empty($field['multiple']);

        if ($multiple) {
            if (!is_array($ids)) {
                return json_encode([]);
            }
            $clean = array_values(array_filter(array_map('intval', $ids)));
            return json_encode($clean);
        }

        // Single selection — store as JSON array with one element for consistency
        $id = $ids !== null && $ids !== '' ? (int) $ids : null;
        return $id !== null && $id > 0 ? json_encode([$id]) : json_encode([]);
    }

    // ----------------------------------------------------------------
    // Validate
    // ----------------------------------------------------------------

    /**
     * Validate a sanitized field value. Returns an error string or null.
     */
    public static function validateField(array $field, mixed $value): ?string
    {
        $label = $field['label'] ?? $field['key'];

        // A PICTURE FIELD WITH NO PICTURE POSTS 0: its control holds an
        // attachment's id as a whole number, so 0 is none, and a required
        // picture field left empty is refused like any other.
        $none = $value === null || $value === '' || $value === '[]'
            || (in_array($field['type'] ?? 'text', ['image', 'file'], true) && is_scalar($value) && (int) $value <= 0);
        if (!empty($field['required']) && $none) {
            // THE AUTHOR'S OWN WORDING WINS. The field group editor's Validation
            // tab offers "Message when it is left blank", and until this read it
            // the box collected a string nothing ever printed — help text making
            // a claim about behaviour that was not true. Blank still means the
            // standard sentence, which is what the control's own help says.
            $custom = trim((string) ($field['msg_required'] ?? ''));
            return $custom !== '' ? $custom : "{$label} is required.";
        }

        // A FILE FIELD TAKES A FILE THE LIBRARY HAS, of a kind it names: one
        // posted by hand that is not, or is of another kind, is refused.
        if (($field['type'] ?? 'text') === 'file' && is_scalar($value) && (int) $value > 0) {
            $file  = \Cms\Models\Media::file((int) $value);
            $kinds = \Cms\Models\Media::kindsFor((string) ($field['accept'] ?? ''));
            if ($file === null) {
                return "{$label}: that file is not in the library.";
            }
            if ($kinds !== [] && !isset($kinds[$file['mime']])) {
                $names = array_map('strtoupper', array_values(array_unique($kinds)));
                $last  = array_pop($names);
                return "{$label} takes " . ($names === [] ? $last : implode(', ', $names) . ' or ' . $last) . ' files.';
            }
        }

        // A CAPTION IS AS LONG AS A GALLERY'S MAY BE, and a longer one is
        // refused in the Galleries screen's words, never cut short.
        if (($field['type'] ?? 'text') === 'gallery' && is_string($value)) {
            foreach ((array) json_decode($value, true) as $row) {
                $caption = is_array($row) && is_string($row['caption'] ?? null) ? $row['caption'] : '';
                if (mb_strlen($caption) > \Cms\Models\Gallery::CAPTION_MAX) {
                    $file = \Cms\Models\Media::find((int) ($row['id'] ?? 0));
                    $name = $file !== null ? (string) ($file['original_name'] ?: $file['filename']) : 'a picture';
                    return "{$label}: " . \Cms\Models\Gallery::tooLong('', $caption, '“' . $name . '”')[0];
                }
            }
        }

        if (($field['type'] ?? 'text') === 'number' && $value !== null && $value !== '') {
            if (isset($field['min']) && (float) $value < (float) $field['min']) {
                return "{$label} must be at least {$field['min']}.";
            }
            if (isset($field['max']) && (float) $value > (float) $field['max']) {
                return "{$label} must be no more than {$field['max']}.";
            }
        }

        // A PHONE NUMBER THAT CAN BE DIALLED as written, by the rule Business
        // details use and in their words, from the business's own country.
        $typed = is_scalar($value) ? trim((string) $value) : '';
        if (($field['type'] ?? 'text') === 'phone' && $typed !== '' && FieldDisplay::dialable($typed) === '') {
            return "{$label}, {$typed}, cannot be dialled as written: " . \Cms\Site\Business::howToWrite(FieldDisplay::dialCountry());
        }
        // AN EMAIL ADDRESS, checked as a form's email field is (`Forms\Answers`).
        if (($field['type'] ?? 'text') === 'email' && $typed !== '' && filter_var($typed, FILTER_VALIDATE_EMAIL) === false) {
            return "{$label} must be a valid email address.";
        }

        // A REPEATER'S ROWS: each column checked as a field of its type is,
        // a column ticked Required in every row, and each refusal naming its
        // row (by its choice, in a table whose choices are its rows) and its
        // column, every one at once.
        if (($field['type'] ?? 'text') === 'repeater' && is_string($value) && $value !== '') {
            $presetKey = (string) ($field['preset_rows']['field'] ?? '');
            $choices   = [];
            foreach ((array) ($field['sub_fields'] ?? []) as $sub) {
                if (is_array($sub) && $presetKey !== '' && (string) ($sub['key'] ?? '') === $presetKey) {
                    $choices = (array) ($sub['options'] ?? []);
                }
            }
            $problems = [];
            foreach (array_values((array) json_decode($value, true)) as $n => $row) {
                $row     = is_array($row) ? $row : [];
                $kept    = (string) ($row[$presetKey] ?? '');
                $rowName = $presetKey !== '' && $kept !== '' ? (string) ($choices[$kept] ?? $kept) : 'row ' . ($n + 1);
                foreach ((array) ($field['sub_fields'] ?? []) as $sub) {
                    if (!is_array($sub) || !is_string($sub['key'] ?? null) || $sub['key'] === $presetKey) {
                        continue;
                    }
                    $cell    = $row[$sub['key']] ?? '';
                    $problem = self::validateField($sub, is_scalar($cell) ? (string) $cell : '');
                    if ($problem !== null) {
                        $problems[] = "{$label}, {$rowName}: {$problem}";
                    }
                }
            }
            if ($problems !== []) {
                return implode(' ', $problems);
            }
        }

        return null;
    }

    /**
     * @deprecated The repeater's behaviour is in www/admin/assets/js/entry-form.js,
     *             emitted by the edit screen. Kept as a no-op so an older template
     *             that still calls it does not fatal.
     */
    public static function repeaterScript(): string
    {
        return '';
    }
}
