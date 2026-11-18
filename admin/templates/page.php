<article>
    <header class="post-header">
        <a href="<?= htmlspecialchars(\Cms\Helpers\Url::home()) ?>" class="post-header__back">&larr; <?= htmlspecialchars(\Cms\Site\Words::of('home')) ?></a>
        <h1><?= htmlspecialchars($page['title']) ?></h1>
    </header>
    <div class="page-content"><?= \Cms\Helpers\Shortcode::process($page['content']) ?></div>
</article>
