<?php

declare(strict_types=1);

use Cms\ContentType\ArchetypeDefaults;
use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Models\FieldGroup;
use Cms\Models\Post;
use Cms\Security\Csrf;
use Cms\Security\Csrf as Token;
use Cms\Ui\Icons;
use Cms\Ui\Sidebar;

/**
 * ADD / EDIT A CONTENT TYPE — the other half of the rule that every Content
 * Type is fully manageable in the admin.
 *
 * The list screen proves a type is VISIBLE; this one proves it is EDITABLE. A
 * type that reaches the admin and cannot be changed here is a failure, not a
 * state.
 *
 * ── NO ob_start() AND NO require layout.php ─────────────────────────────────
 * `ContentTypeDefinitionController` buffers this file and requires the layout
 * itself. A template that also wrapped itself renders the whole shell TWICE,
 * nested, at HTTP 200 — see the guard at the top of `layout.php`.
 *
 * ── WHAT CARRIES EACH FACT ──────────────────────────────────────────────────
 *   which glyph will this wear    the GLYPH, previewed beside the picker, not
 *                                 the slug's name in a dropdown
 *   what does an archetype add    the name of the field group it brings, on
 *                                 the row — never a paragraph about archetypes
 *   what will the URLs be         the slug fields' own values
 *   is the key locked             it is, after creation, because entries are
 *                                 stored against it — so it renders as the
 *                                 fact it is rather than an input that throws
 *
 * ── WHY THE KEY IS NOT EDITABLE AFTER CREATION ──────────────────────────────
 * `posts.post_type` stores this string on every entry. Renaming it here without
 * migrating those rows would orphan every entry of the type — they would stay
 * in the table, match no registered type, and vanish from the admin while
 * remaining in the database. The list screen's own "N entries stay in the
 * database" warning is about the same fact from the other side.
 *
 * @var array<string, mixed>|null $contentTypeDefinition  null on create
 * @var array<string, mixed>      $formData               $_POST on a failed submit
 * @var array<string, string>     $errors                 keyed by field name
 * @var bool                      $saved
 * @var list<array{key: string, label: string}> $parentTypeOptions  types this one
 *      may belong to — itself and anything that would close a loop excluded
 */

$isNew   = ($contentTypeDefinition ?? null) === null;
$id      = $isNew ? 0 : (int) $contentTypeDefinition['id'];
$errors  = $errors ?? [];
$saved   = $saved ?? false;
$formData = $formData ?? [];

// $_POST wins on a failed submit, so a validation error never discards what the
// admin typed; the stored row is the fallback.
$val = static function (string $key, string $fallback = '') use ($formData, $contentTypeDefinition): string {
    if (array_key_exists($key, $formData) && !is_array($formData[$key])) {
        return (string) $formData[$key];
    }
    return (string) ($contentTypeDefinition[$key] ?? $fallback);
};

$supports = $formData !== []
    ? array_keys((array) ($formData['supports'] ?? []))
    : (array) ($contentTypeDefinition['supports'] ?? ['title', 'content']);

$archetypes = $formData !== []
    ? (array) ($formData['archetypes'] ?? [])
    : (array) ($contentTypeDefinition['archetypes'] ?? ['post']);

$isActive = $formData !== []
    ? !empty($formData['is_active'])
    : (bool) ($contentTypeDefinition['is_active'] ?? true);

$currentIcon = $val('menu_icon', 'file-text');
$entryCount  = $isNew ? 0 : Post::countOfType((string) $contentTypeDefinition['type_key']);

$action = $isNew
    ? Url::admin('content-types/new')
    : Url::admin('content-types/' . $id . '/edit');

// The glyphs offered. Deliberately the icon REGISTRY's own Content Type keys
// plus a short list of real slugs — never a free-text box, which is how an
// install ends up with a type whose glyph resolves to nothing and renders a
// blank row on the rail.
$iconChoices = [
    'file-text'     => 'Document',
    'calendar'      => 'Calendar',
    'users'         => 'People',
    'image'         => 'Image',
    'grid'          => 'Grid',
    'shopping-cart' => 'Product',
    'folder'        => 'Folder',
    'tags'          => 'Tags',
    'location-dot'  => 'Place',
    'bolt'          => 'Highlight',
    'list-check'    => 'Checklist',
    'star-of-david' => 'Feature',
];

// ── THE STORED GLYPH IS ALWAYS AN OPTION ────────────────────────────────────
//
// `$iconChoices` is a CURATED list of twelve. A type whose `menu_icon` is not
// one of them — any type registered in `config/content-types.php`, for one,
// which may name any of the 279 slugs on disk — would match no `<option>`, so
// no option would carry `selected` and the browser would fall back to showing
// the FIRST one.
//
// That is not a display glitch. The preview beside it renders the STORED glyph
// (`Sidebar::iconFor()` resolves any real slug), so the control would show a
// people mark next to the word "Document" — and because a `<select>` posts
// whatever it is displaying, **the next save of that form would silently
// rewrite the type's glyph to `file-text`**.
//
// A control that shows a value the record does not hold, and destroys the real
// one when saved, is a screen not describing the thing it claims to manage. So
// the stored slug JOINS the list rather than the list overriding the record.
//
// It goes FIRST because it is the current value, and it is named from its own
// slug — there is no friendlier name to give it, and printing the slug is
// honest about where the value came from.
if ($currentIcon !== '' && !isset($iconChoices[$currentIcon])) {
    $iconChoices = [$currentIcon => ucfirst(str_replace('-', ' ', $currentIcon))]
                 + $iconChoices;
}

$supportChoices = [
    'title'     => 'Title',
    'content'   => 'Body content',
    // Writes to `posts.excerpt`, the column migration 053 added.
    'excerpt'   => 'Excerpt',
    'thumbnail' => 'Featured image',
];

$archetypeChoices = ['post', 'event', 'listing', 'product'];

// ── THE GROUP AN ARCHETYPE HAS ALREADY BROUGHT ──────────────────────────────
// An archetype that is already applied has a REAL field group behind it, and
// that group is where its fields are edited — renaming one, deleting one,
// adding a fifth. Offering the starter-field checkboxes again there would be a
// second control over the same fields that silently does nothing (applyArchetype
// leaves an existing group alone): a box the owner types into that changes
// nothing. So the row shows the group instead, and links to it.
$appliedGroups = [];
if (!$isNew) {
    foreach ($archetypeChoices as $a) {
        $group = FieldGroup::findArchetypeGroup((string) $contentTypeDefinition['type_key'], $a);
        if ($group !== null) {
            $appliedGroups[$a] = $group;
        }
    }
}

// Which starter fields are ticked. On a failed submit the admin's own choices
// come back; otherwise every field the archetype offers, because taking the
// whole shape is what ticking an archetype has always meant and the control is
// here to let you take LESS, not to make you pick from scratch.
$seedTicked = static function (string $archetype, string $fieldKey) use ($formData): bool {
    if ($formData === [] || !isset($formData['archetype_fields_present'][$archetype])) {
        return true;
    }
    return !empty($formData['archetype_fields'][$archetype][$fieldKey]);
};
?>
<div class="page">

    <?php // ABOVE `.page__header`, not inside `.page__header-main` — that element
          // is a flex ROW, so inside it the link renders beside the title instead
          // of over it. See core.css §1. ?>
    <a class="page__back" href="<?= h(Url::admin('content-types')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Content types
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">
                <?= h($isNew ? 'Add content type' : (string) $contentTypeDefinition['plural_label']) ?>
            </h1>
        </div>
    </div>

    <?php if ($saved): ?>
        <div class="flashes">
            <div class="flash flash--success">Content type saved.</div>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <?php // One alert listing every problem — not one red box per field.
              // --danger is painted only on a CLOSED list of places, and the
              // block-level notice, with its own box and glyph, is the first
              // of them. ?>
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

            <?php // ONE CARD, hairlines between its sections, footer inside it.
                  // The same shape as the entry form and the Pages form, from the
                  // same vocabulary — core.css §4. ?>
            <div class="form-main">
                <div class="form-card">

                    <div class="form-card__section">
                        <?= panel_title('Names', 'names') ?>

                        <?php // TWO labels, side by side, because they are one
                              // decision — "News" and "News item" are chosen
                              // together and read wrong apart. ?>
                        <div class="form-grid form-grid--2">
                            <div class="field">
                                <label class="label" for="f-plural">Plural<span class="label__req">required</span></label>
                                <input class="input" type="text" id="f-plural" name="plural_label" required
                                       value="<?= h($val('plural_label')) ?>" autocomplete="off"
                                       placeholder="News" data-type-plural>
                                <?php // WHERE THE WORD ENDS UP. "Plural" tells you the
                                      // grammar, not the consequence; where the plural
                                      // name is used in the admin is the fact that
                                      // decides what you type. ?>
                                <p class="form-help">The sidebar row and the list heading.</p>
                            </div>

                            <div class="field">
                                <label class="label" for="f-singular">Singular<span class="label__req">required</span></label>
                                <input class="input" type="text" id="f-singular" name="singular_label" required
                                       value="<?= h($val('singular_label')) ?>" autocomplete="off"
                                       placeholder="News item">
                                <p class="form-help">Used on buttons and headings — &ldquo;Add new&nbsp;&hellip;&rdquo;.</p>
                            </div>
                        </div>

                        <div class="field">
                            <?php if ($isNew): ?>
                                <label class="label" for="f-key">Key<span class="label__req">required</span></label>
                                <input class="input" type="text" id="f-key" name="type_key" required
                                       value="<?= h($val('type_key')) ?>" autocomplete="off"
                                       pattern="[a-z][a-z0-9_]{0,49}" placeholder="news" data-type-key>
                                <?php // THE LIMIT IS STATED because it is enforced
                                      // and the result is immutable the moment
                                      // the first entry is saved against it. A
                                      // rule you only learn by tripping over it
                                      // is not one anyone can plan around.
                                      //
                                      // FIFTY, NOT TWENTY. Every column here
                                      // that holds a type key is varchar(50), so
                                      // a cap of twenty would be stricter than
                                      // its own storage by thirty characters.
                                      // `ContentTypeDefinitionController` has
                                      // the measurement. ?>
                                <p class="form-help">
                                    Lowercase letters, digits and underscores, up to 50
                                    characters. Suggested from the plural name, and
                                    <strong>it cannot be changed later</strong> — entries
                                    are stored against it.
                                </p>
                            <?php else: ?>
                                <?php // NOT a disabled input. A control that cannot
                                      // act is a read-only row at field scale — and
                                      // a greyed box invites a click that does
                                      // nothing. The key is a FACT here, so it
                                      // renders as one. ?>
                                <span class="label">Key</span>
                                <div class="kv-list">
                                    <div class="kv-list__row">
                                        <span class="kv-list__val"><code><?= h((string) $contentTypeDefinition['type_key']) ?></code></span>
                                    </div>
                                </div>
                                <p class="form-help">
                                    <?= (int) $entryCount ?>
                                    <?= $entryCount === 1 ? 'entry is' : 'entries are' ?>
                                    stored against this key, so it is fixed.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-card__section">
                        <?= panel_title('Addresses', 'addresses') ?>

                        <div class="form-grid form-grid--2">
                            <?php // THE PREFIX SITS IN FRONT OF THE BOX, not under it
                                  // as a truncated hint ending in an ellipsis. The
                                  // address is one string and it reads as one; the
                                  // part you can change is the part you can type in.
                                  // The entry form's own permalink row does it too —
                                  // `.permalink__prefix` is already the vocabulary
                                  // for it. ?>
                            <div class="field">
                                <label class="label" for="f-admin-slug">Admin URL</label>
                                <?php // THE PATH, NOT THE WHOLE ORIGIN. The full
                                      // absolute URL can be 46 characters of hostname
                                      // in a half-width cell, squeezing the box you
                                      // type in down to three visible letters. The
                                      // host is the same on every row of every screen
                                      // and is the one part nobody is deciding here. ?>
                                <div class="prefixed">
                                    <span class="prefixed__prefix">/<?= h(trim(ADMIN_URL_SLUG, '/')) ?>/</span>
                                    <input class="input input--mono" type="text" id="f-admin-slug" name="admin_slug"
                                           value="<?= h($val('admin_slug')) ?>" autocomplete="off"
                                           spellcheck="false" placeholder="<?= h($isNew ? 'news' : '') ?>">
                                </div>
                                <p class="form-help">Left blank, it follows the plural name.</p>
                            </div>

                            <div class="field">
                                <label class="label" for="f-public-slug">Public URL</label>
                                <div class="prefixed">
                                    <span class="prefixed__prefix">/</span>
                                    <input class="input input--mono" type="text" id="f-public-slug" name="public_slug"
                                           value="<?= h($val('public_slug')) ?>" autocomplete="off"
                                           spellcheck="false" placeholder="<?= h($isNew ? 'news' : '') ?>">
                                </div>
                                <p class="form-help">What visitors see. Changing it moves every entry's address.</p>
                            </div>
                        </div>

                    </div>

                    <div class="form-card__section">
                        <?= panel_title('Archive', 'archive') ?>
                        <?php // HOW THIS TYPE'S LIST PAGES — migration 090, read by
                              // `Registry::perPage()` / `firstPageExtra()` in the
                              // archive and term controllers. Each help line says
                              // what those two controllers do, and nothing else:
                              // the extra is the type archive's alone, and a term
                              // page follows this number only when its taxonomy
                              // belongs to this type alone. Full-width boxes, NOT
                              // General's narrow count box: in this column every
                              // control shares the grid's two edges, and controls
                              // stay inside their column. ?>
                        <div class="form-grid form-grid--2">
                            <div class="field">
                                <label class="label" for="f-per-page">Entries per page</label>
                                <input class="input" type="number" id="f-per-page" name="per_page"
                                       min="0" max="100" step="1" inputmode="numeric"
                                       value="<?= h($val('per_page')) ?>"
                                       placeholder="<?= (int) \Cms\Helpers\Paging::perPage() ?>">
                                <p class="form-help">Left blank, it follows Settings → General (<?= (int) \Cms\Helpers\Paging::perPage() ?>). 0 lists every entry on one page. A category page follows it when its taxonomy belongs to this type alone.</p>
                            </div>
                            <div class="field">
                                <label class="label" for="f-first-page-extra">Extra entries on page 1</label>
                                <input class="input" type="number" id="f-first-page-extra" name="first_page_extra"
                                       min="0" max="100" step="1" inputmode="numeric"
                                       value="<?= h($val('first_page_extra', '0')) ?>">
                                <p class="form-help">For a design that leads page 1 with a featured entry: this many more on page 1 of the archive, and page 2 carries on after them. Category pages are not affected.</p>
                            </div>
                        </div>
                    </div>

                    <div class="form-card__section">
                        <?= panel_title('Belongs to', 'parent') ?>
                        <?php // A type can belong to another — Club Events
                              // belonging to a Club, say. Without migration 047
                              // that could only be a relationship FIELD — an
                              // association, not a nesting.
                              //
                              // DECLARED ON THE TYPE, chosen on the entry. The
                              // type-level answer is what gives the entry screen
                              // a list to offer; without it that control would
                              // have to show every entry of every type, which on
                              // a site with four hundred is not a control.
                              //
                              // ITS OWN SECTION, NOT A ROW UNDER Addresses. It is
                              // not an address, and that section is named for
                              // what its fields are.
                              //
                              // AND ON THE GRID'S WIDTH, not `field--narrow`: a
                              // column allows two control geometries — one per
                              // grid track — and a third width is a fault. A
                              // one-cell `form-grid--2` is the same half-column
                              // every other field here uses. ?>
                        <div class="form-grid form-grid--2">
                            <div class="field">
                                <?php // THE LABEL IS THE SENTENCE, because the
                                      // section heading is already the category
                                      // and printing "Belongs to" twice one
                                      // inch apart says nothing the second
                                      // time. Reads with its own control: "A
                                      // News item belongs to — nothing". ?>
                                <?php $_parentNoun = strtolower($val('singular_label') !== '' ? $val('singular_label') : 'entry'); ?>
                                <label class="label" for="f-parent-type">
                                    <?= preg_match('/^[aeiou]/', $_parentNoun) === 1 ? 'An' : 'A' ?> <?= h($_parentNoun) ?> belongs to
                                </label>
                                <select class="select" id="f-parent-type" name="parent_type">
                                    <?php // A NAMED FIRST OPTION, never a blank
                                          // row: an empty line cannot distinguish
                                          // "belongs to nothing" from "there is
                                          // nothing it could belong to", and those
                                          // are different facts about the install. ?>
                                    <option value="">Nothing — these stand on their own</option>
                                    <?php foreach ($parentTypeOptions as $option): ?>
                                        <option value="<?= h((string) $option['key']) ?>"
                                            <?= $val('parent_type') === $option['key'] ? ' selected' : '' ?>>
                                            <?= h((string) $option['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="form-help">
                                    Each entry can then name which one it belongs to,
                                    and its row says so. <strong>Addresses do not
                                    change</strong> — an entry stays at its own, the
                                    way a child page does.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="form-card__section">
                        <?= panel_title('What kind of content is this?', 'archetypes') ?>
                        <?php // THE MEANING, NOT THE FIELD GROUP'S NAME. A row
                              // reading `Post — Article details` would name the
                              // group the choice generates and explain the choice
                              // not at all: "Article details" does not tell
                              // anyone whether News is a Post or a Listing.
                              //
                              // `ArchetypeDefaults::describe()` is what each
                              // archetype means, in one place.
                              //
                              // The heading asks the question the control answers.
                              // "Archetypes" is this codebase's word for it and a
                              // site owner has never met it; the word still appears
                              // on the rows, so nothing is hidden. ?>
                        <p class="form-help form-help--lead">
                            Pick every one that applies — they add to each other. A
                            type can be an Event and a Product at once. Each brings a
                            starting set of fields; choose which of them you want, and
                            change or remove any of them afterwards. Unticking one
                            takes away the group it brought.
                        </p>
                        <?php // THE WARNING IS SAID ONCE, HERE, not on every
                              // applied row — "Unticking this takes that group away
                              // again" would print twice on a type carrying two
                              // shapes, and four times on a type carrying four.
                              // The same sentence repeated down a list stops being
                              // read at the second one. The rows below carry the
                              // fact that differs between them — how many fields,
                              // and which group. ?>

                        <div class="arch-list">
                            <?php foreach ($archetypeChoices as $archetype): ?>
                            <?php
                                $seedFields = ArchetypeDefaults::getFields($archetype);
                                $applied    = $appliedGroups[$archetype] ?? null;
                                $isOn       = in_array($archetype, $archetypes, true);

                                // THE THIRD STATE, and it is reachable: an archetype
                                // that is ON this type but whose group the owner
                                // deleted from the Field groups screen. Ticking is
                                // what generates a group, so nothing here can
                                // regenerate one for an archetype already ticked —
                                // and rendering the starter-field boxes there would
                                // be four controls that change nothing on save.
                                $wasApplied = $applied === null
                                    && $seedFields !== []
                                    && in_array($archetype, (array) ($contentTypeDefinition['archetypes'] ?? []), true);
                            ?>
                            <div class="arch">
                                <?php // THE WHOLE HEAD IS THE TARGET, and it reacts —
                                      // hover band, focus ring, accent edge when
                                      // chosen — rather than a bare `<label>` with a
                                      // pointer cursor and no response of any kind. ?>
                                <label class="arch__head">
                                    <?php // WHAT IT BRINGS TO THE ENTRY FORM, which
                                          // `type-form.js` ticks below when this is
                                          // ticked. A Products type made without it had
                                          // no Featured image: a product screen with no
                                          // photo box until the owner found the switch. ?>
                                    <input type="checkbox" name="archetypes[]" value="<?= h($archetype) ?>"
                                           <?= $isOn ? 'checked' : '' ?>
                                           data-archetype="<?= h($archetype) ?>"
                                           data-archetype-supports="<?= h(implode(' ', ArchetypeDefaults::getSupports($archetype))) ?>">
                                    <?php // NAME AND MEANING ON ONE LINE. Two stacked
                                          // lines inside a wrapper span would be a line
                                          // of height bought for nothing — the name is
                                          // two words.
                                          //
                                          // The kind's own mark comes from the registry
                                          // beside its label so the two cannot drift. ?>
                                    <span class="arch__name">
                                        <?= icon(ArchetypeDefaults::getIcon($archetype), 'arch__glyph icon') ?>
                                        <?= h(ucfirst($archetype)) ?>
                                    </span>
                                    <span class="arch__note"><?= h(ArchetypeDefaults::describe($archetype)) ?></span>
                                </label>

                                <?php // WHAT THE TICK BRINGS — inside the same box, on
                                      // the head's own text edge, so it belongs to
                                      // THIS choice and not to the one below it.
                                      //
                                      // `[hidden]`, not a class: the block collapses
                                      // when the archetype is unticked, and with no JS
                                      // every block is simply open, which is the state
                                      // that submits correctly. ?>
                                <div class="arch__brings" data-archetype-seed="<?= h($archetype) ?>"
                                     <?= $isOn ? '' : 'hidden' ?>>
                                    <?php if ($wasApplied): ?>
                                        Its field group was deleted. Untick and save,
                                        then tick it again, to start the shape over.
                                    <?php elseif ($applied !== null): ?>
                                        <?php $n = count((array) ($applied['fields'] ?? [])); ?>
                                        <?= (int) $n ?> <?= $n === 1 ? 'field' : 'fields' ?>, in
                                        <?php // A LINK THAT LOOKS LIKE ONE. In the
                                              // paragraph's own colour, the one route
                                              // from here to the fields themselves
                                              // would be invisible unless you
                                              // happened to hover it. ?>
                                        <a class="link" href="<?= h(Url::admin('field-groups/' . (int) $applied['id'] . '/edit')) ?>"><?= h((string) $applied['title']) ?></a>
                                    <?php elseif ($seedFields === []): ?>
                                        <?php // NOT "it uses the title, body and
                                              // featured image" — those are the Entry
                                              // form panel's checkboxes, each of which
                                              // the author can turn off, so naming them
                                              // would be a claim about a control this
                                              // row does not read. `getFields('post')`
                                              // returns `[]`; that is the whole fact. ?>
                                        Brings no extra fields of its own.
                                    <?php else: ?>
                                        <div class="arch__seed">
                                            <?php // THE MARKER SAYS THE CHOICE WAS
                                                  // OFFERED. Unchecked boxes send
                                                  // nothing, so without it "I want none
                                                  // of these" and "this form never
                                                  // asked" are the same POST. See
                                                  // ContentTypeDefinitionController::seedSelection(). ?>
                                            <input type="hidden" name="archetype_fields_present[<?= h($archetype) ?>]" value="1">
                                            <?php foreach ($seedFields as $f): ?>
                                                <label class="check-list__row">
                                                    <input type="checkbox"
                                                           name="archetype_fields[<?= h($archetype) ?>][<?= h((string) $f['key']) ?>]"
                                                           value="1" <?= $seedTicked($archetype, (string) $f['key']) ? 'checked' : '' ?>>
                                                    <span><?= h((string) $f['label']) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>
            </div>

            <div class="form-aside">
                <div class="form-card">

                    <div class="form-card__section">
                        <?= panel_title('Glyph', 'glyph') ?>
                        <?php // SHOW THE GLYPH, do not name it. A dropdown reading
                              // "location-dot" tells the admin nothing about what
                              // their sidebar will look like; the mark beside it
                              // does, and it is the same mark the sidebar renders.
                              //
                              // A labelled <select> and NOT a grid of swatches —
                              // the same rule the theme picker follows. ?>
                        <div class="field icon-picker">
                            <span class="icon-picker__preview" data-icon-preview>
                                <?= icon(Sidebar::iconFor($currentIcon), 'icon icon--lg') ?>
                            </span>
                            <select class="select" name="menu_icon" aria-label="Glyph" data-icon-select>
                                <?php foreach ($iconChoices as $slug => $name): ?>
                                    <option value="<?= h($slug) ?>"<?= $currentIcon === $slug ? ' selected' : '' ?>>
                                        <?= h($name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php // Every candidate glyph, rendered by Icons once, so
                              // the picker's preview swaps to real markup from the
                              // one source rather than a second copy hand-written
                              // in JavaScript. ?>
                        <template data-icon-set>
                            <?php foreach (array_keys($iconChoices) as $slug): ?>
                                <span data-icon-for="<?= h($slug) ?>"><?= icon(Sidebar::iconFor($slug), 'icon icon--lg') ?></span>
                            <?php endforeach; ?>
                        </template>
                    </div>

                    <div class="form-card__section">
                        <?= panel_title('Entry form', 'entry-form') ?>
                        <?php // What the entry editor will show. `excerpt` writes
                              // to `posts.excerpt` (migration 053); without that
                              // column it would be a checkbox enabling a box that
                              // discarded what was typed into it. ?>
                        <div class="check-list">
                            <?php foreach ($supportChoices as $key => $name): ?>
                                <label class="check-list__row">
                                    <input type="checkbox" name="supports[<?= h($key) ?>]" value="1"
                                           <?= in_array($key, $supports, true) ? 'checked' : '' ?>>
                                    <span><?= h($name) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-card__section">
                        <?php // NO HEADING. "Active" over a box labelled "Registered"
                              // is the same word twice — one of them has to go, and
                              // the one that survives is the one on the control the
                              // reader actually ticks. ?>
                        <div class="check-list">
                            <label class="check-list__row">
                                <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
                                <span>Registered</span>
                            </label>
                        </div>
                        <?php // The consequence, not the mechanism. Deactivating is
                              // reversible and destroys nothing, and saying so is
                              // what stops it reading like a delete. ?>
                        <p class="form-help">
                            Unregistering removes the sidebar row and stops the public URLs resolving.
                            Entries are kept.
                        </p>
                    </div>

                    <?php // SAVE LIVES WITH THE SETTINGS, full width at the foot of
                          // the panel. A footer section on the main card would put
                          // the button at the end of a column that ends two-thirds
                          // of the way up a tall screen, while the settings sit
                          // untouched beside it. ?>
                    <div class="form-card__section form-aside__save">
                        <button type="submit" class="btn btn--primary">
                            <?= $isNew ? 'Create content type' : 'Save changes' ?>
                        </button>
                        <a class="link link--xs" href="<?= h(Url::admin('content-types')) ?>">Cancel</a>
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
                        <button type="submit" class="link link--xs"
                                form="delete-content-type">Delete content type</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </form>

    <?php if (!$isNew): ?>
        <?php // OUTSIDE the form above, targeted by `form=` from the button in
              // the danger zone. HTML has no nested forms, and a delete posted
              // by the save form would carry every field with it. ?>
        <form id="delete-content-type" method="post"
              action="<?= h(Url::admin('content-types/' . $id . '/delete')) ?>"
              data-confirm="Delete the <?= h((string) $contentTypeDefinition['plural_label']) ?> content type?"
              data-confirm-detail="<?= (int) $entryCount ?> <?= $entryCount === 1 ? 'entry stays' : 'entries stay' ?> in the database, but nothing in the admin will link to <?= $entryCount === 1 ? 'it' : 'them' ?> any more."
              data-confirm-verb="Delete type" hidden>
            <?= Token::field() ?>
        </form>
    <?php endif; ?>

</div>

<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
<script src="<?= h(Asset::admin('js/type-form.js')) ?>"></script>
