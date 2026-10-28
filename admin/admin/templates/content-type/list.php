<?php

declare(strict_types=1);

use Cms\Helpers\Dates;
use Cms\Helpers\TermRenderer;
use Cms\ContentType\FieldRenderer;
use Cms\Security\Csrf;
use Cms\Helpers\Url;
use Cms\Ui\Sidebar;
use Cms\Ui\Status;

/**
 * THE ENTRY LIST — one screen, every Content Type.
 *
 * This is the screen the kernel hands you for free the moment a Content Type is
 * registered, so it is rendered once and repeats for News, Events, Clubs, Staff
 * and everything after them. Nothing in here is per-type except the labels, the
 * glyph and the taxonomies, all of which come from the Registry.
 *
 * ── WHAT CARRIES EACH FACT ──────────────────────────────────────────────────
 * Show, don't tell. Before any sentence goes into this view, the question is
 * what SHAPE carries the fact:
 *
 *   what state is this in     the status chip — accent + a glyph, never a hue
 *   how much is in the trash  the count on the Trash control, not a sentence
 *   what kind of place is this  the type's own glyph in the empty state
 *   where does this row go    the row navigates; the title is a real anchor
 *   what is it filed under    the terms, under the slug — each one a filter
 *   how is the list ordered   the arrow on the column heading doing it
 *
 * There is no page subtitle. A subtitle has to beat what the surface below it
 * already shows, and "Manage your News entries" beats nothing.
 *
 * ── THE ROW'S CLICK TARGET ──────────────────────────────────────────────────
 * The WHOLE ROW navigates — model A, a real `<a>` stretched over it by
 * `core.css`. It is an anchor and not a click handler because an anchor is what
 * gives keyboard, middle-click and open-in-new-tab a path. Everything else on
 * the row that acts — the tick box, the term and author filters, the verbs — is
 * lifted above it.
 *
 * ── WHAT THE LIST DOES ──────────────────────────────────────────────────────
 *   filters     search, status, author, month ("All dates") and a dropdown per
 *               taxonomy — a nested one indented — then the button
 *   sorting     Title and Date from their column headings; a first click on
 *               Date is newest first, on Title A→Z
 *   links       a row's author and each of its terms filter the list by that
 *   bulk        tick boxes and a select-all in the heading; Edit and Move to
 *               trash. Edit files the selection under terms, credits it to an
 *               author and sets its status
 *   pages       First, Previous, a page-number box "of N", Next, Last; and
 *               "Number of items per page"
 *
 * And what it deliberately does not do, each for a reason and not for taste:
 *   · ONE BULK BAR, not one above and one below. Two action selects are two
 *     controls that must agree, and the select-all they act on is in the
 *     heading, beside this bar.
 *   · TAGS ARE THE SAME TICK LIST AS CATEGORIES in bulk edit, not a text box
 *     that ADDS names: every taxonomy offers add, remove or leave, because bulk
 *     filing picks among terms that exist — a name typed into a box and
 *     applied to twenty entries is how "Javascript" and "JavaScript" become two
 *     tags in one click.
 *   · NO "UNDO" AFTER A BULK TRASH. A restore brings an entry back unpublished
 *     (`Post::restore()`), so a link reading "Undo" would be a promise the
 *     click does not keep. The Trash control with its count is beside the
 *     title.
 *
 * ── THE FILTER BAR IS A ROUND-TRIP, AND TAKES THAT IDIOM ────────────────────
 * The list paginates, so a client-side filter over the rows already fetched
 * would see one page of them. The filter is a server round-trip instead, and
 * changes idiom with it: `.filterbar--apply` (core.css §12) — a GET form,
 * LABELLED controls, and a Filter button whose presence IS the signal that the
 * page reloads. `list-filter.js` does not load here.
 *
 * @var array<string, mixed>                  $contentType  resolved via ContentTypeSchema
 * @var list<array<string, mixed>>            $posts        ONE PAGE, from Post::adminList()
 * @var int                                   $total        how many matched, all pages
 * @var int                                   $allCount     how many there are, unfiltered
 * @var int                                   $page, $perPage, $totalPages
 * @var array<string, mixed>                  $filters      what the bar redisplays
 * @var array{orderby: string, order: string} $sort
 * @var bool                                  $filtering
 * @var \Closure(array<string, mixed>=): string $listUrl    this view's address, with changes
 * @var array<string, string>                 $months       `Y-m` => "September 2026"
 * @var array<int, string>                    $listAuthors  [] for an author
 * @var array<string, array<string, mixed>>   $cptTaxonomies
 * @var array<string, list<array<string, mixed>>> $termTrees
 * @var array<int, array<string, list<array{id: int, name: string}>>> $rowTerms
 * @var list<array<string, mixed>>|null       $bulkAuthors  null for an author
 * @var array<string, mixed>|null             $bulkReport
 * @var int                                   $trashedCount
 * @var array<string, mixed>                  $user
 */

$label       = (string) $contentType['label'];
$labelSingle = (string) $contentType['label_single'];
$adminSlug   = (string) $contentType['admin_slug'];
$publicSlug  = (string) ($contentType['public_slug'] ?? '');
$canFilterByAuthor = $listAuthors !== [] || $filters['author'] !== null;

$pageTitle   = $label;
$trashedCount = $trashedCount ?? 0;

// A COUNT ALWAYS PRINTS ITS NOUN — and the noun is "entry", not the type's
// label: a type's plural label names a SECTION ("News", "Team"), and counting
// with it prints "2 news" or "3 team".
$entries = static fn (int $n): string => $n . ' ' . ($n === 1 ? 'entry' : 'entries');

// A SORTABLE COLUMN HEADING. The arrow on the active column says which way;
// an inactive one carries the neutral pair, because a heading that sorts must
// look like one before it is clicked — the pointer arriving on it is too late.
$sortLink = static function (string $column, string $text) use ($sort, $listUrl): string {
    $active = $sort['orderby'] === $column;
    $first  = $column === 'title' ? 'asc' : 'desc';
    $next   = $active ? ($sort['order'] === 'asc' ? 'desc' : 'asc') : $first;
    $glyph  = !$active ? 'sort' : ($sort['order'] === 'asc' ? 'sort-up' : 'sort-down');

    return '<a class="entry-list__sort' . ($active ? ' is-active' : '') . '"'
        . ' href="' . h($listUrl(['orderby' => $column, 'order' => $next, 'page' => null])) . '"'
        . ($active ? ' aria-current="true"' : '') . '>'
        . h($text) . icon($glyph, 'icon icon--sm') . '</a>';
};

ob_start();
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title"><?= h($label) ?></h1>
        </div>

        <div class="page__header-actions">
            <?php if ($trashedCount > 0): ?>
                <?php // The count is the point: it turns "is there anything in
                      // the trash?" from a click into a glance. A zero count is
                      // not rendered at all — an empty trash is not news, and a
                      // control for a place with nothing in it is noise. ?>
                <?php // A muted tabular number, not a filled badge. The accent
                      // fill means "this element ACTS", and a count does not
                      // act — the button around it does. ?>
                <a class="btn" href="<?= h(Url::admin($adminSlug . '/trash')) ?>">
                    Trash
                    <span class="card__count"><?= (int) $trashedCount ?></span>
                </a>
            <?php endif; ?>

            <?php // ONE primary action per screen. On an empty list the empty
                  // state carries it, right where the eye already is — two
                  // identical green buttons a hundred pixels apart is the same
                  // offer made twice, and it reads as a mistake. "Empty" is the
                  // TYPE having nothing, not this filter finding nothing. ?>
            <?php if ($allCount > 0): ?>
                <?php // Full-size primary, and no glyph: position and skin
                      // already say which button this is. ?>
                <a class="btn btn--primary" href="<?= h(Url::admin($adminSlug . '/new')) ?>">
                    Add <?= h($labelSingle) ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($bulkReport !== null): ?>
        <?php // WHAT THE LAST BULK ACTION DID — once, above everything it
              // changed. A count and a verb; the rows below already show the
              // result, so the flash does not list them. ?>
        <?php if ((int) ($bulkReport['done'] ?? 0) > 0): ?>
            <div class="flashes">
                <div class="flash flash--success" data-bulk-done>
                    <?= h($entries((int) $bulkReport['done'])) ?>
                    <?= h(match ((string) ($bulkReport['action'] ?? '')) {
                        'trash'   => 'moved to the trash.',
                        default   => 'updated.',
                    }) ?>
                </div>
            </div>
        <?php endif; ?>
        <?php // WHAT WAS NOT CHANGED, AND WHY — by name. A bulk action that
              // quietly skips the entries breaking a taxonomy's rule reports
              // "20 updated" over 17. Each refusal is the rule's own words
              // (`Term::countViolation()`), and nothing of that entry changed:
              // an entry takes the whole edit or none of it. ?>
        <?php if (!empty($bulkReport['skipped'])): ?>
            <div class="alert alert--danger alert--compact" data-bulk-skipped>
                <?= icon('triangle-exclamation', 'alert__icon icon', '', 15) ?>
                <div>
                    <p class="alert__title">
                        <?= h($entries(count((array) $bulkReport['skipped']))) ?> not changed
                    </p>
                    <?php foreach ((array) $bulkReport['skipped'] as $skip): ?>
                        <p class="alert__text">
                            <strong><?= h((string) ($skip['title'] ?? '')) ?></strong> —
                            <?= h((string) ($skip['reason'] ?? '')) ?>
                        </p>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($allCount === 0): ?>
        <?php // AN EMPTY STATE IS THE PUREST CASE OF TELLING, so it gets a glyph
              // naming the kind of place you are standing in — the Content
              // Type's OWN registered icon, not a generic box — and the one
              // action that ends the emptiness. ?>
        <div class="empty">
            <?php // `empty__glyph` WITHOUT the `icon` class. Both are in
                  // core.css at equal specificity and `.icon` (§15) is declared
                  // after `.empty__glyph` (§11), so adding both makes the later
                  // rule win and the 28px glyph renders at 14px.
                  // The `fill="currentColor"` icon() emits is an attribute, so
                  // nothing is lost by dropping the class. ?>
            <?= icon(Sidebar::iconFor((string) $contentType['menu_icon']), 'empty__glyph', '', 28) ?>
            <p class="empty__title">No <?= h(strtolower($label)) ?> yet</p>
            <div class="empty__action">
                <a class="btn btn--primary" href="<?= h(Url::admin($adminSlug . '/new')) ?>">
                    Add <?= h($labelSingle) ?>
                </a>
            </div>
        </div>
    <?php else: ?>
        <?php // A GET FORM, because filtering is a question and not a change:
              // the answer has an address, so it can be bookmarked, shared and
              // reached again with Back. The sort rides along hidden, so
              // filtering a list sorted by title keeps it sorted by title; the
              // page does not, because page 4 of a new question is a guess. ?>
        <form class="filterbar filterbar--apply" method="get" action="<?= h(Url::admin($adminSlug)) ?>"
              data-entry-filter>
            <?php if ($sort['orderby'] !== ''): ?>
                <input type="hidden" name="orderby" value="<?= h($sort['orderby']) ?>">
                <input type="hidden" name="order" value="<?= h($sort['order']) ?>">
            <?php endif; ?>

            <div class="filterbar__group">
                <label class="filterbar__field">
                    <span class="filterbar__label">Search</span>
                    <input class="input input--search" type="search" name="s"
                           value="<?= h((string) $filters['s']) ?>"
                           placeholder="Title, address or text">
                </label>

                <label class="filterbar__field">
                    <span class="filterbar__label">Status</span>
                    <select class="select" name="status">
                        <option value="">Any status</option>
                        <?php // filterLabels(): what can be stored plus Scheduled,
                              // which is shown and not stored (Status::derived()). ?>
                        <?php foreach (Status::filterLabels() as $key => $statusLabel): ?>
                            <?php if ($key === 'trash') { continue; /* trash has its own screen */ } ?>
                            <option value="<?= h($key) ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>><?= h($statusLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <?php if ($canFilterByAuthor): ?>
                    <label class="filterbar__field">
                        <span class="filterbar__label">Author</span>
                        <select class="select" name="author">
                            <option value="">Anyone</option>
                            <?php // A SELECT MUST HOLD THE VALUE IT IS SHOWING. An
                                  // author with nothing left of this type is not
                                  // in the list, and a filter by them would
                                  // otherwise display "Anyone" over an empty
                                  // page. ?>
                            <?php $authorOptions = $listAuthors;
                            if ($filters['author'] !== null && !isset($authorOptions[$filters['author']])) {
                                $who = \Cms\Models\User::find((int) $filters['author']);
                                $authorOptions[(int) $filters['author']] = $who !== null
                                    ? \Cms\Models\User::displayName($who) : 'User #' . (int) $filters['author'];
                            } ?>
                            <?php foreach ($authorOptions as $authorId => $authorName): ?>
                                <option value="<?= (int) $authorId ?>"<?= $filters['author'] === (int) $authorId ? ' selected' : '' ?>><?= h($authorName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>

                <label class="filterbar__field">
                    <span class="filterbar__label">Date</span>
                    <select class="select" name="month">
                        <option value="">All dates</option>
                        <?php foreach ($months as $monthKey => $monthLabel): ?>
                            <option value="<?= h($monthKey) ?>"<?= $filters['month'] === $monthKey ? ' selected' : '' ?>><?= h($monthLabel) ?></option>
                        <?php endforeach; ?>
                        <?php if ($filters['month'] !== '' && !isset($months[$filters['month']])): ?>
                            <option value="<?= h($filters['month']) ?>" selected><?= h(Dates::format($filters['month'] . '-15 12:00:00', 'F Y')) ?></option>
                        <?php endif; ?>
                    </select>
                </label>

                <?php // ONE PER TAXONOMY THE REGISTRY ATTACHES — nothing here is
                      // per type, so a type whose owner made a second taxonomy
                      // gets its dropdown too. ?>
                <?php foreach ($cptTaxonomies as $taxKey => $tax): ?>
                    <label class="filterbar__field">
                        <span class="filterbar__label"><?= h((string) $tax['label']) ?></span>
                        <select class="select" name="tax_<?= h((string) $taxKey) ?>">
                            <option value="">All <?= h(strtolower((string) $tax['label'])) ?></option>
                            <?= TermRenderer::selectOptions(
                                $termTrees[$taxKey] ?? [],
                                isset($filters['terms'][$taxKey]) ? [(int) $filters['terms'][$taxKey]] : []
                            ) ?>
                        </select>
                    </label>
                <?php endforeach; ?>

                <?php // "Number of items per page". A number and not a select
                      // of presets, so the box can hold whatever it was given —
                      // from 1 to 999. ?>
                <label class="filterbar__field">
                    <span class="filterbar__label">Per page</span>
                    <input class="input input--count" type="number" name="per_page"
                           min="1" max="999" value="<?= (int) $perPage ?>">
                </label>
            </div>

            <div class="filterbar__actions">
                <?php // What the filter DID, not an instruction on how to use it.
                      // Only while a filter is on: "12 of 12" is noise. ?>
                <?php if ($filtering): ?>
                    <span class="filterbar__label" data-filter-count><?= (int) $total ?> of <?= (int) $allCount ?></span>
                    <?php // Clears the FILTERS and keeps the sort — the arrow
                          // on the heading is a separate choice with its own
                          // way back. ?>
                    <a class="filterbar__reset" href="<?= h($listUrl(array_fill_keys(
                        array_merge(['s', 'status', 'author', 'month', 'page'],
                            array_map(static fn ($k): string => 'tax_' . $k, array_keys($cptTaxonomies))),
                        null))) ?>">Clear</a>
                <?php endif; ?>
                <?php // Full size, like the controls it applies — a 24px button
                      // beside 32px selects makes a lopsided row. ?>
                <button type="submit" class="btn">Filter</button>
            </div>
        </form>

        <?php // ── THE BULK FORM ─────────────────────────────────────────────
              // The rows sit OUTSIDE it and their tick boxes join it through
              // `form="entry-bulk"`: every row already holds a form (Trash),
              // and HTML has no nested forms — the parser discards the inner
              // tag, and its button submits the outer form instead. ?>
        <form id="entry-bulk" method="post" action="<?= h(Url::admin($adminSlug . '/bulk')) ?>"
              data-entry-bulk
              data-label-one="entry" data-label-many="entries"
              <?php // THE QUESTION BULK TRASH ASKS — the row's own Trash asks
                    // it for one; twenty entries do not go without a word. ?>
              data-ask-trash="Move {n} to the trash?"
              data-ask-trash-detail="{It stops|They stop} appearing on the site straight away. You can restore {it|them} from the trash."
              data-ask-trash-verb="Move to trash">
            <?= Csrf::field() ?>
            <?php // Back to THIS view afterwards — the filter, the sort and the
                  // page the owner was standing on — rather than to page 1 of
                  // everything, which loses their place in a list of 309. ?>
            <input type="hidden" name="return" value="<?= h($listUrl(['page' => $page])) ?>">

            <div class="bulk-bar">
                <select class="select select--sm" name="bulk_action" aria-label="Bulk action" data-bulk-action>
                    <option value="">Bulk actions</option>
                    <option value="edit">Edit</option>
                    <option value="trash">Move to trash</option>
                </select>
                <button type="submit" class="btn btn--sm" data-bulk-apply disabled>Apply</button>
            </div>

            <?php // ── BULK EDIT ──────────────────────────────────────────────
                  // An inline bulk editor, as a card: what is selected, how it
                  // is filed, who wrote it and whether it is out. Opened
                  // by Edit + Apply; nothing here posts until Update. ?>
            <div class="form-card bulk-edit" data-bulk-edit hidden>
                <div class="form-card__section">
                    <?= panel_title('Bulk edit', 'bulk-edit') ?>
                    <div class="form-grid form-grid--3 bulk-edit__grid">
                        <div class="field">
                            <span class="label" data-bulk-count></span>
                            <?php // WHAT IS SELECTED, as CHIPS — the admin's own
                                  // "a value you picked and can remove"
                                  // (core.css §9). Each title carries an ×, and
                                  // the × unticks the row, so a selection can be
                                  // trimmed without
                                  // scrolling back to find it. Filled by the
                                  // script, from this prototype. ?>
                            <div class="chip-picker__chips" data-bulk-titles></div>
                            <template data-bulk-chip>
                                <span class="chip">
                                    <span class="chip__label"></span>
                                    <button type="button" class="chip__remove">
                                        <?= icon('x', 'icon icon--xs') ?>
                                    </button>
                                </span>
                            </template>
                        </div>

                        <?php foreach ($cptTaxonomies as $taxKey => $tax): ?>
                            <?php
                            $one = (int) ($tax['min_terms'] ?? 0) === 1
                                && isset($tax['max_terms']) && (int) $tax['max_terms'] === 1;
                            ?>
                            <fieldset class="field bulk-edit__tax" data-bulk-tax="<?= h((string) $taxKey) ?>"
                                      data-bulk-mode="<?= $one ? 'one' : 'many' ?>">
                                <legend class="label"><?= h((string) $tax['label']) ?></legend>
                                <?php // "CHANGED" IS WHAT SAYS TO TOUCH THIS TAXONOMY
                                      // AT ALL. Left alone, an entry keeps its terms
                                      // whatever they are — including one filed
                                      // before a rule tightened, which would
                                      // otherwise be refused an author change for a
                                      // taxonomy nobody touched. ?>
                                <input type="hidden" name="bulk_terms[<?= h((string) $taxKey) ?>][changed]" value="" data-bulk-changed>
                                <div class="check-list bulk-edit__list">
                                    <?php if ($one): ?>
                                        <?php // EXACTLY ONE IS A SINGLE CHOICE, so
                                              // radios, as on the entry form — and
                                              // choosing one MOVES the whole
                                              // selection there. "No change"
                                              // is the answer that leaves each
                                              // where it is. ?>
                                        <label class="check-list__row">
                                            <input type="radio" name="bulk_terms[<?= h((string) $taxKey) ?>][set]" value="" checked>
                                            <span>No change</span>
                                        </label>
                                        <?= FieldRenderer::checkTree($termTrees[$taxKey] ?? [], [], 'radio',
                                            'bulk_terms[' . $taxKey . '][set]') ?>
                                    <?php else: ?>
                                        <?php // THREE STATES: ticked is on every
                                              // selected entry, clear is
                                              // on none, and the dash is on some and
                                              // stays that way unless clicked. The
                                              // script sets them from the rows. ?>
                                        <?= FieldRenderer::checkTree($termTrees[$taxKey] ?? [], [], 'checkbox',
                                            'bulk_terms[' . $taxKey . '][on][]') ?>
                                    <?php endif; ?>
                                </div>
                            </fieldset>
                        <?php endforeach; ?>

                        <div class="field">
                            <?php if ($bulkAuthors !== null): ?>
                                <?php // Editors and up, as on the entry form — an
                                      // author writes as themselves. ?>
                                <label class="label" for="bulk-author">Author</label>
                                <select class="select" id="bulk-author" name="bulk_author">
                                    <option value="">No change</option>
                                    <?php foreach ($bulkAuthors as $who): ?>
                                        <option value="<?= (int) $who['id'] ?>"><?= h(\Cms\Models\User::displayName($who)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>

                            <?php // PUBLISHED OR PRIVATE — the two a selection
                                  // can share. Password needs a password, and
                                  // Scheduled is a date, not a status. ?>
                            <label class="label" for="bulk-status">Status</label>
                            <select class="select" id="bulk-status" name="bulk_status">
                                <option value="">No change</option>
                                <option value="published">Published</option>
                                <option value="private">Private</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="form-card__section form-actions">
                    <button type="submit" class="btn btn--primary" data-bulk-update>Update</button>
                    <button type="button" class="btn" data-bulk-cancel>Cancel</button>
                </div>
            </div>
        </form>

        <?php if ($posts === []): ?>
            <?php // THE FILTERED-EMPTY STATE, and it is a DIFFERENT element from
                  // the one above on purpose. "Your filter found nothing" and
                  // "there is nothing here" are different facts, so they get
                  // different glyphs: the magnifying glass, not the kind-of-
                  // place. Sharing one would tell the owner of a 400-entry site
                  // that they have none. ?>
            <div class="empty" data-filter-empty>
                <?= icon('magnifying-glass', 'empty__glyph', '', 28) ?>
                <p class="empty__title">No <?= h(strtolower($label)) ?> match that filter</p>
            </div>
        <?php else: ?>
        <div class="list entry-list entry-list--select" id="entry-list">
            <?php // THE HEADING ROW — the redirects list's precedent (`.rd-list__head`):
                  // a `.list__row` itself, so each label sits on the grid of the
                  // cells it names and cannot drift from them. It carries the
                  // select-all and the two sortable columns. ?>
            <div class="list__row entry-list__head">
                <label class="entry-list__check">
                    <input type="checkbox" data-bulk-all
                           aria-label="Select every <?= h(strtolower($labelSingle)) ?> on this page">
                </label>
                <div class="entry-list__main"><?= $sortLink('title', 'Title') ?></div>
                <div>Status</div>
                <div class="entry-list__when"><?= $sortLink('date', 'Date') ?></div>
                <div aria-hidden="true"></div>
            </div>

            <?php foreach ($posts as $post): ?>
                <?php
                $id      = (int) $post['id'];
                $editUrl = Url::admin($adminSlug . '/' . $id . '/edit');

                // A public URL exists only when the type HAS public routes and
                // the entry is actually reachable at one. A "view" control that
                // leads to a 404 is worse than no control — but "reachable" is
                // reachable BY THE SIGNED-IN ADMIN READING THIS, which is every
                // status but `trash`. See the note in content-type/edit.php.
                $viewUrl = ($publicSlug !== '' && (string) $post['status'] !== 'trash')
                    ? Url::page($publicSlug . '/' . (string) $post['slug'])
                    : null;

                // THE ROW'S DATE IS THE ONE THE LIST SORTS ON — `list_date`,
                // Post::listDateSql(): a live entry's publish date, a scheduled
                // one's included, and anything else's last edit.
                $stamp = (string) $post['list_date'];

                // WHOSE IT IS, NAMED — migration 047, and NOT an indent. A
                // page's parent is another page and sits in the same list, so
                // `pages/list.php` can indent and let the row above carry the
                // meaning. An event's parent is a NEWS ITEM, which this list
                // does not contain, so an indent here would point at whichever
                // row happened to be above it.
                //
                // NULL WHEN THE PARENT IS GONE. 047 is ON DELETE SET NULL and a
                // trashed parent still has a row, so this can miss — and a child
                // whose parent cannot be named simply does not claim one, rather
                // than printing a blank where a name should be.
                $parentTitle = $parentType !== null && !empty($post['parent_id'])
                    ? ($parentTitles[(int) $post['parent_id']] ?? null)
                    : null;

                // What bulk edit needs to know about this row to draw its three
                // states: which terms it has, per taxonomy.
                $filed = [];
                foreach ($cptTaxonomies as $taxKey => $tax) {
                    $filed[$taxKey] = array_map(static fn (array $t): int => $t['id'], $rowTerms[$id][$taxKey] ?? []);
                }
                ?>
                <div class="list__row"
                     data-entry-id="<?= $id ?>"
                     data-status="<?= h(Status::forEntry($post)) ?>"
                     data-bulk-terms="<?= h((string) json_encode($filed)) ?>">
                    <?php // THE TICK BOX'S TARGET IS ITS WHOLE CELL — a label
                          // filling the row's height, not the 13px box. ?>
                    <label class="entry-list__check">
                        <input type="checkbox" name="ids[]" value="<?= $id ?>" form="entry-bulk"
                               data-bulk-row data-title="<?= h((string) $post['title']) ?>"
                               aria-label="Select &ldquo;<?= h((string) $post['title']) ?>&rdquo;">
                    </label>

                    <div class="entry-list__main">
                        <?php // `list__row-link` goes ON THE ANCHOR, not on this
                              // wrapper. core.css stretches `a.list__row-link`
                              // across the whole row; putting the class on a div
                              // instead leaves a row that lights up on hover with
                              // no link under most of it — a hover cue over dead
                              // space. ?>
                        <a class="list__row-link list__row-title" href="<?= h($editUrl) ?>"><?= h((string) $post['title']) ?></a>
                        <div class="list__row-sub">/<?= h((string) $post['slug']) ?></div>
                        <?php if ($parentTitle !== null): ?>
                            <?php // THE PARENT TYPE'S NAME IS IN THE LABEL, not
                                  // just the title: "Board nominations open" on
                                  // its own does not say it is the news item this
                                  // event belongs to, and the whole point of the
                                  // line is the relationship. ?>
                            <div class="list__row-sub entry-list__parent">
                                <?= icon('folder-tree', 'icon icon--sm') ?>
                                <?= h((string) $parentType['label_single']) ?>:
                                <?= h($parentTitle) ?>
                            </div>
                        <?php endif; ?>
                        <?php // WHAT IT IS FILED UNDER, and each term is the filter
                              // for it — a line under the slug the way the parent
                              // is, because this row's columns are fixed and a
                              // taxonomy's are not.
                              // The taxonomy names itself, in the singular when
                              // there is one term. ?>
                        <?php foreach ($cptTaxonomies as $taxKey => $tax): ?>
                            <?php $termsHere = $rowTerms[$id][$taxKey] ?? []; ?>
                            <?php if ($termsHere !== []): ?>
                                <div class="list__row-sub entry-list__terms" data-row-terms="<?= h((string) $taxKey) ?>">
                                    <?= icon('tag', 'icon icon--sm') ?>
                                    <?= h((string) (count($termsHere) === 1 ? $tax['label_single'] : $tax['label'])) ?>:
                                    <?php foreach ($termsHere as $i => $term): ?><?= $i > 0 ? ', ' : '' ?><a href="<?= h($listUrl(['tax_' . $taxKey => $term['id'], 'page' => null])) ?>"><?= h($term['name']) ?></a><?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <?= Status::render(Status::forEntry($post)) ?>

                    <div class="list__row-meta">
                        <?php // THE AUTHOR IS A FILTER — for editors and up. An
                              // author's list is already theirs alone. ?>
                        <?php if ($canFilterByAuthor): ?>
                            <a class="entry-list__author" href="<?= h($listUrl(['author' => (int) $post['author_id'], 'page' => null])) ?>"><?= h((string) $post['author_name']) ?></a>
                        <?php else: ?>
                            <span class="entry-list__author"><?= h((string) $post['author_name']) ?></span>
                        <?php endif; ?>
                        <?php // <time> so the machine-readable value is the full
                              // timestamp while the human reads the short date. ?>
                        <time datetime="<?= h(date('c', (int) strtotime($stamp))) ?>"><?= h(Dates::short($stamp)) ?></time>
                    </div>

                    <?php // THE VERBS THE ROW ACTUALLY HAS. This is the screen
                          // an owner spends their day in, so editing an entry
                          // and trashing one are both visible on its row, not
                          // only "View on site".
                          //
                          // Edit is the primary verb and gets the button; the
                          // title still links to the same place, but a link with
                          // no affordance is not an offer. Trash is the
                          // low-emphasis destructive — `.link .link--xs`, no
                          // colour, because the confirm dialog it opens is the
                          // alert surface. Same shape the Structure lists
                          // already carry. ?>
                    <div class="list__row-actions">
                        <?php // ALWAYS RENDERED, empty when there is nothing to
                              // link to — it holds the rail's first grid cell so
                              // Edit and Trash keep the same left edge on a draft
                              // as on a published row. ?>
                        <span class="entry-list__view">
                        <?php if ($viewUrl !== null): ?>
                            <?php // Glyph-only, so a tooltip is legal here — and
                                  // this is the one place in the row where it is.
                                  // aria-label carries the same words, because a
                                  // hover bubble does not exist on touch. ?>
                            <a class="icon-btn" href="<?= h($viewUrl) ?>" target="_blank" rel="noopener"
                               data-tip="View on site" aria-label="View on site">
                                <?= icon('up-right-from-square', 'icon') ?>
                            </a>
                        <?php endif; ?>
                        </span>
                        <a class="btn btn--sm" href="<?= h($editUrl) ?>">Edit</a>
                        <form class="form-inline" method="post"
                              action="<?= h(Url::admin($adminSlug . '/' . $id . '/trash')) ?>"
                              data-confirm="Move &ldquo;<?= h((string) $post['title']) ?>&rdquo; to the trash?"
                              data-confirm-detail="It stops appearing on the site straight away. You can restore it from the trash."
                              data-confirm-verb="Move to trash">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link link--xs">Trash</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php // PAGINATION IS RENDERED ONLY WHEN THERE IS MORE THAN ONE PAGE —
              // the media library's rule: a lone "1" with dead arrows either
              // side is chrome that reports its own irrelevance. The box is a
              // current-page field: type a number, press Enter. ?>
        <?php if ($totalPages > 1): ?>
            <nav class="pager" aria-label="<?= h($label) ?> pages">
                <?php if ($page > 1): ?>
                    <a class="btn btn--sm" href="<?= h($listUrl(['page' => 1])) ?>">First</a>
                    <a class="btn btn--sm" href="<?= h($listUrl(['page' => $page - 1])) ?>" rel="prev">Previous</a>
                <?php endif; ?>

                <form class="pager__jump" method="get" action="<?= h(Url::admin($adminSlug)) ?>">
                    <?php // The view rides along, so a jump keeps the filter
                          // and the sort it was made from. ?>
                    <?php parse_str((string) parse_url($listUrl(), PHP_URL_QUERY), $keep); ?>
                    <?php foreach ($keep as $keepName => $keepValue): ?>
                        <?php if (is_string($keepValue)): ?>
                            <input type="hidden" name="<?= h((string) $keepName) ?>" value="<?= h($keepValue) ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <label class="pager__status">
                        Page
                        <input class="input input--sm input--count" type="number" name="page" min="1"
                               max="<?= (int) $totalPages ?>" value="<?= (int) $page ?>"
                               aria-label="Page number">
                        of <?= (int) $totalPages ?>
                    </label>
                </form>

                <?php if ($page < $totalPages): ?>
                    <a class="btn btn--sm" href="<?= h($listUrl(['page' => $page + 1])) ?>" rel="next">Next</a>
                    <a class="btn btn--sm" href="<?= h($listUrl(['page' => $totalPages])) ?>">Last</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

</div>

<?php if ($allCount > 0): ?>
    <?php // Emitted by the SCREEN, inside $pageContent, which the layout prints
          // above its own scripts — so anything this needs from admin.js is
          // already there (admin.js is in <head> for exactly that reason). ?>
    <script src="<?= h(\Cms\Helpers\Asset::admin('js/entry-bulk.js')) ?>"></script>
<?php endif; ?>
<?php // confirm.js OWNS THE DIALOG. `data-confirm` on a form is inert without
      // it: the trash form would post on the first click with no question
      // asked, on the one control in the row there is no undo button for. It is
      // loaded unconditionally, not inside the `if` above: the trash form only
      // exists when there are rows, but the cost of the tag is nothing and a
      // conditional script is easy to lose. ?>
<script src="<?= h(\Cms\Helpers\Asset::admin('js/confirm.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
