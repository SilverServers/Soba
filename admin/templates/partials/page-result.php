<?php
/**
 * ONE PAGE IN A LIST OF SEARCH RESULTS — the shape an entry's card has
 * (`partials/post-card.php`), by hand: a title that is a link, and the start of
 * what it says. The site's search draws it (`search.php`), and so does a
 * static copy's search index (`Public\SearchController`, `Export\StaticCopy`),
 * so a copy's results look as the site's do.
 *
 * @var array<string, mixed> $row the page: title, slug, content
 */

// `Post::summary()` — the cut that knows a block's end is a word boundary. A
// page has no excerpt, so it always gets the start of its text.
$summary = \Cms\Models\Post::summary($row);
?>
<li class="post-list__item">
    <h2 class="post-list__title">
        <a href="<?= htmlspecialchars(\Cms\Helpers\Url::page((string) $row['slug'])) ?>"><?= htmlspecialchars((string) $row['title']) ?></a>
    </h2>
    <?php if ($summary['text'] !== ''): ?>
        <p class="post-list__excerpt">
            <?= htmlspecialchars($summary['text']) ?><?= $summary['cut'] ? '&hellip;' : '' ?>
        </p>
    <?php endif; ?>
</li>
