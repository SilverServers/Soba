<?php

declare(strict_types=1);

use Cms\ContentType\FieldRenderer;
use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\SEO\Head;
use Cms\Security\Csrf;
use Cms\Ui\Status;
use Cms\Ui\VariableEditor;

/**
 * ADD / EDIT A PAGE — the entry form's twin.
 *
 * Same two-column shape, same vocabulary, same rules. Four differences, all of
 * them because a page is not an entry:
 *
 *   PARENT       a page nests. The select is a real control here, not a
 *                taxonomy panel in disguise.
 *   ORDER        `menu_order` decides sibling order, so it is a number the
 *                admin sets rather than a date the system sets.
 *   SEO          pages own `seo_title` / `meta_description` as COLUMNS, where
 *                an entry keeps them in post_meta. Identical on screen; the
 *                difference is entirely below the form.
 *   NO FIELDS    pages have no Content Type, so no archetypes and no custom
 *                fields. There is no `$meta` here and nothing to render from
 *                `FieldRenderer` except the featured image picker.
 *
 *   HISTORY      as an entry's: `PageController` keeps the page as it was before
 *                each save (`Revision::snapshot()`), and the card lists the
 *                newest five, each with Restore.
 *
 * @var array<string, mixed>|null  $page           null on create
 * @var list<array<string, mixed>> $parentOptions  id + title, this page excluded
 * @var list<string>               $errors
 * @var bool                       $saved
 * @var array<string, mixed>       $user
 * @var list<array<string, mixed>> $revisions      newest first; none on create
 */

$isNew     = ($page ?? null) === null;
$id        = $isNew ? 0 : (int) $page['id'];
$errors    = $errors ?? [];
$saved     = $saved  ?? false;
$revisions = $revisions ?? [];

/**
 * What to show in a field: what was TYPED while the save has not happened, and
 * what is STORED once it has.
 *
 * ── THE `$saved` CONDITION IS NOT A DETAIL ─────────────────────────────────
 * Preferring `$_POST` is right on a FAILED save — an author whose slug clashed
 * must not lose the paragraph they wrote. It is wrong on a SUCCESSFUL one:
 *
 * `Html\Sanitizer` cleans content on the way into the database. Post
 * `<p>kept</p><script>alert(1)</script>`, and what is stored is `<p>kept</p>` —
 * but a screen re-rendered from `$_POST` would show the script still there.
 * The author would see markup the site will never serve, believe it saved, and
 * find out otherwise the next time they open the page.
 *
 * Once the record has been written it is the only truthful thing on the screen.
 */
$val = static function (string $key, mixed $fallback = '') use ($page, $saved): string {
    if (!$saved && isset($_POST[$key]) && !is_array($_POST[$key])) {
        return (string) $_POST[$key];
    }
    return (string) ($page[$key] ?? $fallback ?? '');
};

$status   = $val('status', 'private');
$parentId = (int) ($_POST['parent_id'] ?? $page['parent_id'] ?? 0);

$action = $isNew
    ? Url::admin('pages/new')
    : Url::admin('pages/' . $id . '/edit');

$pageTitle = $isNew ? 'Add page' : (string) $page['title'];

// EVERY STATUS BUT `trash` IS REACHABLE BY A SIGNED-IN ADMIN, which is who is
// reading this screen — see the long note in content-type/edit.php.
// `=== 'published'` would turn the address on a Private page into dead text.
$viewUrl = (!$isNew && (string) ($page['status'] ?? '') !== 'trash')
    ? Url::pageOf($page)
    : null;

ob_start();
?>
<?php // `--editor` IS WHAT TURNS ON THE FULL-HEIGHT CHAIN (admin.css §7b). It is a
      // page-level modifier and not a rule on `.form-main`, because the height
      // has to come from the page down; a class further in cannot invent one. ?>
<div class="page page--editor">

    <form method="post" action="<?= h($action) ?>" class="editor-form"<?= \Cms\Ui\KeepWriting::attributes('page:' . ($isNew ? 'new' : (int) $page['id']), $saved, $errors !== [], $isNew ? null : 'page:' . (int) $page['id']) ?>>
        <?= Csrf::field() ?>

        <?php // THE BACK LINK AND THE SAVE BUTTON SHARE ONE ROW, at the top —
              // and the row is inside the form, because the button submits it.
              // See partials/form-topbar.php. ?>
        <?php
        $backUrl   = Url::admin('pages');
        $backLabel = 'Pages';
        $saveLabel = $isNew ? 'Create page' : 'Save changes';
        require ROOT_PATH . '/admin/templates/partials/form-topbar.php';
        ?>

    <?php if ($saved): ?>
        <div class="flashes">
            <div class="flash flash--success">Page saved.</div>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert--danger">
            <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__title">
                    <?= count($errors) === 1 ? 'One thing needs fixing' : count($errors) . ' things need fixing' ?>
                </p>
                <ul class="alert__text">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <?php require ROOT_PATH . '/admin/templates/partials/keep-writing.php'; ?>

        <div class="panel__grid">

            <?php // ONE CARD, hairlines between its sections, footer inside it.
                  // The same shape as the entry form, from the same vocabulary —
                  // core.css §4. ?>
            <div class="form-main">

                                                                    <?php
                    // THE TITLE CARD, full width above the grid — the shared partial the
                    // entry form uses too. The title is the page's substance and the slug is
                    // a URL, not a field; see partials/title-card.php.
                    $titleLabel = 'Page title';
                    $titleValue = $val('title');
                    // Under its language's prefix, for a translation (/fr/).
                    $slugPrefix = rtrim(Url::public((string) ($page['lang'] ?? '')), '/') . '/';
                    $slugValue  = $val('slug');
                    // The address's own end: the preview is the address, so it
                    // ends the way the site's addresses do.
                    $slugSuffix = Url::trailingSlash() ? '/' : '';
                    // A LINK IF IT RESOLVES FOR THE PERSON READING THIS — one source
                    // for that, and it is $viewUrl. Computed twice, the two would
                    // drift, and the address would go dead on every Private page.
                    $slugIsLive = $viewUrl !== null;
                    // $viewUrl is already computed above; the partial puts the
                    // view-on-site control next to the address it opens.
                    require ROOT_PATH . '/admin/templates/partials/title-card.php';
                    ?>

                <div class="form-card">


                    <?php // `--fill` HANDS THE LEFTOVER HEIGHT DOWN to the
                          // textarea — see admin.css §7b. It marks the ONE
                          // section on the form that should absorb the space;
                          // put it on two and they fight over it. ?>
                    <div class="form-card__section form-card__section--fill">
                        <?php // THE MIDDLE RUNG. Without it the main column would
                              // run from a 26px page title straight to a 12px
                              // field label, while the ASIDE has 15px headings on
                              // every section — the secondary column better
                              // structured than the primary one. Title and slug
                              // are in the title card above; this section is the
                              // page's body. ?>
                        <?php // THE HEADING STAYS, AND IT IS A SECTION HEADING.
                              // It stays because a label is not a caption — it is
                              // what tells you the box is yours to type in, and
                              // without one the field reads as an empty broken
                              // box.
                              //
                              // It is drawn as a section heading rather than a
                              // 12px `.label`, as on the entry form: every panel
                              // on this screen carries a heading band, and as a
                              // `.label` this would be the one section drawn a
                              // rung below its peers. The two screens are the
                              // same screen with one column of difference; they
                              // cannot disagree about this.
                              //
                              // The control keeps its accessible name through
                              // `aria-label` rather than a second visible copy of
                              // the word. ?>
                        <?= panel_title('Content', 'body') ?>
                        <div class="field">
                            <?php // THE RICH-TEXT EDITOR BINDS HERE. `editor.js` hides this
                                  // textarea and puts a toolbar and a
                                  // contenteditable surface beside it — but THE
                                  // TEXTAREA IS STILL WHAT POSTS, so with
                                  // JavaScript off, or if that file fails to
                                  // load, this is a plain textarea that works.
                                  //
                                  // The editor is authored here, with no
                                  // third-party dependency.
                                  //
                                  // The GUARANTEE is `Html\Sanitizer`, applied
                                  // in `Post::create/update`, not by the editor
                                  // — content from an import or a hand-made
                                  // POST is cleaned the same way.
                                  //
                                  // No `rows`: .textarea--tall in core.css §4
                                  // sizes it, so the height is a stylesheet fact
                                  // rather than a line count in six templates. ?>
                            <textarea class="textarea textarea--tall" id="f-content" name="content"
                                      aria-label="Content"
                                      placeholder="Write here&hellip;" data-editor><?= h($val('content')) ?></textarea>
                        </div>
                    </div>

                    <?php // NO FOOTER. Cancel and Save are in the top bar; a
                          // second copy at the bottom of a full-height body
                          // field is two primary buttons on one screen. ?>

                </div>
            </div>

            <div class="form-aside">
                <div class="form-card">

                    <div class="form-card__section">
                        <div class="panel-head">
                            <?= panel_title('Visibility', 'visibility') ?>
                            <?php if (!$isNew): ?>
                                <?= Status::render(Status::forEntry($page)) ?>
                            <?php endif; ?>
                        </div>

                        <div class="field">
                            <label class="label" for="f-status">Status</label>
                            <select class="select" id="f-status" name="status" data-status-select>
                                <?php foreach (Status::labels() as $key => $statusLabel): ?>
                                    <?php if ($key === 'trash') { continue; } ?>
                                    <option value="<?= h($key) ?>"<?= $status === $key ? ' selected' : '' ?>>
                                        <?= h($statusLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php // ALL THREE, each as the public controller
                                  // treats it. Published: reachable. Private:
                                  // PageController 404s it unless `Auth::check()`
                                  // passes, which is any signed-in account and not
                                  // "staff only". Password: the same controller
                                  // asks for the password and remembers it for
                                  // the session. Hidden and Private are one state
                                  // — see `Ui\Status` and migration 043. ?>
                            <p class="form-help">
                                Published is reachable by anyone. Private makes the address
                                404 for everyone except a signed-in account, so you can
                                read it back before it goes out. Password asks any visitor
                                for the password below.
                            </p>
                        </div>

                        <div class="field" data-password-field<?= $status === 'password' ? '' : ' hidden' ?>>
                            <label class="label" for="f-post-password">Password</label>
                            <input class="input" type="text" id="f-post-password" name="post_password"
                                   autocomplete="off"<?= $status === 'password' ? '' : ' disabled' ?>
                                   placeholder="<?= h(!$isNew && !empty($page['password_hash']) ? 'Unchanged' : '') ?>">
                        </div>

                        <?php // ── WHEN IT COMES OFF ────────────────────────────
                              // An entry's Unpublish date, the same control and
                              // conversions (`Dates::toInput()`/`fromInput()`):
                              // empty keeps the page on the site, a date takes it
                              // off then (`Page::live()`), as Private would. ?>
                        <?php
                        $unpublishLocal = (!$saved && isset($_POST['unpublish_local']) && !is_array($_POST['unpublish_local']))
                            ? (string) $_POST['unpublish_local']
                            : \Cms\Helpers\Dates::toInput($isNew ? null : ($page['unpublish_at'] ?? null));
                        ?>
                        <div class="field">
                            <label class="label" for="f-unpublish">Unpublish date</label>
                            <input class="input" type="datetime-local" id="f-unpublish" name="unpublish_local"
                                   value="<?= h($unpublishLocal) ?>">
                            <p class="form-help">
                                Empty keeps it on the site; a date takes it off then.
                                Site time: <?= h(\Cms\Helpers\Dates::zone()->getName()) ?>.
                            </p>
                        </div>
                    </div>

                    <?php // A PAGE NOT ON THE SITE, SHARED BY A LINK
                          // (`Content\Share`): Private, or its unpublish date
                          // come. A live page needs none. ?>
                    <?php
                    $shareable = !$isNew && $viewUrl !== null
                        && ((string) ($page['status'] ?? '') === 'private' || \Cms\Models\Post::hasEnded($page));
                    ?>
                    <?php if ($shareable): ?>
                        <?php [$sharePart, $shareType, $shareId, $shareAdmin, $shareUrl] = ['panel', 'page', $id, 'pages/' . $id, (string) $viewUrl]; ?>
                        <?php require ROOT_PATH . '/admin/templates/partials/share-draft.php'; ?>
                    <?php endif; ?>

                    <div class="form-card__section">
                        <?= panel_title('Placement', 'group-structure') ?>

                        <div class="field">
                            <label class="label" for="f-parent">Parent</label>
                            <?php // "None" and not an empty option: a blank box does
                                  // not distinguish "no parent" from "there are no
                                  // pages to choose". A top-level page is a real
                                  // choice, so it gets a real label. ?>
                            <select class="select" id="f-parent" name="parent_id">
                                <option value="">None</option>
                                <?php foreach ($parentOptions as $option): ?>
                                    <option value="<?= (int) $option['id'] ?>"<?= $parentId === (int) $option['id'] ? ' selected' : '' ?>>
                                        <?= h((string) $option['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label class="label" for="f-menu-order">Order</label>
                            <input class="input" type="number" id="f-menu-order" name="menu_order"
                                   value="<?= h($val('menu_order', '0')) ?>" min="0" step="1">
                            <?php // What the number DOES, in the terms the admin
                                  // will see it act — not "integer, ascending". ?>
                            <p class="form-help">Lower numbers come first among pages with the same parent.</p>
                        </div>
                    </div>

                    <?php if (!$isNew): ?>
                        <?php [$trPart, $trTable, $trRow, $trAdmin] = ['panel', 'pages', $page, 'pages']; ?>
                        <?php require ROOT_PATH . '/admin/templates/partials/translation.php'; ?>
                    <?php endif; ?>

                    <div class="form-card__section">
                        <?= panel_title('Featured image', 'media') ?>
                        <div class="field">
                            <?= FieldRenderer::renderImage('featured_image_id', (int) ($page['featured_image_id'] ?? 0)) ?>
                            <?php // NO HINT. The picker DOES something on both
                                  // forms (PageController::show sets og_image,
                                  // Sitemap::pages emits <image>), and a control
                                  // that works does not need a note saying so. ?>
                        </div>
                    </div>

                    <div class="form-card__section">
                        <?= panel_title('Search engines', 'seo') ?>

                        <?php // GUIDANCE. "Title" inside a card called "Search
                              // engines" does not tell a site owner that it
                              // overrides the browser tab, or how long it should
                              // be. A length that gets truncated in search
                              // results is a thing you can only find out by
                              // being told. ?>
                        <?php // PILLS (`variable-editor.js`), the same editor as
                              // Settings → SEO's patterns. The title box STARTS
                              // FROM the Pages pattern; saved still saying exactly
                              // that, it is stored as none and goes on following
                              // the pattern (`Head::ownTitle()`).
                              //
                              // NO maxlength ON EITHER. It would count the stored
                              // text, and `{site_name}` is eleven characters of it
                              // whatever the site is called — the limit would say
                              // nothing about the title a search engine sees. ?>
                        <?php $ownTitle = trim($val('seo_title')); ?>
                        <div class="field" <?= VariableEditor::attrs('page') ?>>
                            <label class="label" for="f-seo-title">Title</label>
                            <input class="input" type="text" id="f-seo-title" name="seo_title"
                                   value="<?= h($ownTitle !== '' ? $ownTitle : Head::titlePattern('page')) ?>">
                            <p class="form-help">
                                The browser tab and search result heading. Starts as the Pages
                                pattern (Settings → SEO); change it and this page keeps its own.
                                Around 50–60 characters.
                            </p>
                        </div>

                        <div class="field" <?= VariableEditor::attrs('page') ?>>
                            <label class="label" for="f-meta-description">Description</label>
                            <textarea class="textarea" id="f-meta-description" name="meta_description"
                                      rows="3"
                                      placeholder="A sentence describing this page."><?= h($val('meta_description')) ?></textarea>
                            <p class="form-help">
                                The grey text under the heading in search results.
                                Around 120–155 characters.
                            </p>
                        </div>
                    </div>

                    <?php if (!$isNew && $revisions !== []): ?>
                        <?php // THE ENTRY FORM'S HISTORY CARD, the same rows: each
                              // is its own POST, by `form=`, because a restore is a
                              // write and a form cannot sit inside this one. ?>
                        <div class="form-card__section">
                            <?= panel_title('History', 'history') ?>
                            <div class="list list--bleed">
                                <?php foreach (array_slice($revisions, 0, 5) as $revision): ?>
                                    <div class="list__row">
                                        <div class="entry-list__main">
                                            <div class="list__row-title"><?= h((string) $revision['title']) ?></div>
                                            <div class="list__row-sub">
                                                <time datetime="<?= h(date('c', strtotime((string) $revision['created_at']))) ?>">
                                                    <?= h(\Cms\Helpers\Dates::stamp((string) $revision['created_at'])) ?>
                                                </time>
                                            </div>
                                        </div>
                                        <div class="list__row-actions">
                                            <button type="submit" class="btn btn--sm"
                                                    form="restore-<?= (int) $revision['id'] ?>">Restore</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!$isNew): ?>
                        <?php // A PLAIN TEXT LINK at the foot of the panel, not a
                              // red-bordered card with an outline button. A
                              // destructive TRIGGER does not shout — the confirm
                              // dialog it opens IS the alert surface, and a
                              // low-emphasis destructive action is a plain
                              // `.link .link--xs` with no colour at all.
                              //
                              // INSIDE the panel, as its last section, not floating
                              // under the card with nothing containing it. ?>
                        <div class="form-card__section form-aside__danger">
                        <button type="submit" class="link link--xs" form="trash-page">Move to trash</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </form>

    <?php if (!$isNew): ?>
        <?php // OUTSIDE the form above, targeted by `form=`. HTML has no nested
              // forms, and a trash posted by the save form would carry every
              // field with it. ?>
        <form id="trash-page" method="post"
              action="<?= h(Url::admin('pages/' . $id . '/trash')) ?>" hidden>
            <?= Csrf::field() ?>
        </form>
        <?php $trPart = 'forms'; ?>
        <?php require ROOT_PATH . '/admin/templates/partials/translation.php'; ?>
        <?php if (!empty($shareable)): ?>
            <?php $sharePart = 'forms'; ?>
            <?php require ROOT_PATH . '/admin/templates/partials/share-draft.php'; ?>
        <?php endif; ?>

        <?php foreach (array_slice($revisions, 0, 5) as $revision): ?>
            <form id="restore-<?= (int) $revision['id'] ?>" method="post"
                  action="<?= h(Url::admin('revisions/' . (int) $revision['id'] . '/restore')) ?>" hidden>
                <?= Csrf::field() ?>
            </form>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<script src="<?= h(Asset::admin('js/permalink.js')) ?>"></script>
<script src="<?= h(Asset::admin('js/entry-form.js')) ?>"></script>
<?php // The Search engines panel's pills: the template once, reorder.js for the
      // drag's mirror and line, then the editor. ?>
<?= VariableEditor::template() ?>
<script src="<?= h(Asset::admin('js/reorder.js')) ?>"></script>
<script src="<?= h(Asset::admin('js/variable-editor.js')) ?>" defer></script>
<?php // WITHOUT THIS THE TEXTAREA IS JUST A TEXTAREA — which is the
      // documented degraded state, not a fault. Linked on the screens that
      // have a `[data-editor]` box and nowhere else. ?>
<?= \Cms\Ui\Editor::iconTemplate() ?>
<?php // BEFORE editor.js, which calls `Prompt.ask()`. Both are deferred, so
      // they execute in document order and the global exists by the time the
      // editor binds. ?>
<script src="<?= h(\Cms\Helpers\Asset::admin('js/prompt.js')) ?>" defer></script>
<script src="<?= h(\Cms\Helpers\Asset::admin('js/editor.js')) ?>" defer></script>
<?php // AFTER editor.js, deferred too, so the editor has bound its box before
      // this reads the writing (`Ui\KeepWriting`). ?>
<script src="<?= h(\Cms\Helpers\Asset::admin('js/keep-writing.js')) ?>" defer></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
