<?php

declare(strict_types=1);

use Cms\Security\Csrf;

/**
 * THE BROWSER SET-UP (`Install\Installer`): the database, then the site and
 * its first account, then where to sign in. A page of its own, as the sign-in
 * page is, in the admin's default theme: there is no site to name yet, and no
 * database to read a look from.
 *
 * @var string                $step    database | site | done
 * @var list<string>          $errors
 * @var array<string, string> $form    what was typed, never a password
 * @var array|null            $done    username, signin, config (the text to save by hand, or null)
 * @var string                $action  this page's own address
 */

$pageTitle = 'Set up';
$hasRail   = false;
$user      = null;
require ROOT_PATH . '/admin/templates/partials/head.php';
?>
<body>
<main class="auth">
    <div class="auth__inner">
        <div class="auth__brand">Set up this site</div>

        <?php if ($errors !== []): ?>
            <div class="alert alert--danger" role="alert">
                <?= icon('circle-exclamation', 'alert__icon icon', '', 18) ?>
                <div class="alert__body">
                    <?php foreach ($errors as $error): ?>
                        <p class="alert__text"><?= h($error) ?></p>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($step === 'database'): ?>
            <form class="form-card" method="post" action="<?= h($action) ?>">
              <div class="form-card__section">
                <?= Csrf::field() ?>
                <input type="hidden" name="step" value="database">
                <p class="form-help form-help--lead">The database the site keeps its content in. Your host's control panel makes one, with a user that may use it.</p>

                <div class="field">
                    <label class="label" for="db_host">Host</label>
                    <input class="input" type="text" id="db_host" name="db_host" value="<?= h($form['db_host']) ?>"
                           autocapitalize="none" spellcheck="false" required>
                    <p class="form-help">On most hosts, localhost.</p>
                </div>
                <div class="field">
                    <label class="label" for="db_name">Database name</label>
                    <input class="input" type="text" id="db_name" name="db_name" value="<?= h($form['db_name']) ?>"
                           autocapitalize="none" spellcheck="false" required autofocus>
                </div>
                <div class="field">
                    <label class="label" for="db_user">User</label>
                    <input class="input" type="text" id="db_user" name="db_user" value="<?= h($form['db_user']) ?>"
                           autocomplete="off" autocapitalize="none" spellcheck="false" required>
                </div>
                <div class="field">
                    <label class="label" for="db_pass">Password</label>
                    <input class="input" type="password" id="db_pass" name="db_pass" autocomplete="off">
                </div>

                <div class="form-actions form-actions--bare">
                    <div class="form-actions__spacer"></div>
                    <button class="btn btn--primary" type="submit">Continue</button>
                </div>
              </div>
            </form>

        <?php elseif ($step === 'site'): ?>
            <form class="form-card" method="post" action="<?= h($action) ?>">
              <div class="form-card__section">
                <?= Csrf::field() ?>
                <input type="hidden" name="step" value="site">

                <div class="field">
                    <label class="label" for="title">Site title</label>
                    <input class="input" type="text" id="title" name="title" value="<?= h($form['title']) ?>" required autofocus>
                </div>
                <div class="field">
                    <label class="label" for="address">Site address</label>
                    <input class="input" type="url" id="address" name="address" value="<?= h($form['address']) ?>"
                           autocapitalize="none" spellcheck="false" required>
                    <p class="form-help">The address visitors use, as this page was reached.</p>
                </div>
                <div class="field">
                    <label class="label" for="username">Your username</label>
                    <input class="input" type="text" id="username" name="username" value="<?= h($form['username']) ?>"
                           autocomplete="username" autocapitalize="none" spellcheck="false" required>
                </div>
                <div class="field">
                    <label class="label" for="email">Your email</label>
                    <input class="input" type="email" id="email" name="email" value="<?= h($form['email']) ?>"
                           autocomplete="email" required>
                </div>
                <div class="field">
                    <label class="label" for="password">Password</label>
                    <input class="input" type="password" id="password" name="password" autocomplete="new-password" required>
                    <p class="form-help">At least 12 characters, not one every script tries first, and not your own name or the site's.</p>
                </div>
                <div class="field">
                    <label class="label" for="password2">Password again</label>
                    <input class="input" type="password" id="password2" name="password2" autocomplete="new-password" required>
                </div>

                <div class="form-actions form-actions--bare">
                    <a class="link link--xs" href="<?= h($action . '?step=database') ?>">Another database</a>
                    <div class="form-actions__spacer"></div>
                    <button class="btn btn--primary" type="submit">Set up the site</button>
                </div>
              </div>
            </form>

        <?php else: ?>
            <div class="form-card">
              <div class="form-card__section">
                <?php if ($done['config'] === null): ?>
                    <p>The site is set up. Sign in as <strong><?= h($done['username']) ?></strong>, with the password you chose.</p>
                <?php else: ?>
                    <p>The site is set up, but the server would not let its settings be saved. Save this as <code>admin/config/config.php</code>, then sign in as <strong><?= h($done['username']) ?></strong>.</p>
                    <?php // Exactly as it must be saved: the mono face, every line whole. ?>
                    <textarea class="textarea textarea--tall input--mono" readonly rows="16" wrap="off" aria-label="config.php"><?= h($done['config']) ?></textarea>
                <?php endif; ?>
                <div class="form-actions form-actions--bare">
                    <div class="form-actions__spacer"></div>
                    <a class="btn btn--primary" href="<?= h($done['signin']) ?>">Sign in</a>
                </div>
              </div>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
