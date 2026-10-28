<?php

declare(strict_types=1);

/**
 * The password gate for a protected page or entry.
 *
 * Renders INSIDE base.php (it is handed back as $pageContent), so it gets the
 * site chrome and the theme's sheets — it carries no <html> and no styles of
 * its own. Every value it used to set inline now comes from `.gate*` in
 * theme.css.
 *
 * The wrong-password message is an `.cms-alert--error`: a standalone
 * surface with its own box, not red text painted onto the form. Alert colour is
 * a surface, never a state.
 *
 * @var string $pageTitle
 * @var bool   $passwordError
 */
?>
<article class="gate">
    <h1 class="gate__title"><?= htmlspecialchars($pageTitle) ?></h1>
    <?php /* The gate stands between the visitor and the content, so by definition
             none of its words can be stored in that content. audit-ok */ ?>
    <p class="gate__intro">This content is password protected. Enter the password to view it.</p>

    <?php if (!empty($passwordError)): ?>
        <?php // audit-ok: an error message on the gate. ?>
        <p class="cms-alert cms-alert--error imprint-alert imprint-alert--error">Incorrect password. Please try again.</p>
    <?php endif; ?>

    <form method="POST">
        <div class="gate__field">
            <?php // audit-ok: the label on the gate's own input. ?>
            <label class="cms-form__label imprint-form__label" for="content_password">Password</label>
            <input type="password"
                   id="content_password"
                   name="content_password"
                   class="cms-form__input imprint-form__input"
                   required
                   autocomplete="off">
        </div>
        <?php // audit-ok: the verb on the gate's own button. ?>
        <button type="submit" class="cms-form__submit imprint-form__submit">Submit</button>
    </form>
</article>
