<?php

declare(strict_types=1);

use Cms\ContentType\Registry;
use Cms\ContentType\TaxonomyRegistry;
use Cms\Helpers\Url;
use Cms\Security\Csrf;
use Cms\Site\Language;
use Cms\Site\Words;

/**
 * SETTINGS → WORDS: the theme's own words (`Site\Words`), each with a box for
 * the site's own language and, once there is one, a box for its second. A box
 * shows the word that ships as its placeholder and holds only the owner's
 * own: left empty, the shipped word is used. For the second language, each
 * translated type's and taxonomy's name too, its own name standing in.
 *
 * @var array<string, string> $ownWords    the owner's words for the site's language
 * @var array<string, string> $secondWords the owner's words for the second language
 * @var bool                  $saved
 */

$ownName    = Language::ALL[Language::own()] ?? Language::own();
$secondTag  = Language::second();
$secondName = $secondTag !== '' ? (Language::ALL[$secondTag] ?? $secondTag) : '';
$shippedOwn = Words::shipped(false);
$shippedTwo = $secondTag !== '' ? Words::shipped(true) : [];
$fieldId    = static fn (string $which, string $key): string => 'f-word-' . $which . '-' . trim((string) preg_replace('/[^a-z0-9]+/', '-', $key), '-');
// One mark per section on this screen (`Ui\Sections`).
$marks = [
    'Lists and pages'         => 'words-lists',
    'Search'                  => 'words-search',
    'Not found and passwords' => 'words-not-found',
    'Menus and cookies'       => 'words-menus',
    'Opening hours'           => 'words-hours',
    'Months and days'         => 'words-dates',
];
// The names a page in the second language gives each translated type and taxonomy.
$names = [];
if ($secondTag !== '') {
    foreach (Registry::all() as $key => $type) {
        if (\Cms\Content\Translation::translates((string) $key) && (string) ($type['public_slug'] ?? '') !== '') {
            $names['type:' . $key] = (string) ($type['label'] ?? $key);
        }
    }
    foreach (TaxonomyRegistry::all() as $key => $taxonomy) {
        if (\Cms\Content\Translation::taxonomyTranslates((string) $key) && (string) ($taxonomy['public_slug'] ?? '') !== '') {
            $names['tax:' . $key] = (string) ($taxonomy['label'] ?? $key);
        }
    }
}
?>
<div class="page">
    <a class="page__back" href="<?= h(Url::admin('settings')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Settings
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Words</h1>
            <p class="page__subtitle">What the site's pages say that no page holds: their links, buttons, notices, months and days<?= $secondTag !== '' ? ', in ' . h($ownName) . ' and in ' . h($secondName) : '' ?>.</p>
        </div>
    </div>

    <?php if ($saved): ?>
        <div class="flashes">
            <div class="flash flash--success">Settings saved.</div>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= h(Url::admin('settings/words')) ?>">
        <?= Csrf::field() ?>

        <div class="form-main">
            <div class="form-card">
                <?php foreach (Words::GROUPS as $group => $words): ?>
                    <div class="form-card__section">
                        <?= panel_title($group, $marks[$group]) ?>
                        <div class="form-grid form-grid--12">
                            <?php foreach ($words as $key => [$english, $where]): ?>
                                <div class="field <?= $secondTag !== '' ? 'field--half' : '' ?>">
                                    <label class="label" for="<?= h($fieldId('own', $key)) ?>"><?= h($english) ?><?= $secondTag !== '' ? ' (' . h($ownName) . ')' : '' ?></label>
                                    <input class="input" type="text" id="<?= h($fieldId('own', $key)) ?>" name="own[<?= h($key) ?>]"
                                           value="<?= h($ownWords[$key] ?? '') ?>" placeholder="<?= h($shippedOwn[$key] ?? $english) ?>"
                                           autocomplete="off"<?= $where !== '' ? ' aria-describedby="' . h($fieldId('own', $key)) . '-where"' : '' ?>>
                                    <?php if ($where !== ''): ?>
                                        <p class="form-help" id="<?= h($fieldId('own', $key)) ?>-where"><?= h($where) ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php if ($secondTag !== ''): ?>
                                    <div class="field field--half">
                                        <label class="label" for="<?= h($fieldId('second', $key)) ?>"><?= h($english) ?> (<?= h($secondName) ?>)</label>
                                        <input class="input" type="text" id="<?= h($fieldId('second', $key)) ?>" name="second[<?= h($key) ?>]"
                                               value="<?= h($secondWords[$key] ?? '') ?>" placeholder="<?= h($shippedTwo[$key] ?? $english) ?>"
                                               autocomplete="off" lang="<?= h($secondTag) ?>">
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if ($names !== []): ?>
                    <div class="form-card__section">
                        <?= panel_title('Names in ' . $secondName, 'words-names') ?>
                        <p class="form-help">A list's heading and its trail on a page in <?= h($secondName) ?>; empty, the name it has on the site.</p>
                        <div class="form-grid form-grid--12">
                            <?php foreach ($names as $key => $own): ?>
                                <div class="field field--half">
                                    <label class="label" for="<?= h($fieldId('second', $key)) ?>"><?= h($own) ?></label>
                                    <input class="input" type="text" id="<?= h($fieldId('second', $key)) ?>" name="second[<?= h($key) ?>]"
                                           value="<?= h($secondWords[$key] ?? '') ?>" placeholder="<?= h($own) ?>"
                                           autocomplete="off" lang="<?= h($secondTag) ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="form-card__section form-actions">
                    <a class="link link--xs" href="<?= h(Url::admin('settings')) ?>">Cancel</a>
                    <div class="form-actions__spacer"></div>
                    <button type="submit" class="btn btn--primary">Save changes</button>
                </div>
            </div>
        </div>
    </form>
</div>
