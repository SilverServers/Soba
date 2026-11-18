<?php

declare(strict_types=1);

/**
 * SITE SEARCH — from `Public\SearchController::index()`:
 *   $q        what was searched for ('' before anything is)
 *   $results  each ['kind' => page|entry, 'row', 'type']
 *   $capped   true when the list stopped at its limit
 *
 * An entry is drawn by the SAME card every other list of entries uses
 * (`partials/post-card.php`), handed its own type, as the term archive does —
 * a results list holds several types at once. A page gets the same shape from
 * `partials/page-result.php`: a title that is a link, and the start of what it
 * says. A static copy's search draws both the same way.
 */
?>

<div class="archive-header">
    <?php // audit-ok: the name of a page that has no content to name it. ?>
    <h1><?= htmlspecialchars(\Cms\Site\Words::of('search')) ?></h1>
</div>

<form class="search-form" role="search" method="get" action="<?= htmlspecialchars(\Cms\Helpers\Url::search()) ?>">
    <input class="<?= htmlspecialchars(\Cms\Compat\OldNames::classes('cms-form__input')) ?>" type="search" name="q"
           value="<?= htmlspecialchars($q) ?>"
           placeholder="<?= htmlspecialchars(\Cms\Site\Words::of('search_label')) ?>" aria-label="<?= htmlspecialchars(\Cms\Site\Words::of('search_label')) ?>">
    <?php // audit-ok: the verb on the search form's own button. ?>
    <button class="<?= htmlspecialchars(\Cms\Compat\OldNames::classes('cms-form__submit')) ?>" type="submit"><?= htmlspecialchars(\Cms\Site\Words::of('search')) ?></button>
</form>

<?php if ($q !== ''): ?>
    <?php if ($results === []): ?>

        <?php // audit-ok: what an EMPTY result says. There is no entry to carry it. ?>
        <p class="post-list-empty"><?= htmlspecialchars(\Cms\Site\Words::of('search_none', ['q' => $q])) ?></p>

    <?php else: ?>

        <?php // audit-ok: the count of what was found and the words searched for — interface, not content. ?>
        <p class="search-summary"><?= htmlspecialchars(\Cms\Site\Words::of($capped ? 'search_first' : (count($results) === 1 ? 'search_one' : 'search_many'), ['n' => count($results), 'q' => $q])) ?></p>

        <ul class="post-list">
            <?php foreach ($results as $result): ?>
                <?php if ($result['kind'] === 'entry' && is_array($result['type'])): ?>
                    <?php $p = $result['row']; $cardType = $result['type']; ?>
                    <?php require \Cms\Helpers\Template::locate('partials/post-card.php'); ?>
                <?php elseif ($result['kind'] === 'page'): ?>
                    <?php $row = $result['row']; ?>
                    <?php require \Cms\Helpers\Template::locate('partials/page-result.php'); ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>

    <?php endif; ?>
<?php endif; ?>
