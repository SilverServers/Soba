<?php

declare(strict_types=1);

use Cms\Helpers\Url;

/**
 * BACKUP CODES — ten, shown this once (`TwoStepController::showCodes()`). Only
 * their HMAC is stored, so this page is the only place they will ever be
 * readable; it says so, and offers them as a file to keep.
 *
 * @var list<string> $codes
 * @var bool         $first  they came with the first method
 * @var string       $site
 */

$pageTitle = 'Backup codes · ' . $site;
$hasRail   = false;
$user      = null;

$text = $site . " — backup codes for two-step sign-in\nEach one works once.\n\n" . implode("\n", $codes) . "\n";
$me   = (array) \Cms\Auth\Auth::user();

require ROOT_PATH . '/admin/templates/partials/head.php';
?>
<body>
<main class="auth">
    <div class="auth__inner">
        <div class="auth__brand"><?= h($site) ?></div>

        <?php if ($first): ?>
            <div class="flashes"><div class="flash flash--success">Two-step sign-in is on.</div></div>
        <?php endif; ?>

        <div class="form-card">
          <div class="form-card__section">
            <?= panel_title('Backup codes', 'backup-codes') ?>
            <div class="field">
                <label class="label" for="codes">Your backup codes</label>
                <textarea class="input input--mono" id="codes" rows="6" readonly><?= h(implode("\n", array_map(
                    static fn (array $pair): string => implode('    ', $pair), array_chunk($codes, 2)
                ))) ?></textarea>
                <p class="form-help">If your phone is lost or the mail does not come, each of these signs you in once.
                    Keep them somewhere safe. This is the only time they are shown.</p>
            </div>
            <div class="form-actions form-actions--bare">
                <a class="link link--xs" download="backup-codes.txt"
                   href="data:text/plain;charset=utf-8,<?= h(rawurlencode($text)) ?>">Download as a text file</a>
                <div class="form-actions__spacer"></div>
                <a class="btn btn--primary" href="<?= h(Url::admin('users/' . (int) ($me['id'] ?? 0) . '/edit') . '#two-step') ?>">I have kept them</a>
            </div>
          </div>
        </div>
    </div>
</main>
</body>
</html>
