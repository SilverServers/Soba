<?php

declare(strict_types=1);

use Cms\Content\Share;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * SHARE A DRAFT — a page's or an entry's editor, for one that is not on the
 * site yet (`Content\Share`): a link that shows it to whoever has it, without
 * signing in, until the date chosen. With one working: the link to copy, its
 * date, and Stop sharing. Without: how long, and Make a link.
 *
 * Drawn twice: `panel` in the editor's side column, and `forms` after the
 * editor's own form, because a form may not sit inside another; the panel's
 * buttons name theirs with `form=`.
 *
 * @var string                    $sharePart   'panel' | 'forms'
 * @var string                    $shareType   'page' | 'post'
 * @var int                       $shareId
 * @var string                    $shareAdmin  the editor's admin path, `pages/12`
 * @var string                    $shareUrl    the page's own public address
 */

$shareNow  = Share::current($shareType, $shareId);
$shareForm = 'share-' . $shareType . '-' . $shareId;
?>
<?php if ($sharePart === 'panel'): ?>
    <div class="form-card__section" id="share">
        <?= panel_title('Share a draft', 'share-draft') ?>
        <?php if ($shareNow !== null): ?>
            <?php $shareLink = $shareUrl . '?' . Share::PARAM . '=' . $shareNow['code']; ?>
            <div class="field">
                <label class="label" for="f-share-link">Link</label>
                <div class="input-row">
                    <input class="input input--mono" type="text" id="f-share-link" value="<?= h($shareLink) ?>"
                           readonly spellcheck="false">
                    <button type="button" class="btn btn--sm" data-copy="#f-share-link">Copy</button>
                </div>
                <p class="form-help">
                    Anyone with it can read this without signing in until
                    <?= h(Dates::stamp($shareNow['expires_at'])) ?>.
                </p>
            </div>
            <button type="submit" class="btn btn--sm" form="<?= h($shareForm) ?>-stop">Stop sharing</button>
        <?php else: ?>
            <div class="field">
                <label class="label" for="f-share-days">Link works for</label>
                <select class="select" id="f-share-days" name="share_days" form="<?= h($shareForm) ?>">
                    <?php foreach (Share::DAYS as $days): ?>
                        <option value="<?= (int) $days ?>"<?= $days === Share::DEFAULT_DAYS ? ' selected' : '' ?>><?= (int) $days ?> day<?= $days === 1 ? '' : 's' ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="form-help">A link that shows it, as it is when opened, to anyone who has it, without signing in.</p>
            </div>
            <button type="submit" class="btn btn--sm" form="<?= h($shareForm) ?>">Make a link</button>
        <?php endif; ?>
    </div>
<?php else: ?>
    <form id="<?= h($shareForm) ?>" method="post" action="<?= h(Url::admin($shareAdmin . '/share')) ?>" hidden>
        <?= Csrf::field() ?>
    </form>
    <form id="<?= h($shareForm) ?>-stop" method="post" action="<?= h(Url::admin($shareAdmin . '/share/stop')) ?>" hidden>
        <?= Csrf::field() ?>
    </form>
<?php endif; ?>
