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
    <h1><?= htmlspecialchars(isset($contentType['label']) ? \Cms\Site\Words::typeLabel($contentType) : \Cms\Site\Words::of('archive')) ?></h1>
    <?php // AN EVENTS LIST AS A CALENDAR TO SUBSCRIBE TO (`Content\Calendar`):
          // a calendar app given this address keeps itself up to date. ?>
    <?php if ($isEvent && \Cms\Content\Calendar::has((string) ($contentType['key'] ?? ''))): ?>
        <p class="archive-header__calendar"><a href="<?= htmlspecialchars(\Cms\Content\Calendar::url((string) $contentType['key'])) ?>"><?= htmlspecialchars(\Cms\Site\Words::of('calendar_subscribe')) ?></a></p><?php /* audit-ok: the link's own verb, which the system draws */ ?>
    <?php endif; ?>
</div>

<?php
/* A SHOP'S LIST CAN BE ORDERED AND NARROWED — by price or newest, and by each
   thing a customer chooses when buying (Size, Colour). A plain GET form: the
   address carries the choice, so a filtered list can be bookmarked and sent,
   and its pager keeps it. The controller hands `$shopTools` to a product type
   only. */
$shopTools = $shopTools ?? null;
?>
<?php if ($shopTools !== null): ?>
    <form class="shop-sort" method="get" action="<?= htmlspecialchars(\Cms\Helpers\Url::archive((string) \Cms\ContentType\Registry::publicSlug((string) ($contentType['key'] ?? '')))) ?>">
        <label class="u-visually-hidden" for="shop-sort">Sort</label><?php /* audit-ok: the field's own label */ ?>
        <select class="shop-variant" id="shop-sort" name="sort">
            <?php foreach ($shopTools['sorts'] as $key => $word): ?>
                <option value="<?= htmlspecialchars($key) ?>"<?= $shopTools['sort'] === $key ? ' selected' : '' ?>><?= htmlspecialchars($word) ?></option>
            <?php endforeach; ?>
        </select>
        <?php foreach ($shopTools['filters'] as $taxKey => $filter): ?>
            <label class="u-visually-hidden" for="shop-<?= htmlspecialchars($taxKey) ?>"><?= htmlspecialchars($filter['label']) ?></label>
            <select class="shop-variant" id="shop-<?= htmlspecialchars($taxKey) ?>" name="<?= htmlspecialchars($taxKey) ?>">
                <option value="">Any <?= htmlspecialchars(mb_strtolower($filter['label'])) ?></option><?php /* audit-ok: the choice that narrows nothing, naming what it is a choice of */ ?>
                <?php foreach ($filter['terms'] as $slug => $name): ?>
                    <option value="<?= htmlspecialchars($slug) ?>"<?= $filter['current'] === $slug ? ' selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endforeach; ?>
        <button type="submit" class="shop-btn shop-btn--quiet">Show</button><?php /* audit-ok: the form's one verb */ ?>
    </form>
<?php endif; ?>

<?php if (empty($posts) && $shopTools !== null && $shopTools['active']): ?>

    <p class="post-list-empty">
        <?php // audit-ok: what a list narrowed to nothing says — the system's state, with the way back. ?>
        Nothing here matches that.
        <a href="<?= htmlspecialchars(\Cms\Helpers\Url::archive((string) \Cms\ContentType\Registry::publicSlug((string) ($contentType['key'] ?? '')))) ?>">Show everything</a>
    </p>

<?php elseif (empty($posts)): ?>

    <?php // audit-ok: what an EMPTY list says. There is no entry to carry it. ?>
    <?php // AND THE WAY TO FILL IT, in the same line, for someone signed in who
          // can (`Site\FirstStep`), never a visitor. audit-ok: an editor's
          // link, not the site's words. ?>
    <p class="post-list-empty"><?= htmlspecialchars(\Cms\Site\Words::of('empty_archive')) ?>
        <?php if (($first = \Cms\Site\FirstStep::forList([(string) ($contentType['key'] ?? '')])) !== null): ?><a href="<?= htmlspecialchars($first['href']) ?>"><?= htmlspecialchars($first['label']) ?></a><?php endif; ?></p>

<?php else: ?>

    <?php // AN EVENTS LIST: WHAT IS OVER, UNDER A HEADING OF ITS OWN. The
          // controller says where on this page the past begins (`$pastFrom`,
          // `Content\Events`), and null when it does not, or the list is
          // not in date order. ?>
    <?php $pastFrom = $pastFrom ?? null; ?>
    <?php // audit-ok: the theme's name for this part of the list, like "Related" — renamed in a site's own template. ?>
    <?php $pastTitle = \Cms\Site\Words::of('past_events'); ?>
    <?php $lists = $pastFrom === null ? [[null, $posts]]
        : array_values(array_filter([[null, array_slice($posts, 0, $pastFrom)], [$pastTitle, array_slice($posts, $pastFrom)]],
            static fn (array $list): bool => $list[1] !== [])); ?>
    <?php foreach ($lists as [$heading, $cards]): ?>
        <?php if ($heading !== null): ?>
            <section class="post-list-past" aria-labelledby="post-list-past-title">
                <h2 class="post-list-past__title" id="post-list-past-title"><?= htmlspecialchars($heading) ?></h2>
        <?php endif; ?>
        <ul class="post-list">
            <?php foreach ($cards as $p): ?>
                <?php // ONE CARD, ONE PLACE: `partials/post-card.php`, because
                      // the term archive renders the identical card and a second
                      // copy would drift from this one. `$cardType` is named rather
                      // than reusing `$contentType`, because a term archive's rows
                      // each carry their own type and this one's do not. ?>
                <?php $cardType = $contentType; ?>
                <?php require \Cms\Helpers\Template::locate('partials/post-card.php'); ?>
            <?php endforeach; ?>
        </ul>
        <?php if ($heading !== null): ?>
            </section>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php // THE PAGER AND THE HEAD'S rel="prev"/"next" READ ONE RULE —
          // `Helpers\Paging::url()` from `$pageBase`, page 1's address, which the
          // controller sets. A bare `?page=` would make "Previous" from page 2
          // point at `?page=1`, a second address for page 1. ?>
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
