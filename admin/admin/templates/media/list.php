<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Security\Csrf;
use Cms\Models\Media;

/**
 * FILES AND MEDIA — the library.
 *
 * ── WHAT THIS SCREEN IS NOT ─────────────────────────────────────────────────
 * It is NOT a second media picker. `entry-form.js` already owns a picker dialog
 * with its own `.media-grid`, and that grid is reused here rather than restyled
 * — same class, same tile, same aspect ratio. The picker is for CHOOSING one
 * file for a field; this is for MANAGING the library. The only visual addition
 * is a caption under each tile, because a filename is the thing you manage by
 * and the picker deliberately does not need it.
 *
 * ── THE FILTER IS A REAL FORM, NOT THE INSTANT IDIOM ─────────────────────────
 * `pages/list.php` filters in the browser with no Apply button, and says in its
 * own comment that this is honest ONLY because `Page::all()` has no pagination
 * — every row is already in the document. `Media::all()` pages at 40. Filtering
 * the DOM here would search the current page and silently report "no files
 * match" while the match sits on page 3. So: a GET form, the server filters, and
 * the URL carries the state (which also makes a filtered library linkable).
 *
 * ── DOCUMENTS GET A GLYPH, NOT A BROKEN <img> ───────────────────────────────
 * A PDF has no thumbnail. Pointing an <img> at the file itself renders the
 * browser's broken-image mark, which reads as a damaged upload. The tile shows
 * the KIND instead: an empty slot shows what goes in it.
 *
 * @var list<array<string, mixed>> $attachments  the current page of files
 * @var int                        $total        matching rows, all pages
 * @var int                        $totalPages
 * @var int                        $page         1-based
 * @var list<array<string, mixed>> $folders      top-level, with file_count
 * @var string|null                $type         'image' | 'document' | null
 * @var int|null                   $folderId     0 = unfiled, null = any
 * @var string|null                $search
 * @var bool                       $isEditorPlus
 * @var array<string, mixed>       $user
 */

$pageTitle = 'Files and media';

/** Rebuild this screen's URL with one filter changed, keeping the rest. */
$filterUrl = static function (array $changes) use ($type, $folderId, $search): string {
    $q = [
        'type'      => $type,
        'folder_id' => $folderId,
        'search'    => $search,
    ];
    foreach ($changes as $k => $v) {
        $q[$k] = $v;
    }
    // `page` is deliberately NOT carried: changing a filter changes the result
    // set, and landing on page 3 of a different set shows an empty screen that
    // looks like "no files" rather than "you are past the end".
    $q = array_filter($q, static fn ($v) => $v !== null && $v !== '');
    return Url::admin('media') . ($q === [] ? '' : '?' . http_build_query($q));
};

/** 1024-based, because the OS the file came from reported it that way. */
$readableSize = static function (int $bytes): string {
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

$isFiltered = $type !== null || $folderId !== null || ($search ?? '') !== '';

ob_start();
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Files and media</h1>
        </div>

        <div class="page__header-actions">
            <?php // THE UPLOAD IS A LABEL WRAPPING A FILE INPUT, which is the
                  // same device the picker dialog uses. A bare <input type=file>
                  // renders the browser's own grey "Choose file / no file
                  // selected" control — a third button style on a screen that
                  // already has two, and one no stylesheet can reach. ?>
            <?php // ── AND A FOLDER CAN BE MADE ────────────────────────────
                  // The select in the filter bar FILTERS by folder; this is
                  // what adds an option to it.
                  //
                  // IN THE HEADER, NOT THE FILTER BAR. That bar is hidden on an
                  // empty library, deliberately, because filters over nothing
                  // can only return nothing. A CREATE is not a filter, and a
                  // fresh install is exactly when somebody sets their folders
                  // up.
                  //
                  // A plain form: every other create in this admin works with no
                  // script, and the JSON endpoint stays for the media dialogs. ?>
            <?php if ($isEditorPlus): ?>
                <form method="post" action="<?= h(Url::admin('media/folders/new')) ?>"
                      class="folder-add">
                    <?= Csrf::field() ?>
                    <input class="input input--sm" type="text" name="folder_name"
                           placeholder="New folder&hellip;" autocomplete="off"
                           aria-label="Name for a new folder" maxlength="100">
                    <button type="submit" class="btn btn--sm">Add folder</button>
                </form>

                <label class="btn btn--primary" for="media-upload">
                    Add files
                    <input type="file" id="media-upload" multiple hidden
                           data-media-upload>
                </label>
            <?php endif; ?>
        </div>
    </div>

    <?php // ── THESE TWO SIT ABOVE THE FILTER BAR, NOT INSIDE IT ─────────────
          // Inside it is the one place they could not be seen: the bar is
          // hidden over an empty library, and an empty library is exactly when
          // somebody is setting their folders up — a message about a folder
          // would be invisible on the screen where the folder was made. ?>
    <?php if (!empty($folderMade)): ?>
        <?php // WHAT JUST HAPPENED, on a screen that would otherwise look
              // unchanged: over an empty library the folder list is not
              // drawn at all, so without this an owner creates a folder and
              // gets back the page they left. ?>
        <div class="flashes">
            <div class="flash flash--success">
                Folder &ldquo;<?= h((string) $folderMade) ?>&rdquo; created.
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($folderError)): ?>
        <?php // WHERE THE PERSON IS LOOKING. A refusal about a folder name
              // belongs beside the folder controls, not on a page of its own
              // that loses the list it is talking about.
              //
              // `alert--compact`, which the admin already has for exactly
              // this size of message, rather than a new class that no
              // stylesheet styles. ?>
        <div class="alert alert--danger alert--compact">
            <?= icon('triangle-exclamation', 'alert__icon icon', '', 15) ?>
            <div class="alert__body">
                <p class="alert__text"><?= h((string) $folderError) ?></p>
            </div>
        </div>
    <?php endif; ?>


    <?php // ── THE FOLDERS THEMSELVES ──────────────────────────────────────
          // Not only options in the filter select, which is hidden over an
          // empty library — or a folder could be made and then seen nowhere.
          // A row the admin can create and never remove is a failure, not a
          // state: whatever the admin can create, it can also delete.
          //
          // A ROW OF CHIPS, not a screen: a folder is a name and a count. Each
          // one filters the library, and carries its own delete. ?>
    <?php if ($folders !== []): ?>
        <div class="folder-bar">
            <span class="folder-bar__label">Folders</span>
            <?php foreach ($folders as $folder): ?>
                <span class="folder-bar__item">
                    <a class="folder-bar__link"
                       href="<?= h(Url::admin('media') . '?folder_id=' . (int) $folder['id']) ?>">
                        <?= h((string) $folder['name']) ?>
                        <span class="folder-bar__count"><?= (int) $folder['file_count'] ?></span>
                    </a>
                    <?php if ($isEditorPlus): ?>
                        <?php // THE CONFIRM SAYS WHAT SURVIVES. "Delete folder"
                              // reads like it takes the pictures with it, and
                              // `attachments.folder_id` is ON DELETE SET NULL —
                              // the files are unfiled, not destroyed. ?>
                        <form class="form-inline" method="post"
                              action="<?= h(Url::admin('media/folders/' . (int) $folder['id'] . '/delete')) ?>"
                              data-confirm="Delete this folder?"
                              data-confirm-detail="<?= h((string) $folder['name']) ?> will go. The <?= (int) $folder['file_count'] ?> file<?= (int) $folder['file_count'] === 1 ? '' : 's' ?> in it stay in the library, unfiled."
                              data-confirm-verb="Delete folder">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link link--xs">Delete</button>
                        </form>
                    <?php endif; ?>
                </span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php // NO FILTER CONTROLS OVER AN EMPTY LIBRARY. On a fresh install, a
          // search box, an Apply button and three type tabs above the words
          // "No files yet" would be four controls that can only ever return
          // nothing — the same fault the pager below avoids by not drawing
          // "Page 1 of 1".
          //
          // The test is `$total === 0 && !$isFiltered`, NOT `$attachments ===
          // []`: once a filter IS active the bar must stay, or the only way back
          // from an empty result is the browser's Back button. ?>
    <?php if ($total > 0 || $isFiltered): ?>

    <?php // THE TYPE TABS AND THE FILTER BAR ARE ONE FORM'S WORTH OF STATE but
          // two controls, because a type is a place you go and a search is
          // something you type. Tabs are links so they are back-button-able and
          // linkable; the rest submits. ?>
    <div class="filterbar">
        <div class="filterbar__group">
            <form method="get" action="<?= h(Url::admin('media')) ?>" class="filterbar__group">
                <?php if ($type !== null): ?>
                    <input type="hidden" name="type" value="<?= h($type) ?>">
                <?php endif; ?>

                <input class="input input--search" type="search" name="search"
                       value="<?= h((string) ($search ?? '')) ?>"
                       placeholder="Search file names&hellip;"
                       aria-label="Search files by name">

                <?php if ($folders !== []): ?>
                    <select class="select" name="folder_id" aria-label="Filter by folder">
                        <option value="">Any folder</option>
                        <option value="0"<?= $folderId === 0 ? ' selected' : '' ?>>Unfiled</option>
                        <?php foreach ($folders as $folder): ?>
                            <option value="<?= (int) $folder['id'] ?>"
                                <?= $folderId === (int) $folder['id'] ? ' selected' : '' ?>>
                                <?= h((string) $folder['name']) ?> (<?= (int) $folder['file_count'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <?php // AN APPLY BUTTON, because this form actually submits.
                      // The instant idiom next door has none and must not grow
                      // one; this one must have one, or the select is a control
                      // that does nothing until you press Enter in a text box
                      // somewhere else. ?>
                <button type="submit" class="btn">Filter</button>
            </form>

        </div>

        <div class="filterbar__actions">
            <span class="filterbar__label">
                <?= (int) $total ?> file<?= $total === 1 ? '' : 's' ?>
            </span>
            <?php if ($isFiltered): ?>
                <a class="filterbar__reset" href="<?= h(Url::admin('media')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="tabs">
        <?php
        $tabs = [
            ''         => 'All',
            'image'    => 'Images',
            'document' => 'Documents',
        ];
        foreach ($tabs as $key => $label):
            $isOn = ($type ?? '') === $key;
            ?>
            <a class="tabs__tab<?= $isOn ? ' is-active' : '' ?>"
               <?= $isOn ? 'aria-current="page"' : '' ?>
               href="<?= h($filterUrl(['type' => $key === '' ? null : $key])) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </div>

    <?php endif; // $total > 0 || $isFiltered ?>

    <?php if ($attachments === []): ?>
        <?php // TWO DIFFERENT EMPTY STATES. "No files yet" on an empty library
              // and "nothing matches" on a filtered one are different facts, and
              // showing the first while a filter is active tells the owner their
              // library is empty when it is not. ?>
        <div class="empty">
            <?php if ($isFiltered): ?>
                <?= icon('magnifying-glass', 'empty__glyph', '', 28) ?>
                <p class="empty__title">No files match that filter</p>
                <div class="empty__action">
                    <a class="btn" href="<?= h(Url::admin('media')) ?>">Clear the filter</a>
                </div>
            <?php else: ?>
                <?= icon('image', 'empty__glyph', '', 28) ?>
                <p class="empty__title">No files yet</p>
                <?php if ($isEditorPlus): ?>
                    <div class="empty__action">
                        <label class="btn btn--primary" for="media-upload-empty">
                            Add files
                            <input type="file" id="media-upload-empty" multiple hidden
                                   data-media-upload>
                        </label>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php // ── GRID + DETAIL PANEL ──────────────────────────────────────
              // Clicking a tile opens its details: the screen for the update,
              // replace, versions and delete the API carries.
              //
              // EACH TILE CARRIES ITS OWN PAYLOAD as JSON on the element. The
              // panel is filled from that rather than from a fetch, because the
              // server has already sent every value the panel shows — a second
              // round trip to re-read what is on screen is latency bought with
              // nothing. ?>
        <div class="media-layout" data-media-layout>
        <div class="media-layout__grid">
        <div class="media-grid media-grid--library" data-media-grid>
            <?php foreach ($attachments as $file): ?>
                <?php
                $isImage = str_starts_with((string) $file['mime_type'], 'image/');
                $url     = Media::getUrl((int) $file['id']);
                $name    = (string) ($file['original_name'] ?: $file['filename']);
                $payload = [
                    'id'         => (int) $file['id'],
                    'url'        => $url,
                    'name'       => $name,
                    'mime'       => (string) $file['mime_type'],
                    'size'       => $readableSize((int) $file['file_size']),
                    'width'      => $file['width']  !== null ? (int) $file['width']  : null,
                    'height'     => $file['height'] !== null ? (int) $file['height'] : null,
                    'alt'        => (string) ($file['alt_text'] ?? ''),
                    'folder_id'  => isset($file['folder_id']) ? (int) $file['folder_id'] : null,
                    'visibility' => (string) ($file['visibility'] ?? 'public'),
                    'is_image'   => $isImage,
                    'uploaded'   => Dates::short((string) $file['created_at']),
                ];
                ?>
                <figure class="media-card" tabindex="0" role="button"
                        aria-label="<?= h($name) ?> — open details"
                        data-media-item data-id="<?= (int) $file['id'] ?>"
                        data-payload="<?= h((string) json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
                    <div class="media-grid__item media-card__thumb">
                        <?php if ($isImage && $url !== ''): ?>
                            <?php // `alt` IS THE STORED ALT TEXT OR EMPTY — never
                                  // the filename. A screen reader announcing
                                  // "IMG_4021.jpg" is noise standing in for a
                                  // description; empty alt on a tile whose
                                  // caption already says the name is correct. ?>
                            <img src="<?= h($url) ?>" alt="<?= h((string) ($file['alt_text'] ?? '')) ?>"
                                 loading="lazy" width="<?= (int) ($file['width'] ?: 0) ?>"
                                 height="<?= (int) ($file['height'] ?: 0) ?>">
                        <?php else: ?>
                            <span class="media-card__kind">
                                <?= icon(str_contains((string) $file['mime_type'], 'pdf') ? 'file-pdf' : 'file', 'icon', '', 24) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <figcaption class="media-card__meta">
                        <span class="media-card__name" title="<?= h($name) ?>"><?= h($name) ?></span>
                        <span class="media-card__size">
                            <?= h($readableSize((int) $file['file_size'])) ?><?php
                            if ($isImage && $file['width'] && $file['height']): ?>
                                · <?= (int) $file['width'] ?>×<?= (int) $file['height'] ?>
                            <?php endif; ?>
                        </span>
                    </figcaption>
                </figure>
            <?php endforeach; ?>
        </div>

        <?php // PAGINATION IS RENDERED ONLY WHEN THERE IS MORE THAN ONE PAGE.
              // A lone "1" with dead arrows either side is chrome that reports
              // its own irrelevance. ?>
        <?php if ($totalPages > 1): ?>
            <nav class="pager" aria-label="Media pages">
                <?php
                $pageUrl = static function (int $n) use ($filterUrl): string {
                    $base = $filterUrl([]);
                    return $base . (str_contains($base, '?') ? '&' : '?') . 'page=' . $n;
                };
                ?>
                <?php if ($page > 1): ?>
                    <a class="btn btn--sm" href="<?= h($pageUrl($page - 1)) ?>">Previous</a>
                <?php endif; ?>

                <span class="pager__status">Page <?= (int) $page ?> of <?= (int) $totalPages ?></span>

                <?php if ($page < $totalPages): ?>
                    <a class="btn btn--sm" href="<?= h($pageUrl($page + 1)) ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
        </div><?php // .media-layout__grid ?>

        <?php // THE DETAIL VIEW. Server-rendered SHELL, filled by the script
              // from the selected tile's payload — the same rule the repeater and
              // the form builder follow: the markup for a shape lives in the
              // template that owns the vocabulary, so there is never a second
              // renderer to drift from this one.
              //
              // ── IT IS A FULL-SIZE MODAL, NOT A 320px SIDEBAR ─────────────
              // A modal hides the grid you are picking from, and that is not the
              // trade it looks like: the thing you came to this screen to do is
              // LOOK AT A FILE, and a 320px gutter shows a postage stamp of it
              // beside a column of controls squeezed to fit — the grid stays on
              // screen and the file, the actual subject, does not.
              //
              // The answer to "you cannot see the grid" is PREVIOUS and NEXT in
              // the header, so you move between files without closing.
              // You do not need the grid visible if you can step through it.
              //
              // Native <dialog> + showModal(): the focus trap and Esc-to-close
              // come free and must not be hand-rolled (core.css §17). ?>
        <dialog class="modal modal--media" data-media-detail
                aria-label="File details">
          <div class="media-detail">
            <div class="media-detail__head">
                <h2 class="media-detail__title" data-md-name></h2>
                <?php // STEP THROUGH THE LIBRARY WITHOUT LEAVING. Disabled at the
                      // ends rather than wrapping — a silent jump from the last
                      // file back to the first reads as a glitch, not a feature. ?>
                <div class="media-detail__nav">
                    <button type="button" class="icon-btn" data-md-prev
                            data-tip="Previous file" aria-label="Previous file">
                        <?= icon('angle-left', 'icon icon--sm') ?>
                    </button>
                    <button type="button" class="icon-btn" data-md-next
                            data-tip="Next file" aria-label="Next file">
                        <?= icon('angle-right', 'icon icon--sm') ?>
                    </button>
                    <button type="button" class="icon-btn" data-md-close
                            data-tip="Close" aria-label="Close details">
                        <?= icon('x', 'icon icon--sm') ?>
                    </button>
                </div>
            </div>

            <?php // TWO COLUMNS: the file, then everything you can say about it.
                  // The preview takes the room because it is the subject. ?>
            <div class="media-detail__body">
            <div class="media-detail__preview" data-md-preview></div>

            <div class="media-detail__side">
            <?php // THE FIELDS SCROLL; THE VERBS DO NOT. Everything a person can
                  // change is taller than the column on a short window, and with
                  // the whole side scrolling "Save changes" would sit below the
                  // fold — clipped by the modal's own bottom edge. The primary action of
                  // a panel is not something to go looking for. ?>
            <div class="media-detail__scroll">
            <dl class="kv-list media-detail__facts">
                <div class="kv-list__row">
                    <dt class="kv-list__key">Type</dt>
                    <dd class="kv-list__val" data-md-mime></dd>
                </div>
                <div class="kv-list__row">
                    <dt class="kv-list__key">Size</dt>
                    <dd class="kv-list__val" data-md-size></dd>
                </div>
                <div class="kv-list__row">
                    <dt class="kv-list__key">Uploaded</dt>
                    <dd class="kv-list__val" data-md-uploaded></dd>
                </div>
            </dl>

            <?php // ── THREE HEADED SECTIONS, NOT ONE RUN OF SIX CONTROLS ────
                  // Title, Alt text, Folder, Visibility and File address in a
                  // single column with nothing between them but a gap would be
                  // text running into text with no hierarchy. They are three
                  // different questions — what this file is
                  // CALLED, where it is FILED, and where it LIVES — so they are
                  // three sections.
                  //
                  // `.form-card__section` and `panel_title()` are the entry
                  // form's own machinery, reused rather than reimplemented, so
                  // these headings take the same band from the same rule and
                  // cannot drift from the rest of the admin. ?>
            <div class="media-detail__fields">
                <div class="form-card__section">
                <?= panel_title('Details', 'names') ?>
                <div class="field">
                    <label class="label" for="md-title">Title</label>
                    <input class="input" type="text" id="md-title" data-md-title>
                </div>

                <div class="field" data-md-alt-field>
                    <label class="label" for="md-alt">Alt text</label>
                    <input class="input" type="text" id="md-alt" data-md-alt
                           placeholder="What is in this image?">
                    <?php // A CLAIM ABOUT BEHAVIOUR, AND IT HOLDS: the
                          // gallery shortcode renders `alt_text` and falls back
                          // to `original_name`, and the library tile renders it
                          // or an empty string. ?>
                    <p class="form-help">
                        Read out by screen readers, and used by the gallery
                        shortcode. Leave empty for a decorative image.
                    </p>
                </div>

                </div>

                <div class="form-card__section">
                <?= panel_title('Filing', 'placement') ?>
                <?php if ($folders !== []): ?>
                    <div class="field">
                        <label class="label" for="md-folder">Folder</label>
                        <select class="select" id="md-folder" data-md-folder>
                            <option value="">Unfiled</option>
                            <?php foreach ($folders as $folder): ?>
                                <option value="<?= (int) $folder['id'] ?>">
                                    <?= h((string) $folder['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label class="label" for="md-visibility">Visibility</label>
                    <select class="select" id="md-visibility" data-md-visibility>
                        <option value="public">Public</option>
                        <option value="private">Private</option>
                    </select>
                    <p class="form-help">
                        A private file is served through <code>/files/{id}</code>
                        and only to someone signed in.
                    </p>
                </div>

                </div>

                <div class="form-card__section">
                <?= panel_title('Address', 'address') ?>
                <div class="field">
                    <?php // The label is the heading here — one control in the
                          // section, and the heading already names it. A second
                          // copy of the word would be a duplicate label. ?>
                    <div class="input-row">
                        <input class="input input--mono" type="text" id="md-url"
                               data-md-url readonly spellcheck="false"
                               aria-label="File address">
                        <button type="button" class="btn btn--sm" data-md-copy>Copy</button>
                    </div>
                </div>
                </div>
            </div>

            </div><?php // .media-detail__scroll ?>

            <div class="media-detail__foot">
            <p class="media-detail__msg" data-md-msg hidden></p>

            <div class="media-detail__actions">
                <button type="button" class="btn btn--primary btn--sm" data-md-save>
                    Save changes
                </button>
                <?php // REPLACE KEEPS THE ID AND THE ADDRESS. Every page already
                      // pointing at this file keeps working, and the old file
                      // becomes a `MediaVersion` row rather than being lost. ?>
                <button type="button" class="btn btn--sm" data-md-replace>Replace file</button>
                <input type="file" data-md-replace-input hidden>
            </div>

            <?php // DESTRUCTIVE ACTION IS A PLAIN TEXT LINK, not a panel with a
                  // button in it — as everywhere else in this admin. ?>
            <button type="button" class="link link--xs" data-md-delete
                    data-confirm="Delete this file permanently?"
                    data-confirm-detail="It is removed from disk. Anything embedding it will break. This cannot be undone."
                    data-confirm-verb="Delete">Delete file</button>
            </div><?php // .media-detail__foot ?>
            </div><?php // .media-detail__side ?>
            </div><?php // .media-detail__body ?>
          </div><?php // .media-detail ?>
        </dialog>

        </div><?php // .media-layout ?>
    <?php endif; ?>

</div>

<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
<?php if ($isEditorPlus): ?>
    <script src="<?= h(Asset::admin('js/media-library.js')) ?>"></script>
<?php endif; ?>
<script src="<?= h(Asset::admin('js/media-detail.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
