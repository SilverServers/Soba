<?php

declare(strict_types=1);

use Cms\ContentType\Registry;
use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Models\Term;
use Cms\Security\Csrf;
use Cms\Ui\Sidebar;

/**
 * TAXONOMIES — the list.
 *
 * A taxonomy is a way of filing entries: Categories on News, Club types on
 * Clubs. When something needs grouping, the first question is whether one of
 * these already holds it, never what new store to add.
 *
 * ── THE TWO WAYS THIS SCREEN CAN LIE, AND THE ANSWERS ───────────────────────
 * The same two the Content Types and Field Groups lists face, with the same
 * answers:
 *
 * 1. SAYING THERE ARE NONE WHEN THERE ARE. Taxonomies registered in
 *    `config/taxonomies.php` have no row in the `taxonomies` table, so a screen
 *    reading the table alone tells an install whose taxonomies are in code that
 *    it has none — while the entry forms render their pickers. Anything
 *    answering "what exists in this install?" asks the REGISTRY, so the
 *    controller merges both sources.
 *
 * 2. SHOWING A ROW YOU CANNOT ACT ON. Every row here carries the actions it
 *    genuinely has:
 *      · a database-defined taxonomy   Terms · Edit · Delete
 *      · a code-defined taxonomy       Terms
 *    …and an `.alert--info` above names the file, which is something a person
 *    can act on. Not a greyed Edit button that does nothing when clicked.
 *
 * ── WHAT CARRIES EACH FACT ──────────────────────────────────────────────────
 *   how many terms are in it   the count on the row, not a sentence. It is the
 *                              question you ask before deleting one
 *   what does it file          the Content Types on the row, with their own
 *                              registered glyphs — never the raw key
 *   files nothing              said on the row: a taxonomy attached to no type
 *                              appears on no entry form and looks like a bug
 *   flat or nested             the shape decides the control an entry form draws
 *                              (tick-box tree vs chip picker), so it is a fact
 *                              worth seeing before you add fifty terms to it
 *   is it switched on          the status chip. An inactive taxonomy keeps its
 *                              terms and every assignment; it leaves the forms
 *
 * NO ob_start() AND NO require layout.php HERE — TaxonomyController buffers this
 * file and requires the layout itself. Mixing the two conventions gives HTTP 200
 * with the whole shell rendered twice, nested.
 *
 * @var list<array<string, mixed>>   $dbTaxonomies     rows from `taxonomies`
 * @var array<string, array>         $codeTaxonomies   registry entries with no row
 * @var array<string, array>         $allContentTypes  Registry::all()
 */

$pageTitle = 'Taxonomies';

// ONE list, assembled from both sources, so the screen answers the question its
// title asks. `managed` is what decides a row's actions — never its source
// label, which is a fact ABOUT the row and not a permission.
$rows = [];

foreach ($dbTaxonomies as $tax) {
    $rows[] = [
        'key'          => (string) $tax['taxonomy_key'],
        'label'        => (string) $tax['plural_label'],
        'admin'        => (string) $tax['admin_slug'],
        'types'        => (array) $tax['content_types'],
        'hierarchical' => (bool) $tax['is_hierarchical'],
        'active'       => (bool) $tax['is_active'],
        'id'           => (int) $tax['id'],
        'managed'      => true,
    ];
}

foreach ($codeTaxonomies as $tax) {
    $rows[] = [
        'key'          => (string) $tax['key'],
        'label'        => (string) $tax['label'],
        'admin'        => (string) $tax['admin_slug'],
        'types'        => (array) ($tax['content_types'] ?? []),
        'hierarchical' => (bool) ($tax['hierarchical'] ?? false),
        'active'       => true,
        'id'           => 0,
        'managed'      => false,
    ];
}

usort($rows, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

$codeCount = count($codeTaxonomies);

// A Content Type key resolved through the REGISTRY, never printed raw: the key
// is an identifier and "event" is not what the owner named their Events. A key
// with no registered type shows as itself — a real state (a type deleted out
// from under the taxonomy) that hiding would turn into "attached to nothing".
$resolve = static function (string $key): array {
    $ct = Registry::get($key);
    return $ct === null
        ? ['label' => $key, 'icon' => null]
        : ['label' => (string) $ct['label'], 'icon' => (string) $ct['menu_icon']];
};
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Taxonomies</h1>
        </div>
        <div class="page__header-actions">
            <?php if ($rows !== []): ?>
                <a class="btn btn--primary" href="<?= h(Url::admin('taxonomies/new')) ?>">Add taxonomy</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($codeCount > 0): ?>
        <?php // THE FACT AND THE ACT. `--info` is the alert SHAPE with no alert
              // COLOUR: a taxonomy defined in code is a documented way to build
              // an install, not a fault, and amber here would make a working one
              // look broken. This exists so the rows below never have to be
              // greyed out. ?>
        <div class="alert alert--info">
            <?= icon('file', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__text">
                    <?= $codeCount === 1 ? 'One taxonomy is' : $codeCount . ' taxonomies are' ?>
                    registered in <code>config/taxonomies.php</code> and
                    <?= $codeCount === 1 ? 'is' : 'are' ?> edited there, not here.
                    Their terms are managed normally.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <?php // The kind of place you are standing in, and the one action that
              // ends the emptiness — show, don't tell. ?>
        <div class="empty">
            <?= icon('tags', 'empty__glyph', '', 28) ?>
            <p class="empty__title">No taxonomies yet</p>
            <div class="empty__action">
                <a class="btn btn--primary" href="<?= h(Url::admin('taxonomies/new')) ?>">Add taxonomy</a>
            </div>
        </div>
    <?php else: ?>
        <div class="list tx-list">
            <?php foreach ($rows as $row): ?>
                <?php
                // The term count is what makes this list scannable: it turns "is
                // anything filed under this?" from a click into a glance, which
                // is the question you ask before deleting one.
                $termCount = count(Term::allForTaxonomy($row['key']));
                $types     = array_map($resolve, $row['types']);
                $termsUrl  = Url::admin('taxonomies/' . $row['admin'] . '/terms');
                ?>
                <div class="list__row">
                    <div class="tx-list__main">
                        <?php // THE TITLE OPENS THE THING THIS SCREEN MANAGES —
                              // the taxonomy, not its terms. The same as the
                              // Content types list beside it: the row's own
                              // Terms button is how you reach the terms, and it
                              // is right there. A code-defined taxonomy has no
                              // editor, so its title keeps the terms. ?>
                        <a class="list__row-link list__row-title" href="<?= h($row['managed']
                            ? Url::admin('taxonomies/' . $row['id'] . '/edit')
                            : $termsUrl) ?>"><?= h($row['label']) ?></a>
                        <div class="list__row-sub tx-list__targets">
                            <?php if ($types === []): ?>
                                <span class="placeholder">Not attached to a content type</span>
                            <?php else: ?>
                                <?php foreach ($types as $type): ?>
                                    <span class="tx-list__target">
                                        <?php if ($type['icon'] !== null): ?>
                                            <?= icon(Sidebar::iconFor($type['icon']), 'icon icon--sm') ?>
                                        <?php endif; ?>
                                        <?= h($type['label']) ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="tx-list__count">
                        <?php // A count, not a badge: the accent fill means "this
                              // element ACTS" and a number does not act. ?>
                        <?= count_of((int) $termCount, 'term') ?>
                    </div>

                    <div class="tx-list__state">
                        <?php // Flat vs nested decides the CONTROL an entry form
                              // draws — a tick-box tree or a chip picker — so it
                              // is worth knowing before fifty terms are added.
                              // Base neutral: core.css §8's ramp is muted →
                              // neutral → accent, and a shape is a KIND, not a
                              // state.
                              //
                              // Inactive outranks it, because a taxonomy that is
                              // switched off is not appearing anywhere at all and
                              // that is the more urgent fact. ?>
                        <?php if (!$row['active']): ?>
                            <span class="status status--archived">
                                <?= icon('toggle-off', 'status__icon icon', '', 12) ?>Inactive
                            </span>
                        <?php elseif ($row['hierarchical']): ?>
                            <span class="status">
                                <?= icon('folder-tree', 'status__icon icon', '', 12) ?>Nested
                            </span>
                        <?php else: ?>
                            <span class="status">
                                <?= icon('tag', 'status__icon icon', '', 12) ?>Flat
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="list__row-actions">
                        <a class="btn btn--sm" href="<?= h($termsUrl) ?>">Terms</a>
                        <?php if ($row['managed']): ?>
                            <a class="btn btn--sm" href="<?= h(Url::admin('taxonomies/' . $row['id'] . '/edit')) ?>">Edit</a>
                            <?php // A destructive TRIGGER does not need to shout:
                                  // the confirm dialog it opens IS the alert
                                  // surface. Low-emphasis destructive is a plain
                                  // link with no colour at all. ?>
                            <form class="form-inline" method="post"
                                  action="<?= h(Url::admin('taxonomies/' . $row['id'] . '/delete')) ?>"
                                  data-confirm="Delete the <?= h($row['label']) ?> taxonomy?"
                                  data-confirm-detail="<?= $termCount ?> <?= $termCount === 1 ? 'term goes' : 'terms go' ?> with it, and <?= $termCount === 1 ? 'its' : 'their' ?> assignments to entries are removed. The entries themselves are untouched."
                                  data-confirm-verb="Delete taxonomy">
                                <?= Csrf::field() ?>
                                <button type="submit" class="link link--xs">Delete</button>
                            </form>
                        <?php endif; ?>
                        <?php // NO disabled Edit on a code-defined row. A control
                              // that cannot act is a read-only row;
                              // the alert above says where it is edited, and the
                              // row keeps only the action it really has. ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
