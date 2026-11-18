<?php

declare(strict_types=1);

use Cms\Content\Translation;
use Cms\Helpers\Url;
use Cms\Security\Csrf;
use Cms\Site\Language;

/**
 * ITS OTHER LANGUAGE — a page's or an entry's editor (`Content\Translation`).
 * On one in the site's own language: its version in the second language, to
 * open, or Translate to make one, or why it cannot have one. On a
 * translation: the original it translates. Not drawn on an original while the
 * site has no second language.
 *
 * Drawn twice, as the share panel is: `panel` in the side column, and `forms`
 * after the editor's own form, because a form may not sit inside another;
 * Translate names its form with `form=`.
 *
 * @var string               $trPart   'panel' | 'forms'
 * @var string               $trTable  'pages' | 'posts'
 * @var array<string, mixed> $trRow    the row as find() returns it
 * @var string               $trAdmin  the admin path of its kind: `pages`, or a type's admin slug
 */

$trLang   = (string) ($trRow['lang'] ?? '');
$trSecond = Language::prefix();
$trForm   = 'translate-' . $trTable . '-' . (int) $trRow['id'];
$trEdit   = static fn (array $row): string => Url::admin($trAdmin . '/' . (int) $row['id'] . '/edit');
$trState  = static fn (array $row): string => ucfirst((string) ($row['status'] ?? ''));
?>
<?php if ($trPart === 'panel'): ?>
    <?php if ($trLang !== '' || $trSecond !== ''): ?>
        <div class="form-card__section" id="translation">
            <?= panel_title('Translation', 'translation') ?>
            <?php if ($trLang === ''): ?>
                <?php
                $trOther = Translation::of($trTable, (int) $trRow['id'], $trSecond);
                $trWhy   = Translation::problem($trTable, $trRow);
                $trName  = Language::nameOf($trSecond);
                ?>
                <p class="form-help">Written in <?= h(Language::nameOf('')) ?>, the site's language.</p>
                <?php if ($trOther !== null): ?>
                    <p class="form-help">
                        <?= h($trName) ?> version:
                        <a class="link" href="<?= h($trEdit($trOther)) ?>"><?= h((string) $trOther['title']) ?></a>,
                        <?= h($trState($trOther)) ?>.
                    </p>
                <?php elseif ($trWhy === null): ?>
                    <?php
                    $trAt = $trTable === 'pages'
                        ? Url::pageOfPath(['slug' => $trRow['slug'], 'lang' => $trSecond])
                        : Url::entryPath(['post_type' => $trRow['post_type'], 'slug' => $trRow['slug'], 'lang' => $trSecond]);
                    ?>
                    <button type="submit" class="btn btn--sm" form="<?= h($trForm) ?>">Translate into <?= h($trName) ?></button>
                    <p class="form-help">Makes a Private copy at <?= h($trAt) ?>, with these words, picture and settings, to write in <?= h($trName) ?> and then publish.</p>
                <?php else: ?>
                    <p class="form-help"><?= h($trWhy) ?></p>
                <?php endif; ?>
            <?php else: ?>
                <?php $trOriginal = Translation::original($trTable, $trRow); ?>
                <p class="form-help">
                    <?php if ($trOriginal !== null): ?>
                        The <?= h(Language::nameOf($trLang)) ?> version of
                        <a class="link" href="<?= h($trEdit($trOriginal)) ?>"><?= h((string) $trOriginal['title']) ?></a>.
                    <?php else: ?>
                        In <?= h(Language::nameOf($trLang)) ?>. What it translated has been deleted.
                    <?php endif; ?>
                </p>
                <?php if ($trLang !== $trSecond): ?>
                    <p class="form-help"><?= h(Language::nameOf($trLang)) ?> is not the site's second language now, so this has no address until it is chosen again on Settings → General.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php elseif ($trLang === '' && $trSecond !== ''): ?>
    <form id="<?= h($trForm) ?>" method="post" action="<?= h(Url::admin($trAdmin . '/' . (int) $trRow['id'] . '/translate')) ?>" hidden>
        <?= Csrf::field() ?>
    </form>
<?php endif; ?>
