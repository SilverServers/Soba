<?php

declare(strict_types=1);

use Cms\Forms\FieldTypes;
use Cms\Forms\Logic;
use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * THE FORM BUILDER — palette, live canvas, options rail.
 *
 * ── WHY THIS SHAPE ──────────────────────────────────────────────────────────
 * A stack of grey accordion rows makes you open every field in turn to see
 * whether a form reads well, assembling the page in your head, and never shows
 * you the form. This is three regions, and the middle one is THE FORM:
 *
 *   ┌ head ─────────────────────────────────────────────────────────────────┐
 *   │ title            Build · Settings · Submissions          Embed  Save  │
 *   ├──────────────┬────────────────────────────────────────────────────────┤
 *   │ Add fields   │                                                        │
 *   │ Field options│   the form, as the visitor will see it                 │
 *   │              │   click a field to select it                           │
 *   │  [palette]   │                                                        │
 *   └──────────────┴────────────────────────────────────────────────────────┘
 *
 * ── THE TYPE LIST IS NOT WRITTEN HERE ───────────────────────────────────────
 * It comes from `Cms\Forms\FieldTypes`, which the public renderer and the
 * controller read too. `<option>` tags hard-coded here beside a separate switch
 * in `Shortcode::renderForm()` would let the builder offer a type the renderer
 * does not handle — a control the visitor never sees. One table, and adding a
 * row to it adds the palette button, the canvas preview, the summary label and
 * the server's whitelist at once.
 *
 * ── BUILD AND SETTINGS ARE PANES, NOT PAGES ─────────────────────────────────
 * Both live inside ONE `<form>` and one Save posts everything. As separate
 * screens with separate saves, a half-finished form plus a settings change
 * would be two round trips and two chances to lose one. The
 * tab strip switches which pane is visible; nothing leaves the DOM, so a field
 * added on Build and a recipient typed on Settings save together.
 *
 * Submissions is a real link, because it is a different resource with its own
 * route and its own list — not a pane of the editor.
 *
 * ── EVERYTHING POSTS AS ONE JSON STRING ─────────────────────────────────────
 * `fields_json`. That is not a detail worth changing: the
 * controller decodes one input, and every control in the rail is NAMELESS so a
 * half-built field cannot post stray keys.
 *
 * @var array<string, mixed>|null $form      null on create
 * @var array<string, mixed>      $formData  $_POST on a failed submit
 * @var list<string>              $errors
 * @var bool                      $saved     showEdit() only, from ?saved=1
 * @var array<string, mixed>      $user
 */

$isNew    = ($form ?? null) === null;
$formData = $formData ?? [];
$errors   = $errors   ?? [];
$saved    = $saved    ?? false;
// WHERE IT IS SHOWN: the site's form places,
// which form holds each, and the form a save just took the place from.
$placesList = $placesList ?? null;
$holders    = $holders    ?? [];
$movedFrom  = $movedFrom  ?? null;
$placeNow   = isset($formData['place_key']) && is_string($formData['place_key'])
    ? ($formData['place_key'] !== '' ? $formData['place_key'] : null)
    : ($form['place_key'] ?? null);

$id     = $isNew ? 0 : (int) $form['id'];
$action = $isNew ? Url::admin('forms/new') : Url::admin('forms/' . $id . '/edit');

/** $_POST wins on a failed submit, so an error never discards the edit. */
$val = static function (string $key, string $fallback = '') use ($formData): string {
    if (isset($formData[$key]) && !is_array($formData[$key])) {
        return (string) $formData[$key];
    }
    return $fallback;
};

// `Form::find()` ALREADY json_decodes BOTH COLUMNS, so `$form['settings']` and
// `$form['fields']` arrive as ARRAYS, not strings. Decoding again returns null
// and casting to string yields the literal word "Array" — a hidden input
// reading `value="Array"`.
$settings = [];
if (!$isNew) {
    $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
}
$setting = static function (string $key, string $fallback = '') use ($settings, $formData): string {
    if (isset($formData[$key]) && !is_array($formData[$key])) {
        return (string) $formData[$key];
    }
    return (string) ($settings[$key] ?? $fallback);
};

// Three sources, in order: what was just posted (a failed submit must not
// discard the fields), the decoded array from the model, or an empty list.
if (isset($formData['fields_json']) && is_string($formData['fields_json'])) {
    $fieldsJson = $formData['fields_json'];
} elseif (is_array($form['fields'] ?? null)) {
    $fieldsJson = json_encode($form['fields']);
} else {
    $fieldsJson = '[]';
}
if (!is_string($fieldsJson) || trim($fieldsJson) === '') {
    $fieldsJson = '[]';
}

$types  = FieldTypes::all();
$groups = FieldTypes::GROUPS;

// The editor owns the whole viewport: a 240px content column with a form
// preview squeezed into it is not a preview of anything.
$mainFlush = true;

$pageTitle = $isNew ? 'Add form' : 'Edit ' . (string) $form['title'];

ob_start();
?>
<form class="builder" method="post" action="<?= h($action) ?>" data-form-builder>
    <?= Csrf::field() ?>

    <?php // THE ONE INPUT THE CONTROLLER READS FOR FIELDS. Every control in the
          // rail is nameless; this carries the whole definition. ?>
    <input type="hidden" name="fields_json" value="<?= h($fieldsJson) ?>" data-fields-json>

    <header class="builder__head">
        <a class="builder__back" href="<?= h(Url::admin('forms')) ?>"
           data-tip="Back to forms" aria-label="Back to forms">
            <?= icon('angle-left', 'icon icon--sm') ?>
        </a>

        <?php // THE TITLE IS THE TITLE INPUT. A heading with a separate "Form
              // title" box under it says the same word twice and puts the thing
              // you rename two clicks from the thing you read. ?>
        <input class="builder__title" type="text" name="title" required
               value="<?= h($val('title', (string) ($form['title'] ?? ''))) ?>"
               placeholder="Untitled form" autocomplete="off"
               aria-label="Form title">

        <?php // `.tabs` — the component admin.css already owns, whose rule is
              // "a background swap, never a coloured underline", rather than a
              // second tab class with an underline. `--inline` drops the
              // strip's own bottom rule, which belongs at the top of a page and
              // not inside a toolbar that has one. ?>
        <nav class="tabs tabs--inline" role="tablist" aria-label="Form editor sections">
            <button type="button" class="tabs__tab is-active" role="tab"
                    aria-selected="true" data-tab="build">Build</button>
            <button type="button" class="tabs__tab" role="tab"
                    aria-selected="false" data-tab="settings">Settings</button>
            <?php if (!$isNew): ?>
                <a class="tabs__tab" href="<?= h(Url::admin('forms/' . $id . '/submissions')) ?>">
                    Submissions
                </a>
            <?php endif; ?>
        </nav>

        <div class="builder__actions">
            <?php if (!$isNew): ?>
                <?php // THE SHORTCODE IS THE ONLY WAY THIS FORM GETS ONTO A PAGE
                      // — `Shortcode::process()` matches `[form id="N"]`. It
                      // belongs beside Save, not in a sidebar card three scrolls
                      // down, because embedding is what you do after saving. ?>
                <button type="button" class="btn btn--sm" data-embed-copy
                        data-embed='[form id="<?= $id ?>"]'>
                    <?= icon('copy', 'icon icon--sm') ?>Embed
                </button>
            <?php endif; ?>
            <button type="submit" class="btn btn--primary btn--sm">
                <?= $isNew ? 'Create form' : 'Save changes' ?>
            </button>
        </div>
    </header>

    <?php if ($saved || $errors !== []): ?>
        <div class="builder__flash">
            <?php if ($saved): ?>
                <div class="flash flash--success">Form saved.</div>
                <?php if ($movedFrom !== null && ($place = \Cms\Helpers\Places::find($form['place_key'] ?? null)) !== null): ?>
                    <div class="flash flash--info"><?= h($place['name']) ?> showed “<?= h((string) $movedFrom['title']) ?>”; it now shows this form.</div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($errors !== []): ?>
                <div class="alert alert--danger">
                    <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
                    <div class="alert__body">
                        <p class="alert__title">
                            <?= count($errors) === 1 ? 'One thing needs fixing' : count($errors) . ' things need fixing' ?>
                        </p>
                        <ul class="alert__text">
                            <?php foreach ($errors as $error): ?>
                                <li><?= h($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php // ══ BUILD ═══════════════════════════════════════════════════════ ?>
    <div class="builder__body" data-pane="build">

        <aside class="rail" data-rail>
            <div class="rail__switch" role="tablist" aria-label="Rail">
                <button type="button" class="rail__switch-btn is-active" role="tab"
                        aria-selected="true" data-rail-tab="add">Add fields</button>
                <button type="button" class="rail__switch-btn" role="tab"
                        aria-selected="false" data-rail-tab="options">Field options</button>
            </div>

            <?php // ── ADD FIELDS ────────────────────────────────────────── ?>
            <div class="rail__pane" data-rail-pane="add">
                <div class="rail__search">
                    <?= icon('magnifying-glass', 'rail__search-icon icon icon--sm') ?>
                    <input class="input" type="search" data-palette-search
                           placeholder="Search fields" aria-label="Search field types"
                           autocomplete="off">
                </div>

                <?php // `.list` / `.list__row` — core.css §5's component for "an
                      // ordered set of rows you pick from", which is exactly what
                      // a palette is. A bespoke grid would be a second vocabulary
                      // for a thing this admin already has, and fourteen bordered
                      // cards look like debris on the rail rather than a list. ?>
                <?php foreach ($groups as $groupKey => $groupLabel): ?>
                    <div class="rail__group" data-palette-group="<?= h($groupKey) ?>">
                        <p class="rail__group-title"><?= h($groupLabel) ?></p>
                        <div class="list">
                            <?php foreach ($types as $typeKey => $type): ?>
                                <?php if ($type['group'] !== $groupKey) { continue; } ?>
                                <button type="button" class="list__row"
                                        data-palette-add="<?= h($typeKey) ?>"
                                        data-palette-name="<?= h(strtolower((string) $type['label'])) ?>">
                                    <?= icon((string) $type['icon'], 'icon icon--sm') ?>
                                    <span class="list__row-title"><?= h((string) $type['label']) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <p class="rail__none" data-palette-none hidden>No field type matches that.</p>

                <?php // NOTHING IN THIS PALETTE IS LOCKED: no field type is
                      // greyed out behind an upsell. A control you cannot use
                      // is an advertisement, and this admin has nothing to
                      // sell. ?>
            </div>

            <?php // ── FIELD OPTIONS ─────────────────────────────────────── ?>
            <div class="rail__pane" data-rail-pane="options" hidden>

                <?php // `.empty` — core.css §11, glyph + title + one supporting
                      // line, not a bespoke copy of the same three elements. ?>
                <div class="empty" data-options-empty>
                    <?= icon('sliders', 'empty__glyph', '', 28) ?>
                    <p class="empty__title">No field selected</p>
                    <p class="empty__text">
                        Click a field in the form to change its settings.
                    </p>
                </div>

                <div class="rail__options" data-options hidden>

                    <div class="rail__head">
                        <span class="rail__head-title" data-opt-kind>Text field</span>
                        <span class="rail__head-id" data-opt-id></span>
                    </div>

                    <div class="field">
                        <label class="label" for="opt-label">Field label</label>
                        <input class="input" type="text" id="opt-label" data-opt="label"
                               autocomplete="off">
                    </div>

                    <div class="rail__flags">
                        <label class="check-list__row">
                            <input type="checkbox" data-opt="required">
                            <span>Required</span>
                        </label>
                    </div>

                    <div class="field" data-opt-wrap="placeholder">
                        <label class="label" for="opt-placeholder">Placeholder</label>
                        <input class="input" type="text" id="opt-placeholder"
                               data-opt="placeholder" autocomplete="off">
                    </div>

                    <div class="field" data-opt-wrap="options">
                        <label class="label" for="opt-options">Options</label>
                        <textarea class="textarea" id="opt-options" data-opt="options"
                                  rows="5" placeholder="One per line"></textarea>
                        <p class="form-help">One choice per line, in the order they appear. To store something
                            other than what is shown (a dropdown, radio buttons or checkboxes): <code>label | value</code>.</p>
                    </div>

                    <div class="field" data-opt-wrap="description">
                        <label class="label" for="opt-description">Description</label>
                        <textarea class="textarea" id="opt-description" data-opt="description"
                                  rows="2"></textarea>
                        <p class="form-help">Shown under the field, for anything that needs explaining.</p>
                    </div>

                    <div class="field" data-opt-wrap="text">
                        <label class="label" for="opt-text">Text</label>
                        <textarea class="textarea" id="opt-text" data-opt="text" rows="4"></textarea>
                        <p class="form-help">
                            Plain text — it is escaped before it is printed, so a form
                            cannot carry markup onto the page it is embedded in.
                        </p>
                    </div>

                    <?php // ── THE GRADED FAMILY'S OWN SETTINGS ─────────────
                          // Bounds are SHARED with Number and Slider rather
                          // than invented per type: one set of three boxes for
                          // four types, so a Scale and a Number cannot end up
                          // meaning different things by "max".
                          //
                          // Every one of these is read by
                          // `Shortcode::renderForm()` and named in
                          // `FormController::parseFieldsJson()`'s whitelist. A
                          // control here with no reader is a failure; a reader
                          // with no control is the same failure facing the
                          // other way. ?>
                    <div class="form-grid form-grid--2">
                        <div class="field" data-opt-wrap="min">
                            <label class="label" for="opt-min">Lowest</label>
                            <input class="input" type="number" id="opt-min" data-opt="min">
                        </div>
                        <div class="field" data-opt-wrap="max">
                            <label class="label" for="opt-max">Highest</label>
                            <input class="input" type="number" id="opt-max" data-opt="max">
                        </div>
                    </div>

                    <div class="field" data-opt-wrap="step">
                        <label class="label" for="opt-step">Step</label>
                        <input class="input" type="number" id="opt-step" data-opt="step" min="0">
                        <p class="form-help">How far one nudge moves. Leave blank for 1.</p>
                    </div>

                    <div class="form-grid form-grid--2">
                        <div class="field" data-opt-wrap="scale_low">
                            <label class="label" for="opt-scale-low">Label at the low end</label>
                            <input class="input" type="text" id="opt-scale-low"
                                   data-opt="scale_low" autocomplete="off">
                        </div>
                        <div class="field" data-opt-wrap="scale_high">
                            <label class="label" for="opt-scale-high">Label at the high end</label>
                            <input class="input" type="text" id="opt-scale-high"
                                   data-opt="scale_high" autocomplete="off">
                        </div>
                    </div>

                    <?php // WHERE A DYNAMIC LIST GETS ITS CHOICES. The types
                          // come from the Registry: anything answering "what
                          // exists in this install?" asks the Registry, never a
                          // table. ?>
                    <?php // WHICH FORM'S FIELDS TO PULL IN. Not this one —
                          // `Forms\Embed::options()` leaves it out, because
                          // offering a form the chance to embed itself invites
                          // the cycle `expand()` then has to cut. ?>
                    <?php // ── WHEN THIS PERSON IS AVAILABLE ────────────────
                          // A SHAPE, not a list of dates. Slots are derived from
                          // it every time the form is rendered, so the calendar
                          // cannot run out — see `Forms\Appointments`. ?>
                    <div class="field" data-opt-wrap="appt_days">
                        <label class="label">Available on</label>
                        <div class="check-list">
                            <?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu',
                                            5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $n => $abbr): ?>
                                <label class="check-list__row">
                                    <input type="checkbox" data-opt-day value="<?= (int) $n ?>">
                                    <span><?= h($abbr) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?php // The value the builder actually reads and writes.
                              // The tick boxes are a friendlier face on it — a
                              // comma list of weekday numbers is not something
                              // anybody should have to type. ?>
                        <input type="hidden" data-opt="appt_days">
                    </div>

                    <div class="form-grid form-grid--2" data-opt-wrap="appt_from">
                        <div class="field">
                            <label class="label" for="opt-appt-from">From</label>
                            <input class="input" type="time" id="opt-appt-from" data-opt="appt_from">
                        </div>
                        <div class="field">
                            <label class="label" for="opt-appt-to">Until</label>
                            <input class="input" type="time" id="opt-appt-to" data-opt="appt_to">
                        </div>
                    </div>

                    <div class="form-grid form-grid--2" data-opt-wrap="appt_minutes">
                        <div class="field">
                            <label class="label" for="opt-appt-minutes">Each appointment lasts</label>
                            <input class="input" type="number" id="opt-appt-minutes"
                                   data-opt="appt_minutes" min="5" step="5">
                            <p class="form-help">Minutes.</p>
                        </div>
                        <div class="field">
                            <label class="label" for="opt-appt-ahead">Book up to</label>
                            <input class="input" type="number" id="opt-appt-ahead"
                                   data-opt="appt_ahead" min="1" max="90">
                            <p class="form-help">Days ahead. 90 at most.</p>
                        </div>
                    </div>

                    <div class="field" data-opt-wrap="embed_form">
                        <label class="label" for="opt-embed">Form to embed</label>
                        <select class="select" id="opt-embed" data-opt="embed_form">
                            <option value="">— Choose a form —</option>
                            <?php foreach (\Cms\Forms\Embed::options($id) as $eid => $etitle): ?>
                                <option value="<?= (int) $eid ?>"><?= h($etitle) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-help">
                            Its fields appear here, under their own names. Change them
                            there and every form that embeds it follows.
                        </p>
                    </div>

                    <div class="field" data-opt-wrap="source">
                        <label class="label" for="opt-source">Take the choices from</label>
                        <select class="select" id="opt-source" data-opt="source">
                            <option value="">— Choose a content type —</option>
                            <?php foreach (\Cms\Forms\Sources::types() as $key => $name): ?>
                                <option value="<?= h($key) ?>"><?= h($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-help">
                            Published entries only, listed by title. The list follows the
                            site: add an entry and it appears here without touching the form.
                        </p>
                    </div>

                    <div class="field" data-opt-wrap="columns">
                        <label class="label" for="opt-columns">Answers</label>
                        <textarea class="textarea" id="opt-columns" data-opt="columns"
                                  rows="5"></textarea>
                        <p class="form-help">
                            One per line, across the top. Leave blank for the usual five,
                            Strongly disagree to Strongly agree. The statements down the
                            side are the Choices list above.
                        </p>
                    </div>

                    <div class="field" data-opt-wrap="width">
                        <label class="label" for="opt-width">Field width</label>
                        <?php // THE LIST IS `Ui\FieldWidth`, not four <option>s
                              // typed here. The field group editor's Width
                              // control renders the same call, the two
                              // controllers whitelist against the same class,
                              // and both renderers turn it into a span of
                              // twelve — so a width cannot be offered on one
                              // screen and ignored on another.
                              //
                              // THE SELECT STAYS. Dragging a field's edge sets
                              // the width too, and that is the better gesture,
                              // but a drag is not reachable from a keyboard and
                              // is not discoverable on first sight. The drag is
                              // an addition, not a replacement. ?>
                        <select class="select" id="opt-width" data-opt="width">
                            <?php foreach (\Cms\Ui\FieldWidth::labels() as $value => $label): ?>
                                <option value="<?= h($value) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-help">Or drag the right edge of a field on the canvas.</p>
                    </div>

                    <?php // ── ADVANCED ──────────────────────────────────── ?>
                    <details class="fold" data-fold="advanced">
                        <summary class="fold__head">
                            Advanced
                            <?= icon('angle-down', 'fold__caret icon icon--sm') ?>
                        </summary>
                        <div class="fold__body">
                            <div class="field">
                                <label class="label" for="opt-name">Field name</label>
                                <input class="input input--mono" type="text" id="opt-name"
                                       data-opt="name" spellcheck="false" autocomplete="off">
                                <p class="form-help">
                                    The key this answer is stored under. Filled in from the
                                    label until you change it.
                                </p>
                            </div>
                            <div class="field" data-opt-wrap="default">
                                <label class="label" for="opt-default">Default value</label>
                                <input class="input" type="text" id="opt-default"
                                       data-opt="default" autocomplete="off">
                            </div>
                            <div class="field" data-opt-wrap="maxlength">
                                <label class="label" for="opt-maxlength">Maximum characters</label>
                                <input class="input" type="number" id="opt-maxlength" min="0"
                                       data-opt="maxlength">
                                <p class="form-help">Leave at 0 for no limit. Checked on the server too.</p>
                            </div>
                        </div>
                    </details>

                    <?php // ── VALIDATION MESSAGES ───────────────────────── ?>
                    <details class="fold" data-fold="validation" data-opt-wrap="validation">
                        <summary class="fold__head">
                            Validation messages
                            <?= icon('angle-down', 'fold__caret icon icon--sm') ?>
                        </summary>
                        <div class="fold__body">
                            <div class="field">
                                <label class="label" for="opt-msg-required">When it is left blank</label>
                                <input class="input" type="text" id="opt-msg-required"
                                       data-opt="msg_required" autocomplete="off"
                                       placeholder="This field is required.">
                            </div>
                            <div class="field" data-opt-wrap="msg_format">
                                <label class="label" for="opt-msg-format">When the format is wrong</label>
                                <input class="input" type="text" id="opt-msg-format"
                                       data-opt="msg_format" autocomplete="off">
                            </div>
                        </div>
                    </details>

                    <?php // ── CONDITIONAL LOGIC ─────────────────────────── ?>
                    <details class="fold" data-fold="logic">
                        <summary class="fold__head">
                            Conditional logic
                            <span class="tag" data-logic-tag hidden>On</span>
                            <?= icon('angle-down', 'fold__caret icon icon--sm') ?>
                        </summary>
                        <div class="fold__body">
                            <label class="check-list__row">
                                <input type="checkbox" data-logic-enable>
                                <span>Only show this field sometimes</span>
                            </label>

                            <div class="logic" data-logic-panel hidden>
                                <div class="logic__lead">
                                    <select class="select select--sm" data-logic-action>
                                        <option value="show">Show</option>
                                        <option value="hide">Hide</option>
                                    </select>
                                    <span class="logic__word">this field if</span>
                                    <select class="select select--sm" data-logic-match>
                                        <option value="all">all</option>
                                        <option value="any">any</option>
                                    </select>
                                    <span class="logic__word">of these are true</span>
                                </div>

                                <div class="logic__rules" data-logic-rules></div>

                                <button type="button" class="btn btn--sm" data-logic-add>
                                    <?= icon('plus', 'icon icon--sm') ?>Add rule
                                </button>

                                <p class="logic__none" data-logic-nofields hidden>
                                    Add another field first — a rule has to test something.
                                </p>
                            </div>
                        </div>
                    </details>

                    <div class="rail__foot">
                        <button type="button" class="btn btn--sm btn--danger-ghost" data-opt-delete
                                data-confirm="Delete this field?"
                                data-confirm-body="Its settings go with it. Answers already submitted are kept."
                                data-confirm-ok="Delete field">
                            <?= icon('trash', 'icon icon--sm') ?>Delete field
                        </button>
                    </div>

                </div>
            </div>
        </aside>

        <?php // ── THE CANVAS ────────────────────────────────────────────── ?>
        <div class="canvas">
            <div class="canvas__sheet">
                <div class="canvas__fields" data-canvas></div>

                <div class="empty" data-canvas-empty hidden>
                    <?= icon('list-check', 'empty__glyph', '', 28) ?>
                    <p class="empty__title">This form has no fields yet</p>
                    <p class="empty__text">Pick one from the palette to start.</p>
                </div>

                <?php // THE SUBMIT BUTTON IS PART OF THE PREVIEW. Its label is a
                      // setting on the other pane, and seeing it here is how you
                      // notice it still says "Submit" on an RSVP form. ?>
                <div class="canvas__submit">
                    <span class="canvas__button" data-canvas-submit>Submit</span>
                </div>
            </div>
        </div>
    </div>

    <?php // ══ SETTINGS ════════════════════════════════════════════════════ ?>
    <div class="builder__body builder__body--single" data-pane="settings" hidden>
        <?php // ORDINARY `.form-card`s AT THE FORM MEASURE — the same shape every
              // other settings screen in this admin uses. A bespoke
              // `.settings-sheet` / `.settings-block` pair was doing exactly what
              // `.form-card` and `.form-card__section` already do. ?>
        <div class="form-main form-main--solo">

            <?php if ($placesList !== null): ?>
                <?php // WHICH PAGE SHOWS IT. A
                      // page of the site's own draws the form placed on it, in
                      // its own design; the form is the source of its fields, so
                      // a question added here appears there. A page shows one
                      // form: choosing one that shows another moves it here. ?>
                <div class="form-card">
                  <div class="form-card__section">
                    <?= panel_title('On the site', 'embedding') ?>
                    <div class="field">
                        <label class="label" for="f-place">Shown on</label>
                        <select class="select" id="f-place" name="place_key">
                            <option value="">No page of its own</option>
                            <?php foreach ($placesList as $key => $place): ?>
                                <?php
                                $holder = $holders[$key] ?? null;
                                $other  = $holder !== null && (int) $holder['id'] !== (int) ($form['id'] ?? 0);
                                ?>
                                <option value="<?= h((string) $key) ?>"<?= $placeNow === (string) $key ? ' selected' : '' ?>><?= h((string) $place['name']) ?><?= $other ? ' — now showing “' . h((string) $holder['title']) . '”' : '' ?></option>
                            <?php endforeach; ?>
                            <?php if ($placeNow !== null && !isset($placesList[$placeNow])): ?>
                                <option value="<?= h($placeNow) ?>" selected>“<?= h($placeNow) ?>” (no longer on the list)</option>
                            <?php endif; ?>
                        </select>
                        <p class="form-help">That page shows this form in the site's own design. A page shows one form: choosing one that shows another moves it here.</p>
                    </div>
                  </div>
                </div>
            <?php endif; ?>

            <div class="form-card">
              <div class="form-card__section">
                <?= panel_title('Notifications', 'notifications') ?>

                <div class="field">
                    <label class="label" for="f-recipients">Send to</label>
                    <input class="input" type="text" id="f-recipients" name="recipients"
                           value="<?= h($setting('recipients')) ?>">
                    <p class="form-help">
                        Email addresses, separated by commas. Leave blank to store
                        submissions without emailing anyone.
                    </p>
                </div>

                <div class="field">
                    <label class="label" for="f-subject">Subject</label>
                    <input class="input" type="text" id="f-subject" name="subject"
                           value="<?= h($setting('subject')) ?>">
                </div>

                <div class="field">
                    <label class="label" for="f-reply-to">Reply-to field</label>
                    <?php // A SELECT, NOT A TEXT BOX. This has to match a field's
                          // NAME exactly or the reply goes nowhere, and a free
                          // text box would let you type a label, a guess or a
                          // typo with no feedback of any kind. The options are
                          // rebuilt from the canvas as fields change. ?>
                    <?php // THE SAVED CHOICE IS IN THE PAGE, selected, as the
                          // script would show it: under its field's label, or
                          // marked when the field is gone. With only "— None —"
                          // here, a save made before the script ran — or without
                          // it — would drop the Reply-To without a word. ?>
                    <?php
                    $replyTo    = (string) $setting('reply_to');
                    $replyLabel = $replyTo . ' (no such field)';
                    foreach ((array) json_decode($fieldsJson, true) as $f) {
                        if (is_array($f) && ($f['type'] ?? '') === 'email' && ($f['name'] ?? '') === $replyTo) {
                            $replyLabel = (string) (($f['label'] ?? '') !== '' ? $f['label'] : $f['name']);
                        }
                    }
                    ?>
                    <select class="select" id="f-reply-to" name="reply_to" data-reply-to
                            data-current="<?= h($replyTo) ?>">
                        <option value="">— None —</option>
                        <?php if ($replyTo !== ''): ?>
                            <option value="<?= h($replyTo) ?>" selected><?= h($replyLabel) ?></option>
                        <?php endif; ?>
                    </select>
                    <p class="form-help">
                        The field holding the sender's address, so a reply goes back
                        to them. Only email fields can do this.
                    </p>
                </div>
              </div>

              <div class="form-card__section">
                <?= panel_title('After submitting', 'after-submit') ?>

                <div class="field">
                    <label class="label" for="f-submit-label">Button label</label>
                    <input class="input" type="text" id="f-submit-label" name="submit_label"
                           value="<?= h($setting('submit_label', 'Submit')) ?>">
                </div>

                <div class="field">
                    <label class="label" for="f-success">Thank-you message</label>
                    <textarea class="textarea" id="f-success" name="success_message"
                              rows="3"><?= h($setting('success_message', 'Thank you! Your submission has been received.')) ?></textarea>
                </div>

                <?php // WITHOUT JAVASCRIPT, and
                      // THE LINK LIMIT: `Forms\Guard` reads a form's
                      // max_links, and this is where it is set. ?>
                <div class="field">
                    <label class="label" for="f-redirect">After sending without JavaScript, go to</label>
                    <input class="input" type="text" id="f-redirect" name="redirect"
                           value="<?= h($setting('redirect')) ?>" placeholder="/thanks/">
                    <p class="form-help">A page on this site. Blank: back to the page the form was sent from, which shows the thank-you message.</p>
                </div>

                <div class="field">
                    <label class="label" for="f-max-links">Most links in a message</label>
                    <input class="input" type="text" inputmode="numeric" id="f-max-links" name="max_links"
                           value="<?= h($setting('max_links')) ?>" placeholder="4">
                    <p class="form-help">A message with more is turned away as spam. Blank: 4.</p>
                </div>
              </div>
            </div>

        </div>
    </div>

    <?php // ── TEMPLATES THE BUILDER CLONES ──────────────────────────────── ?>

    <?php // ONE CANVAS FIELD. The controls inside are swapped per type by
          // forms-builder.js; everything else — the wrapper, the label, the
          // required mark, the description, the selection chrome — is authored
          // here, so the parts that never vary have exactly one definition. ?>
    <template data-canvas-template>
        <div class="cfield" data-cfield tabindex="0" role="button">
            <div class="cfield__chrome">
                <span class="cfield__id" data-cfield-id></span>
                <?php // "Drag to reorder" is all it needs: there is no arrow-key
                      // reorder. See reorder.js. ?>
                <span class="cfield__grip" data-cfield-grip
                      aria-label="Drag to reorder this field"
                      data-tip="Drag to reorder">
                    <?= icon('up-down-left-right', 'icon icon--sm') ?>
                </span>
                <?php // DELETE LIVES ON THE FIELD, not only in the options rail,
                      // where removing a field would be: click the field, find
                      // the rail, scroll it, find the button — for the one
                      // action whose target you are already pointing at.
                      //
                      // It carries the same `data-confirm` as the rail's copy, so
                      // both routes open the same dialog and neither deletes on a
                      // single stray click. No alert colour on the trigger: the
                      // dialog IS the alert surface. ?>
                <button type="button" class="cfield__kill" data-cfield-delete
                        data-confirm="Delete this field?"
                        data-confirm-detail="It is removed from the form as soon as you save. Answers already submitted against it are kept."
                        data-confirm-verb="Delete field"
                        aria-label="Delete this field" data-tip="Delete this field">
                    <?= icon('trash-can', 'icon icon--sm') ?>
                </button>
            </div>
            <span class="cfield__logic" data-cfield-logic hidden></span>
            <label class="cfield__label" data-cfield-label></label>
            <div class="cfield__control" data-cfield-control></div>
            <p class="cfield__desc" data-cfield-desc hidden></p>
            <?php // ── DRAG THE EDGE TO SET THE WIDTH ────────────────────────
                  // Half, third, quarter or full width, set on the field
                  // itself. The Field width select in the rail still does it
                  // and is the accessible path — this is the direct one, on
                  // the thing itself, where the question is
                  // being asked. It snaps to the four widths rather than
                  // resizing freely: a form field is a span of twelve, not a
                  // pixel measurement, and a free drag would invent widths the
                  // renderer has no class for.
                  //
                  // A BUTTON, so it is focusable and reachable — Left and Right
                  // step the width, which is the keyboard half of the same
                  // gesture and the reason this is not a bare <span>. ?>
            <?php // NO TOOLTIP ON THIS HANDLE. A "drag to reorder" tip on a
                  // drag handle is one of the three tooltip failures, and this
                  // is the same control wearing a different verb. What says
                  // the edge is draggable is `cursor: col-resize`, the
                  // standard affordance, plus the one line under the Field
                  // width select where the setting itself lives.
                  //
                  // `aria-label` stays: that is the accessible name of a button
                  // with no text of its own, not a tip bolted over a label. ?>
            <span class="cfield__resize" data-cfield-resize
                  aria-hidden="true"></span>
        </div>
    </template>

    <?php // ONE LOGIC RULE ROW. `data-logic-fields` is refilled from the current
          // field list every time the rail opens, so a rule can only ever name a
          // field that exists. ?>
    <template data-logic-template>
        <div class="logic__rule" data-logic-rule>
            <select class="select select--sm" data-logic-field aria-label="Field to test"></select>
            <select class="select select--sm" data-logic-op aria-label="Comparison">
                <?php foreach (Logic::OPERATORS as $opKey => $opLabel): ?>
                    <option value="<?= h($opKey) ?>"><?= h($opLabel) ?></option>
                <?php endforeach; ?>
            </select>
            <input class="input input--sm" type="text" data-logic-value
                   aria-label="Value" autocomplete="off">
            <button type="button" class="icon-btn" data-logic-remove
                    data-tip="Remove rule" aria-label="Remove rule">
                <?= icon('x', 'icon icon--sm') ?>
            </button>
        </div>
    </template>
</form>

<?php // THE TYPE TABLE, ONCE, AS DATA. The builder needs a type's label, icon
      // flags and whether it takes options; the alternative is a second copy of
      // `FieldTypes` written in JavaScript, which is the drift that class exists
      // to stop. `application/json` so nothing here is executed. ?>
<script type="application/json" data-field-types><?= FieldTypes::toJson() ?></script>

<?php // AND THE WIDTHS, for the same reason and from the same one list. The
      // canvas paints `.cfield--half|third|quarter`, and dragging a field's
      // right edge snaps between them — which needs the SPANS, not just the
      // names, because the snap is "which width is nearest the column the
      // pointer is over". A hand-written map in the JS would be a seventh copy
      // of the vocabulary `Ui\FieldWidth` was written to collapse, and the
      // stylesheet's spans would drift from the ones the drag snaps to. ?>
<script type="application/json" data-field-widths><?=
    json_encode(\Cms\Ui\FieldWidth::SPANS)
?></script>

<?php // reorder.js BEFORE the builder — the builder calls Reorder.create() at
      // load. Real <script src> tags, not a bundle: there is no build step. ?>
<script src="<?= h(Asset::admin('js/reorder.js')) ?>"></script>
<script src="<?= h(Asset::admin('js/forms-builder.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
