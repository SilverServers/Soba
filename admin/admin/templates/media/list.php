<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
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
 * ── ONE CONTROL PER FILTER, AND THE ADDRESS CARRIES THEM ALL ────────────────
 *   which folder   the chips (All, Unfiled, then each folder)
 *   which kind     the tabs (All, Images, Documents)
 *   which name     the search box
 *   which order    Sort by (`Media::SORTS`)
 * Every link on the screen keeps the rest: a chip keeps the tab, the search and
 * the order; a tab keeps the folder; the pager keeps everything. The folder
 * select the filter bar used to carry is gone: it was a second control for the
 * chips' filter, and the two did not show each other's state.
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
 * @var array{total: int, unfiled: int} $tally   the whole library's counts
 * @var string|null                $type         'image' | 'document' | null
 * @var int|null                   $folderId     0 = unfiled, null = any
 * @var string|null                $search
 * @var string                     $sort         a key of `Media::SORTS`
 * @var bool                       $isEditorPlus
 * @var array<string, mixed>       $user
 */

$pageTitle = 'Files and media';

/** Rebuild this screen's URL with one filter changed, keeping the rest. */
$filterUrl = static function (array $changes) use ($type, $folderId, $search, $sort): string {
    $q = [
        'type'      => $type,
        'folder_id' => $folderId,
        'search'    => $search,
        // The default order is the address with no `sort` at all.
        'sort'      => $sort === Media::DEFAULT_SORT ? null : $sort,
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
$isSorted   = $sort !== Media::DEFAULT_SORT;

// "Show all" leaves every filter and keeps the order; Clear puts everything
// back, the order too.
$showAllUrl = $filterUrl(['type' => null, 'folder_id' => null, 'search' => null]);

// ── WHAT THE GRID HOLDS, IN WORDS ─────────────────────────────────────────
// "24 files in Site pages", "3 unfiled images", "2 files matching “gala”":
// the chips, the tabs and the search box each show their own state, and this
// is the one line that says what they add up to.
$folderName = null;
foreach ($folders as $folder) {
    if ($folderId !== null && (int) $folder['id'] === $folderId) {
        $folderName = (string) $folder['name'];
    }
}
$nouns   = ['image' => ['image', 'images'], 'document' => ['document', 'documents']][$type ?? ''] ?? ['file', 'files'];
$summary = $total . ' ' . ($folderId === 0 ? 'unfiled ' : '') . ($total === 1 ? $nouns[0] : $nouns[1])
    . ($folderId !== null && $folderId > 0 ? ' in ' . ($folderName ?? 'a folder that is not there any more') : '')
    . (($search ?? '') !== '' ? ' matching “' . $search . '”' : '');

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
            <?php // ── FOLDERS ARE MANAGED ON THEIR OWN SCREEN ──────────────
                  // Made, renamed and deleted there (`media/folders.php`); the
                  // chips below only choose one. IN THE HEADER, NOT BY THE
                  // CHIPS: the chips are filters and are not drawn over an
                  // empty library, and a fresh install is exactly when
                  // somebody sets their folders up. ?>
            <?php if ($isEditorPlus): ?>
                <a class="btn" href="<?= h(Url::admin('media/folders')) ?>" data-manage-folders>
                    <?= icon('folder', 'icon icon--sm') ?>Manage folders
                </a>

                <label class="btn btn--primary" for="media-upload">
                    <span data-media-upload-text>Add files</span>
                    <input type="file" id="media-upload" multiple hidden
                           data-media-upload>
                </label>
            <?php endif; ?>
        </div>
    </div>

    <?php // NO FILTER CONTROLS OVER AN EMPTY LIBRARY. On a fresh install, a
          // search box, an Apply button, folder chips and three type tabs above
          // the words "No files yet" would be controls that can only ever
          // return nothing — the same fault the pager below avoids by not
          // drawing "Page 1 of 1".
          //
          // The test is `$total === 0 && !$isFiltered`, NOT `$attachments ===
          // []`: once a filter IS active the controls must stay, or the only
          // way back from an empty result is the browser's Back button. ?>
    <?php if ($total > 0 || $isFiltered): ?>

    <?php // ── THE FOLDER ROW CHOOSES; IT DOES NOT MANAGE ───────────────────
          // A chip is a name and a count, and a link: All first, Unfiled when
          // anything is unfiled (or when it is the one chosen), then each
          // folder. The chosen one wears the tint a chosen value wears (core.css
          // §9) and says so to a screen reader. Nothing on a chip deletes: the
          // chips used to carry a Delete each, the loudest thing in the row,
          // beside the act of choosing one. Deleting is on Manage folders, behind
          // the confirm that says the files stay.
          //
          // The counts are the whole library's, whatever the tab and the search:
          // a chip says how big its folder is, and the line over the grid says
          // what this view holds. ?>
    <?php if ($folders !== []): ?>
        <?php
        $chips = [['id' => null, 'name' => 'All', 'count' => (int) $tally['total']]];
        if ((int) $tally['unfiled'] > 0 || $folderId === 0) {
            $chips[] = ['id' => 0, 'name' => 'Unfiled', 'count' => (int) $tally['unfiled']];
        }
        foreach ($folders as $folder) {
            $chips[] = ['id' => (int) $folder['id'], 'name' => (string) $folder['name'], 'count' => (int) $folder['file_count']];
        }
        ?>
        <nav class="folder-bar" aria-label="Folders">
            <span class="folder-bar__label" aria-hidden="true">Folders</span>
            <?php foreach ($chips as $chip): ?>
                <?php $on = $chip['id'] === $folderId; ?>
                <a class="folder-bar__item<?= $on ? ' is-active' : '' ?>"
                   href="<?= h($filterUrl(['folder_id' => $chip['id']])) ?>"
                   <?= $on ? 'aria-current="page"' : '' ?>>
                    <span class="folder-bar__name"><?= h($chip['name']) ?></span>
                    <span class="folder-bar__count"><?= (int) $chip['count'] ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php // ── SEARCH AND ORDER ARE A FORM, BECAUSE THEY SUBMIT ───────────
          // `.filterbar--apply` (core.css §12): labelled controls and a Filter
          // button, as the customer and entry lists have. The folder and the
          // kind ride along hidden, so a search inside a folder stays in it.
          //
          // CLEAR puts everything back, the order too; it is drawn when the
          // order is not the default. Leaving the filters alone, keeping the
          // order, is "Show all" on the line over the grid. ?>
    <form class="filterbar filterbar--apply" method="get" action="<?= h(Url::admin('media')) ?>">
        <?php if ($type !== null): ?>
            <input type="hidden" name="type" value="<?= h($type) ?>">
        <?php endif; ?>
        <?php if ($folderId !== null): ?>
            <input type="hidden" name="folder_id" value="<?= (int) $folderId ?>">
        <?php endif; ?>

        <div class="filterbar__group">
            <label class="filterbar__field">
                <span class="filterbar__label">Search</span>
                <input class="input input--search" type="search" name="search"
                       value="<?= h((string) ($search ?? '')) ?>"
                       placeholder="File name">
            </label>
            <label class="filterbar__field">
                <span class="filterbar__label">Sort by</span>
                <select class="select" name="sort">
                    <?php foreach (Media::SORTS as $key => $words): ?>
                        <option value="<?= h($key) ?>"<?= $sort === $key ? ' selected' : '' ?>><?= h($words) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="filterbar__actions">
            <?php if ($isSorted): ?>
                <a class="filterbar__reset" href="<?= h(Url::admin('media')) ?>">Clear</a>
            <?php endif; ?>
            <?php // AN APPLY BUTTON, because this form actually submits: without
                  // it, Sort by would do nothing until Enter was pressed in the
                  // search box. ?>
            <button type="submit" class="btn">Filter</button>
        </div>
    </form>

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

    <?php // WHAT THIS VIEW HOLDS, and the one click out of every filter at
          // once. Not over an empty result, where the empty state says it. ?>
    <?php if ($attachments !== []): ?>
        <p class="media-results" data-media-results>
            <?= h($summary) ?><?php if ($isFiltered): ?><span aria-hidden="true"> · </span><a
                class="link" href="<?= h($showAllUrl) ?>" data-show-all>Show all</a><?php endif; ?>
        </p>
    <?php endif; ?>

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
                    <a class="btn" href="<?= h($showAllUrl) ?>">Show all files</a>
                </div>
            <?php else: ?>
                <?= icon('image', 'empty__glyph', '', 28) ?>
                <p class="empty__title">No files yet</p>
                <?php if ($isEditorPlus): ?>
                    <div class="empty__action">
                        <label class="btn btn--primary" for="media-upload-empty">
                            <span data-media-upload-text>Add files</span>
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
                    'file'       => (string) $file['filename'],
                    'is_image'   => $isImage,
                    // A FILE THE SITE ALREADY HAD, named where it is
                    // (`Media::register()`): no Replace, no Private, and
                    // removing it forgets it.
                    'site'       => Media::isSiteFile($file),
                    // THE TWO DATES, as the boxes take them (the site's time)
                    // and as a person reads them. Taken is empty where the
                    // file said nothing.
                    'added'      => Dates::toInput((string) $file['created_at']),
                    'taken'      => Dates::toInput(isset($file['taken_at']) ? (string) $file['taken_at'] : null),
                    'added_text' => Dates::short((string) $file['created_at']),
                    'taken_text' => Dates::short(isset($file['taken_at']) ? (string) $file['taken_at'] : null),
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
                <?php // AN EDITOR CHANGES THE DATES BELOW; anyone else reads
                      // them here. One fact, one place. ?>
                <?php if (!$isEditorPlus): ?>
                    <div class="kv-list__row">
                        <dt class="kv-list__key">Added</dt>
                        <dd class="kv-list__val" data-md-added-text></dd>
                    </div>
                    <div class="kv-list__row" data-md-taken-row>
                        <dt class="kv-list__key">Taken</dt>
                        <dd class="kv-list__val" data-md-taken-text></dd>
                    </div>
                <?php endif; ?>
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

                <?php // FILING AND DATES ARE AN EDITOR'S, as the API says: a
                      // folder or a visibility posted by an author is refused
                      // (403), so drawn for one they made Save fail on a
                      // change to the title. A control that is drawn works. ?>
                <?php if ($isEditorPlus): ?>
                <div class="form-card__section" data-md-filing>
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

                <div class="field" data-md-file-field>
                    <label class="label" for="md-file">File name</label>
                    <input class="input" type="text" id="md-file" data-md-file spellcheck="false" autocomplete="off">
                    <p class="form-help">
                        The file's own name in its address; its kind stays. Renamed,
                        every page that shows it follows, and the old address
                        sends visitors on.
                    </p>
                </div>

                <div class="field" data-md-visibility-field>
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

                <?php // THE TWO DATES THE LIBRARY ORDERS BY. Date added is when
                      // the file came in, or the date an import carried with
                      // it; Date taken is the photo's own, read from it on the
                      // way in. Both can be corrected here: an archive imported
                      // before imports kept their dates is put back in its
                      // place one file at a time, or all at once with
                      // `admin/bin/media-dates.php`. ?>
                <div class="form-card__section">
                <?= panel_title('Dates', 'dates-and-times') ?>
                <div class="field">
                    <label class="label" for="md-added">Date added</label>
                    <input class="input" type="datetime-local" id="md-added" data-md-added required>
                </div>
                <div class="field" data-md-taken-field>
                    <label class="label" for="md-taken">Date taken</label>
                    <input class="input" type="datetime-local" id="md-taken" data-md-taken>
                    <p class="form-help">From the photo itself, when its camera wrote one.</p>
                </div>
                </div>
                <?php endif; // $isEditorPlus ?>

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
                    <p class="form-help" data-md-site hidden>
                        The site's own file, kept where the site keeps it. The
                        library names it there and never moves or replaces it.
                    </p>
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
            <button type="button" class="link link--xs" data-md-delete="file"
                    data-confirm="Delete this file permanently?"
                    data-confirm-detail="It is removed from disk. Anything embedding it will break. This cannot be undone."
                    data-confirm-verb="Delete">Delete file</button>
            <?php // A FILE THE SITE ALREADY HAD is only forgotten
                  // (`Media::register()`): the same action, its own words. ?>
            <button type="button" class="link link--xs" data-md-delete="site" hidden
                    data-confirm="Remove this file from the library?"
                    data-confirm-detail="The file stays where the site keeps it, at the same address, and pages showing it keep working. The library stops listing it."
                    data-confirm-verb="Remove">Remove from library</button>
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
