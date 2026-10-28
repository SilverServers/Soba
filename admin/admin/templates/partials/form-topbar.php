<?php

declare(strict_types=1);

/**
 * THE EDITOR'S ACTION BAR — where am I, and how do I save.
 *
 * In a footer under a full-height body field, the save button would be the LAST
 * thing on the page — on any entry with real content the primary action would
 * be below the fold, and saving would mean scrolling past everything you had
 * just written to reach it. A footer is a sensible place for the action on a
 * SHORT settings form. It is the wrong place on a document editor.
 *
 * It also uses the back link's row, which would otherwise be a full-width line
 * with nothing to its right. One row carries both halves of "leave" and
 * "commit".
 *
 * THE BACK LINK IS INSIDE THE FORM with it. It has to be: this bar is inside
 * `<form>` because the button is a plain `type="submit"` rather than a detached
 * `form="…"` reference, and the link cannot sit above the form and on the same
 * line as something inside it.
 *
 * Cancel stays a LINK and Save stays the one filled button on the screen —
 * core.css §4's rule about a form footer applies to a form's actions wherever
 * they are printed, and this bar has no icons for the same reason.
 *
 * @var string $backUrl    the list this record belongs to
 * @var string $backLabel  that list's name, e.g. "Pages", "News"
 * @var string $saveLabel  "Create page" on new, "Save changes" on edit
 */

$backUrl   = $backUrl   ?? '';
$backLabel = $backLabel ?? 'Back';
$saveLabel = $saveLabel ?? 'Save changes';
?>
<div class="form-topbar">

    <?php // WHERE AM I. A detail page reached from a list needs the way back to
          // be a visible control, not the browser's Back button — which does not
          // exist after a redirect from a save. ?>
    <a class="page__back" href="<?= h($backUrl) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?><?= h($backLabel) ?>
    </a>

    <div class="form-topbar__actions">
        <a class="link link--xs" href="<?= h($backUrl) ?>">Cancel</a>
        <button type="submit" class="btn btn--primary"><?= h($saveLabel) ?></button>
    </div>

</div>
