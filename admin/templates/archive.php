<?php
/**
 * Content Type archive — one template, one presentation per archetype.
 *
 * From Public\ContentTypeController::archive():
 *   $posts (each with a `meta` key), $contentType, $page, $totalPages
 *
 * ── WHY THE ARCHETYPE BRANCHES ARE HERE ─────────────────────────────────────
 * A title, a date and an excerpt for every Content Type, whatever it is, would
 * leave a Team member's role, email and photo — all declared by the `listing`
 * archetype, all filled in on the edit screen — appearing nowhere at all, and
 * a Membership's price likewise. The controller loads `$row['meta']` for every
 * row so the cards can render archetype fields (price, event date, job title).
 *
 * A field the admin offers and no template renders is a box the owner types
 * into that changes nothing on their site.
 *
 * Archetypes are what decide this. Register a `listing` and its entries appear
 * with their photo and role, with no template work.
 */

use Cms\Models\Media;

$archetypes = $contentType['archetypes'] ?? ['post'];
$isListing  = in_array('listing', $archetypes, true);
$isProduct  = in_array('product', $archetypes, true);
$isEvent    = in_array('event', $archetypes, true);
?>

<div class="archive-header">
    <h1><?= htmlspecialchars($contentType['label'] ?? 'Archive') ?></h1>
</div>

<?php if (empty($posts)): ?>

    <?php // audit-ok: what an EMPTY list says. There is no entry to carry it. ?>
    <p class="post-list-empty">No entries published yet.</p>

<?php else: ?>

    <ul class="post-list">
        <?php foreach ($posts as $p): ?>
            <?php // ONE CARD, ONE PLACE: `partials/post-card.php`, because
                  // the term archive renders the identical card and a second
                  // copy would drift from this one. `$cardType` is named rather
                  // than reusing `$contentType`, because a term archive's rows
                  // each carry their own type and this one's do not. ?>
            <?php $cardType = $contentType; ?>
            <?php require \Cms\Helpers\Template::locate('partials/post-card.php'); ?>
        <?php endforeach; ?>
    </ul>

    <?php // THE PAGER AND THE HEAD'S rel="prev"/"next" READ ONE RULE —
          // `Helpers\Paging::url()` from `$pageBase`, page 1's address, which the
          // controller sets. A bare `?page=` would make "Previous" from page 2
          // point at `?page=1`, a second address for page 1. ?>
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="<?= htmlspecialchars(\Cms\Helpers\Paging::url($pageBase, $page - 1)) ?>" rel="prev">&laquo; Previous</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a href="<?= htmlspecialchars(\Cms\Helpers\Paging::url($pageBase, $page + 1)) ?>" rel="next">Next &raquo;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>
