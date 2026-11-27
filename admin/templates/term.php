<?php

declare(strict_types=1);

/**
 * EVERYTHING FILED UNDER ONE TERM. Migration 048.
 *
 * From `Public\TermController::archive()`:
 *   $term (id, name, slug, description), $taxonomy, $posts (each with `meta`
 *   and `content_type`), $page, $totalPages
 *
 * ── THE ROWS ARE OF MIXED TYPES, AND THAT IS THE WHOLE DIFFERENCE ───────────
 * A Content Type archive lists one type, so one `$contentType` builds every
 * address. A term can be attached to several types at once — "Campus" on News
 * and on Events — so each row carries its OWN type and the card is handed that
 * rather than a page-level variable. Getting this wrong does not throw: it
 * builds `/news/spring-hike` for an event and 404s on click.
 */

use Cms\Helpers\Url;

$heading     = (string) $term['name'];
$description = trim((string) ($term['description'] ?? ''));
?>

<div class="archive-header">
    <?php // THE TAXONOMY ABOVE THE TERM, small — "Topic" over "Campus". A
          // heading reading "Campus" alone does not say what kind of thing you
          // are looking at a list of, and the breadcrumb is not always on
          // screen. ?>
    <p class="archive-header__kind"><?= htmlspecialchars((string) $taxonomy['label_single']) ?></p>
    <h1><?= htmlspecialchars($heading) ?></h1>
    <?php if ($description !== ''): ?>
        <p class="archive-header__intro"><?= htmlspecialchars($description) ?></p>
    <?php endif; ?>
</div>

<?php
/* THE TERM'S OWN FIELDS (`ContentType\TermFields`, `Term::meta()`): a
   location's address, phone and photo, each section a fact panel as an
   entry's field groups are, its fields labelled, the empty ones left out and
   a section with nothing filled in not drawn. `ContentType\FieldDisplay`
   turns each stored value into what a visitor reads. */
$termMeta = \Cms\Models\Term::meta((int) $term['id']);
foreach (\Cms\ContentType\TermFields::sections((string) $taxonomy['key']) as $termSection):
    $rows = [];
    foreach ($termSection['fields'] as $field) {
        $shown = \Cms\ContentType\FieldDisplay::html($field, $termMeta[(string) ($field['key'] ?? '')] ?? null);
        if ($shown !== '') {
            $rows[] = [(string) ($field['label'] ?? $field['key']), $shown];
        }
    }
    if ($rows === []) {
        continue;
    }
?>
    <section class="entry-meta entry-fields" aria-label="<?= htmlspecialchars($termSection['label']) ?>">
        <dl class="entry-fields__list">
            <?php foreach ($rows as [$label, $shown]): ?>
                <dt><?= htmlspecialchars($label) ?></dt>
                <dd><?= $shown /* FieldDisplay escapes; rich text is sanitised there */ ?></dd>
            <?php endforeach; ?>
        </dl>
    </section>
<?php endforeach; ?>

<?php if ($posts === []): ?>

    <?php // audit-ok: what an EMPTY list says. There is no entry to carry it.
          //
          // NOT "nothing was found". Nothing is wrong and nothing failed — the
          // term exists and has not been used yet, and a visitor who reads
          // "no results" assumes they mistyped something. ?>
    <?php // audit-ok ?>
    <?php // AND THE WAY TO FILL IT, in the same line, for someone signed in who
          // can (`Site\FirstStep`): an entry of the taxonomy's first type, filed
          // under this on its form. Never a visitor. audit-ok: an editor's
          // link, not the site's words. ?>
    <?php $termTypes = array_slice(array_map('strval', (array) ($taxonomy['content_types'] ?? [])), 0, 1); ?>
    <p class="post-list-empty"><?= htmlspecialchars(\Cms\Site\Words::of('empty_term')) ?>
        <?php if ($termTypes !== [] && ($first = \Cms\Site\FirstStep::forList($termTypes)) !== null): ?><a href="<?= htmlspecialchars($first['href']) ?>"><?= htmlspecialchars($first['label']) ?></a><?php endif; ?></p>

<?php else: ?>

    <ul class="post-list">
        <?php foreach ($posts as $p): ?>
            <?php // EACH ROW'S OWN TYPE. See the header.
                  //
                  // A row whose type is no longer registered — the type was
                  // deleted while its entries were not — is skipped rather than
                  // rendered with a broken address. It cannot be reached
                  // publicly either, since its routes are gone with it.
                  $cardType = $p['content_type'] ?? null; ?>
            <?php if (is_array($cardType)): ?>
                <?php require \Cms\Helpers\Template::locate('partials/post-card.php'); ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>

    <?php // THE SAME PREV/NEXT THE CONTENT TYPE ARCHIVE USES. A class the
          // theme does not have would render as unstyled text next to a
          // paginated archive that looks right. One pagination, one appearance.
          //
          // An absolute address rather than a bare `?page=`: a relative query
          // string works until something links to this page with a trailing
          // slash, and then "Next" points at the wrong address. It is
          // `Helpers\Paging::url()` from `$pageBase` — the rule the head's
          // rel="prev"/"next" read too. ?>
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="<?= htmlspecialchars(\Cms\Helpers\Paging::url($pageBase, $page - 1)) ?>" rel="prev">&laquo; <?= htmlspecialchars(\Cms\Site\Words::of('previous')) ?></a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a href="<?= htmlspecialchars(\Cms\Helpers\Paging::url($pageBase, $page + 1)) ?>" rel="next"><?= htmlspecialchars(\Cms\Site\Words::of('next')) ?> &raquo;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>
