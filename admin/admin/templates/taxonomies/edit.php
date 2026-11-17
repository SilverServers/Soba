<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Models\Term;
use Cms\Security\Csrf;
use Cms\Ui\Sidebar;

/**
 * THE TAXONOMY FORM — one screen, create and edit both.
 *
 * Same layout as the entry form, the Pages form, the Content Type form and the
 * field group editor: `.form-main` / `.form-aside`, one `.form-card` per column,
 * sections divided by hairlines, footer as the last section. Nothing about the
 * geometry is decided in this file — see core.css §4.
 *
 * ── THE KEY IS A FACT ON EDIT, NOT A CONTROL ────────────────────────────────
 * Every `term_relationships` row and every taxonomy field's `taxonomy_key`
 * setting is filed under it, so changing it orphans all of them silently. On an
 * existing taxonomy it renders as a value, not an input.
 *
 * NOT a disabled input. A control that cannot act is a read-only row at field
 * scale, and a greyed box invites a click that does nothing.
 *
 * And it is NOT smuggled through a hidden input either. The controller takes the
 * stored key from the database on update — `TaxonomyController::handleUpdate()`
 * has the reasoning, including the second failure a hidden input would not have
 * fixed: the uniqueness check asks the Registry, and an existing taxonomy is IN
 * the Registry, so it would reject its own key as already in use.
 *
 * ── FLAT vs NESTED IS THE ONE DECISION THAT MATTERS ─────────────────────────
 * It decides the control every entry form draws for this taxonomy — a tick-box
 * tree or a chip picker — so the form says what each one is FOR rather than
 * naming the data structure. "Hierarchical" is a word for the person who wrote
 * the database, not the person filing news stories.
 *
 * @var array<string, mixed>|null $taxonomy         null on create
 * @var array<string, mixed>      $formData         $_POST on a failed submit
 * @var array<string, string>     $errors
 * @var array<string, array>      $allContentTypes  Registry::all()
 * @var bool                      $saved            handleUpdate() only
 */

$saved    = $saved    ?? false;
$errors   = $errors   ?? [];
$formData = $formData ?? [];

$isNew = ($taxonomy ?? null) === null;
$id    = $isNew ? 0 : (int) $taxonomy['id'];

// On a failed submit the form comes back wearing what the AUTHOR typed, not what
// is in the database — otherwise a validation error silently discards the edit
// it is complaining about. Same rule as every other form here.
$val = static function (string $key, string $fallback = '') use ($formData, $taxonomy): string {
    if (isset($formData[$key]) && !is_array($formData[$key])) {
        return (string) $formData[$key];
    }
    return (string) ($taxonomy[$key] ?? $fallback);
};

$has = $formData !== [];

// `!empty()`, NOT `isset()`. The shape is a RADIO PAIR, so `is_hierarchical` is
// ALWAYS posted — `""` for Flat, `"1"` for Nested. `isset()` is true for both, so
// a failed submit would re-render every taxonomy as Nested regardless of what the
// author had chosen, quietly changing their answer while telling them to fix
// something else. The same expression appears in TaxonomyController; they read
// the value the same way or they disagree about what was submitted.
$hierarchical = $has
    ? !empty($formData['is_hierarchical'])
    : (!$isNew && (bool) $taxonomy['is_hierarchical']);
// Whether a customer chooses from it when buying (migration 095).
$isOption = $has
    ? !empty($formData['is_option'])
    : (!$isNew && !empty($taxonomy['is_option']));
$isActive = $has
    ? isset($formData['is_active'])
    : ($isNew ? true : (bool) $taxonomy['is_active']);
$targets = $has
    ? array_values(array_filter((array) ($formData['content_types'] ?? []), 'is_string'))
    : (array) ($taxonomy['content_types'] ?? []);

// HOW MANY AN ENTRY TAKES — the four answers the controller stores as a
// minimum and a maximum (migration 056). Read back from the pair, or from what
// was just posted when the form comes back with an error.
if ($has) {
    $termsRule = (string) ($formData['terms_rule'] ?? 'any');
    $mostTerms = (string) ($formData['max_terms'] ?? '');
} else {
    $minT      = (int) ($taxonomy['min_terms'] ?? 0);
    $maxT      = isset($taxonomy['max_terms']) ? (int) $taxonomy['max_terms'] : null;
    $termsRule = $minT === 1 && $maxT === 1 ? 'exactly_one'
               : ($minT === 1 ? 'one_or_more' : ($maxT !== null ? 'at_most' : 'any'));
    $mostTerms = $termsRule === 'at_most' ? (string) $maxT : '';
}

// The count is the fact you want before deleting, and it is why the danger zone
// can say what actually happens instead of a general warning.
$termCount = $isNew ? 0 : count(Term::allForTaxonomy((string) $taxonomy['taxonomy_key']));

$action = $isNew
    ? Url::admin('taxonomies/new')
    : Url::admin('taxonomies/' . $id . '/edit');

// NO ob_start() AND NO require layout.php HERE — TaxonomyController buffers this
// file and requires the layout itself.
?>
<div class="page">

    <?php // ABOVE `.page__header`, not inside `.page__header-main` — that element
          // is a flex ROW, so the link would render beside the title. core.css §1. ?>
    <a class="page__back" href="<?= h(Url::admin('taxonomies')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Taxonomies
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">
                <?= h($isNew ? 'Add taxonomy' : (string) $taxonomy['plural_label']) ?>
            </h1>
        </div>
        <div class="page__header-actions">
            <?php if (!$isNew): ?>
                <?php // A DESTINATION, not a stat. A bare bordered box reading
                      // "3 terms" alone in the header would have the shape of a
                      // metric tile and the behaviour of a link — you could not
                      // tell by looking that clicking it goes anywhere. The term
                      // glyph is the one the taxonomies list and the sidebar
                      // already use for terms, so the button wears the mark of
                      // the place it opens. ?>
                <a class="btn" href="<?= h(Url::admin('taxonomies/' . (string) $taxonomy['admin_slug'] . '/terms')) ?>">
                    <?= icon(Sidebar::iconFor('terms'), 'icon icon--sm') ?>
                    <?= $termCount ?> <?= $termCount === 1 ? 'term' : 'terms' ?>
                    <?= icon('angle-right', 'icon icon--xs icon--go') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($saved): ?>
        <?php // The SHAPE of an alert with no alert COLOUR: a save that worked is
              // not a fault. It reports what happened and gets out of the way. ?>
        <div class="flashes">
            <div class="flash flash--success">Taxonomy saved.</div>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <?php // One alert listing every problem, not one red box per field —
              // the first of the CLOSED list of places --danger may be
              // painted. ?>
        <div class="alert alert--danger">
            <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__title">
                    <?= count($errors) === 1 ? 'One thing needs fixing' : count($errors) . ' things need fixing' ?>
                </p>
                <ul class="alert__text">
                    <?php foreach ($errors as $message): ?>
                        <li><?= h($message) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= h($action) ?>">
        <?= Csrf::field() ?>

        <div class="panel__grid">

            <div class="form-main">
                <div class="form-card">

                    <div class="form-card__section">
                        <?= panel_title('Names', 'names') ?>

                        <?php // TWO labels, side by side, because they are one
                              // decision — "Categories" and "Category" are chosen
                              // together and read wrong apart. ?>
                        <div class="form-grid form-grid--2">
                            <div class="field">
                                <label class="label" for="f-plural">Plural<span class="label__req">required</span></label>
                                <input class="input" type="text" id="f-plural" name="plural_label" required
                                       value="<?= h($val('plural_label')) ?>" autocomplete="off"
                                       placeholder="Categories" data-tax-plural>
                            </div>

                            <div class="field">
                                <label class="label" for="f-singular">Singular<span class="label__req">required</span></label>
                                <input class="input" type="text" id="f-singular" name="singular_label" required
                                       value="<?= h($val('singular_label')) ?>" autocomplete="off"
                                       placeholder="Category" data-tax-singular>
                            </div>
                        </div>

                        <div class="field">
                            <?php if ($isNew): ?>
                                <label class="label" for="f-key">Key<span class="label__req">required</span></label>
                                <input class="input input--mono" type="text" id="f-key" name="taxonomy_key" required
                                       value="<?= h($val('taxonomy_key')) ?>" autocomplete="off"
                                       pattern="[a-z][a-z0-9_]{0,49}" placeholder="category" data-tax-key>
                                <p class="form-help">
                                    Lowercase letters, digits and underscores. It cannot be changed later.
                                </p>
                            <?php else: ?>
                                <?php // NOT a disabled input — see the docblock.
                                      // The key is a FACT here, so it renders as
                                      // one, and the sentence says WHY rather than
                                      // just that it is fixed. ?>
                                <span class="label">Key</span>
                                <div class="kv-list">
                                    <div class="kv-list__row">
                                        <span class="kv-list__val"><code><?= h((string) $taxonomy['taxonomy_key']) ?></code></span>
                                    </div>
                                </div>
                                <p class="form-help">
                                    <?= $termCount ?>
                                    <?= $termCount === 1 ? 'term is' : 'terms are' ?>
                                    filed under this key, so it is fixed.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-card__section">
                        <?= panel_title('Shape', 'shape') ?>
                        <?php // WHAT IT IS FOR, not what it is called. "Nested"
                              // and "Flat" decide the control every entry form
                              // draws for this taxonomy, so each option says which
                              // control you get and what it suits. "Hierarchical"
                              // is a word for whoever wrote the database. ?>
                        <?php // THE SAME TWO MARKS THE LIST USES. `tag` for flat
                              // and `folder-tree` for nested are what the
                              // taxonomies list draws in its Flat/Nested chip, so
                              // the shape you pick here is the shape you
                              // recognise there. ?>
                        <div class="check-list">
                            <label class="check-list__row">
                                <input type="radio" name="is_hierarchical" value=""
                                       <?= $hierarchical ? '' : 'checked' ?>>
                                <span class="check-list__name">
                                    <?= icon('tag', 'check-list__glyph icon') ?>Flat
                                </span>
                                <span class="check-list__note">a type-ahead picker, like tags</span>
                            </label>
                            <label class="check-list__row">
                                <input type="radio" name="is_hierarchical" value="1"
                                       <?= $hierarchical ? 'checked' : '' ?>>
                                <span class="check-list__name">
                                    <?= icon('folder-tree', 'check-list__glyph icon') ?>Nested
                                </span>
                                <span class="check-list__note">a tick-box tree, like categories</span>
                            </label>
                        </div>
                        <?php if (!$isNew && $termCount > 0): ?>
                            <?php // A FACT ABOUT THE SWITCH, at the moment it can
                                  // be thrown. Changing to Flat leaves the parent
                                  // links in the database; nothing is deleted, but
                                  // the tree stops being drawn, and an owner who
                                  // is not told that reads it as data loss. ?>
                            <p class="form-help">
                                Changing this changes the control on every entry form.
                                Existing terms and their assignments are kept either way —
                                a nested term's parent is remembered even while it is flat.
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-card__section">
                        <?= panel_title('How many an entry takes', 'how-many') ?>
                        <?php // Migration 056: a taxonomy can require a term,
                              // exactly one, or no more than N — what a site that
                              // files every article under exactly one category
                              // needs. Obeyed by every write path through
                              // `Term::setPostTerms()`; the entry form refuses a
                              // save that breaks it, in these words. Each note
                              // says what the owner will SEE on an entry form. ?>
                        <div class="check-list">
                            <label class="check-list__row">
                                <input type="radio" name="terms_rule" value="any"
                                       <?= $termsRule === 'any' ? 'checked' : '' ?>>
                                <span class="check-list__name">Any number</span>
                                <span class="check-list__note">none, one or several</span>
                            </label>
                            <label class="check-list__row">
                                <input type="radio" name="terms_rule" value="one_or_more"
                                       <?= $termsRule === 'one_or_more' ? 'checked' : '' ?>>
                                <span class="check-list__name">At least one</span>
                                <span class="check-list__note">an entry is not saved without one</span>
                            </label>
                            <label class="check-list__row">
                                <input type="radio" name="terms_rule" value="exactly_one"
                                       <?= $termsRule === 'exactly_one' ? 'checked' : '' ?>>
                                <span class="check-list__name">Exactly one</span>
                                <span class="check-list__note">the entry form offers a single choice</span>
                            </label>
                            <label class="check-list__row">
                                <input type="radio" name="terms_rule" value="at_most"
                                       <?= $termsRule === 'at_most' ? 'checked' : '' ?>>
                                <span class="check-list__name">No more than</span>
                                <input class="input input--sm" type="number" name="max_terms" min="1" max="99"
                                       value="<?= h($mostTerms) ?>" aria-label="The most an entry can take">
                            </label>
                        </div>
                    </div>

                    <?php // ── WHAT A CUSTOMER CHOOSES WHEN BUYING ───────────────────
                          // Size and Colour are taxonomies whose terms make a
                          // product's options (migration 095): its options are
                          // made from the sizes and colours ticked on it, and the
                          // product page offers a chooser for each. Drawn only on
                          // an install that sells anything — on a site without a
                          // shop the question has no meaning. ?>
                    <?php if (\Cms\Commerce\Product::sellableTypes() !== []): ?>
                        <div class="form-card__section">
                            <?= panel_title('When a customer buys', 'variants') ?>
                            <div class="check-list">
                                <label class="check-list__row">
                                    <input type="checkbox" name="is_option" value="1" <?= $isOption ? 'checked' : '' ?>>
                                    <span class="check-list__name">Customers choose one</span>
                                    <span class="check-list__note">its terms are the sizes, colours or fits a product is made in</span>
                                </label>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="form-card__section">
                        <?= panel_title('Address', 'address') ?>
                        <?php // THE ADDRESS IS ONE STRING AND IT READS AS ONE —
                              // not a lone narrow box with the finished URL
                              // printed underneath as a separate grey sentence,
                              // two halves of one fact on two lines.
                              //
                              // `.prefixed` is the vocabulary for this and the
                              // Content Type form beside it already uses it: the
                              // fixed part sits in front of the box, the part you
                              // can change is the part you can type in. THE PATH,
                              // NOT THE ORIGIN — the host can be 46 characters
                              // that are the same on every row of every screen
                              // and that nobody is deciding here, and printing it
                              // would force the hint onto its own line. ?>
                        <div class="field">
                            <label class="label" for="f-admin-slug">Admin URL</label>
                            <div class="prefixed">
                                <span class="prefixed__prefix">/<?= h(trim(ADMIN_URL_SLUG, '/')) ?>/taxonomies/</span>
                                <input class="input input--mono" type="text" id="f-admin-slug" name="admin_slug"
                                       value="<?= h($val('admin_slug')) ?>" autocomplete="off"
                                       spellcheck="false" placeholder="<?= h($isNew ? 'categories' : '') ?>">
                                <span class="prefixed__suffix">/terms</span>
                            </div>
                            <p class="form-help">Left blank, it follows the plural name.</p>
                        </div>

                        <?php // ── AND THE ONE VISITORS SEE ────────────────────
                              // Migration 048: the address of the public pages
                              // that list what is filed under a term.
                              //
                              // BLANK MEANS NO PUBLIC PAGES, deliberately — a
                              // taxonomy that organises the admin should not
                              // gain public pages because a column appeared
                              // under it. The routes are only registered for a
                              // taxonomy that has one. ?>
                        <div class="field">
                            <label class="label" for="f-public-slug">Public URL</label>
                            <div class="prefixed">
                                <span class="prefixed__prefix">/</span>
                                <input class="input input--mono" type="text" id="f-public-slug" name="public_slug"
                                       value="<?= h($val('public_slug')) ?>" autocomplete="off"
                                       spellcheck="false" placeholder="<?= h($isNew ? 'topics' : '') ?>">
                                <span class="prefixed__suffix">/term</span>
                            </div>
                            <p class="form-help">
                                Where a visitor sees everything filed under one term. It can
                                sit under a content type's address — <code>articles/category</code>.
                                <strong>Leave it empty for no public pages</strong> — useful
                                for a taxonomy that only organises this admin.
                            </p>
                        </div>
                    </div>

                    <div class="form-card__section form-actions">
                        <a class="link link--xs" href="<?= h(Url::admin('taxonomies')) ?>">Cancel</a>
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn btn--primary">
                            <?= $isNew ? 'Create taxonomy' : 'Save changes' ?>
                        </button>
                    </div>

                </div>
            </div>

            <div class="form-aside">
                <div class="form-card">

                    <div class="form-card__section">
                        <?php // `panel_title`, not a bare `<h2>`. Every aside panel
                              // on the Content Type form and the Pages form carries
                              // its own mark in the heading; as bare `<h2>`s these
                              // two would be the only words in the column with
                              // nothing beside them.
                              // `content-types` is the registered key the sidebar
                              // row uses, so the panel and the destination it is
                              // about cannot end up wearing different marks. ?>
                        <?= panel_title('Used by', 'content-types') ?>
                        <?php if ($allContentTypes === []): ?>
                            <p class="form-help">
                                No content types are registered, so this taxonomy
                                would have nothing to file.
                                <a class="link" href="<?= h(Url::admin('content-types/new')) ?>">Add a content type</a>
                                first.
                            </p>
                        <?php else: ?>
                            <?php // The type's OWN registered glyph beside its own
                                  // label — the same mark the sidebar draws, never
                                  // one derived from the label. ?>
                            <div class="check-list">
                                <?php foreach ($allContentTypes as $ct): ?>
                                    <label class="check-list__row">
                                        <input type="checkbox" name="content_types[]"
                                               value="<?= h((string) $ct['key']) ?>"
                                               <?= in_array($ct['key'], $targets, true) ? 'checked' : '' ?>>
                                        <?= icon(Sidebar::iconFor((string) $ct['menu_icon']), 'icon icon--sm') ?>
                                        <span><?= h((string) $ct['label']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="form-help">
                                The picker appears on every entry of the types ticked here.
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-card__section">
                        <?php // NO HEADING — as on the Content Type form beside
                              // it. "Active" over a box
                              // labelled "Registered" is the same word twice, and
                              // the one that survives is the one on the control
                              // you actually tick. ?>
                        <div class="check-list">
                            <label class="check-list__row">
                                <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
                                <span>Registered</span>
                            </label>
                        </div>
                        <?php // The consequence, not the mechanism. Unregistering
                              // is reversible and destroys nothing, and saying so
                              // is what stops it reading like a delete. ?>
                        <?php // NO PUBLIC URL CLAIM. The help line says what
                              // unregistering changes in the admin, and nothing
                              // about public addresses. ?>
                        <p class="form-help">
                            Unregistering removes the picker from the entry forms and the
                            row from the sidebar. Terms and their assignments are kept.
                        </p>
                    </div>


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
                        <button type="submit" class="link link--xs" form="delete-taxonomy">Delete taxonomy</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </form>

    <?php // OUTSIDE the form above, targeted by `form=`. HTML HAS NO NESTED
          // FORMS — the parser discards the inner tag and reparents its button to
          // the outer form, so a delete written inside the save form quietly
          // SAVES instead. ?>
    <?php if (!$isNew): ?>
        <form id="delete-taxonomy" method="post"
              action="<?= h(Url::admin('taxonomies/' . $id . '/delete')) ?>"
              data-confirm="Delete the <?= h((string) $taxonomy['plural_label']) ?> taxonomy?"
              data-confirm-detail="<?= $termCount ?> <?= $termCount === 1 ? 'term goes' : 'terms go' ?> with it, and <?= $termCount === 1 ? 'its assignment is' : 'their assignments are' ?> removed from every entry. The entries themselves are untouched."
              data-confirm-verb="Delete taxonomy" hidden>
            <?= Csrf::field() ?>
        </form>
    <?php endif; ?>

</div>

<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
<?php if ($isNew): ?>
    <?php // Only on create. The script derives the singular and the key from the
          // plural until each is touched — and on an existing taxonomy the key
          // box does not exist and the labels must never be re-derived, because
          // someone renaming "Categories" to "Topics" is renaming a label, not
          // asking for a different key. ?>
    <script src="<?= h(Asset::admin('js/taxonomy-form.js')) ?>"></script>
<?php endif; ?>
