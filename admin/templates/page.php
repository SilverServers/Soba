<article>
    <header class="post-header">
        <a href="<?= htmlspecialchars(BASE_URL . '/') ?>" class="post-header__back">&larr; Home</a>
        <h1><?= htmlspecialchars($page['title']) ?></h1>
    </header>
    <div class="page-content"><?= \Cms\Helpers\Shortcode::process($page['content']) ?></div>
</article>
