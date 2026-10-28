<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * TERMS — the list, for one taxonomy.
 *
 * The screen the Taxonomies list's title, its `Terms` button and the taxonomy
 * form's header count all point at. It exists per taxonomy, from one template:
 * the routes are registered at bootstrap for every REGISTERED taxonomy, so a
 * taxonomy defined in `config/taxonomies.php` gets exactly the same screen as
 * one created through the admin, running the same code: one path in.
 *
 * ── FLAT AND NESTED ARE ONE LIST, NOT TWO SCREENS ───────────────────────────
 * A nested taxonomy renders its tree with the SAME `.list__row` a flat one uses,
 * indented by a nested element. The alternative — a separate tree widget — is a
 * second vocabulary for the same objects, and the row actions would then have to
 * be built twice and drift.
 *
 * DEPTH IS A NESTED ELEMENT, never `padding-left: {$depth * 20}px` computed in
 * PHP as an inline style: that is off the `--sp-*` scale and out of reach of
 * every stylesheet. See admin.css §5's note on `.check-list--sub`.
 *
 * ── WHAT CARRIES EACH FACT ──────────────────────────────────────────────────
 *   what is this term called   the name, and it links to its own edit screen
 *   what will the URL be       the slug on the row, never a sentence
 *   how much is published in it  the count — what its ARCHIVE lists — and a
 *                              link to exactly those entries. What a
 *                              delete would unfile is a different number, and
 *                              the delete dialog says it
 *   where does it sit          indentation, on a nested taxonomy. A flat one has
 *                              no indentation because it has no nesting — the
 *                              shape is the taxonomy's, not the screen's
 *
 * NO ob_start() AND NO require layout.php HERE — TermController buffers this
 * file and requires the layout itself.
 *
 * @var array<string, mixed>       $taxonomy   the REGISTRY entry, not a DB row
 * @var list<array<string, mixed>> $terms      flat, ordered
 * @var list<array<string, mixed>> $termTree   nested; [] on a flat taxonomy
 * @var array<string, mixed>|null  $bulkReport what the last bulk Delete or Merge did
 */

$label       = (string) $taxonomy['label'];
$labelSingle = (string) $taxonomy['label_single'];
$adminSlug   = (string) $taxonomy['admin_slug'];
$nested      = !empty($taxonomy['hierarchical']);

$pageTitle = $label;

$base = 'taxonomies/' . $adminSlug . '/terms';

// HOW MANY ENTRIES ARE FILED UNDER EACH TERM, in ONE query rather than one per
// row. A count-per-row on a taxonomy with two hundred terms is two hundred
// queries for a number, on shared hosting.
//
// TWO NUMBERS, BECAUSE THERE ARE TWO QUESTIONS:
//   $live   what the row PRINTS — how many entries the term's archive lists:
//           live ones, a pillar counting its branch (Term::liveCounts()).
//           Counting every filing — drafts, the trash, next month's — would
//           print 5 over an archive of 3, and 0 over a pillar whose entries
//           all sit one level down.
//   $usage  what the DELETE dialog says is lost: every direct filing,
//           whatever state the entry is in.
$usage = \Cms\Models\Term::usageCounts((string) $taxonomy['key']);
$live  = \Cms\Models\Term::liveCounts((string) $taxonomy['key']);

// THE COUNT IS A WAY IN: it opens the entry list filtered by the term AND
// Published, so the list under the link holds exactly the number on it. Only
// when the taxonomy files ONE type: a count across two types has no single
// list to open.
$typeKeys = array_values((array) ($taxonomy['content_types'] ?? []));
$oneType  = count($typeKeys) === 1 ? \Cms\ContentType\Registry::get((string) $typeKeys[0]) : null;
$countUrl = static function (int $termId) use ($oneType, $taxonomy): ?string {
    return $oneType === null ? null : Url::admin((string) $oneType['admin_slug']) . '?'
        . http_build_query(['status' => 'published', 'tax_' . $taxonomy['key'] => $termId]);
};

/**
 * One row, and its children under it. Recursive so nesting works to any depth —
 * the tree is built by the model, and this only draws it.
 */
$renderRow = static function (array $term, int $depth) use (&$renderRow, $base, $usage, $live, $countUrl, $labelSingle): string {
    ob_start();
    $id    = (int) $term['id'];
    $count = (int) ($usage[$id] ?? 0);
    $shown = (int) ($live[$id] ?? 0);
    $href  = $countUrl($id);
    ?>
    <div class="list__row">
        <?php // The tick box's whole cell is its target — the entry list's
              // `.entry-list__check`, the same cell doing the same job. ?>
        <label class="entry-list__check">
            <input type="checkbox" name="ids[]" value="<?= $id ?>" form="term-bulk"
                   data-bulk-row data-title="<?= h((string) $term['name']) ?>"
                   aria-label="Select <?= h((string) $term['name']) ?>">
        </label>
        <div class="term-list__main">
            <?php // Indentation is a real element with a real class, and the
                  // depth is stamped as a data attribute so CSS owns the number.
                  // A pixel value computed here would be off the spacing scale
                  // and unreachable from any stylesheet. ?>
            <?php if ($depth > 0): ?>
                <span class="term-list__depth" data-depth="<?= $depth ?>" aria-hidden="true"></span>
            <?php endif; ?>

            <?php // A MARK PER TERM, AND IT SAYS WHICH KIND. Without it the
                  // hierarchy — the one thing this list exists to show — would
                  // be carried by indentation alone, which reads as nothing.
                  //
                  // A term with children is a heading you file things under; a
                  // leaf is a thing you file. Two different jobs, so two marks,
                  // both from the sidebar's own vocabulary for taxonomies. ?>
            <?= icon(empty($term['children']) ? 'tag' : 'tags',
                     'term-list__glyph icon icon--sm') ?>

            <div class="term-list__names">
                <a class="list__row-link list__row-title" href="<?= h(Url::admin($base . '/' . $id . '/edit')) ?>">
                    <?= h((string) $term['name']) ?>
                </a>
                <div class="list__row-sub"><code><?= h((string) $term['slug']) ?></code></div>
            </div>
        </div>

        <div class="term-list__count">
            <?php // A count, not a badge: the accent fill means "this element
                  // ACTS" and a number does not act.
                  //
                  // AND A ZERO IS NOT THE SAME FACT AS A NUMBER. "0 entries use
                  // this" is the answer to the question you came here with —
                  // whether deleting it costs anything — so it is drawn as the
                  // quiet state rather than as a count that happens to be zero
                  // sitting at the same weight as one that is not. ?>
            <?php // NO TOOLTIP. A tip belongs only on a GLYPH-ONLY element,
                  // because a tip on something that already has visible text is
                  // a second label competing with the first. The count's
                  // meaning is carried where it matters — the delete dialog says
                  // "N entries are filed under it" before anything is lost. ?>
            <?php // "PUBLISHED" IS THE NOUN, because it is what is counted: the
                  // archive's entries, not every filing. A draft filed here is
                  // not in this number and is in the delete dialog's. ?>
            <?php if ($href !== null): ?>
                <a href="<?= h($href) ?>"><?= count_of($shown, 'published', 'published') ?></a>
            <?php else: ?>
                <?= count_of($shown, 'published', 'published') ?>
            <?php endif; ?>
        </div>

        <div class="list__row-actions">
            <a class="btn btn--sm" href="<?= h(Url::admin($base . '/' . $id . '/edit')) ?>">Edit</a>
            <?php // A destructive TRIGGER does not need to shout: the confirm
                  // dialog it opens IS the alert surface. The dialog says
                  // what actually happens — the ENTRIES are not touched, only
                  // their filing under this term — because "delete" next to a
                  // count of 40 reads like deleting 40 things. ?>
            <form class="form-inline" method="post"
                  action="<?= h(Url::admin($base . '/' . $id . '/delete')) ?>"
                  data-confirm="Delete <?= h((string) $term['name']) ?>?"
                  <?php // WHAT ACTUALLY HAPPENS, in two short sentences. A confirm
                        // dialog is the one place a sentence has to survive being
                        // read in a hurry. ?>
                  data-confirm-detail="<?= $count === 0
                        ? 'Nothing is filed under it.'
                        : $count . ($count === 1 ? ' entry is' : ' entries are') . ' filed under it, and '
                          . ($count === 1 ? 'stops' : 'stop') . ' being. The '
                          . ($count === 1 ? 'entry itself is' : 'entries themselves are') . ' not deleted.' ?><?= !empty($term['children']) ? ' Terms nested under it move up a level.' : '' ?>"
                  data-confirm-verb="Delete <?= h(strtolower($labelSingle)) ?>">
                <?= Csrf::field() ?>
                <button type="submit" class="link link--xs">Delete</button>
            </form>
        </div>
    </div>
    <?php
    $html = (string) ob_get_clean();

    foreach ((array) ($term['children'] ?? []) as $child) {
        $html .= $renderRow($child, $depth + 1);
    }

    return $html;
};
?>
<div class="page">

    <?php // ABOVE `.page__header`, not inside `.page__header-main` — that element
          // is a flex ROW, so the link would render beside the title. core.css §1. ?>
    <a class="page__back" href="<?= h(Url::admin('taxonomies')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Taxonomies
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title"><?= h($label) ?></h1>
        </div>
        <div class="page__header-actions">
            <?php if ($terms !== []): ?>
                <a class="btn btn--primary" href="<?= h(Url::admin($base . '/new')) ?>">
                    Add <?= h(strtolower($labelSingle)) ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($bulkReport)): ?>
        <?php $n = (int) ($bulkReport['done'] ?? 0); $noun = strtolower($n === 1 ? $labelSingle : $label); ?>
        <?php if ($n > 0): ?>
            <div class="flashes">
                <div class="flash flash--success" data-bulk-done>
                    <?= $n ?> <?= h($noun) ?>
                    <?= ($bulkReport['action'] ?? '') === 'merge'
                        ? 'merged into &ldquo;' . h((string) $bulkReport['into']) . '&rdquo;.'
                        : 'deleted.' ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if (!empty($bulkReport['skipped'])): ?>
            <?php // A MERGE THAT WOULD BREAK THE TAXONOMY'S RULE MOVES NOTHING
                  // for that term, and says which entry and why (Term::merge()). ?>
            <div class="alert alert--danger alert--compact" data-bulk-skipped>
                <?= icon('triangle-exclamation', 'alert__icon icon', '', 15) ?>
                <div>
                    <p class="alert__title">Not merged</p>
                    <?php foreach ((array) $bulkReport['skipped'] as $skip): ?>
                        <p class="alert__text">
                            <?php if (($skip['title'] ?? '') !== ''): ?><strong><?= h((string) $skip['title']) ?></strong> — <?php endif; ?>
                            <?= h((string) ($skip['reason'] ?? '')) ?>
                        </p>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($terms === []): ?>
        <?php // The kind of place you are standing in, and the one action that
              // ends the emptiness — show, don't tell. It says what a term
              // DOES here, because an empty taxonomy is usually reached straight
              // after creating one and "no terms yet" alone assumes the reader
              // already knows what a term is for. ?>
        <div class="empty">
            <?= icon($nested ? 'folder-tree' : 'tag', 'empty__glyph', '', 28) ?>
            <p class="empty__title">No <?= h(strtolower($label)) ?> yet</p>
            <p class="empty__text">
                Entries are filed under these, and the picker on the entry form
                shows nothing until there is at least one.
            </p>
            <div class="empty__action">
                <a class="btn btn--primary" href="<?= h(Url::admin($base . '/new')) ?>">
                    Add <?= h(strtolower($labelSingle)) ?>
                </a>
            </div>
        </div>
    <?php else: ?>
        <?php // ── MANY AT ONCE ─────────────────────────────────────────────
              // Ticked terms are deleted in bulk, or merged into one — fold
              // "SEO tips" into "SEO". The rows' tick boxes join the form by
              // `form=`: each row already holds its own delete form. ?>
        <form id="term-bulk" method="post" action="<?= h(Url::admin($base . '/bulk')) ?>"
              data-entry-bulk
              data-label-one="<?= h(strtolower($labelSingle)) ?>"
              data-label-many="<?= h(strtolower($label)) ?>"
              data-ask-delete="Delete {n}?"
              data-ask-delete-detail="The entries filed under {it are|them are} not deleted; they stop being filed under {it|them}. Terms nested under {it|them} move up a level."
              data-ask-delete-verb="Delete"
              data-ask-merge="Merge {n} into {into}?"
              <?php // THE REDIRECT IS PROMISED ONLY WHERE ONE IS WRITTEN — a
                    // taxonomy with no public address has no archive to send
                    // anyone from (TermController::handleBulk()). ?>
              data-ask-merge-detail="Everything filed under {it|them} is filed under {into} instead, and {its|their} nested terms move under {into}.<?= !empty($taxonomy['public_slug']) ? ' Visitors to {its address|their addresses} are sent on to {into}.' : '' ?> Then {it is|they are} deleted."
              data-ask-merge-verb="Merge">
            <?= Csrf::field() ?>
            <div class="bulk-bar">
                <select class="select select--sm" name="bulk_action" aria-label="Bulk action" data-bulk-action>
                    <option value="">Bulk actions</option>
                    <option value="delete">Delete</option>
                    <option value="merge" data-needs-into>Merge into&hellip;</option>
                </select>
                <?php // THE TARGET, shown once Merge is chosen. Every term of the
                      // taxonomy, nested as the tree is — the same indented list
                      // the entry list's filter draws. ?>
                <select class="select select--sm" name="merge_into" aria-label="Merge into" data-bulk-into hidden>
                    <option value="">Choose a <?= h(strtolower($labelSingle)) ?>&hellip;</option>
                    <?= \Cms\Helpers\TermRenderer::selectOptions(
                        $nested ? $termTree : array_map(static fn (array $t): array => $t + ['children' => []], $terms),
                        []
                    ) ?>
                </select>
                <button type="submit" class="btn btn--sm" data-bulk-apply disabled>Apply</button>
            </div>
        </form>

        <div class="list term-list<?= $nested ? ' term-list--nested' : '' ?>">
            <?php // The heading row: the select-all, and a label over the
                  // number so it is not a bare figure in an unheaded column. ?>
            <div class="list__row entry-list__head">
                <label class="entry-list__check">
                    <input type="checkbox" data-bulk-all aria-label="Select every <?= h(strtolower($labelSingle)) ?>">
                </label>
                <div class="term-list__main">Name</div>
                <div class="term-list__count">Published</div>
                <div aria-hidden="true"></div>
            </div>
            <?php // A NESTED taxonomy draws its tree; a flat one draws the flat
                  // list the model already ordered. Same rows either way — the
                  // difference is the data's shape, not a second screen. ?>
            <?php foreach (($nested ? $termTree : $terms) as $term): ?>
                <?= $renderRow($term, 0) ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<?php if ($terms !== []): ?>
    <script src="<?= h(Asset::admin('js/entry-bulk.js')) ?>"></script>
<?php endif; ?>
<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
