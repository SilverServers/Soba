<?php

declare(strict_types=1);

use Cms\Helpers\Url;

/**
 * ADMIN 403 — signed in, and this is not yours to change.
 *
 * ── WHY IT EXISTS ──────────────────────────────────────────────────────────
 * An author who reached someone else's entry by its address used to be let in:
 * the handlers checked sign-in and nothing else (see `Auth::canChange()`). Now
 * they are refused, and a refusal needs a page that says so. The two shapes it
 * replaced were a bare `exit('You do not have permission…')` — plain text with
 * no shell, which reads as the CMS crashing — and a silent redirect to the
 * dashboard, which reads as the click doing nothing at all.
 *
 * Same vocabulary as `404.php`: `.empty`, one glyph, one line, one way back. The
 * line states the rule rather than apologising, because the rule is the thing
 * the person can act on — find an editor.
 *
 * @var string|null $backUrl    where "back" goes; the admin home when absent
 * @var string|null $backLabel  what it is called
 */

$backUrl   = $backUrl   ?? Url::admin();
$backLabel = $backLabel ?? 'the admin';

$pageTitle = 'Not yours to change';

ob_start();
?>
<div class="page">
    <div class="empty">
        <?= icon('lock', 'empty__glyph', '', 28) ?>
        <p class="empty__title">Only an editor can change this</p>
        <div class="empty__action">
            <a class="btn" href="<?= h($backUrl) ?>">Back to <?= h($backLabel) ?></a>
        </div>
    </div>
</div>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
