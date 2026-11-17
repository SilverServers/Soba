<?php

declare(strict_types=1);

namespace Cms\Helpers;

use Cms\Compat\OldNames;
use Cms\Forms\Captcha;
use Cms\Forms\Appointments;
use Cms\Forms\Embed;
use Cms\Forms\FieldTypes;
use Cms\Forms\Guard;
use Cms\Forms\Sources;
use Cms\Forms\Upload;
use Cms\Forms\Logic;
use Cms\Models\Gallery;
use Cms\Models\Form;
use Cms\Models\Media;
use Cms\Ui\FieldWidth;
use Cms\Ui\Icons;

/**
 * Shortcode — processes shortcode tags embedded in content.
 *
 * Supported shortcodes:
 *   [gallery id="N"]  — renders a gallery's images
 *   [form id="N"]     — renders a contact form
 *   [phone], [phone key], [sms], [email], [hours]
 *                     — the business's details (`Site\Business::shortcodes()`)
 *   [video youtube="ID" start="S" title="T"]
 *                     — a YouTube video an earlier release stored this way,
 *                       drawn as YouTube's own embed. A video is pasted as
 *                       YouTube's embed code now, and kept as it is
 *                       (`Html\YouTube`).
 */
class Shortcode
{
    /**
     * Process all shortcodes in a content string.
     */
    public static function process(string $content): string
    {
        // [gallery id="N"] — and each also carries the CMS's old class names
        // (`Compat\OldNames::withOldClasses()`, through 0.7.x).
        $content = preg_replace_callback(
            '/\[gallery\s+id=["\']?(\d+)["\']?\s*\]/i',
            static fn (array $m): string => OldNames::withOldClasses(self::renderGallery($m)),
            $content
        );

        // [form id="N"]
        $content = preg_replace_callback(
            '/\[form\s+id=["\']?(\d+)["\']?\s*\]/i',
            static fn (array $m): string => OldNames::withOldClasses(self::renderForm($m)),
            $content
        );

        // [video …] — `videos()`, which a whole-page theme also calls alone.
        $content = self::videos($content);
        // [phone], [email], [hours] … — the business's details as they are now.
        $content = \Cms\Site\Business::shortcodes($content);

        return $content;
    }

    /**
     * [video …] ALONE — an earlier release's stored video, as YouTube's own
     * embed. A whole-page theme that draws [gallery] and [form] in its own
     * markup may call this for the rest. A paragraph holding nothing else
     * becomes the frame itself; then any left, wherever they sit.
     */
    public static function videos(string $content): string
    {
        $video   = static fn (array $m): string => self::renderVideo(self::attributes($m[1]));
        $content = (string) preg_replace_callback('#<p>\s*\[video\s+([^\]]*)\]\s*</p>#i', $video, $content);

        return (string) preg_replace_callback('#\[video\s+([^\]]*)\]#i', $video, $content);
    }

    /** `a="1" b='2'` → ['a' => '1', 'b' => '2'], entities decoded. */
    private static function attributes(string $raw): array
    {
        preg_match_all('/([a-z_]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|(\S+))/i', $raw, $found, PREG_SET_ORDER);
        $out = [];
        foreach ($found as $a) {
            $value = $a[2] !== '' ? $a[2] : (($a[3] ?? '') !== '' ? $a[3] : ($a[4] ?? ''));
            $out[strtolower($a[1])] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $out;
    }

    /**
     * A VIDEO AN EARLIER RELEASE STORED AS `[video]`, drawn as YouTube's own
     * embed (`Html\YouTube::iframe()`). A video is pasted as YouTube's embed
     * code now and kept as it is; this keeps the older ones showing.
     *
     * @param array<string, string> $a  youtube (required), start, title
     */
    public static function renderVideo(array $a): string
    {
        $id = (string) ($a['youtube'] ?? '');
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $id) !== 1) {
            return '<!-- [video] names no YouTube video -->';
        }

        return \Cms\Html\YouTube::iframe($id,
            ctype_digit((string) ($a['start'] ?? '')) ? (int) $a['start'] : 0, trim((string) ($a['title'] ?? '')));
    }

    /**
     * Render a [gallery id="N"] shortcode.
     */
    private static function renderGallery(array $matches): string
    {
        $galleryId = (int) $matches[1];
        $gallery   = Gallery::find($galleryId);

        if ($gallery === null) {
            return '<!-- Gallery #' . $galleryId . ' not found -->';
        }

        $items    = Gallery::getItems($galleryId);
        $settings = $gallery['settings'] ?? [];
        $layout   = $settings['layout'] ?? 'grid';
        $columns  = (int) ($settings['columns'] ?? 3);
        $lightbox = !empty($settings['lightbox']);

        if (empty($items)) {
            return '<!-- Gallery #' . $galleryId . ' is empty -->';
        }

        $html = '<div class="cms-gallery cms-gallery--' . htmlspecialchars($layout) . '"'
              . ' style="--gallery-columns: ' . $columns . '">';

        foreach ($items as $item) {
            $visibility = $item['visibility'] ?? 'public';
            $url = $visibility === 'private'
                ? BASE_URL . '/files/' . $item['attachment_id']
                : BASE_URL . '/uploads/' . $item['filename'];
            $alt = htmlspecialchars($item['alt_text'] ?? $item['original_name']);

            // AN ITEM'S DETAILS (migration 091). The grid shows the thumbnail
            // when there is one. The image opens the full file
            // when the lightbox is on, or else goes where the item links; with
            // the lightbox on, the link moves to the caption so both stay
            // reachable. `Url::resolve()` turns a `javascript:` link into `#`.
            $thumb   = !empty($item['thumb_attachment_id']) ? Media::getUrl((int) $item['thumb_attachment_id']) : '';
            $caption = trim((string) ($item['caption'] ?? ''));
            $link    = trim((string) ($item['link_url'] ?? ''));
            $link    = $link !== '' ? Url::resolve($link) : '';
            $href    = $lightbox ? $url : $link;

            $html .= '<figure class="cms-gallery__item">';
            if ($href !== '') {
                $html .= '<a href="' . htmlspecialchars($href) . '"'
                       . ($lightbox ? ' data-lightbox="gallery-' . $galleryId . '"' : '') . '>';
            }
            // Without a thumbnail, the image's own smaller widths, at a column's width.
            $set   = $thumb === '' && $visibility !== 'private' ? \Cms\Media\Sizes::srcset((int) $item['attachment_id']) : '';
            $html .= '<img src="' . htmlspecialchars($thumb !== '' ? $thumb : $url) . '"'
                   . ($set !== '' ? ' srcset="' . htmlspecialchars($set) . '" sizes="(min-width: 860px) ' . (int) ceil(860 / max(1, $columns))
                       . 'px, ' . (int) ceil(100 / max(1, $columns)) . 'vw"' : '')
                   . ' alt="' . $alt . '" loading="lazy">';
            if ($href !== '') {
                $html .= '</a>';
            }
            if ($caption !== '') {
                $text = htmlspecialchars($caption);
                if ($lightbox && $link !== '') {
                    $text = '<a href="' . htmlspecialchars($link) . '">' . $text . '</a>';
                }
                $html .= '<figcaption class="cms-gallery__caption">' . $text . '</figcaption>';
            }
            $html .= '</figure>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * A field's choice list: one per line, blanks dropped.
     *
     * ONE PLACE, because three branches need it — select, radio and checkboxes —
     * and the public validator has to agree with all of them about what counts
     * as an option.
     *
     * @param  array<string, mixed> $field
     * @return list<string>
     */
    /**
     * `choices()` as label and value: a dropdown, radio or checkbox option line
     * "label | value" shows the label and posts the value
     * (`FieldTypes::optionPairs()`); a sourced field's choice is both.
     *
     * @param  array<string, mixed> $field
     * @return list<array{label: string, value: string}>
     */
    private static function choicePairs(array $field): array
    {
        $type = (string) ($field['type'] ?? '');
        if (in_array($type, FieldTypes::LABELLED, true) && !FieldTypes::isSourced($type)) {
            return FieldTypes::optionPairs((string) ($field['options'] ?? ''));
        }

        return array_map(static fn (string $c): array => ['label' => $c, 'value' => $c], self::choices($field));
    }

    private static function choices(array $field): array
    {
        // A SOURCED FIELD'S OPTIONS ARE THE SITE'S CONTENT, not a textarea.
        // Answered HERE rather than at each branch, so the renderer, the
        // preview and the validator cannot disagree about where a field's
        // choices come from — which is the same reasoning that put the type
        // table in one place.
        if (FieldTypes::isSourced((string) ($field['type'] ?? ''))) {
            return Sources::choicesFor($field);
        }

        return array_values(array_filter(
            array_map('trim', explode("\n", (string) ($field['options'] ?? ''))),
            static fn(string $o): bool => $o !== ''
        ));
    }

    /**
     * A Likert's COLUMNS — the answers offered for every row.
     *
     * Its rows come from the Options list, which the author already knows how
     * to fill in; the columns are a second list, and they default to the five
     * everybody means by "a Likert scale" so the field works the moment it is
     * dropped rather than after two textareas have been filled in.
     *
     * @param  array<string, mixed> $field
     * @return list<string>
     */
    private static function likertColumns(array $field): array
    {
        $raw = array_values(array_filter(
            array_map('trim', explode("\n", (string) ($field['columns'] ?? ''))),
            static fn(string $o): bool => $o !== ''
        ));

        return $raw !== [] ? $raw : [
            'Strongly disagree', 'Disagree', 'Neutral', 'Agree', 'Strongly agree',
        ];
    }

    /**
     * Render a [form id="N"] shortcode.
     */
    private static function renderForm(array $matches): string
    {
        $formId = (int) $matches[1];
        $form   = Form::find($formId);

        if ($form === null) {
            return '<!-- Form #' . $formId . ' not found -->';
        }

        // EMBEDS ARE EXPANDED BEFORE ANYTHING LOOKS AT THE LIST, here and in
        // the submit controller both, so the two see the same fields. Expanding
        // only here would draw the embedded questions on the page while the
        // validator had never heard of them — collected and silently
        // discarded. See `Forms\Embed`.
        $fields   = Embed::expand(is_array($form['fields'] ?? null) ? $form['fields'] : []);
        $settings = $form['settings'] ?? [];

        if (empty($fields)) {
            return '<!-- Form #' . $formId . ' has no fields -->';
        }

        // A STATIC COPY (`Export\StaticCopy`) DRAWS A FORM ONLY WHERE IT CAN
        // SEND IT, to its own handler (`Export\Forms`); anywhere else the
        // form's place shows the words given for it.
        $exporting = defined('CMS_EXPORT') && is_array(CMS_EXPORT);
        if ($exporting && \Cms\Export\Forms::unsendable($form, (bool) (CMS_EXPORT['php'] ?? false)) !== null) {
            return \Cms\Export\Forms::instead($formId, (string) (CMS_EXPORT['instead'] ?? ''));
        }

        // ESCAPED ONCE, for the button and for the attribute form.js reads
        // when it puts the button back after a failed send: the browser hands
        // the script the words as typed, so "Send & go" comes back as itself.
        $submitLabel    = htmlspecialchars($settings['submit_label'] ?? 'Submit');
        $successMessage = htmlspecialchars($settings['success_message'] ?? 'Thank you! Your submission has been received.');
        // A page address, ending as Settings → Permalinks says: a front that
        // adds the slash with a 301 would turn the POST into a GET. AND A
        // PATH (`Url::path()`): the post goes to whichever host the page was
        // read on, so a site answering on `www.` and on its bare name, with a
        // Content-Security-Policy of `form-action 'self'`, takes it on both.
        $actionUrl      = $exporting
            ? \Cms\Export\Forms::action($formId)
            : \Cms\Helpers\Url::path('forms/' . $formId . '/submit');

        // SENT WITHOUT JAVASCRIPT. A browser
        // that posted the form itself comes back to this page marked
        // `sent=1&form={id}` (`FormController::afterSend()`), and THIS form —
        // not every form on the page, a footer sign-up included — says what
        // the script says after a send: the thank-you, in place of the form.
        if (($_GET['sent'] ?? '') === '1' && (string) ($_GET['form'] ?? '') === (string) $formId) {
            return '<div class="cms-form" data-form-id="' . $formId . '">'
                 . '<div class="cms-form__alert cms-form__alert--success" role="status">' . $successMessage . '</div>'
                 . '</div>';
        }

        $html  = '<div class="cms-form" data-form-id="' . $formId . '">';
        // MULTIPART WHEN ANYTHING SENDS A FILE. `new FormData(form)` posts as
        // multipart regardless, so the AJAX path works either way — but a form
        // that falls back to a plain browser submit would silently drop every
        // file without it, and "the upload just does not arrive" is the least
        // debuggable bug there is.
        $sendsFiles = false;
        foreach ($fields as $f) {
            if (in_array($f['type'] ?? '', ['file', 'signature'], true)) {
                $sendsFiles = true;
                break;
            }
        }
        $html .= '<form method="POST" action="' . htmlspecialchars($actionUrl) . '"'
               . ($sendsFiles ? ' enctype="multipart/form-data"' : '')
               . ' class="cms-form__form" data-cms-form data-submit-label="' . $submitLabel . '">';

        // THE TIME-TRAP AND THE TWO DECOYS (`Forms\Guard`).
        //
        // The decoys are kept out of sight by a stylesheet linked beside them
        // (`Guard::fields()`), not one the theme may or may not load: a
        // shortcode can be rendered into a theme that loads none of ours, and
        // a visible unlabelled box is one every real visitor fills in, every
        // submission then discarded as spam. Not an inline style either: a
        // page whose Content-Security-Policy refuses one would show them.
        $html .= Guard::fields();

        $html .= '<div class="cms-form__fields">';

        // A NAME MAP FOR THE LOGIC SUMMARY AND FOR logic.js. Nothing else needs
        // it, but building it inside the loop would rebuild it per field.
        $hasLogic = false;
        // Same rule as `logic.js`: the script is linked only when a field on
        // THIS form needs it. A page with a contact form should not carry a
        // drag system it never uses.
        $hasRanking = false;
        $hasSummary = false;
        $hasTags    = false;
        $hasCaptcha = false;
        $hasSignature = false;
        $hasUpload    = false;
        $hasPages     = false;
        $hasRepeater  = false;

        foreach ($fields as $field) {
            $name        = htmlspecialchars($field['name'] ?? '');
            $label       = htmlspecialchars($field['label'] ?? '');
            $type        = (string) ($field['type'] ?? 'text');
            $required    = !empty($field['required']);
            $placeholder = htmlspecialchars($field['placeholder'] ?? '');
            $width       = $field['width'] ?? 'full';
            $default     = htmlspecialchars((string) ($field['default'] ?? ''));
            $description = (string) ($field['description'] ?? '');
            $maxlength   = (int) ($field['maxlength'] ?? 0);
            $reqAttr     = $required ? ' required' : '';
            $maxAttr     = $maxlength > 0 ? ' maxlength="' . $maxlength . '"' : '';
            $inputId     = 'cms-f' . $formId . '-' . $name;

            // THE DESCRIPTION IS TIED TO THE CONTROL, not merely printed under
            // it. `aria-describedby` is what makes a screen reader announce
            // "Email, required, we only use this to reply" instead of reading
            // the field and leaving its explanation stranded as loose page text
            // that may never be reached. Computed here because every branch of
            // the switch below has to carry it.
            $descAttr = $description !== ''
                ? ' aria-describedby="' . $inputId . '-help"'
                : '';

            $meta = FieldTypes::get($type);

            // A PAGE BREAK IS A MARKER IN THE FLOW, and it goes HERE — before
            // the field wrapper is opened. Emitted after it, the `<hr>` would
            // sit inside an unclosed `.cms-form__field` div, not a direct child
            // of the field list, and `pages.js` could not see it: three steps
            // would render as one, silently, with the markers still in the
            // page.
            //
            // ── WHY NOT RENDER THE STEPS SERVER-SIDE ──────────────────────
            // The form has to work with no JavaScript, and a server-rendered
            // step one would hide steps two and three behind a control that
            // does not exist without a script — a form nobody can complete.
            // Flat markup submits everything at once and is merely long;
            // `pages.js` improves it into steps when it can.
            if ($type === 'pagebreak') {
                $html .= '<hr class="cms-form__pagebreak" data-pagebreak>';
                $hasPages = true;
                continue;
            }

            if ($type === 'hidden') {
                $html .= '<input type="hidden" name="' . $name . '" value="' . $default . '">';
                continue;
            }

            // ── THE LOGIC WRAPPER ────────────────────────────────────────────
            // `data-logic` carries the rule set as JSON and `logic.js` reads it.
            // The field is rendered VISIBLE and the script hides it on load —
            // rather than the server pre-hiding it — because the answer depends
            // on values the visitor has not entered yet. Rendering it hidden and
            // letting JS reveal it would mean a visitor with JavaScript off sees
            // a form MISSING fields, which is a form that cannot be completed;
            // this way they see all of them, which is a form that can.
            $logic     = Logic::parse($field['logic'] ?? null);
            $logicAttr = '';
            if ($logic !== null) {
                $hasLogic  = true;
                $logicAttr = " data-logic='"
                    . htmlspecialchars((string) json_encode($logic), ENT_QUOTES) . "'";
            }

            // FOUR WIDTHS, FROM THE ONE LIST. `FieldWidth::modifier()` returns
            // '' for full — the base class already spans everything — and
            // `--half`, `--third` or `--quarter` otherwise. Reading the list
            // rather than testing for the one string means a width added there
            // reaches this page without an edit here, which is the whole reason
            // that class exists.
            $widthClass = FieldWidth::modifier($width);
            $widthClass = $widthClass === '' ? '' : ' cms-form__field' . $widthClass;
            // `data-field` NAMES THE WRAPPER. Reading a field's identity off
            // the first input inside it works until a field has no named input
            // — Tags has one unnamed entry box and its answers only exist once
            // the script has run, so it would be invisible to anything looking
            // for a name. The wrapper always knows, and it is one attribute
            // rather than a rule per type.
            $html .= '<div class="cms-form__field' . $widthClass . '"'
                   . ' data-field="' . $name . '"' . $logicAttr . '>';

            // ── THE TWO TYPES THAT COLLECT NOTHING ───────────────────────────
            // `note` is ESCAPED text, never HTML. An HTML field in a form builder
            // is a stored-XSS surface that fires on every visitor of every page
            // the form is embedded in; every user-supplied value is escaped
            // at output, and an admin is still a user.
            if ($type === 'note') {
                $html .= '<p class="cms-form__note">'
                       . nl2br(htmlspecialchars((string) ($field['text'] ?? '')))
                       . '</p></div>';
                continue;
            }
            if ($type === 'divider') {
                $html .= '<hr class="cms-form__divider"></div>';
                continue;
            }

            // A SECTION NAMES WHAT COMES NEXT. `divider` draws the line;
            // this draws the line AND says what is under it, which is what a
            // long form needs to stop being one undifferentiated column. The
            // heading is the field's own Label and the optional line beneath
            // it is its Text, both escaped — see `note` for why never HTML.
            // A SUMMARY IS A VIEW, NOT A QUESTION. It collects nothing and
            // stores nothing; `summary.js` fills it in from the other fields as
            // they are answered. Rendered empty and populated on load rather
            // than server-side, because the answers do not exist yet when this
            // runs — the visitor has not typed them.
            //
            // WITH NO JAVASCRIPT it is an empty box with its heading, which is
            // honest: it promises a recap and shows none, rather than showing a
            // stale one. Nothing else on the form depends on it.
            if ($type === 'summary') {
                $html .= '<div class="cms-form__summary" data-summary>';
                if ($label !== '') {
                    $html .= '<h3 class="cms-form__summary-title">' . $label . '</h3>';
                }
                $html .= '<dl class="cms-form__summary-list" data-summary-list></dl>';
                $html .= '</div></div>';
                $hasSummary = true;
                continue;
            }

            // A CAPTCHA IS A CHALLENGE, NOT A QUESTION. It collects nothing
            // that is stored: `Forms\Captcha` checks it before any field is
            // read, and `input: false` keeps it out of the submission.
            // IN THE WRAPPER THE LOOP OPENED, closed once like every other
            // field's. A second wrapper here, closed alone, left the first
            // open: the browser put the form's thank-you inside the form,
            // which form.js hides once the message has gone, so a form with a
            // captcha thanked nobody.
            if ($type === 'captcha') {
                if ($label !== '') {
                    $html .= '<span class="cms-form__label">' . $label . '</span>';
                }
                $html .= Captcha::render($inputId);
                $html .= '</div>';
                $hasCaptcha = true;
                continue;
            }

            if ($type === 'section') {
                $html .= '<div class="cms-form__section">';
                if ($label !== '') {
                    $html .= '<h3 class="cms-form__section-title">' . $label . '</h3>';
                }
                $text = (string) ($field['text'] ?? '');
                if ($text !== '') {
                    $html .= '<p class="cms-form__section-text">'
                           . nl2br(htmlspecialchars($text)) . '</p>';
                }
                $html .= '</div></div>';
                continue;
            }

            // A CHECKBOX GETS NO STANDALONE LABEL. It carries its own
            // `.cms-form__checkbox-label` wrapping the box and the text
            // below, so emitting this one too would print the wording TWICE —
            // once above the row and once beside the box.
            //
            // A radio or checkbox GROUP gets a <legend>-shaped label rather than
            // a <label for>: `for` has to point at one control, and pointing it
            // at the first option makes clicking the group's heading tick that
            // option.
            $isGroup = in_array($type, ['radio', 'checkboxes'], true);
            if ($label !== '' && $type !== 'checkbox') {
                $html .= $isGroup
                    ? '<span class="cms-form__label">'
                    : '<label class="cms-form__label" for="' . $inputId . '">';
                $html .= $label;
                if ($required) {
                    $html .= ' <span class="cms-form__required">*</span>';
                }
                $html .= $isGroup ? '</span>' : '</label>';
            }

            // ── A COMPOSITE IS ONE FIELD IN SEVERAL BOXES ────────────────────
            // Name is First and Last; Address is six lines. They post as
            // `name[first]`, `name[last]` — one PHP array, one answer, one
            // required rule — and each box carries a small label UNDER it,
            // which is the whole reason this beats two Text fields: "First"
            // and "Last" say what the boxes are for without spending a heading
            // on each.
            //
            // The sub-label is a real `<label for>`, not a caption: it has to
            // be the box's accessible name, or a screen reader announces six
            // identically-named "Address" boxes.
            if (FieldTypes::isComposite($type)) {
                $html .= '<div class="cms-form__parts cms-form__parts--'
                       . htmlspecialchars($type) . '">';
                foreach (FieldTypes::parts($type) as $key => $partLabel) {
                    $partId = $inputId . '-' . $key;
                    $html .= '<div class="cms-form__part">';
                    $html .= '<input type="text" name="' . $name . '[' . $key . ']"'
                           . ' id="' . $partId . '"'
                           . ' class="cms-form__input"'
                           // REQUIRED IS THE FIELD'S, NOT EACH BOX'S. Putting
                           // it on every part would demand a Line 2 from
                           // everyone who has no Line 2; the server checks that
                           // the composite as a whole is not blank.
                           . $descAttr . '>';
                    $html .= '<label class="cms-form__part-label" for="' . $partId . '">'
                           . htmlspecialchars($partLabel) . '</label>';
                    $html .= '</div>';
                }
                $html .= '</div>';
                if ($description !== '') {
                    $html .= '<p class="cms-form__help" id="' . $inputId . '-help">'
                           . htmlspecialchars($description) . '</p>';
                }
                $html .= '</div>';
                continue;
            }

            switch ($type) {
                case 'textarea':
                    $html .= '<textarea name="' . $name . '" id="' . $inputId . '"'
                           . ' class="cms-form__input cms-form__textarea"'
                           . ' placeholder="' . $placeholder . '"'
                           . $descAttr . $reqAttr . $maxAttr . ' rows="5">' . $default . '</textarea>';
                    break;

                case 'appointment':
                    // ── THE WHOLE CALENDAR, ON THE PAGE ──────────────────────
                    // Radios grouped by day. A date picker plus a time dropdown
                    // needs the date chosen before the times are known, which
                    // means a round trip or a script; this needs neither and
                    // shows the visitor what is actually free without them
                    // hunting for it.
                    $free = Appointments::available($formId, $field);
                    if ($free === []) {
                        // AN EMPTY CALENDAR SAYS SO. A control with no options
                        // reads as broken; this reads as fully booked, which is
                        // what it is.
                        $html .= '<p class="cms-form__hint">'
                               . 'There are no times available at the moment.</p>';
                        break;
                    }
                    $html .= '<div class="cms-form__appt" role="radiogroup"'
                           . ($label !== '' ? ' aria-label="' . $label . '"' : '') . $descAttr . '>';
                    foreach ($free as $dayLabel => $slots) {
                        $html .= '<div class="cms-form__appt-day">';
                        $html .= '<h4 class="cms-form__appt-date">'
                               . htmlspecialchars((string) $dayLabel) . '</h4>';
                        $html .= '<div class="cms-form__appt-slots">';
                        foreach ($slots as $slot) {
                            $slotId = $inputId . '-' . md5($slot['key']);
                            $html .= '<label class="cms-form__appt-slot" for="' . $slotId . '">';
                            $html .= '<input type="radio" name="' . $name . '"'
                                   . ' id="' . $slotId . '"'
                                   . ' value="' . htmlspecialchars($slot['key']) . '"'
                                   // THE DAY IS IN THE ACCESSIBLE NAME. "2:30
                                   // PM" alone, forty times down a page, is
                                   // forty identical options.
                                   . ' aria-label="' . htmlspecialchars($dayLabel . ' at ' . $slot['label']) . '"'
                                   . $reqAttr . '>';
                            $html .= '<span>' . htmlspecialchars($slot['label']) . '</span>';
                            $html .= '</label>';
                        }
                        $html .= '</div></div>';
                    }
                    $html .= '</div>';
                    break;

                case 'repeater':
                    // ── ONE QUESTION, AS MANY ANSWERS AS THEY NEED ───────────
                    // The Options list names the columns; `repeater.js` clones
                    // the first row and renumbers. It posts `name[row][column]`
                    // — the same array shape Name and Address established, so
                    // `Forms\Answers::read()` reads it with the machinery already there.
                    //
                    // ONE ROW IS RENDERED SERVER-SIDE, so a visitor with no
                    // JavaScript can still answer once. That is a smaller
                    // answer than they might have given and it is a real one;
                    // rendering none would be a question with no box under it.
                    $cols = self::choices($field);
                    if ($cols === []) {
                        $cols = ['Item'];
                    }
                    $html .= '<div class="cms-form__repeater" data-repeater'
                           . ' data-repeater-name="' . $name . '">';
                    $html .= '<div class="cms-form__rep-rows" data-rep-rows>';
                    $html .= '<div class="cms-form__rep-row" data-rep-row>';
                    foreach ($cols as $c => $col) {
                        $cellId = $inputId . '-0-' . $c;
                        $html .= '<div class="cms-form__rep-cell">';
                        $html .= '<input type="text" class="cms-form__input"'
                               . ' id="' . $cellId . '"'
                               . ' name="' . $name . '[0][' . htmlspecialchars($col) . ']">';
                        $html .= '<label class="cms-form__part-label" for="' . $cellId . '">'
                               . htmlspecialchars($col) . '</label>';
                        $html .= '</div>';
                    }
                    // REMOVE IS PER ROW AND HIDDEN ON THE ONLY ROW — a control
                    // that empties the question is not a control, it is a trap.
                    $html .= '<button type="button" class="cms-form__rep-kill"'
                           . ' data-rep-kill aria-label="Remove this row">&times;</button>';
                    $html .= '</div></div>';
                    $html .= '<button type="button" class="cms-form__rep-add"'
                           . ' data-rep-add>Add another</button>';
                    $html .= '</div>';
                    $hasRepeater = true;
                    break;

                case 'dynamic':
                    // ONE OF THESE — a dropdown, because a list that grows with
                    // the site can be long and a hundred radio buttons is not a
                    // question, it is a wall.
                    $html .= '<select name="' . $name . '" id="' . $inputId . '"'
                           . ' class="cms-form__input cms-form__select"' . $descAttr . $reqAttr . '>';
                    $html .= '<option value="">— Select —</option>';
                    foreach (self::choices($field) as $opt) {
                        $html .= '<option value="' . htmlspecialchars($opt) . '">'
                               . htmlspecialchars($opt) . '</option>';
                    }
                    $html .= '</select>';
                    // AN EMPTY SOURCE SAYS SO. A dropdown with nothing in it
                    // looks like a broken control; this says which cupboard is
                    // bare, in the visitor's language rather than the
                    // developer's.
                    if (self::choices($field) === []) {
                        $html .= '<p class="cms-form__hint">Nothing to choose from yet.</p>';
                    }
                    break;

                case 'lookup':
                    // AS MANY AS APPLY. Checkboxes rather than a multi-select:
                    // a multiple `<select>` requires knowing to hold Ctrl,
                    // which is a keyboard trick, not an affordance.
                    $html .= '<div class="cms-form__choices" role="group"' . $descAttr . '>';
                    foreach (self::choices($field) as $n => $opt) {
                        $optId = $inputId . '-' . $n;
                        $html .= '<label class="cms-form__choice" for="' . $optId . '">';
                        $html .= '<input type="checkbox" name="' . $name . '[]"'
                               . ' id="' . $optId . '" value="' . htmlspecialchars($opt) . '">';
                        $html .= '<span>' . htmlspecialchars($opt) . '</span>';
                        $html .= '</label>';
                    }
                    $html .= '</div>';
                    if (self::choices($field) === []) {
                        $html .= '<p class="cms-form__hint">Nothing to choose from yet.</p>';
                    }
                    break;

                case 'select':
                    $html .= '<select name="' . $name . '" id="' . $inputId . '"'
                           . ' class="cms-form__input cms-form__select"' . $descAttr . $reqAttr . '>';
                    $html .= '<option value="">— Select —</option>';
                    // THE LABEL IS SHOWN, THE VALUE POSTED ("label | value").
                    foreach (self::choicePairs($field) as $opt) {
                        $html .= '<option value="' . htmlspecialchars($opt['value']) . '">'
                               . htmlspecialchars($opt['label']) . '</option>';
                    }
                    $html .= '</select>';
                    break;

                case 'radio':
                case 'checkboxes':
                    // `name[]` for checkboxes, because a set of them posts many
                    // values and PHP keeps only the last without the brackets —
                    // three ticked boxes would arrive as one answer.
                    $ctrl = $type === 'radio' ? 'radio' : 'checkbox';
                    $post = $type === 'radio' ? $name : $name . '[]';
                    $html .= '<div class="cms-form__choices" role="group"' . $descAttr . '>';
                    // THE LABEL IS SHOWN, THE VALUE POSTED ("label | value").
                    foreach (self::choicePairs($field) as $n => $opt) {
                        $optId = $inputId . '-' . $n;
                        $html .= '<label class="cms-form__choice" for="' . $optId . '">';
                        $html .= '<input type="' . $ctrl . '" name="' . $post . '"'
                               . ' id="' . $optId . '" value="' . htmlspecialchars($opt['value']) . '"'
                               // REQUIRED GOES ON THE RADIOS ONLY. On a checkbox
                               // SET the browser reads it per box, so `required`
                               // would demand every single one be ticked.
                               . ($type === 'radio' ? $reqAttr : '') . '>';
                        $html .= '<span>' . htmlspecialchars($opt['label']) . '</span>';
                        $html .= '</label>';
                    }
                    $html .= '</div>';
                    break;

                case 'scale':
                case 'rating':
                    // A ROW OF RADIOS. Min/max come from the Number field's own
                    // boxes for a scale; a rating is 1..5 unless it says
                    // otherwise. The INPUT is a real radio in both cases, so
                    // the answer arrives, validates and stores exactly like a
                    // radio group's — the stars are a label, not a mechanism.
                    $lo = $type === 'rating' ? 1 : (int) ($field['min'] ?? 1);
                    $hi = $type === 'rating' ? (int) ($field['max'] ?? 5)
                                             : (int) ($field['max'] ?? 5);
                    if ($hi < $lo) { $hi = $lo; }
                    // A ceiling, because a scale of 1..900 is a row nobody can
                    // read and a request nobody meant to send.
                    if ($hi - $lo > 20) { $hi = $lo + 20; }

                    $html .= '<div class="cms-form__' . $type . '" role="radiogroup"'
                           . ($label !== '' ? ' aria-label="' . $label . '"' : '') . $descAttr . '>';
                    for ($n = $lo; $n <= $hi; $n++) {
                        $optId = $inputId . '-' . $n;
                        $html .= '<label class="cms-form__' . $type . '-step" for="' . $optId . '">';
                        $html .= '<input type="radio" name="' . $name . '" id="' . $optId . '"'
                               . ' value="' . $n . '"' . $reqAttr . '>';
                        // THE NUMBER IS ALWAYS IN THE MARKUP, even for stars —
                        // it is the accessible name of that radio, and a
                        // star-shaped control that announces nothing is a
                        // control a screen reader cannot use. CSS hides it for
                        // a rating and shows it for a scale.
                        $html .= '<span class="cms-form__' . $type . '-mark">' . $n . '</span>';
                        $html .= '</label>';
                    }
                    $html .= '</div>';

                    // THE ENDS ARE LABELLED WHERE THE AUTHOR LABELLED THEM. A
                    // 0-10 row with nothing at either end asks the visitor to
                    // guess which way is good.
                    $lowText  = trim((string) ($field['scale_low'] ?? ''));
                    $highText = trim((string) ($field['scale_high'] ?? ''));
                    if ($lowText !== '' || $highText !== '') {
                        $html .= '<div class="cms-form__scale-ends">'
                               . '<span>' . htmlspecialchars($lowText) . '</span>'
                               . '<span>' . htmlspecialchars($highText) . '</span>'
                               . '</div>';
                    }
                    break;

                case 'file':
                    // `accept` IS A COURTESY, NEVER A CHECK. It filters the
                    // file picker so a visitor is not offered files that will
                    // be refused; `Forms\Upload` re-derives the type from the
                    // BYTES on the server, because this attribute is a hint the
                    // client is free to ignore.
                    $html .= '<input type="file" name="' . $name . '" id="' . $inputId . '"'
                           . ' class="cms-form__file"'
                           . ' accept="' . htmlspecialchars(Upload::acceptAttribute($field)) . '"'
                           . $descAttr . $reqAttr . '>';
                    // The kinds it takes when the field names some, and the size.
                    $limitMb = round(Upload::limitFor($field) / 1024 / 1024, 1);
                    $kinds   = Upload::namesFor($field);
                    $html .= '<p class="cms-form__hint">'
                           . ($kinds !== '' ? htmlspecialchars($kinds) . ', up to ' : 'Up to ') . $limitMb . ' MB.</p>';
                    $hasUpload = true;
                    break;

                case 'signature':
                    // ── DRAWN, THEN POSTED AS AN IMAGE ───────────────────────
                    // The canvas is the control and the hidden input is the
                    // answer: `signature.js` writes a PNG data URL into it as
                    // the visitor draws, and the server turns that back into a
                    // file through the same `Forms\Upload` path everything
                    // else uses.
                    //
                    // WITH NO JAVASCRIPT there is no canvas worth drawing on,
                    // so the field says so rather than showing an empty box
                    // that cannot be signed.
                    $html .= '<div class="cms-form__signature" data-signature>';
                    $html .= '<canvas class="cms-form__signature-pad" data-signature-pad'
                           . ' width="600" height="200"'
                           . ' aria-label="' . ($label !== '' ? $label : 'Signature') . '"></canvas>';
                    $html .= '<input type="hidden" name="' . $name . '" data-signature-value>';
                    $html .= '<button type="button" class="cms-form__signature-clear"'
                           . ' data-signature-clear>Clear</button>';
                    $html .= '<noscript><p class="cms-form__hint">'
                           . 'A signature needs JavaScript to draw.</p></noscript>';
                    $html .= '</div>';
                    $hasSignature = true;
                    break;

                case 'tags':
                    // ── SEVERAL SHORT ANSWERS, TYPED ─────────────────────────
                    // One visible box to type into and a hidden input per tag
                    // already entered. `tags.js` adds and removes them; the
                    // hidden inputs are what post, so the answers survive a
                    // page that never ran the script — a visitor with no
                    // JavaScript gets a plain text box whose value posts as one
                    // tag, which is a smaller answer but a real one.
                    $html .= '<div class="cms-form__tags" data-tags'
                           . ' data-tags-name="' . $name . '">';
                    $html .= '<ul class="cms-form__tag-list" data-tag-list></ul>';
                    $html .= '<input type="text" class="cms-form__input"'
                           . ' id="' . $inputId . '" data-tag-entry'
                           . ' placeholder="' . $placeholder . '"'
                           . ' autocomplete="off"' . $descAttr . '>';
                    // NO `name` ON THE VISIBLE BOX. It is an entry field, not
                    // an answer: whatever is half-typed in it when the form is
                    // submitted would otherwise arrive as a tag nobody
                    // finished. `tags.js` commits it on submit instead.
                    $html .= '<noscript><input type="hidden" name="' . $name . '[0]" value=""></noscript>';
                    $html .= '</div>';
                    $hasTags = true;
                    break;

                case 'ranking':
                    // ── DRAG THE OPTIONS INTO THEIR ORDER ────────────────────
                    // The list IS the answer, so the order of these <li>s is
                    // what gets posted. Each carries its own hidden input, and
                    // `ranking.js` rewrites their `name` indices after a drag —
                    // the same discipline the field group editor uses, because
                    // PHP rebuilds an array from the indices in the request and
                    // not from the order the elements happened to appear in.
                    //
                    // WITHOUT JAVASCRIPT IT STILL SUBMITS. The hidden inputs
                    // are in the markup and already in the author's order, so a
                    // visitor with no JS posts that order rather than nothing —
                    // which is a ranking nobody chose, but it is an answer and
                    // it validates. A control that silently posts empty is
                    // worse.
                    $html .= '<ol class="cms-form__ranking" data-ranking>';
                    foreach (self::choices($field) as $n => $opt) {
                        // THE POSITION IS PRINTED, not left to a list marker.
                        // `display: flex` on an <li> suppresses its marker in
                        // every engine, so `list-style: decimal` drew nothing —
                        // a ranking with no numbers on it, which is the one
                        // thing a ranking has to show. It is also DATA: the
                        // number is the answer, and `ranking.js` repaints it
                        // after a drag so it cannot say 1 while sitting third.
                        $html .= '<li class="cms-form__rank" data-rank>';
                        $html .= '<span class="cms-form__rank-num" data-rank-num>'
                               . ($n + 1) . '</span>';
                        // THE LIBRARY GLYPH, not a hand-drawn one. `Icons::render()`
                        // reads the SVG off disk, so it works on a public page as
                        // well as an admin one — and every glyph comes from the
                        // library: a hand-drawn <svg> beside library icons shows
                        // in a visibly different weight.
                        $html .= '<span class="cms-form__rank-grip" data-rank-grip'
                               . ' aria-hidden="true">'
                               . Icons::render('grip-lines', 'cms-form__rank-icon')
                               . '</span>';
                        $html .= '<span class="cms-form__rank-text">'
                               . htmlspecialchars($opt) . '</span>';
                        $html .= '<input type="hidden" name="' . $name . '[' . $n . ']"'
                               . ' value="' . htmlspecialchars($opt) . '">';
                        $html .= '</li>';
                    }
                    $html .= '</ol>';
                    $hasRanking = true;
                    break;

                case 'likert':
                    // A SCALE ASKED SEVERAL TIMES. Each row is a statement from
                    // the Options list and posts its own answer under
                    // `name[row]`, which is the same array shape Name and
                    // Address post — so `Forms\Answers::read()`'s composite branch reads it
                    // and nothing new is needed to store it.
                    $rows = self::choices($field);
                    $cols = self::likertColumns($field);
                    $html .= '<table class="cms-form__likert">';
                    $html .= '<thead><tr><td></td>';
                    foreach ($cols as $col) {
                        $html .= '<th scope="col">' . htmlspecialchars($col) . '</th>';
                    }
                    $html .= '</tr></thead><tbody>';
                    foreach ($rows as $r => $rowText) {
                        $rowKey = 'r' . $r;
                        $html .= '<tr><th scope="row">' . htmlspecialchars($rowText) . '</th>';
                        foreach ($cols as $c => $col) {
                            $cellId = $inputId . '-' . $rowKey . '-' . $c;
                            $html .= '<td>';
                            // THE CELL'S LABEL NAMES BOTH AXES. "Agree" alone is
                            // meaningless in a grid of thirty identical radios;
                            // a screen reader reads the row and column headers
                            // for a real <table>, and this is the fallback for
                            // everything that does not.
                            $html .= '<label for="' . $cellId . '">';
                            $html .= '<input type="radio" name="' . $name . '[' . $rowKey . ']"'
                                   . ' id="' . $cellId . '" value="' . htmlspecialchars($col) . '"'
                                   . ' aria-label="' . htmlspecialchars($rowText . ' — ' . $col) . '">';
                            $html .= '</label></td>';
                        }
                        $html .= '</tr>';
                    }
                    $html .= '</tbody></table>';
                    break;

                case 'toggle':
                    // ON OR OFF, AND IT READS AS BOTH. A checkbox is "tick this
                    // if it applies"; a toggle is a setting with two states, so
                    // it gets a track and a knob rather than a box. It posts the
                    // same '1' or nothing, so `Forms\Answers::read()`'s checkbox branch
                    // covers it and nothing downstream knows the difference.
                    $html .= '<label class="cms-form__toggle">';
                    $html .= '<input type="checkbox" name="' . $name . '" value="1"'
                           . ' id="' . $inputId . '" class="cms-form__toggle-input"'
                           . $descAttr . $reqAttr . '>';
                    $html .= '<span class="cms-form__toggle-track" aria-hidden="true"></span>';
                    $html .= '</label>';
                    break;

                case 'range':
                    // MIN, MAX AND STEP ARE THE NUMBER FIELD'S OWN BOXES, so a
                    // slider needs no second set of controls to keep in step
                    // with. The current value is printed beside it, because a
                    // slider with no readout is a control whose answer you
                    // cannot state.
                    $min  = $field['min']  ?? '0';
                    $max  = $field['max']  ?? '100';
                    $step = $field['step'] ?? '1';
                    $html .= '<div class="cms-form__range">';
                    $html .= '<input type="range" name="' . $name . '" id="' . $inputId . '"'
                           . ' class="cms-form__range-input"'
                           . ' min="' . htmlspecialchars((string) $min) . '"'
                           . ' max="' . htmlspecialchars((string) $max) . '"'
                           . ' step="' . htmlspecialchars((string) $step) . '"'
                           . ' value="' . ($default !== '' ? $default : htmlspecialchars((string) $min)) . '"'
                           . $descAttr . '>';
                    $html .= '<output class="cms-form__range-value" for="' . $inputId . '">'
                           . ($default !== '' ? $default : htmlspecialchars((string) $min))
                           . '</output>';
                    $html .= '</div>';
                    break;

                case 'checkbox':
                    // The required mark lives HERE, not on a separate label —
                    // this row is the checkbox's only label. Without it a
                    // required tickbox would look optional next to fields that
                    // say so.
                    $html .= '<label class="cms-form__checkbox-label">';
                    $html .= '<input type="checkbox" name="' . $name . '" value="1"'
                           . ' id="' . $inputId . '" class="cms-form__checkbox"' . $descAttr . $reqAttr . '>';
                    $html .= '<span>' . $label;
                    if ($required) {
                        $html .= ' <span class="cms-form__required">*</span>';
                    }
                    $html .= '</span>';
                    $html .= '</label>';
                    break;

                default:
                    // EVERY REMAINING TYPE IS ONE `<input>` WITH A DIFFERENT
                    // `type`, so they share this branch rather than repeating
                    // seven near-identical cases. `FieldTypes` decides which are
                    // legal; a stored type this map does not name renders as
                    // text, and the builder cannot offer one.
                    $htmlType = [
                        'email'    => 'email',
                        'tel'      => 'tel',
                        'url'      => 'url',
                        'number'   => 'number',
                        'date'     => 'date',
                        'time'     => 'time',
                        'password' => 'password',
                    ][$type] ?? 'text';
                    $html .= '<input type="' . $htmlType . '" name="' . $name . '"'
                           . ' id="' . $inputId . '" class="cms-form__input"'
                           . ' value="' . $default . '"'
                           . ($meta['placeholder'] ? ' placeholder="' . $placeholder . '"' : '')
                           . $descAttr . $reqAttr . $maxAttr . '>';
                    break;
            }

            if ($description !== '') {
                $html .= '<p class="cms-form__help" id="' . $inputId . '-help">'
                       . htmlspecialchars($description) . '</p>';
            }

            $html .= '</div>';
        }

        $html .= '</div>'; // .cms-form__fields

        $html .= '<div class="cms-form__actions">';
        $html .= '<button type="submit" class="cms-form__submit">' . $submitLabel . '</button>';
        $html .= '</div>';

        $html .= '</form>';

        // Success/error message container
        $html .= '<div class="cms-form__alert" hidden></div>';

        $html .= '</div>'; // .cms-form

        // logic.js ONLY WHEN A FIELD USES IT. A form with no conditional field
        // has nothing for it to do, and a script tag on every page carrying any
        // form is a request bought for nothing. Same-origin, no dependency.
        // form.js binds it, after this file.
        if ($hasLogic) {
            $html .= '<script src="' . htmlspecialchars(Asset::sitePath('assets/js/logic.js')) . '"></script>';
        }

        // AND reorder.js ONLY WHEN A RANKING NEEDS IT — same rule, same reason.
        // It is an identical copy of the admin's own file, so the drag a
        // visitor does is the drag the owner does.
        // A VENDOR'S SCRIPT, ONLY WHEN AN OWNER HAS ASKED FOR ONE. The built-in
        // challenge needs no script at all, and it is the default — the CMS
        // ships no third-party script. This tag exists because the person who
        // owns the site chose a provider and pasted their own keys in.
        if ($hasCaptcha && Captcha::isRemote()) {
            $html .= '<script src="' . htmlspecialchars(Captcha::scriptUrl())
                   . '" async defer></script>';
        }
        if ($hasRepeater) {
            $html .= '<script src="' . htmlspecialchars(Asset::sitePath('assets/js/repeater.js')) . '"></script>';
        }
        if ($hasPages) {
            $html .= '<script src="' . htmlspecialchars(Asset::sitePath('assets/js/pages.js')) . '"></script>';
        }
        if ($hasSignature) {
            $html .= '<script src="' . htmlspecialchars(Asset::sitePath('assets/js/signature.js')) . '"></script>';
        }
        if ($hasTags) {
            $html .= '<script src="' . htmlspecialchars(Asset::sitePath('assets/js/tags.js')) . '"></script>';
        }
        if ($hasSummary) {
            $html .= '<script src="' . htmlspecialchars(Asset::sitePath('assets/js/summary.js')) . '"></script>';
        }
        if ($hasRanking) {
            $html .= '<script src="' . htmlspecialchars(Asset::sitePath('assets/js/reorder.js')) . '"></script>';
            $html .= '<script src="' . htmlspecialchars(Asset::sitePath('assets/js/ranking.js')) . '"></script>';
        }

        // IN A STATIC COPY the page was drawn when the copy was made, and so
        // was its token: form-copy.js asks the copy's handler for a fresh one
        // as the page loads. Without a script nothing can, so the visitor is
        // told, with the words given for a form the copy cannot send.
        // Opened from a folder, nothing can send it either: the words wait,
        // hidden, for form-copy.js to put them in the form's place.
        if ($exporting) {
            $html .= \Cms\Export\Forms::noscript((string) (CMS_EXPORT['instead'] ?? ''));
            $html .= \Cms\Export\Forms::offline($formId, (string) (CMS_EXPORT['instead'] ?? ''));
            $html .= '<script src="' . htmlspecialchars(Asset::sitePath('assets/js/form-copy.js')) . '"></script>';
        }

        // SENT BY SCRIPT, from this site's file (form.js) and nothing
        // inline, so a page whose Content-Security-Policy allows only this
        // site's scripts sends it the same way. It binds each form once.
        $html .= '<script src="' . htmlspecialchars(Asset::sitePath('assets/js/form.js')) . '"></script>';

        return $html;
    }
}
