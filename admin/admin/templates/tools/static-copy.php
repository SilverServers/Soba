<?php

declare(strict_types=1);

use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * TOOLS → STATIC COPY (`Controllers\Admin\StaticCopyController`).
 *
 * The newest copy first — going, with how far it has got, or done, with what
 * it holds, what went wrong and its download — then the form for a new one.
 * While a copy is being made the form is not drawn: one thing at a time.
 *
 * @var string|null                $cannot  why this server cannot make one here, or null
 * @var array<string, mixed>|null  $copy    the newest copy's status, with its id
 * @var array{address: string, no_php: bool, instead: string} $form
 * @var string|null                $refused why the last start was refused
 */

$going = $copy !== null && $copy['phase'] !== 'done';
$size  = static function (int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB'];
    $i = -1;
    do {
        $bytes /= 1024;
        $i++;
    } while ($bytes >= 1024 && $i < count($units) - 1);
    return round($bytes, $bytes < 10 ? 1 : 0) . ' ' . $units[$i];
};

ob_start();
?>
<div class="page">

    <a class="page__back" href="<?= h(Url::admin('tools')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Tools
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Static copy</h1>
        </div>
    </div>

    <div class="form-main">
        <div class="form-card">

            <?php if ($copy !== null): ?>
                <div class="form-card__section">
                    <?= panel_title($going ? 'Making the copy' : 'The copy', 'static-copy-file') ?>

                    <?php if ($going): ?>
                        <?php // HOW FAR, AS A BAR: what is done of all it knows of
                              // so far. The crawl finds pages as it goes, so the
                              // bar can slow; the figures under it are exact. ?>
                        <?php $known = max(1, (int) $copy['pages'] + (int) $copy['files'] + (int) $copy['queued']); ?>
                        <div class="static-copy__going"
                             data-static-copy-step="<?= h(Url::admin('tools/static-copy/' . rawurlencode((string) $copy['id']) . '/step')) ?>">
                            <div class="progress-bar">
                                <div class="progress-bar__fill" data-static-copy-bar
                                     style="width: <?= (int) round(100 * ((int) $copy['pages'] + (int) $copy['files']) / $known) ?>%"></div>
                            </div>
                            <div class="kv-list">
                                <div class="kv-list__row">
                                    <span class="kv-list__key">For</span>
                                    <span class="kv-list__val"><?= h((string) $copy['address']) ?></span>
                                </div>
                                <div class="kv-list__row">
                                    <span class="kv-list__key">Pages</span>
                                    <span class="kv-list__val kv-list__val--num" data-static-copy="pages"><?= (int) $copy['pages'] ?></span>
                                </div>
                                <div class="kv-list__row">
                                    <span class="kv-list__key">Files</span>
                                    <span class="kv-list__val kv-list__val--num" data-static-copy="files"><?= (int) $copy['files'] ?></span>
                                </div>
                                <div class="kv-list__row">
                                    <span class="kv-list__key">Still to do</span>
                                    <span class="kv-list__val kv-list__val--num" data-static-copy="queued"><?= (int) $copy['queued'] ?></span>
                                </div>
                            </div>
                            <div class="alert alert--danger" data-static-copy-error hidden>
                                <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
                                <div class="alert__body">
                                    <p class="alert__title">The copy stopped</p>
                                    <p class="alert__text" data-static-copy-error-text></p>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php
                        $errors  = array_values(array_filter($copy['problems'], static fn (array $p): bool => $p['level'] === 'error'));
                        $notes   = array_values(array_filter($copy['problems'], static fn (array $p): bool => $p['level'] !== 'error'));
                        $archive = is_string($copy['archive']) && is_file($copy['archive']) ? $copy['archive'] : null;
                        ?>
                        <div class="kv-list">
                            <div class="kv-list__row">
                                <span class="kv-list__key">Made</span>
                                <span class="kv-list__val"><?= h(\Cms\Helpers\Dates::stamp((int) $copy['finished'])) ?></span>
                            </div>
                            <div class="kv-list__row">
                                <span class="kv-list__key">For</span>
                                <span class="kv-list__val"><?= h((string) $copy['address']) ?></span>
                            </div>
                            <div class="kv-list__row">
                                <span class="kv-list__key">Links</span>
                                <span class="kv-list__val"><?= $copy['anywhere'] ? 'Relative: at any address, or opened from a folder' : 'To this address' ?></span>
                            </div>
                            <div class="kv-list__row">
                                <span class="kv-list__key">Forms</span>
                                <span class="kv-list__val"><?= $copy['php'] ? 'Sent by the copy\'s own handler, which needs PHP' : 'Shown as words: the host runs no PHP' ?></span>
                            </div>
                            <div class="kv-list__row">
                                <span class="kv-list__key">Pages</span>
                                <span class="kv-list__val kv-list__val--num"><?= (int) $copy['pages'] ?></span>
                            </div>
                            <div class="kv-list__row">
                                <span class="kv-list__key">Files</span>
                                <span class="kv-list__val kv-list__val--num"><?= (int) $copy['files'] ?></span>
                            </div>
                        </div>

                        <?php if ($errors !== []): ?>
                            <?php // WHAT FAILED, ON ITS OWN SURFACE: a page that did
                                  // not draw, or drew with an error in the log, is
                                  // not in the copy as the site has it. ?>
                            <div class="alert alert--danger">
                                <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
                                <div class="alert__body">
                                    <p class="alert__title"><?= count($errors) === 1 ? 'One page is not as the site has it' : count($errors) . ' pages are not as the site has them' ?></p>
                                    <?php foreach ($errors as $p): ?>
                                        <p class="alert__text"><code><?= h((string) $p['at']) ?></code> <?= h((string) $p['what']) ?></p>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($notes !== []): ?>
                            <ul class="static-copy__notes">
                                <?php foreach ($notes as $p): ?>
                                    <li class="form-help"><code><?= h((string) $p['at']) ?></code> <?= h((string) $p['what']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if ($archive !== null): ?>
                            <div class="form-actions form-actions--bare">
                                <a class="btn btn--primary" href="<?= h(Url::admin('tools/static-copy/' . rawurlencode((string) $copy['id']) . '/download')) ?>" download>
                                    <?= icon('download', 'icon icon--sm') ?>Download <?= h(basename($archive)) ?> (<?= h($size((int) filesize($archive))) ?>)
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!$going): ?>
                <div class="form-card__section">
                    <?= panel_title($copy !== null ? 'Make a new copy' : 'Make a copy', 'static-copy-make') ?>

                    <?php if ($cannot !== null): ?>
                        <?php // NO BUTTON THAT COULD ONLY FAIL. The command does
                              // the same, from the server's own command line. ?>
                        <div class="alert alert--info">
                            <?= icon('circle-info', 'alert__icon icon', '', 18) ?>
                            <div class="alert__body">
                                <p class="alert__title">Make the copy from the server's command line</p>
                                <p class="alert__text"><?= h($cannot) ?></p>
                                <p class="alert__text"><code>php admin/bin/export-static.php <?= h($form['address']) ?></code></p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php if ($refused !== null): ?>
                            <div class="alert alert--danger">
                                <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
                                <div class="alert__body">
                                    <p class="alert__title">The copy was not started</p>
                                    <p class="alert__text"><?= h($refused) ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php // THE FIELDS ARE THE SECTION'S OWN CHILDREN, each tied
                              // to the form by `form=`. A section holding a writing
                              // surface takes the recess around it (admin.css,
                              // `:has(> .field > .textarea)`), and a <form> between
                              // the section and its fields would hide them from that
                              // rule: a white box on a white section. ?>
                        <form id="static-copy-form" method="post" action="<?= h(Url::admin('tools/static-copy')) ?>"><?= Csrf::field() ?></form>

                        <div class="field">
                            <label class="label" for="f-copy-address">Address it will be published at</label>
                            <input class="input input--mono" type="url" id="f-copy-address" name="address" form="static-copy-form"
                                   value="<?= h($form['address']) ?>" required spellcheck="false" autocomplete="off">
                            <p class="form-help">Every link, canonical and sitemap entry in the copy names it.</p>
                        </div>

                        <div class="field">
                            <label class="check-list__row" for="f-copy-anywhere">
                                <input type="checkbox" id="f-copy-anywhere" name="anywhere" value="1" form="static-copy-form"<?= $form['anywhere'] ? ' checked' : '' ?>>
                                <span>Links that work anywhere</span>
                            </label>
                            <p class="form-help">
                                At any address, or opened from a folder with no web server.
                                A page's link names its file, <code>news/index.html</code>;
                                canonicals and the sitemap still name the address above.
                            </p>
                        </div>

                        <div class="field">
                            <label class="check-list__row" for="f-copy-no-php">
                                <input type="checkbox" id="f-copy-no-php" name="no_php" value="1" form="static-copy-form"<?= $form['no_php'] ? ' checked' : '' ?>>
                                <span>The host runs no PHP</span>
                            </label>
                            <p class="form-help">
                                With PHP, the copy sends its forms itself and mails each
                                answer as the site does. Without it, no form can be sent.
                            </p>
                        </div>

                        <div class="field">
                            <label class="label" for="f-copy-instead">In place of a form the copy cannot send</label>
                            <textarea class="textarea" id="f-copy-instead" name="instead" rows="2" form="static-copy-form"
                                      maxlength="500" placeholder="Email us at hello@example.org"><?= h($form['instead']) ?></textarea>
                            <p class="form-help">
                                Every form, on a host without PHP; on any host, one that
                                books appointments or names nobody to send to.
                            </p>
                        </div>

                        <div class="form-actions form-actions--bare">
                            <button type="submit" class="btn btn--primary" form="static-copy-form">Make the copy</button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>
    </div>

</div>
<?php if ($going): ?>
    <script src="<?= h(\Cms\Helpers\Asset::admin('js/static-copy.js')) ?>"></script>
<?php endif; ?>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
