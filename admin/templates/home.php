<?php // ── THE FRONT PAGE'S OWN WORDS ──────────────────────────────────────────
      // A page, not a hero written into this file. Words hardcoded in a theme
      // template are words the owner cannot edit in the admin and the assistant
      // cannot find.
      //
      // `HomeController` looks up the published page with slug `home`. There may
      // not be one: a fresh install renders the feed alone.
      // `page-content` is the same wrapper `page.php` uses, so the front page's
      // prose is styled by the one rule that already styles page prose, and
      // its shortcodes run as a page's do: a [form], a [gallery], a [phone]. ?>
<?php if (!empty($intro)): ?>
    <div class="home-intro page-content">
        <?= \Cms\Helpers\Shortcode::process((string) $intro['content']) ?>
    </div>
<?php endif; ?>

<?php if (empty($posts)): ?>

    <?php // audit-ok: same as archive.php — the empty state of a list. ?>
    <p class="post-list-empty"><?= htmlspecialchars(\Cms\Site\Words::of('empty_home')) ?></p>

<?php else: ?>

    <ul class="post-list">
        <?php foreach ($posts as $p): ?>
            <li class="post-list__item">
                <h2 class="post-list__title">
                    <?php // THE ITEM'S OWN TYPE SLUG. A single slug for the whole
                          // list is a 404 per row once there are two content
                          // types — the entry knows which archive it lives under,
                          // the loop does not. ?>
                    <a href="<?= htmlspecialchars(\Cms\Helpers\Url::entry($p, (string) $p['public_slug'])) ?>">
                        <?= htmlspecialchars($p['title']) ?>
                    </a>
                </h2>
                <p class="post-list__meta">
                    <?= htmlspecialchars(\Cms\Site\Words::of('by')) ?> <?= htmlspecialchars($p['author_name']) ?>
                    &mdash;
                    <?= htmlspecialchars(\Cms\Helpers\Dates::short((string) $p['published_at'])) ?>
                </p>
                <?php // `Post::summary()` — the excerpt, or the start of the
                      // text — shared with the card and search, so "…" is
                      // printed only on text that was cut.
                      $summary = \Cms\Models\Post::summary($p); ?>
                <?php if ($summary['text'] !== ''): ?>
                    <p class="post-list__excerpt">
                        <?= htmlspecialchars($summary['text']) ?><?= $summary['cut'] ? '&hellip;' : '' ?>
                    </p>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php // `Helpers\Paging::url()` from `$pageBase` — the head's rel links
          // read the same rule, and page 1 is `/`, never `?page=1`. ?>
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="<?= htmlspecialchars(\Cms\Helpers\Paging::url($pageBase, $page - 1)) ?>" rel="prev">&laquo; <?= htmlspecialchars(\Cms\Site\Words::of('newer')) ?></a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a href="<?= htmlspecialchars(\Cms\Helpers\Paging::url($pageBase, $page + 1)) ?>" rel="next"><?= htmlspecialchars(\Cms\Site\Words::of('older')) ?> &raquo;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>
