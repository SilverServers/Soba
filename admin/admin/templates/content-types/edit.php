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

$archetypeChoices = ArchetypeDefaults::KEYS;

// A KIND WITH NO PAGE (`ArchetypeDefaults::ALONE`): its type has no Public
// URL, so the box gives way to the fact while one is ticked (type-form.js).
$pageless = array_intersect($archetypes, ArchetypeDefaults::ALONE) !== [];

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

                        <?php // THE TYPE'S OWN WORD FOR ITS TITLE (migration 150,
                              // `Registry::titleLabel()`), such as Name. Blank is
                              // the word every type has, which the box shows. ?>
                        <div class="form-grid form-grid--2">
                            <div class="field">
                                <label class="label" for="f-title-label">Title label</label>
                                <input class="input" type="text" id="f-title-label" name="title_label" maxlength="100"
                                       value="<?= h($val('title_label')) ?>" autocomplete="off"
                                       placeholder="<?= h(($val('singular_label') !== '' ? $val('singular_label') : 'News item') . ' title') ?>">
                                <p class="form-help">Over each entry's title and its list, such as Name or Business name.</p>
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

                            <div class="field" data-type-public-field<?= $pageless ? ' hidden' : '' ?>>
                                <label class="label" for="f-public-slug">Public URL</label>
                                <div class="prefixed">
                                    <span class="prefixed__prefix">/</span>
                                    <?php // ON A NEW TYPE the box follows the plural name
                                          // as it is typed (type-form.js), so a type has
                                          // pages unless the box is cleared. ?>
                                    <input class="input input--mono" type="text" id="f-public-slug" name="public_slug"
                                           value="<?= h($val('public_slug')) ?>" autocomplete="off"
                                           spellcheck="false"<?= $isNew ? ' data-type-public' : '' ?>>
                                </div>
                                <p class="form-help">What visitors see. Changing it moves every entry's address. Left blank, entries have no page of their own.</p>
                            </div>
                            <div class="field" data-type-pageless<?= $pageless ? '' : ' hidden' ?>>
                                <span class="label">Public URL</span>
                                <p class="form-help">None. An announcement has no page of its own: it shows on the pages it names.</p>
                            </div>
                        </div>

                    </div>

                    <?php
                    // ITS TITLES AND ADDRESSES MADE FROM ITS FIELDS — migration
                    // 146, `ContentType\Patterns`. On a type that exists, since
                    // a pattern names its fields and taxonomies.
                    $patternKey = $isNew ? '' : (string) ($contentTypeDefinition['type_key'] ?? '');
                    ?>
                    <?php if ($patternKey !== '' && \Cms\ContentType\Registry::get($patternKey) !== null): ?>
                        <div class="form-card__section" data-patterns>
                            <?= panel_title('Titles and addresses', 'title-patterns') ?>
                            <div class="form-grid form-grid--2">
                                <div class="field">
                                    <label class="label" for="f-title-pattern">Title, when left blank</label>
                                    <input class="input input--mono" type="text" id="f-title-pattern" name="title_pattern"
                                           value="<?= h($val('title_pattern')) ?>" autocomplete="off" spellcheck="false" maxlength="255">
                                    <p class="form-help">An entry saved with no title is titled this way, and its title box shows it.</p>
                                </div>
                                <div class="field">
                                    <label class="label" for="f-slug-pattern">Address, when not typed</label>
                                    <input class="input input--mono" type="text" id="f-slug-pattern" name="slug_pattern"
                                           value="<?= h($val('slug_pattern')) ?>" autocomplete="off" spellcheck="false" maxlength="255">
                                    <p class="form-help">A new entry's address is made this way unless one is typed.</p>
                                </div>
                            </div>
                            <p class="form-help">
                                Each names <?php foreach (\Cms\ContentType\Patterns::tokens($patternKey) as $patternToken): ?><code><?= h($patternToken) ?></code> <?php endforeach; ?>
                                and the site's own, such as <code>{sep}</code>. A date takes a format after a colon, such as
                                <code>{<?= h(\Cms\ContentType\Patterns::dateField($patternKey) ?? 'report_date') ?>:F j, Y}</code>;
                                <code>{id}</code> is the entry's number, and an address can name its <code>{title}</code>.
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php
                    // WHAT COPY MAKES OF ITS ENTRIES — migration 147,
                    // `ContentType\CopyRules`: as typed on a failed save, else as
                    // kept, else the CMS's own. In the column's two halves, as its
                    // other settings are.
                    $copyKept = (array) (json_decode((string) ($contentTypeDefinition['copy_rules'] ?? ''), true) ?: []);
                    $copyNow  = $formData !== []
                        ? ['suffix' => (string) ($formData['copy_suffix'] ?? ''), 'status' => (string) ($formData['copy_status'] ?? 'private'), 'skip' => (string) ($formData['copy_skip'] ?? '')]
                        : ['suffix' => array_key_exists('suffix', $copyKept) ? (string) $copyKept['suffix'] : \Cms\ContentType\CopyRules::SUFFIX,
                           'status' => ($copyKept['status'] ?? '') === 'same' ? 'same' : 'private',
                           'skip'   => implode(', ', \Cms\ContentType\CopyRules::names($copyKept['skip'] ?? []))];
                    ?>
                    <div class="form-card__section" data-copies>
                        <?= panel_title('Copies', 'copies') ?>
                        <div class="form-grid form-grid--2">
                            <div class="field">
                                <label class="label" for="f-copy-suffix">After a copy's title</label>
                                <input class="input" type="text" id="f-copy-suffix" name="copy_suffix" maxlength="100"
                                       value="<?= h($copyNow['suffix']) ?>" autocomplete="off">
                                <p class="form-help">Left blank, a copy keeps its title, and its address ends -2.</p>
                            </div>
                            <div class="field">
                                <label class="label" for="f-copy-status">A copy starts as</label>
                                <select class="select" id="f-copy-status" name="copy_status">
                                    <option value="private">Private</option>
                                    <option value="same"<?= $copyNow['status'] === 'same' ? ' selected' : '' ?>>Its original's status</option>
                                </select>
                                <p class="form-help">Private keeps a copy off the site until it is published.</p>
                            </div>
                            <div class="field">
                                <label class="label" for="f-copy-skip">Never copied</label>
                                <input class="input input--mono" type="text" id="f-copy-skip" name="copy_skip" autocomplete="off" spellcheck="false"
                                       value="<?= h($copyNow['skip']) ?>">
                                <p class="form-help">Fields a copy leaves empty, by key, with commas between; legacy_* is each that begins legacy_.</p>
                            </div>
                        </div>
                    </div>

                    <div class="form-card__section" data-type-archive<?= $pageless ? ' hidden' : '' ?>>
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

                    <?php
                    // WHAT STAFF SEE ON ITS SCREENS (migration 140, `ContentType\StaffView`):
                    // each part's role as the form shows it, and its select.
                    $staffType = $isNew ? '' : (string) ($contentTypeDefinition['type_key'] ?? '');
                    $staffNow  = static function (string $set, string $part, string $default) use ($staffType, $formData): string {
                        if (isset($formData['staff_view'])) {
                            $posted = $formData[$set === 'panels' ? 'panels' : 'list_parts'][$part] ?? $default;
                            return is_string($posted) && isset(\Cms\ContentType\StaffView::ROLE_CHOICES[$posted]) ? $posted : $default;
                        }
                        $role = $staffType !== '' && \Cms\ContentType\Registry::get($staffType) !== null
                            ? \Cms\ContentType\StaffView::role($staffType, $set, $part, $default) : $default;
                        return $role === false ? 'none' : $role;
                    };
                    $roleSelect = static function (string $id, string $name, string $chosen): string {
                        $html = '<select class="select" id="' . h($id) . '" name="' . h($name) . '">';
                        foreach (\Cms\ContentType\StaffView::ROLE_CHOICES as $value => $words) {
                            $html .= '<option value="' . h($value) . '"' . ($chosen === $value ? ' selected' : '') . '>' . h($words) . '</option>';
                        }
                        return $html . '</select>';
                    };
                    ?>
                    <?php
                    // WHAT ITS ENTRY LIST SHOWS AND HOW IT IS ORDERED — migration
                    // 130, `ContentType\ListColumns`. On a type that exists, since
                    // the columns are its own fields, taxonomies and child types;
                    // a new one shows its default until then. Ticked: what the
                    // list shows now, its own default included, so a save keeps it.
                    $listKey = $isNew ? '' : (string) ($contentTypeDefinition['type_key'] ?? '');
                    $listOn  = $listKey !== '' && \Cms\ContentType\Registry::get($listKey) !== null;
                    if ($listOn) {
                        $listOptions = \Cms\ContentType\ListColumns::available($listKey);
                        $listChosen  = isset($formData['list_view'])
                            ? array_map('strval', (array) ($formData['list_columns'] ?? []))
                            : array_column(\Cms\ContentType\ListColumns::forType($listKey), 'spec');
                        $sortNow     = \Cms\ContentType\ListColumns::defaultSort($listKey);
                        $sortChosen  = isset($formData['list_view']) ? (string) ($formData['list_sort'] ?? '')
                            : ($sortNow === null ? '' : preg_replace('/^field:/', '', $sortNow['orderby']) . ':' . $sortNow['order']);
                        $sortOptions = ['' => 'Date, newest first', 'date:asc' => 'Date, oldest first', 'title:asc' => 'Title, A to Z', 'title:desc' => 'Title, Z to A'];
                        foreach (\Cms\ContentType\ContentTypeSchema::fields($listKey) as $sortField) {
                            $words = match ((string) ($sortField['type'] ?? 'text')) {
                                'date', 'datetime' => ['earliest first', 'latest first'],
                                'number'           => ['lowest first', 'highest first'],
                                'text', 'select'   => ['A to Z', 'Z to A'],
                                default            => null,
                            };
                            if ($words !== null) {
                                $sortOptions[$sortField['key'] . ':asc']  = $sortField['label'] . ', ' . $words[0];
                                $sortOptions[$sortField['key'] . ':desc'] = $sortField['label'] . ', ' . $words[1];
                            }
                        }
                    }
                    ?>
                    <?php if ($listOn): ?>
                        <div class="form-card__section" data-list-view>
                            <?= panel_title('Its list', 'list-view') ?>
                            <input type="hidden" name="list_view" value="1">
                            <fieldset class="field">
                                <legend class="label">Shown beside each title</legend>
                                <?php if ($listOptions === []): ?>
                                    <p class="form-help">This type has no field, taxonomy or child type its list can show yet.</p>
                                <?php else: ?>
                                    <div class="check-list">
                                        <?php foreach ($listOptions as $option): ?>
                                            <label class="check-list__row">
                                                <input type="checkbox" name="list_columns[]" value="<?= h($option['spec']) ?>"
                                                       <?= in_array($option['spec'], $listChosen, true) ? 'checked' : '' ?>>
                                                <span><?= h($option['heading']) ?><?= match ($option['kind']) { 'tax' => ', its terms', 'children' => ', how many', default => '' } ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="form-help">Each in a column of the list, in this order. A field's column sorts the list when its heading is pressed.</p>
                                <?php endif; ?>
                            </fieldset>
                            <div class="form-grid form-grid--2">
                                <div class="field">
                                    <label class="label" for="f-list-sort">Sorted by</label>
                                    <select class="select" id="f-list-sort" name="list_sort">
                                        <?php foreach ($sortOptions as $sortValue => $sortLabel): ?>
                                            <option value="<?= h($sortValue) ?>"<?= $sortChosen === $sortValue ? ' selected' : '' ?>><?= h($sortLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (\Cms\ContentType\ListColumns::isEvent($listKey)): ?>
                                        <p class="form-help">Its list opens on Upcoming, soonest first, beside Past, newest first; this is the order of All.</p>
                                    <?php else: ?>
                                        <p class="form-help">How the list is ordered until someone presses a heading.</p>
                                    <?php endif; ?>
                                </div>
                                <?php // WHAT THE LIST IS GROUPED BY (migration 140): a
                                      // Choice field or a taxonomy, a heading for
                                      // each of its values, in their order. ?>
                                <?php
                                $groupOptions = \Cms\ContentType\StaffView::groupOptions($listKey);
                                $groupChosen  = isset($formData['list_view']) ? (string) ($formData['group_by'] ?? '')
                                    : (string) (((array) (\Cms\ContentType\Registry::get($listKey)['list'] ?? []))['group_by'] ?? '');
                                ?>
                                <div class="field">
                                    <label class="label" for="f-group-by">Grouped by</label>
                                    <select class="select" id="f-group-by" name="group_by">
                                        <option value="">Not grouped</option>
                                        <?php foreach ($groupOptions as $groupValue => $groupLabel): ?>
                                            <option value="<?= h($groupValue) ?>"<?= $groupChosen === $groupValue ? ' selected' : '' ?>><?= h($groupLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="form-help">A heading for each of a Choice field's choices or a taxonomy's terms, in their order, with its entries under it.</p>
                                </div>
                                <?php // WHO SEES EACH PART OF THE LIST (migration 140):
                                      // bulk actions not offered are refused too. In
                                      // this grid, so the column keeps its two halves. ?>
                                <?php foreach (\Cms\ContentType\StaffView::LIST as $part => $default): ?>
                                    <div class="field" data-staff-list="<?= h($part) ?>">
                                        <label class="label" for="f-list-<?= h($part) ?>"><?= h(\Cms\ContentType\StaffView::NAMES[$part]) ?> shown to</label>
                                        <?= $roleSelect('f-list-' . $part, 'list_parts[' . $part . ']', $staffNow('list', $part, $default)) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php // HOW MANY TERMS OF EACH TAXONOMY ITS ENTRIES TAKE
                          // (migration 151, `Term::countRule()`), beside each
                          // taxonomy on the type, in the four answers the
                          // taxonomy's own screen gives: its own rule until one
                          // is chosen here, for this type's entries alone. A new
                          // type has none on it yet. ?>
                    <?php
                    $termTaxonomies = $isNew ? [] : \Cms\ContentType\TaxonomyRegistry::getForContentType((string) $contentTypeDefinition['type_key']);
                    $storedRules    = json_decode((string) ($contentTypeDefinition['term_rules'] ?? ''), true);
                    $ruleWords      = static function (array $rule): string {
                        return match (true) {
                            $rule === [1, 1]                      => 'exactly one',
                            $rule[0] === 1 && $rule[1] === null   => 'at least one',
                            $rule[0] === 1                        => 'one to ' . $rule[1],
                            $rule[1] !== null                     => 'no more than ' . $rule[1],
                            default                               => 'any number',
                        };
                    };
                    ?>
                    <?php if ($termTaxonomies !== []): ?>
                        <div class="form-card__section">
                            <?= panel_title('How many terms an entry takes', 'how-many') ?>
                            <?php // THE TAXONOMY'S OWN SCREEN'S FOUR ANSWERS, its own
                                  // rule first and named, and the number No more than
                                  // takes beside them, on the form's two columns. ?>
                            <?php foreach ($termTaxonomies as $taxKey => $tax): ?>
                                <?php
                                $posted = is_array($formData['term_rules'][$taxKey] ?? null) ? $formData['term_rules'][$taxKey] : null;
                                $kept   = \Cms\Models\Term::ruleOf(is_array($storedRules) ? ($storedRules[$taxKey] ?? null) : null);
                                $chosen = $posted !== null ? (string) ($posted['rule'] ?? '')
                                    : ($kept === null ? '' : match (true) {
                                        $kept === [1, 1]   => 'exactly_one',
                                        $kept[0] === 1     => 'one_or_more',
                                        $kept[1] !== null  => 'at_most',
                                        default            => 'any',
                                    });
                                $most   = $posted !== null ? (string) ($posted['max'] ?? '') : ($chosen === 'at_most' ? (string) $kept[1] : '');
                                $own    = [(int) ($tax['min_terms'] ?? 0), isset($tax['max_terms']) ? (int) $tax['max_terms'] : null];
                                ?>
                                <div class="form-grid form-grid--2" data-term-rule="<?= h((string) $taxKey) ?>">
                                    <div class="field">
                                        <label class="label" for="<?= h('f-terms-' . $taxKey) ?>"><?= h((string) $tax['label']) ?></label>
                                        <select class="select" id="<?= h('f-terms-' . $taxKey) ?>" name="<?= h('term_rules[' . $taxKey . '][rule]') ?>">
                                            <?php foreach (['' => 'As the taxonomy says: ' . $ruleWords($own), 'any' => 'Any number', 'one_or_more' => 'At least one',
                                                            'exactly_one' => 'Exactly one', 'at_most' => 'No more than a number'] as $ruleKey => $ruleName): ?>
                                                <option value="<?= h($ruleKey) ?>"<?= $chosen === $ruleKey ? ' selected' : '' ?>><?= h($ruleName) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="field">
                                        <label class="label" for="<?= h('f-terms-' . $taxKey . '-max') ?>">No more than</label>
                                        <input class="input" type="number" id="<?= h('f-terms-' . $taxKey . '-max') ?>" name="<?= h('term_rules[' . $taxKey . '][max]') ?>"
                                               min="1" max="99" value="<?= h($most) ?>">
                                        <p class="form-help">1 to 99, with No more than a number.</p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <p class="form-help">For this type's entries alone, in place of each taxonomy's own rule.</p>
                        </div>
                    <?php endif; ?>

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
                            <?php // HOW EACH IS NAMED where it is chosen (migration
                                  // 148): by its title, or in the token form of
                                  // titles made from fields, over its own fields. ?>
                            <div class="field">
                                <label class="label" for="f-parent-label">Each named as</label>
                                <input class="input input--mono" type="text" id="f-parent-label" name="parent_label" maxlength="255"
                                       value="<?= h($val('parent_label')) ?>" autocomplete="off" spellcheck="false"
                                       placeholder="{title}">
                                <?php $_parentTokens = $val('parent_type') !== '' && \Cms\ContentType\Registry::get($val('parent_type')) !== null
                                    ? \Cms\ContentType\Patterns::tokens($val('parent_type')) : []; ?>
                                <p class="form-help">
                                    Left blank, by its title. Or by its own fields<?php if ($_parentTokens !== []): ?>,
                                    <?php foreach ($_parentTokens as $_parentToken): ?><code><?= h($_parentToken) ?></code> <?php endforeach; ?>and
                                    <code>{title}</code><?php endif; ?>: a date in a format after a colon, such as
                                    <code>{event_start_date:D, M j}</code>.
                                </p>
                            </div>
                        </div>
                    </div>

                    <?php
                    // ITS CHILDREN ON EACH ENTRY'S EDITOR — migration 145,
                    // `ContentType\Children`. For each type that belongs to this
                    // one, the columns its rows show there; none ticked, it is
                    // not shown. On a type that exists and has such types.
                    $childOptions = $listOn ? \Cms\ContentType\Children::available($listKey) : [];
                    $childNow     = [];
                    if ($childOptions !== []) {
                        // AS KEPT, read from the row: the Registry was loaded
                        // before a save this screen is drawn after.
                        foreach ((array) (json_decode((string) ($contentTypeDefinition['children'] ?? ''), true) ?: []) as $keptChild => $keptSpec) {
                            $childNow[(string) $keptChild] = array_map('strval', array_filter((array) ($keptSpec['columns'] ?? []), 'is_scalar'));
                        }
                        if (isset($formData['children_view'])) {
                            $childNow = [];
                            foreach ((array) ($formData['children'] ?? []) as $postedChild => $postedSpec) {
                                $childNow[(string) $postedChild] = array_map('strval', array_filter((array) ($postedSpec['columns'] ?? []), 'is_scalar'));
                            }
                        }
                    }
                    $_entrySingle = mb_strtolower($val('singular_label') !== '' ? $val('singular_label') : 'entry');
                    ?>
                    <?php if ($childOptions !== []): ?>
                        <div class="form-card__section" data-children-view>
                            <?= panel_title('Its children', 'children') ?>
                            <input type="hidden" name="children_view" value="1">
                            <?php foreach ($childOptions as $childOption): ?>
                                <fieldset class="field">
                                    <legend class="label"><?= h($childOption['label']) ?>, on each <?= h($_entrySingle) ?>'s editor</legend>
                                    <div class="check-list">
                                        <?php foreach ($childOption['columns'] as $column): ?>
                                            <label class="check-list__row">
                                                <input type="checkbox" name="children[<?= h($childOption['key']) ?>][columns][]" value="<?= h($column['spec']) ?>"
                                                       <?= in_array($column['spec'], $childNow[$childOption['key']] ?? [], true) ? 'checked' : '' ?>>
                                                <span><?= h($column['label']) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </fieldset>
                            <?php endforeach; ?>
                            <p class="form-help">Each ticked is shown on every row, in this order, and each row is saved as it is added or changed. None ticked, they are not shown there.</p>
                        </div>
                    <?php endif; ?>

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
                            takes away the group it brought. An announcement is a
                            kind of its own, with no page and in no list.
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
                                           data-archetype="<?= h($archetype) ?>"<?= in_array($archetype, ArchetypeDefaults::ALONE, true) ? ' data-archetype-alone' : '' ?>
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
                        <?php // THE STATUS A NEW ENTRY STARTS AT on its editor
                              // (migration 131, `Registry::defaultStatus()`):
                              // Private unless a type's entries are made to be
                              // seen at once, a weekly event's, say. ?>
                        <div class="form-grid form-grid--2">
                            <div class="field">
                                <label class="label" for="f-default-status">New entries start as</label>
                                <select class="select" id="f-default-status" name="default_status">
                                    <option value="private">Private</option>
                                    <option value="published"<?= $val('default_status', 'private') === 'published' ? ' selected' : '' ?>>Published</option>
                                </select>
                                <p class="form-help">The Status a new entry's editor starts at. Copies and translations keep their own.</p>
                            </div>
                        </div>
                        <?php // WHERE ITS FIELDS GO on its editor (migration 149):
                              // a type whose fields are the point, an event's date
                              // and place, opens on them. A field group's own
                              // Position wins. A row of its own, half the column. ?>
                        <div class="form-grid form-grid--2">
                            <div class="field">
                                <label class="label" for="f-fields-position">Its fields</label>
                                <select class="select" id="f-fields-position" name="fields_position">
                                    <option value="">After the text</option>
                                    <option value="high"<?= $val('fields_position') === 'high' ? ' selected' : '' ?>>Above the text</option>
                                </select>
                                <p class="form-help">Where an entry's screen draws them. A field group's own Position wins.</p>
                            </div>
                        </div>
                        <?php // WHO SEES EACH PANEL of an entry's editor (migration
                              // 140, `ContentType\StaffView`): the least role, or
                              // no one. A panel not shown leaves what its entry
                              // holds as it is on save. ?>
                        <?php // One a row, half the column, as New entries start as
                              // is: the aside's controls keep two widths. ?>
                        <?php foreach (\Cms\ContentType\StaffView::PANELS as $part => $default): ?>
                            <div class="form-grid form-grid--2" data-staff-panel="<?= h($part) ?>">
                                <div class="field">
                                    <label class="label" for="f-panel-<?= h($part) ?>"><?= h(\Cms\ContentType\StaffView::NAMES[$part]) ?> shown to</label>
                                    <?= $roleSelect('f-panel-' . $part, 'panels[' . $part . ']', $staffNow('panels', $part, $default)) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php // After the rows, not between them: each row is spaced
                              // from the one before it only when nothing comes between. ?>
                        <input type="hidden" name="staff_view" value="1">
                        <p class="form-help">Each is shown to that role and those above it. A panel not shown keeps what an entry holds when it is saved.</p>
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
