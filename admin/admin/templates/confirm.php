<?php

declare(strict_types=1);

use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * CONFIRM IT IS YOU — the password again before a change to how this account,
 * or this site, is signed in to (`TwoStep::requireConfirmed()`), when it was
 * not given in the last ten minutes. A browser left signed in is not enough to
 * switch the second step off or move the sign-in page.
 *
 * @var string      $return   where to go back to, an admin path
 * @var string      $account  whose password
 * @var string|null $error
 */

$site      = \Cms\SEO\Head::globalTokens()['site_name'];
$pageTitle = 'Confirm it is you · ' . $site;
$hasRail   = false;
$user      = null;

require ROOT_PATH . '/admin/templates/partials/head.php';
?>
<body>
<main class="auth">
    <div class="auth__inner">
        <div class="auth__brand"><?= h($site) ?></div>

        <?php if ($error !== null): ?>
            <div class="alert alert--danger" role="alert">
                <?= icon('circle-exclamation', 'alert__icon icon', '', 18) ?>
                <div class="alert__body"><p class="alert__text"><?= h($error) ?></p></div>
            </div>
        <?php endif; ?>

        <form class="form-card" method="post" action="<?= h(Url::admin('confirm') . '?return=' . rawurlencode($return)) ?>">
          <div class="form-card__section">
            <?= Csrf::field() ?>
            <?= panel_title('Confirm it is you', 'confirm') ?>
            <input type="text" name="username" value="<?= h($account) ?>" autocomplete="username" hidden>
            <div class="field">
                <label class="label" for="password">Your password</label>
                <input class="input" type="password" id="password" name="password"
                       autocomplete="current-password" required autofocus>
                <p class="form-help">Asked before a change to how this account or this site is signed in to.</p>
            </div>
            <div class="form-actions form-actions--bare">
                <a class="link link--xs" href="<?= h(ADMIN_URL) ?>">Cancel</a>
                <div class="form-actions__spacer"></div>
                <button class="btn btn--primary" type="submit">Confirm</button>
            </div>
          </div>
        </form>
    </div>
</main>
</body>
</html>
