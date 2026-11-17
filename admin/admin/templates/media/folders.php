<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Models\MediaFolder;
use Cms\Security\Csrf;

/**
 * FILES AND MEDIA → MANAGE FOLDERS.
 *
 * ── CHOOSING A FOLDER AND MANAGING ONE ARE TWO PLACES ───────────────────────
 * The library's folder row chooses: each chip a name and a count, a filter and
 * nothing else. It used to carry a Delete on every chip, the loudest thing in
 * the row each time anyone opened the library, and nothing else a folder could
 * be given: no rename. Making, renaming and deleting a folder happen here, on
 * a screen reached on purpose from the library's header, the way a site's
 * categories have a screen of their own.
 *
 * ── WHAT CARRIES EACH FACT ──────────────────────────────────────────────────
 *   what is it called      the name, in its own box: changing the box and
 *                          pressing Rename is the rename
 *   what is in it          the count, a link to the library showing exactly
 *                          those files
 *   what does deleting it cost   the confirm says it: the folder goes, and
 *                          its files stay in the library, unfiled
 *                          (`attachments.folder_id` is ON DELETE SET NULL)
 *
 * Forms and links throughout, so all of it works with no script; the confirm
 * is `confirm.js` over a plain form, as on every delete in this admin.
 *
 * @var list<array<string, mixed>>                              $folders  with file_count
 * @var string|null                                             $said     what the last action did
 * @var array{id: ?int, name: string, message: string}|null     $refused  why it was refused, and what was typed
 */

$pageTitle = 'Folders';

// The box a refusal is about: the new folder's (id null) or one folder's.
$refusedId = $refused !== null && $refused['name'] !== '' ? $refused['id'] : false;

ob_start();
?>
<div class="page">

    <?php // ABOVE `.page__header`, not inside `.page__header-main` — that element
          // is a flex ROW, so the link would render beside the title. ?>
    <a class="page__back" href="<?= h(Url::admin('media')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Files and media
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Folders</h1>
        </div>
        <div class="page__header-actions">
            <?php // A ROW THAT DOES NOT WRAP, the box sized for a folder's name
                  // (`.folder-add`). A refused name comes back in the box, so
                  // it can be corrected rather than typed again. ?>
            <form method="post" action="<?= h(Url::admin('media/folders/new')) ?>" class="folder-add">
                <?= Csrf::field() ?>
                <input class="input input--sm" type="text" name="folder_name"
                       value="<?= h($refusedId === null ? $refused['name'] : '') ?>"
                       placeholder="New folder&hellip;" autocomplete="off" required
                       aria-label="Name for a new folder" maxlength="<?= MediaFolder::NAME_MAX ?>"
                       <?= $refusedId === null ? 'aria-invalid="true" aria-describedby="folder-refused"' : '' ?>>
                <button type="submit" class="btn btn--sm btn--primary">Add folder</button>
            </form>
        </div>
    </div>

    <?php if ($said !== null): ?>
        <div class="flashes">
            <div class="flash flash--success" data-folders-said><?= h($said) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($refused !== null): ?>
        <?php // NAMED, not "already exists": the owner is looking at the list
              // while reading this. The box it is about says so too. ?>
        <div class="alert alert--danger alert--compact" id="folder-refused" data-folders-refused>
            <?= icon('triangle-exclamation', 'alert__icon icon', '', 15) ?>
            <div class="alert__body">
                <p class="alert__text"><?= h($refused['message']) ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($folders === []): ?>
        <div class="empty">
            <?= icon('folder', 'empty__glyph', '', 28) ?>
            <p class="empty__title">No folders yet</p>
            <p class="empty__text">
                A folder groups files in the library. A file is in one folder,
                or in none.
            </p>
        </div>
    <?php else: ?>
        <div class="list folder-list">
            <div class="list__row entry-list__head">
                <div>Name</div>
                <div class="folder-list__count">Files</div>
                <div aria-hidden="true"></div>
            </div>
            <?php foreach ($folders as $folder): ?>
                <?php
                $id    = (int) $folder['id'];
                $name  = (string) $folder['name'];
                $count = (int) $folder['file_count'];
                $mine  = $refusedId === $id;
                ?>
                <div class="list__row" data-folder-row="<?= $id ?>">
                    <?php // RENAME IS THE NAME'S OWN BOX. Change it and press
                          // Rename; the same rules as a new folder's name
                          // (`MediaFolder::refusal()`), its own name excepted. ?>
                    <form class="folder-list__rename" method="post"
                          action="<?= h(Url::admin('media/folders/' . $id . '/rename')) ?>">
                        <?= Csrf::field() ?>
                        <input class="input input--sm" type="text" name="folder_name"
                               value="<?= h($mine ? $refused['name'] : $name) ?>"
                               autocomplete="off" required maxlength="<?= MediaFolder::NAME_MAX ?>"
                               aria-label="Name of the folder <?= h($name) ?>"
                               <?= $mine ? 'aria-invalid="true" aria-describedby="folder-refused"' : '' ?>>
                        <button type="submit" class="btn btn--sm">Rename</button>
                    </form>

                    <div class="folder-list__count">
                        <?php // THE COUNT IS A WAY IN: the library, showing exactly
                              // the files it counts. ?>
                        <a href="<?= h(Url::admin('media') . '?folder_id=' . $id) ?>"><?= count_of($count, 'file') ?></a>
                    </div>

                    <div class="list__row-actions">
                        <?php // A DESTRUCTIVE TRIGGER DOES NOT NEED TO SHOUT: the
                              // confirm it opens is the alert surface, and it says
                              // what survives, because "delete folder" reads as if
                              // it takes the pictures with it. ?>
                        <form class="form-inline" method="post"
                              action="<?= h(Url::admin('media/folders/' . $id . '/delete')) ?>"
                              data-confirm="Delete this folder?"
                              data-confirm-detail="<?= h($name) ?> will go. <?= $count === 0
                                  ? 'There are no files in it.'
                                  : 'The ' . ($count === 1 ? 'file in it stays' : $count . ' files in it stay') . ' in the library, unfiled.' ?>"
                              data-confirm-verb="Delete folder">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link link--xs">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
