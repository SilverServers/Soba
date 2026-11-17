<?php

declare(strict_types=1);

use Cms\ContentType\ArchetypeDefaults;
use Cms\ContentType\FieldRenderer;
use Cms\Helpers\Asset;
use Cms\Helpers\Dates;
use Cms\Helpers\TermRenderer;
use Cms\Helpers\Url;
use Cms\Models\Term;
use Cms\SEO\Head;
use Cms\Security\Csrf;
use Cms\Ui\Sidebar;
use Cms\Ui\Status;
use Cms\Ui\VariableEditor;

/**
 * THE ENTRY FORM — one screen, every Content Type, create and edit both.
 *
 * The last of the five screens that cover every registered Content Type. Like
 * `list.php`, nothing in here is per-type except what the Registry hands it: the
 * labels, the glyph, `supports`, the fields and the attached taxonomies.
 *
 * ── ONE FORM, TWO COLUMNS ───────────────────────────────────────────────────
 * `.panel__grid` — the entry's own substance on the left, everything ABOUT it on
 * the right. Both columns are inside the SAME `<form>`, because they save
 * together; a right-hand column that posts separately is two saves the author
 * has to remember to do in the right order.
 *
 * The form is the page's substance, so every card is SHARP (`--r-none`).
 * `.form-card` is already that — see core.css §4: the form that a /new or
 * /edit page exists to show takes `--r-none`.
 *
 * ── WHAT CARRIES EACH FACT ──────────────────────────────────────────────────
 *   is this live, and how       the status chip in the header — the SAME chip
 *                               the list uses, so the two screens agree
 *   what is wrong with my input the `.alert--danger` block, once, at the top
 *   what will the URL be        the ADDRESS ROW in the title card — the real URL
 *                               with the editable part underlined, and a live
 *                               link when the entry actually resolves
 *   what image is attached      the THUMBNAIL, never the attachment id
 *   how many past versions      the count on the History rows, not a paragraph
 *   is this field required      the word beside its label, not a red asterisk
 *
 * ── THE TITLE CARD ──────────────────────────────────────────────────────────
 * Title and slug are NOT in the two-column grid. They sit in a full-width card
 * above it — `partials/title-card.php`, shared with the Pages form — because the
 * title is the entry's substance and the slug is a URL rather than a field.
 *
 * Two identical 13px boxes stacked in the main column would make the most
 * important control on the screen indistinguishable from a technical
 * derivative of it. The h1 shows the KIND ("Edit news item"), not the entry's
 * name: the name is in the title card, editable, and an h1 repeating it would
 * make the editable copy the less prominent of the two.
 *
 * ── THE EXCERPT, AND WHY IT NEEDS ITS COLUMN ────────────────────────────────
 * The excerpt box is backed by `posts.excerpt` (migration 053). Without a
 * column, a box would accept text, look saved and drop it on every submit —
 * a missing control that lies. It posts `excerpt`; the controller writes it
 * only when that key is present.
 *
 * @var array<string, mixed>            $user
 * @var array<string, mixed>            $contentType
 * @var array<string, mixed>|null       $post             null on create
 * @var array<string, mixed>            $meta
 * @var list<string>                    $errors
 * @var array<string, mixed>            $cptTaxonomies
 * @var array<string, list<array>>      $assignedTerms
 * @var int                             $featuredImageId
 * @var string|null                     $seoTitle
 * @var string|null                     $metaDescription
 * @var list<array<string, mixed>>      $revisions        showEdit() only
 * @var bool                            $saved            handleUpdate() only
 */

$label       = (string) $contentType['label'];
$labelSingle = (string) $contentType['label_single'];
$adminSlug   = (string) $contentType['admin_slug'];
$publicSlug  = (string) ($contentType['public_slug'] ?? '');
$supports    = (array) ($contentType['supports'] ?? []);
$fields      = (array) ($contentType['fields'] ?? []);
$archetypes  = (array) ($contentType['archetypes'] ?? []);

// ── IS THIS TYPE ARRANGED BY HAND? ──────────────────────────────────────────
// A feed is chronological and nobody orders it; a roster and a price list are
// arranged by a person. Only the second kind gets a Position control, because a
// control that does nothing is the same lie as a field nothing renders.
//
// The test is the shared function below, not a copy written out by hand: a
// hand-written OR would give a ticketed event (`event` + `product`) a Position
// box, while the public archive sorts events by their start date and ignores
// it. A comment asserting that three copies agree is not a mechanism that makes
// them agree — the shared function is.
$handOrdered = ArchetypeDefaults::isHandOrdered($archetypes);

$isNew = ($post ?? null) === null;
$id    = $isNew ? 0 : (int) $post['id'];

// Only showEdit() loads these; the create and re-render paths do not set them
// at all, and an undefined variable in a template is a warning in the log on
// every save. Default them HERE rather than making four controllers agree.
$revisions = $revisions ?? [];
$saved     = $saved     ?? false;
$authors   = $authors   ?? null;   // ContentTypeController::authorChoices(); null for an author
$errors    = $errors    ?? [];

// On a failed submit the form must come back wearing what the AUTHOR typed, not
// what is in the database — otherwise a validation error silently discards the
// edit it is complaining about. `$_POST` wins whenever there is one.
/**
 * What to show in a field: what was TYPED while the save has not happened, and
 * what is STORED once it has.
 *
 * ── THE `$saved` CONDITION IS NOT A DETAIL ─────────────────────────────────
 * Preferring `$_POST` is right on a FAILED save — an author whose slug clashed
 * must not lose the paragraph they wrote. It is wrong on a SUCCESSFUL one:
 *
 * `Html\Sanitizer` cleans content on the way into the database. Post
 * `<p>kept</p><script>alert(1)</script>`, and what is stored is `<p>kept</p>` —
 * but a screen re-rendered from `$_POST` would show the script still there.
 * The author would see markup the site will never serve, believe it saved, and
 * find out otherwise the next time they open the page.
 *
 * Once the record has been written it is the only truthful thing on the screen.
 */
$val = static function (string $key, mixed $fallback = '') use ($post, $saved): string {
    if (!$saved && isset($_POST[$key]) && !is_array($_POST[$key])) {
        return (string) $_POST[$key];
    }
    return (string) ($post[$key] ?? $fallback ?? '');
};

$status = $val('status', 'private');

$action    = $isNew
    ? Url::admin($adminSlug . '/new')
    : Url::admin($adminSlug . '/' . $id . '/edit');
$pageTitle = $isNew ? 'Add ' . $labelSingle : (string) $post['title'];

// A public URL exists only when the type HAS public routes AND this entry is
// reachable at one. A "view" control leading to a 404 is worse than none.
//
// ── REACHABLE BY THE PERSON LOOKING AT THIS SCREEN ──────────────────────────
// Not `status === 'published'`: that would take the address on a Private entry
// and render it as dead text with no view control, which is a feature removed
// rather than a 404 prevented.
//
// The audience for this control is an ADMIN WHO IS SIGNED IN, and every status
// except `trash` opens for them: `published` for anyone, `private` because
// `PostController` gates it on `Auth::check()` and they pass it, `password`
// because the controller asks for the password and lets them in. Only `trash`
// is excluded by `findBySlug()` and genuinely 404s, and a new entry has no
// address yet because it has no saved slug.
$viewUrl = (!$isNew && $publicSlug !== '' && (string) $post['status'] !== 'trash')
    ? Url::page($publicSlug . '/' . (string) $post['slug'])
    : null;

ob_start();
?>
<?php // `--editor` IS WHAT TURNS ON THE FULL-HEIGHT CHAIN (admin.css §7b). It is a
      // page-level modifier and not a rule on `.form-main`, because the height
      // has to come from the page down; a class further in cannot invent one. ?>
<div class="page page--editor">

    <form method="post" action="<?= h($action) ?>" class="editor-form"<?= \Cms\Ui\KeepWriting::attributes($adminSlug . ':' . ($isNew ? 'new' : $id), $saved, $errors !== [], $isNew ? null : 'post:' . $id) ?>>
        <?= Csrf::field() ?>

        <?php // THE BACK LINK AND THE SAVE BUTTON SHARE ONE ROW, at the top —
              // and the row is inside the form, because the button submits it.
              // See partials/form-topbar.php. ?>
        <?php
        $backUrl   = Url::admin($adminSlug);
        $backLabel = $label;
        $saveLabel = $isNew ? 'Create ' . $labelSingle : 'Save changes';
        require ROOT_PATH . '/admin/templates/partials/form-topbar.php';
        ?>

    <?php if ($saved): ?>
        <?php // The SHAPE of an alert with no alert COLOUR: a save that worked
              // is not a fault, and amber here would make "it worked" look like
              // a broken deploy. It reports what happened and gets out of the
              // way — no "Success!", no advice on what to do next. ?>
        <div class="flashes">
            <div class="flash flash--success"><?= h($labelSingle) ?> saved.</div>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <?php // --danger is painted only on a CLOSED list of places, and the
              // block-level notice, with its own box and its own glyph, is the
              // first of them. One alert listing every problem, not one per
              // field — a column of
              // red boxes is a wall, and the fields themselves carry `required`
              // so the browser points at the offending one. ?>
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

    <?php require ROOT_PATH . '/admin/templates/partials/keep-writing.php'; ?>

        <div class="panel__grid">

            <?php // ── THE ENTRY ITSELF ────────────────────────────────────
                  //
                  // ONE CARD, THREE SECTIONS, hairlines between them — not three
                  // bordered boxes stacked with a gap. See core.css §4. ?>
            <div class="form-main">

                                                                    <?php
                    // THE TITLE CARD, full width above the grid — the same partial the Pages
                    // form uses. The title is the entry's substance and the slug is a URL,
                    // not a field; see partials/title-card.php.
                    $titleLabel = $labelSingle . ' title';
                    $titleValue = $val('title');
                    $slugPrefix = $publicSlug !== ''
                        ? rtrim(Url::public($publicSlug), '/') . '/'
                        : rtrim(Url::public(''), '/') . '/';
                    $slugValue  = $val('slug');
                    // The address's own end: the preview is the address, so it
                    // ends the way the site's addresses do.
                    $slugSuffix = Url::trailingSlash() ? '/' : '';
                    // A LINK ONLY IF IT RESOLVES — the same condition $viewUrl uses above.
                    $slugIsLive = $viewUrl !== null;
                    // $viewUrl is already computed above; the partial puts the
                    // view-on-site control next to the address it opens.
                    require ROOT_PATH . '/admin/templates/partials/title-card.php';
                    ?>

                <div class="form-card">


                    <?php if (in_array('content', $supports, true)): ?>
                    <?php // `--fill` HANDS THE LEFTOVER HEIGHT DOWN to the
                          // textarea — see admin.css §7b. It marks the ONE
                          // section on the form that should absorb the space;
                          // put it on two and they fight over it.
                          //
                          // A CONTENT TYPE THAT DOES NOT `support` CONTENT has
                          // no `--fill` section at all, and the chain simply
                          // does nothing: the card sizes to its fields and the
                          // column ends where it ends. That is correct — there
                          // is no body field to give the space to. ?>
                    <div class="form-card__section form-card__section--fill">
                        <?php // THE MIDDLE RUNG. Without it the main column would
                              // run from a 26px page title straight to a 12px
                              // field label, while the ASIDE has 15px headings on
                              // every section — the secondary column better
                              // structured than the primary one. Title and slug
                              // are in the title card above; this is the body. ?>
                        <?php // THE HEADING STAYS, AND IT IS A SECTION HEADING.
                              // A label is not a caption: it is what tells you
                              // the box is yours to type in, and without one the
                              // field reads as an empty broken box.
                              //
                              // As a 12px `.label` it would be the ONLY section
                              // on the card without a heading band, directly
                              // above `Price and plan`, which has one: two peer
                              // sections of one card, drawn at two different
                              // levels. It is the same rung as the field group
                              // sections below it, so it is drawn on that rung.
                              //
                              // The control keeps its accessible name through
                              // `aria-label` rather than a second visible copy
                              // of the word. ?>
                        <?= panel_title('Content', 'body') ?>
                        <div class="field">
                            <?php // THE RICH-TEXT EDITOR BINDS HERE. `editor.js` hides this
                                  // textarea and puts a toolbar and a
                                  // contenteditable surface beside it — but THE
                                  // TEXTAREA IS STILL WHAT POSTS, so with
                                  // JavaScript off, or if that file fails to
                                  // load, this is a plain textarea that works.
                                  //
                                  // The editor is authored here, with no
                                  // third-party dependency.
                                  //
                                  // The GUARANTEE is `Html\Sanitizer`, applied
                                  // in `Post::create/update`, not by the editor
                                  // — content from an import or a hand-made
                                  // POST is cleaned the same way.
                                  //
                                  // No `rows`: .textarea--tall in core.css §4
                                  // sizes it, so the height is a stylesheet fact
                                  // rather than a line count in six templates. ?>
                            <textarea class="textarea textarea--tall" id="f-content" name="content"
                                      aria-label="Content"
                                      placeholder="Write here&hellip;" data-editor><?= h($val('content')) ?></textarea>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (in_array('excerpt', $supports, true)): ?>
                    <?php // ── THE EXCERPT — migration 053 ─────────────────────
                          // Under the body: the summary is read after the
                          // thing it summarises. A plain textarea, because it is plain
                          // text (`Post::cleanExcerpt()`) — an editor toolbar
                          // over two sentences would offer formatting the card
                          // then throws away.
                          //
                          // The help line is the one fact the box cannot show:
                          // where these words go. Both places are real —
                          // `Post::summary()` for every card and `PostController`
                          // for the description — and so is the fallback. ?>
                    <div class="form-card__section">
                        <?= panel_title('Excerpt', 'excerpt') ?>
                        <div class="field">
                            <textarea class="textarea" id="f-excerpt" name="excerpt" rows="3"
                                      aria-label="Excerpt"
                                      placeholder="A sentence or two about this <?= h(strtolower($labelSingle)) ?>."><?= h($val('excerpt')) ?></textarea>
                            <p class="form-help">
                                Shown on listing cards, and in search results when the
                                description under Search engines is blank. Leave it empty
                                to use the start of the text.
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($fields !== []): ?>
                        <?php
                        // ONE SECTION PER FIELD GROUP, HEADED BY THE GROUP'S NAME.
                        //
                        // The field group editor tells its author, in help text
                        // under the box, that the name "appears as the heading
                        // above these fields on the entry form", and this is
                        // where that holds. In one shared section, two groups
                        // attached to one type would be one undifferentiated run
                        // of fields with no way to tell where Date and location
                        // ended and Contact details began.
                        //
                        // `_group_title` is put on each field by
                        // ContentTypeSchema::mergeFields(), which is the one place
                        // the merge happens — so this template never has to know
                        // that a Content Type's fields come from two sources.
                        //
                        // Code-registered fields carry no group, so they keep the
                        // "<Type> details" heading: they are declared inside the
                        // type's own registration and that IS what they belong to.
                        $sections = [];
                        // AND THE MARK EACH SECTION WEARS. Every other heading
                        // band on this screen carries a glyph, through
                        // `panel_title()`, so these do too: a bare `<h2>` among
                        // them is visible the moment two sections sit next to
                        // each other.
                        //
                        // A group generated by an archetype wears THAT
                        // ARCHETYPE'S OWN MARK — the clock on Date and location,
                        // the box on Price and plan — so the entry form shows
                        // the same mark the owner ticked on the type form.
                        // `ContentTypeSchema` already tags the fields with
                        // `_archetype_key`; nothing new is stored for this. A
                        // group somebody WROTE has no kind, so it takes the
                        // generic list mark from `Sections::glyphs()`.
                        $sectionIcon = [];
                        foreach ($fields as $field) {
                            $heading = (string) ($field['_group_title'] ?? ($labelSingle . ' details'));
                            $sections[$heading][] = $field;
                            if (!isset($sectionIcon[$heading])) {
                                $key = (string) ($field['_archetype_key'] ?? '');
                                $sectionIcon[$heading] = $key !== ''
                                    ? ArchetypeDefaults::getIcon($key)
                                    : \Cms\Ui\Sections::glyphs()['fields'];
                            }
                        }
                        ?>
                        <?php foreach ($sections as $heading => $sectionFields): ?>
                            <?php // Rendered by the one renderer that also
                                  // sanitizes and validates them. See
                                  // FieldRenderer's header for why the simple
                                  // types are not inlined here. ?>
                            <div class="form-card__section">
                                <h2 class="form-card__title">
                                    <?= icon($sectionIcon[$heading], 'icon icon--sm') ?><?= h($heading) ?>
                                </h2>
                                <?php // A TWELVE-COLUMN GRID. `.form-grid--2`,
                                      // where a full-width field spans both
                                      // columns and half is the default cell,
                                      // works for exactly two widths. The
                                      // Presentation tab offers four, and
                                      // halves and thirds share no denominator
                                      // below twelve, so twelve is the smallest
                                      // grid that can express both.
                                      // `.form-grid--2` is still used by six
                                      // other screens; this is the only one
                                      // that reads a per-field width.
                                      //
                                      // The setting reaching a renderer at all
                                      // is the point: a width written to the
                                      // database and consumed by nothing would
                                      // be help text making a claim about
                                      // behaviour that is not true. ?>
                                <div class="form-grid form-grid--12">
                                <?php foreach ($sectionFields as $field): ?>
                                    <?php
                                    // A failed submit re-renders with $_POST, same
                                    // rule as the base fields above.
                                    $postKey = 'field_' . $field['key'];
                                    $value   = array_key_exists($postKey, $_POST)
                                        ? $_POST[$postKey]
                                        : ($meta[$field['key']] ?? null);
                                    ?>
                                    <?= FieldRenderer::renderField($field, $value) ?>
                                <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>


                    <?php // ── SOLD IN MORE THAN ONE FORM ──────────────────────
                          // Only for a type that carries the `product` kind, and
                          // only once it has been saved — a variant belongs to a
                          // post id, and an unsaved entry has not got one. A
                          // panel drawn on a new entry would be a table that
                          // silently throws away whatever was typed into it.
                          //
                          // ROWS, TYPED OR MADE. Three options are quicker
                          // typed; fifteen sizes and colours are made from the
                          // terms ticked on the product ("Make options" below).
                          // Either way each is a row, with its own count.
                          //
                          // IT IS A REPEATER, in markup and in hooks.
                          // `entry-form.js` already listens for
                          // `[data-repeater-add]` and `[data-repeater-remove]`
                          // on the document and clones
                          // `[data-repeater-template]`, so this panel needs no
                          // script of its own; a `variations.js` doing the
                          // identical job would be a second copy.
                          //
                          // Without JavaScript the rows that EXIST still render
                          // and still save; only "Add an option" stops working,
                          // which is the degraded state this admin asks for
                          // rather than a panel that does nothing at all. ?>
                    <?php // ON A NEW PRODUCT TOO. It appeared only after the first
                          // save, so a product sold in sizes was saved once without
                          // them; `handleCreate()` keeps the rows now as
                          // `handleUpdate()` does. ?>
                    <?php if (in_array('product', $archetypes, true)): ?>
                        <?php // AFTER A REFUSED SAVE, THE ROWS AS TYPED — see
                              // `Variation::fromPost()`. Otherwise the stored ones. ?>
                        <?php $variants = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['variations_panel'])
                            ? \Cms\Commerce\Variation::fromPost(is_array($_POST['variations'] ?? null) ? $_POST['variations'] : [])
                            : ($isNew ? [] : \Cms\Commerce\Variation::forProduct($id)); ?>
                        <?php
                        // ── WHAT "MAKE OPTIONS" MAKES THEM FROM ─────────────────
                        // This type's option taxonomies — Size, Colour — each with
                        // every term in its own order. `entry-form.js` keeps the
                        // ones ticked on this product and adds a row for every
                        // combination not already here.
                        $optionSpec = [];
                        foreach (\Cms\Commerce\Variation::optionTaxonomies((string) $contentType['key']) as $taxKey => $tax) {
                            $optionSpec[] = [
                                'tax'   => (string) $taxKey,
                                'label' => (string) ($tax['label'] ?? $taxKey),
                                'terms' => array_map(static fn (array $t): array => [
                                    'id' => (int) $t['id'], 'name' => (string) $t['name'], 'slug' => (string) $t['slug'],
                                ], \Cms\Models\Term::allForTaxonomy((string) $taxKey)),
                            ];
                        }
                        ?>
                        <div class="form-card__section" data-variations-panel>
                            <h2 class="form-card__title">
                                <?= icon(\Cms\Ui\Sections::glyphFor('variants'), 'icon icon--sm') ?>Options
                            </h2>

                            <p class="form-help">
                                Sizes, colours, weights — anything this is sold in
                                more than one of. Leave it empty and the entry is
                                sold as one thing at the price above.
                                <strong>A blank price means the price above</strong>,
                                and its sale price too, so three sizes at the same
                                money need it typed once.
                            </p>

                            <?php if ($optionSpec !== []): ?>
                                <?php // MADE, NOT TYPED. A tee in five sizes and
                                      // three colours was fifteen rows typed one at
                                      // a time. Tick its sizes and colours where
                                      // they are filed, press this, and every
                                      // combination not already here is added. ?>
                                <div class="form-actions form-actions--bare">
                                    <button type="button" class="btn"
                                            data-options-make="<?= h((string) json_encode($optionSpec)) ?>">
                                        <?= icon('table-cells', 'icon icon--sm') ?>Make options from <?= h(implode(' × ', array_column($optionSpec, 'label'))) ?>
                                    </button>
                                </div>
                            <?php endif; ?>

                            <?php // `data-variations` NAMES THIS ONE. The
                                  // repeater hooks are shared with every
                                  // repeater FIELD on the form, so a selector
                                  // written as `[data-repeater]` finds whichever
                                  // comes first, and a script using it would act
                                  // on the wrong panel. ?>
                            <?php // ── THE PANEL ANNOUNCES ITSELF ───────────
                                  // "The owner removed every option" and "this
                                  // form has no options panel" arrive at the
                                  // server as the SAME POST — no `variations`
                                  // key at all — and they mean opposite things.
                                  // Treating the first as the second would leave
                                  // the variants in the database after the owner
                                  // deleted every row, on a save that reported
                                  // success; treating the second as the first
                                  // would wipe a product's options the moment
                                  // it was saved from a screen that never
                                  // showed them.
                                  //
                                  // One hidden input tells them apart. ?>
                            <input type="hidden" name="variations_panel" value="1">
                            <div class="repeater" data-repeater data-variations>
                                <div class="repeater__rows" data-repeater-rows>
                                    <?php foreach ($variants as $i => $v): ?>
                                        <?= variation_row($i, $v) ?>
                                    <?php endforeach; ?>
                                </div>

                                <div class="form-actions--bare">
                                    <button type="button" class="btn" data-repeater-add>Add an option</button>
                                </div>

                                <template data-repeater-template><?= variation_row('__ROW__', null) ?></template>
                            </div>

                            <?php // ── ONE VALUE INTO MANY ROWS ────────────────────
                                  // A price, a sale price, a count or a photo, into
                                  // every option or every option of one colour —
                                  // the photo for Navy set once rather than five
                                  // times. It fills the rows; Save keeps them.
                                  //
                                  // ITS LABELS ARE SHOWN, and read as the sentence
                                  // the row is: what to set, to what, on which. ?>
                            <div class="form-actions form-actions--bare options-bulk" data-options-bulk>
                                <label for="bulk-field">What to set</label>
                                <select class="select" id="bulk-field" data-bulk-field>
                                    <option value="price">Price</option>
                                    <option value="sale_price">Sale price</option>
                                    <option value="stock_qty">In stock</option>
                                    <option value="image_id">Photo</option>
                                </select>
                                <label for="bulk-value">to</label>
                                <input class="input" type="text" id="bulk-value" data-bulk-value
                                       inputmode="decimal" autocomplete="off">
                                <div class="options-bulk__photo" data-bulk-photo hidden>
                                    <?= \Cms\ContentType\FieldRenderer::renderImage('bulk_photo', null) ?>
                                </div>
                                <label for="bulk-scope">on</label>
                                <select class="select" id="bulk-scope" data-bulk-scope>
                                    <option value="">Every option</option>
                                    <?php foreach ($optionSpec as $spec): ?>
                                        <optgroup label="<?= h($spec['label']) ?>">
                                            <?php foreach ($spec['terms'] as $term): ?>
                                                <option value="<?= h($spec['tax'] . ':' . $term['id']) ?>"><?= h($term['name']) ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn" data-bulk-apply>Set</button>
                            </div>

                            <p class="form-help">
                                Each option counts its own stock and can have its
                                own code, sale price and photo.
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php // THE FORM'S FOOTER, and it is the card's LAST SECTION —
                          // the hairline above it is the section divider, so it is
                          // not a second rule floating 8px under the card's own
                          // bottom border. Full-size primary: a page's primary
                          // Save is never --sm. No glyph either; core.css §4
                          // ends with the reason. ?>
                    <?php // NO FOOTER. Cancel and Save are in the top bar; a
                          // second copy at the bottom of a full-height body
                          // field is two primary buttons on one screen. ?>

                </div>
            </div>

            <?php // ── EVERYTHING ABOUT IT ─────────────────────────────────
                  //
                  // Also ONE card, not five separately bordered boxes sharing no
                  // rhythm with the single tall card beside them — core.css §4
                  // has the reasoning. ?>
            <div class="form-aside">
                <div class="form-card">

                    <div class="form-card__section">
                        <div class="panel-head">
                            <?= panel_title('Visibility', 'visibility') ?>
                            <?php if (!$isNew): ?>
                                <?= Status::render(Status::forEntry($post)) ?>
                            <?php endif; ?>
                        </div>

                        <div class="field">
                            <label class="label" for="f-status">Status</label>
                            <select class="select" id="f-status" name="status" data-status-select>
                                <?php foreach (Status::labels() as $key => $statusLabel): ?>
                                    <?php if ($key === 'trash') { continue; /* trash has its own screen */ } ?>
                                    <option value="<?= h($key) ?>"<?= $status === $key ? ' selected' : '' ?>>
                                        <?= h($statusLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php // ALL THREE, each as the public controller
                                  // treats it. Published: reachable. Private:
                                  // PostController 404s it unless `Auth::check()`
                                  // passes, which is any signed-in account and not
                                  // "staff only". Password: the same controller
                                  // asks for the password and remembers it for
                                  // the session. Hidden and Private are one state
                                  // — see `Ui\Status` and migration 043. ?>
                            <p class="form-help">
                                Published is reachable by anyone. Private makes the address
                                404 for everyone except a signed-in account, so you can
                                read it back before it goes out. Password asks any visitor
                                for the password below.
                            </p>
                        </div>

                        <?php // Revealed by entry-form.js only for the one status
                              // that uses it, and DISABLED while hidden — a
                              // hidden-but-enabled input still posts, which would
                              // keep re-hashing a password nobody can see. ?>
                        <div class="field" data-password-field<?= $status === 'password' ? '' : ' hidden' ?>>
                            <label class="label" for="f-post-password">Password</label>
                            <input class="input" type="text" id="f-post-password" name="post_password"
                                   autocomplete="off"<?= $status === 'password' ? '' : ' disabled' ?>
                                   <?php // Empty means "keep the current one" on an
                                         // entry that already has a password, so the
                                         // box is not pre-filled with a hash and not
                                         // pre-filled with the password either. ?>
                                   placeholder="<?= h(!$isNew && !empty($post['password_hash']) ? 'Unchanged' : '') ?>">
                        </div>

                        <?php // ── WHEN IT GOES OUT ─────────────────────────────
                              // Publish now or schedule for later, as one
                              // native control — no picker to author, and
                              // a phone gets its own. Empty is "the moment it goes
                              // live"; a past date back-dates it; a future one
                              // holds it off the site (Post::live()) until then,
                              // with no job that has to run at that moment.
                              //
                              // The box speaks the SITE's time, not UTC and not
                              // the browser's: Dates::toInput()/fromInput() are
                              // the two conversions, and the help line names the
                              // zone because nothing else on the screen can.
                              //
                              // On a failed save the typed value comes back as
                              // typed — even an unreadable one, so the author can
                              // see what the error is about. ?>
                        <?php
                        $publishedLocal = (!$saved && isset($_POST['published_local']) && !is_array($_POST['published_local']))
                            ? (string) $_POST['published_local']
                            : Dates::toInput($isNew ? null : ($post['published_at'] ?? null));
                        ?>
                        <div class="field">
                            <label class="label" for="f-published">Publish date</label>
                            <input class="input" type="datetime-local" id="f-published" name="published_local"
                                   value="<?= h($publishedLocal) ?>">
                            <p class="form-help">
                                Empty means the moment it goes live; a later date holds it
                                back until then. Site time: <?= h(Dates::zone()->getName()) ?>.
                            </p>
                        </div>

                        <?php // ── WHEN IT COMES OFF ────────────────────────────
                              // The same control and the same two conversions.
                              // Empty keeps it on the site; a date takes it off
                              // then (Post::live()), as Private would, with
                              // nothing that has to run at that moment, and the
                              // chip says Ended. ?>
                        <?php
                        $unpublishLocal = (!$saved && isset($_POST['unpublish_local']) && !is_array($_POST['unpublish_local']))
                            ? (string) $_POST['unpublish_local']
                            : Dates::toInput($isNew ? null : ($post['unpublish_at'] ?? null));
                        ?>
                        <div class="field">
                            <label class="label" for="f-unpublish">Unpublish date</label>
                            <input class="input" type="datetime-local" id="f-unpublish" name="unpublish_local"
                                   value="<?= h($unpublishLocal) ?>">
                            <p class="form-help">
                                Empty keeps it on the site; a date takes it off then.
                            </p>
                        </div>
                    </div>

                    <?php // AN ENTRY NOT ON THE SITE, SHARED BY A LINK
                          // (`Content\Share`): Private, its publish date still to
                          // come, or its unpublish date gone by. A live entry
                          // needs none, and a type with no address has nowhere
                          // to send anyone. ?>
                    <?php
                    $shareable = !$isNew && $viewUrl !== null
                        && ((string) $post['status'] === 'private' || \Cms\Models\Post::isScheduled($post) || \Cms\Models\Post::hasEnded($post));
                    ?>
                    <?php if ($shareable): ?>
                        <?php [$sharePart, $shareType, $shareId, $shareAdmin, $shareUrl] = ['panel', 'post', $id, $adminSlug . '/' . $id, (string) $viewUrl]; ?>
                        <?php require ROOT_PATH . '/admin/templates/partials/share-draft.php'; ?>
                    <?php endif; ?>

                    <?php // ── WHO WROTE IT ──────────────────────────────────
                          // An Author box, for an editor or admin; an author
                          // writes as themselves and is not offered it.
                          // The marker says the control was there (saveAuthor()).
                          // The options read as the byline will: display name,
                          // else username — User::displayName(), the same rule
                          // User::nameSql() applies in every query. ?>
                    <?php if ($authors !== null): ?>
                        <?php [$authorUsers, $authorChosen] = $authors; ?>
                        <div class="form-card__section">
                            <?= panel_title('Author', 'author') ?>
                            <input type="hidden" name="author_panel" value="1">
                            <div class="field">
                                <label class="label" for="f-author">Written by</label>
                                <select class="select" id="f-author" name="author_id">
                                    <?php foreach ($authorUsers as $authorUser): ?>
                                        <option value="<?= (int) $authorUser['id'] ?>"<?= $authorChosen === (int) $authorUser['id'] ? ' selected' : '' ?>><?= h(\Cms\Models\User::displayName($authorUser)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="form-help">The byline shows the name set on their user profile.</p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php // ── WHICH ONE DOES THIS BELONG TO? ────────────────
                          // Only for a type that DECLARES a parent (migration
                          // 047), so most entry screens never see it. The
                          // declaration is what gives this control a list:
                          // without it the only honest option set is every entry
                          // of every type, which on a real site is a thousand-row
                          // select and not a control. ?>
                    <?php if ($parentType !== null): ?>
                        <div class="form-card__section">
                            <?= panel_title('Belongs to', 'parent') ?>
                            <?php // THE MARKER. A "Nothing" selection and an
                                  // absent control post the same empty value and
                                  // mean opposite things — deliberately detaching
                                  // an entry, and a type that has no parent at
                                  // all. `saveParent()` reads this to tell them
                                  // apart, the same marker the options panel
                                  // uses, for the same reason. ?>
                            <input type="hidden" name="parent_panel" value="1">
                            <div class="field">
                                <label class="label" for="f-parent"><?= h((string) $parentType['label_single']) ?></label>
                                <select class="select" id="f-parent" name="parent_id">
                                    <?php // NAMED, never a blank row: an empty
                                          // line cannot say whether this belongs
                                          // to nothing or whether there is
                                          // nothing for it to belong to. ?>
                                    <option value="0"><?= $parentOptions === []
                                        ? h('No ' . strtolower((string) $parentType['label']) . ' yet')
                                        : 'Nothing yet' ?></option>
                                    <?php foreach ($parentOptions as $option): ?>
                                        <option value="<?= (int) $option['id'] ?>"
                                            <?= $parentId === (int) $option['id'] ? ' selected' : '' ?>>
                                            <?= h((string) $option['title']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="form-help">
                                    <?php // AND WHAT IT DOES NOT DO. "Belongs to"
                                          // reads like it should change the
                                          // address; saying so here is cheaper
                                          // than the support call when it does
                                          // not. ?>
                                    Named on this entry's row in the
                                    <?= h(strtolower((string) $contentType['label'])) ?> list.
                                    Its own address does not change.
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($handOrdered): ?>
                        <div class="form-card__section">
                            <?= panel_title('Position', 'order') ?>
                            <div class="field">
                                <label class="label" for="f-menu-order">Order</label>
                                <input class="input" type="number" id="f-menu-order" name="menu_order"
                                       value="<?= h($val('menu_order', (string) ($isNew ? '' : (int) $post['menu_order']))) ?>"
                                       min="0" step="1"
                                       placeholder="<?= h($isNew ? 'Last' : '') ?>">
                                <?php // What the number DOES, in the terms the author
                                      // will see it act — the same sentence the Pages
                                      // form uses, because it is the same column doing
                                      // the same job. ?>
                                <p class="form-help">
                                    Lower numbers come first on <?= h((string) $contentType['label']) ?>.
                                    Leave empty on a new one and it goes last.
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (in_array('thumbnail', $supports, true)): ?>
                        <div class="form-card__section">
                            <?= panel_title('Featured image', 'media') ?>
                            <div class="field">
                                <?= FieldRenderer::renderImage('featured_image_id', $featuredImageId) ?>
                                <?php // NO HINT. PostController::show uses the
                                      // featured image as the og:image fallback,
                                      // and Sitemap::contentType emits it as
                                      // <image>. A control that works does not
                                      // need a note saying so. ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($cptTaxonomies !== []): ?>
                        <?php // The taxonomies the REGISTRY says are attached to this
                              // type — not a query against the taxonomies table:
                              // anything answering "what exists in this install?"
                              // asks the Registry. ?>
                        <?php foreach ($cptTaxonomies as $taxKey => $tax): ?>
                            <?php
                            $assigned = array_map(
                                static fn (array $t): int => (int) $t['id'],
                                $assignedTerms[$taxKey] ?? []
                            );
                            // A failed submit re-renders with what was ticked.
                            if (isset($_POST['taxonomy_' . $taxKey]) && is_array($_POST['taxonomy_' . $taxKey])) {
                                $assigned = array_map('intval', $_POST['taxonomy_' . $taxKey]);
                            }
                            $hierarchical = !empty($tax['hierarchical']);
                            ?>
                            <div class="form-card__section">
                                <?= panel_title((string) $tax['label'], 'taxonomies') ?>
                                <?php // Category style is a checkbox tree; tag style
                                      // is a chip-picker. TaxonomyRegistry's own
                                      // docblock draws that line. ?>
                                <?= $hierarchical
                                    ? TermRenderer::checkboxTree(Term::treeForTaxonomy((string) $taxKey), $assigned, (string) $taxKey)
                                    : TermRenderer::tagPicker(Term::allForTaxonomy((string) $taxKey), $assigned, (string) $taxKey, (string) $tax['label_single']) ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="form-card__section">
                        <?= panel_title('Search engines', 'seo') ?>

                        <?php // GUIDANCE. "Title" inside a card called "Search
                              // engines" does not tell a site owner that it
                              // overrides the browser tab, or how long it should
                              // be before a search engine truncates it. That is a
                              // fact you can only learn by being told. ?>
                        <?php // PILLS (`variable-editor.js`), the same editor as
                              // Settings → SEO's patterns. The title box STARTS
                              // FROM the Entries pattern; saved still saying
                              // exactly that, it is stored as none and goes on
                              // following the pattern (`Head::ownTitle()`).
                              //
                              // NO maxlength ON EITHER: it would count the stored
                              // text, in which `{site_name}` is eleven characters
                              // whatever the site is called. ?>
                        <?php $ownTitle = trim((string) ($_POST['seo_title'] ?? $seoTitle ?? '')); ?>
                        <div class="field" <?= VariableEditor::attrs('content_type') ?>>
                            <label class="label" for="f-seo-title">Title</label>
                            <input class="input" type="text" id="f-seo-title" name="seo_title"
                                   value="<?= h($ownTitle !== '' ? $ownTitle : Head::titlePattern('content_type')) ?>">
                            <p class="form-help">
                                The browser tab and search result heading. Starts as the Entries
                                pattern (Settings → SEO); change it and this
                                <?= h(strtolower($labelSingle)) ?> keeps its own. Around 50–60
                                characters.
                            </p>
                        </div>

                        <div class="field" <?= VariableEditor::attrs('content_type') ?>>
                            <label class="label" for="f-meta-description">Description</label>
                            <textarea class="textarea" id="f-meta-description" name="meta_description"
                                      rows="3"
                                      placeholder="A sentence describing this <?= h(strtolower($labelSingle)) ?>."><?= h((string) ($_POST['meta_description'] ?? $metaDescription ?? '')) ?></textarea>
                            <p class="form-help">
                                The grey text under the heading in search results.
                                Around 120–155 characters.<?php if (in_array('excerpt', $supports, true)): ?>
                                Leave blank to use the excerpt.<?php endif; ?>
                            </p>
                        </div>

                        <?php
                        // ── ROBOTS AND SOCIAL IMAGE ───────────────────────────
                        // `PostController` reads `_seo_robots` and `_og_image`
                        // for the page it serves; this is where an owner writes
                        // them: a per-entry "Allow search engines to show this
                        // in search results?" and a Social image, on the panel
                        // that already holds the title and description.
                        //
                        // A failed save shows what was chosen; otherwise the
                        // entry's own value. `$meta` is set on every path that
                        // renders this form.
                        $seoIndex  = array_key_exists('seo_index', $_POST)
                            ? (string) $_POST['seo_index'] !== '0'
                            : !str_contains((string) ($meta['_seo_robots'] ?? ''), 'noindex');
                        $ogImageId = array_key_exists('og_image_id', $_POST)
                            ? (int) $_POST['og_image_id']
                            : (ctype_digit((string) ($meta['_og_image'] ?? '')) ? (int) $meta['_og_image'] : 0);
                        ?>
                        <div class="field">
                            <label class="label" for="f-seo-index">Show in search results</label>
                            <select class="select" id="f-seo-index" name="seo_index">
                                <option value="1"<?= $seoIndex ? ' selected' : '' ?>>Yes</option>
                                <option value="0"<?= $seoIndex ? '' : ' selected' ?>>No</option>
                            </select>
                            <?php // WHAT "NO" DOES, and only that: the page says
                                  // `noindex` (PostController) and the sitemap leaves
                                  // it out (Sitemap::contentType()). It stays on the
                                  // site, in its lists, at its address. ?>
                            <p class="form-help">
                                No asks search engines to leave it out, and takes it
                                out of the sitemap. It stays on the site and in its lists.
                            </p>
                        </div>

                        <div class="field">
                            <span class="label">Social image</span>
                            <?= FieldRenderer::renderImage('og_image_id', $ogImageId) ?>
                            <?php // The order PostController uses: this, then the
                                  // featured image, then the site's own image from
                                  // Settings → SEO (SEO\Head). ?>
                            <p class="form-help">
                                Shown when the page is shared. Leave empty to use the
                                featured image, or without one the Sharing image
                                from Settings → SEO.
                            </p>
                        </div>
                    </div>

                    <?php if (!$isNew && $revisions !== []): ?>
                        <?php // A LIST OF ROWS, the same vocabulary as every other
                              // list in the admin, so restoring a version reads like
                              // the operation it is. Each row is its own POST — a
                              // restore is a write, so it cannot be a link.
                              //
                              // --bleed pulls the rows out to the card's own edges:
                              // a bordered list inset inside a padded section is
                              // padding within padding. It reads var(--form-pad), so
                              // it cannot drift from
                              // the padding it is cancelling. ?>
                        <div class="form-card__section">
                            <?= panel_title('History', 'history') ?>
                            <div class="list list--bleed">
                                <?php foreach (array_slice($revisions, 0, 5) as $revision): ?>
                                    <div class="list__row">
                                        <div class="entry-list__main">
                                            <div class="list__row-title"><?= h((string) $revision['title']) ?></div>
                                            <div class="list__row-sub">
                                                <time datetime="<?= h(date('c', strtotime((string) $revision['created_at']))) ?>">
                                                    <?= h(Dates::stamp((string) $revision['created_at'])) ?>
                                                </time>
                                            </div>
                                        </div>
                                        <div class="list__row-actions">
                                            <?php // `form=` and NOT a nested <form>. See the note
                                                  // where these forms are emitted, below. ?>
                                            <button type="submit" class="btn btn--sm"
                                                    form="restore-<?= (int) $revision['id'] ?>">Restore</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>


                    <?php if (!$isNew): ?>
                        <?php // A PLAIN TEXT LINK at the foot of the panel, not a
                              // red-bordered card with an outline button. A
                              // destructive TRIGGER does not shout — the confirm
                              // dialog it opens IS the alert surface, and a
                              // low-emphasis destructive action is a plain
                              // `.link .link--xs` with no colour at all.
                              //
                              // INSIDE the panel, as its last section, not floating
                              // under the card with nothing containing it. ?>
                        <div class="form-card__section form-aside__danger">
                        <button type="submit" class="link link--xs" form="trash-entry">Move to trash</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </form>

    <?php // ── EVERY OTHER WRITE ON THIS SCREEN, OUTSIDE THE SAVE FORM ──────
          //
          // HTML HAS NO NESTED FORMS. Writing `<form action=".../trash">` inside
          // the save form does not produce a nested form — the parser DISCARDS
          // the inner tag, and its button becomes a submit button of the OUTER
          // one. So a nested "Move to trash" would quietly SAVE the entry
          // instead of trashing it: HTTP 302, a success flash, and the entry
          // still sitting in the list. Nothing looks broken.
          //
          // `form=` is the mechanism that actually exists for this: the button
          // stays where it belongs in the layout and submits a form declared
          // anywhere in the document. ?>
    <?php if (!$isNew): ?>
        <form id="trash-entry" method="post"
              action="<?= h(Url::admin($adminSlug . '/' . $id . '/trash')) ?>" hidden>
            <?= Csrf::field() ?>
        </form>
        <?php if (!empty($shareable)): ?>
            <?php $sharePart = 'forms'; ?>
            <?php require ROOT_PATH . '/admin/templates/partials/share-draft.php'; ?>
        <?php endif; ?>

        <?php foreach (array_slice($revisions, 0, 5) as $revision): ?>
            <form id="restore-<?= (int) $revision['id'] ?>" method="post"
                  action="<?= h(Url::admin('revisions/' . (int) $revision['id'] . '/restore')) ?>" hidden>
                <?= Csrf::field() ?>
            </form>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<?php // Emitted by the SCREEN, inside $pageContent, which the layout prints
      // above its own scripts — so CMS (admin.js, in <head>) is already there. ?>
<?php // CONDITIONAL LOGIC ON THE ENTRY FORM. `FieldRenderer::wrap()` puts the
      // rule set on each `.field` as `data-logic`; this binds the same engine the
      // public form uses. `prefix` is `field_` because that is the convention
      // `FieldRenderer` names its controls with, while a rule stored in the field
      // group editor names the field's own key — the engine reconciles the two so
      // neither has to know about the other.
      //
      // The server re-evaluates every rule in `validateAndSanitize()` regardless.
      // This is here so the form REACTS, not so it is correct. ?>
<script src="<?= h(Asset::admin('js/logic.js')) ?>"></script>
<script src="<?= h(Asset::admin('js/permalink.js')) ?>"></script>
<script src="<?= h(Asset::admin('js/entry-form.js')) ?>"></script>
<?php // The Search engines panel's pills: the template once, reorder.js for the
      // drag's mirror and line, then the editor. ?>
<?= VariableEditor::template() ?>
<script src="<?= h(Asset::admin('js/reorder.js')) ?>"></script>
<script src="<?= h(Asset::admin('js/variable-editor.js')) ?>" defer></script>
<script>
(function () {
    var form = document.querySelector('.editor-form');
    if (form && window.Logic) { Logic.bind(form, {prefix: 'field_'}); }
}());
</script>
<?php // WITHOUT THIS THE TEXTAREA IS JUST A TEXTAREA — which is the
      // documented degraded state, not a fault. Linked on the screens that
      // have a `[data-editor]` box and nowhere else. ?>
<?= \Cms\Ui\Editor::iconTemplate() ?>
<?php // BEFORE editor.js, which calls `Prompt.ask()`. Both are deferred, so
      // they execute in document order and the global exists by the time the
      // editor binds. ?>
<script src="<?= h(\Cms\Helpers\Asset::admin('js/prompt.js')) ?>" defer></script>
<script src="<?= h(\Cms\Helpers\Asset::admin('js/editor.js')) ?>" defer></script>
<?php // AFTER editor.js, deferred too, so the editor has bound its box before
      // this reads the writing (`Ui\KeepWriting`). ?>
<script src="<?= h(\Cms\Helpers\Asset::admin('js/keep-writing.js')) ?>" defer></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
