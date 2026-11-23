<?php

declare(strict_types=1);

/**
 * What a browser WITHOUT JAVASCRIPT sees after posting a form, when it is not
 * sent somewhere else: why the form turned the
 * message away, or — with nowhere to go back to — that it was sent.
 *
 * Renders INSIDE base.php (handed back as $pageContent), as a page of the site,
 * with a page's own markup. A site that wants its own words gives
 * `form-refused.php` (the whole page; `Public\FormController::answer()`), or
 * puts its own copy of this file in `admin/site/templates/`.
 *
 * @var array{success: bool, message: string, back: string} $answer
 */
?>
<article>
    <header class="post-header">
        <?php /* The way back to the form: a link, since this is the page a
                 browser without JavaScript sees. audit-ok */ ?>
        <a href="<?= htmlspecialchars($answer['back']) ?>" class="post-header__back">&larr; Back</a>
        <?php /* The CMS's fallback words beside the site's own (form-refused.php).
                 audit-ok */ ?>
        <h1><?= $answer['success'] ? 'Sent' : 'That could not be sent' ?></h1>
    </header>
    <?php // ONE LINE, as page.php's: `.page-content` keeps white space
          // (pre-wrap), so a newline and an indent here were a blank line
          // between the heading and the message. ?>
    <div class="page-content"><p class="<?= htmlspecialchars($answer['success'] ? 'cms-alert cms-alert--success' : 'cms-alert cms-alert--error') ?>"><?= htmlspecialchars($answer['message']) ?></p></div>
</article>
