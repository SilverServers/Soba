<?php

declare(strict_types=1);

use Cms\ContentType\ArchetypeDefaults;
use Cms\ContentType\Registry;
use Cms\Helpers\Url;
use Cms\Models\Post;
use Cms\Security\Csrf;
use Cms\Ui\Sidebar;

/**
 * CONTENT TYPES — every Content Type in the install, and what each one can do.
 *
 * > No Content Type may exist that the admin cannot fully edit and delete.
 * > A read-only entry in a management screen is a failure, not a state.
 *
 * ── THE TWO WAYS THIS SCREEN CAN LIE, AND WHAT STOPS EACH ───────────────────
 *
 * 1. SAYING THERE ARE NONE WHEN THERE ARE. Reading the `content_types` TABLE
 *    alone would tell an install whose types are registered in
 *    `config/content-types.php` that it had none — while the sidebar, the
 *    routes and the entry screens all work from those same types. Anything
 *    answering "what exists in this install?" asks the REGISTRY. The
 *    controller merges both, and this template renders the merged list.
 *
 * 2. SHOWING A ROW YOU CANNOT ACT ON. This template does NOT render one. Every
 *    row here carries the actions it genuinely has:
 *      · a database-defined type      Entries · Fields · Edit · Delete
 *      · a code-defined type          Entries · Fields
 *    …and where a code-defined type appears, the screen says WHERE it is
 *    defined, as a fact the admin can act on with a text editor, next to an
 *    `.alert` that names the file. That is not a "read-only category": it is a
 *    row whose management surface is a file, stated plainly, rather than a
 *    greyed-out Edit button that does nothing when clicked.
 *
 * ── WHAT CARRIES EACH FACT ──────────────────────────────────────────────────
 *   what kind of thing is this   the type's own registered glyph
 *   how much is in it            the entry count on the row, not a sentence
 *   is it switched on            the status chip — an inactive type keeps its
 *                                rows and its data, it just leaves the sidebar
 *   what can I do with it        the buttons that are there
 *
 * @var list<array<string, mixed>> $dbContentTypes    rows from `content_types`
 * @var array<string, array>       $codeContentTypes  Registry entries with no DB row
 * @var array<string, mixed>       $user
 */

$pageTitle = 'Content types';

// ONE list, assembled from both sources, so the screen answers the question its
// title asks. `$isManaged` is what decides a row's actions — never its source
// label, which is a fact ABOUT the row and not a permission.
$rows = [];

foreach ($dbContentTypes as $definition) {
    $rows[] = [
        'key'       => (string) $definition['type_key'],
        'label'     => (string) $definition['plural_label'],
        'single'    => (string) $definition['singular_label'],
        'admin'     => (string) $definition['admin_slug'],
        'icon'      => (string) $definition['menu_icon'],
        'archetype' => (array) $definition['archetypes'],
        'active'    => (bool) $definition['is_active'],
        'id'        => (int) $definition['id'],
        'managed'   => true,
    ];
}

foreach ($codeContentTypes as $contentType) {
    $rows[] = [
        'key'       => (string) $contentType['key'],
        'label'     => (string) $contentType['label'],
        'single'    => (string) $contentType['label_single'],
        'admin'     => (string) $contentType['admin_slug'],
        'icon'      => (string) $contentType['menu_icon'],
        'archetype' => (array) ($contentType['archetypes'] ?? []),
        'active'    => true,
        'id'        => 0,
        'managed'   => false,
    ];
}

usort($rows, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

$codeCount = count($codeContentTypes);

// NO ob_start() AND NO require layout.php HERE. ContentTypeDefinitionController
// buffers this file and requires the layout itself — the pattern layout.php's
// docblock documents. A template that also wrapped itself would render the whole
// shell TWICE, nested, at HTTP 200. See layout.php's guard.
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Content types</h1>
        </div>
        <div class="page__header-actions">
            <?php if ($rows !== []): ?>
                <a class="btn btn--primary" href="<?= h(Url::admin('content-types/new')) ?>">Add content type</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($codeCount > 0): ?>
        <?php // THE FACT AND THE ACT. `--info` is the alert SHAPE with no alert
              // COLOUR: a type defined in code is not a fault, and amber here
              // would make a normal, documented way of registering a type look
              // like a broken install.
              //
              // This exists so the rows below never have to be greyed out. The
              // admin is told WHERE those types live — which is something they
              // can act on — instead of being handed an Edit button that does
              // nothing. ?>
        <div class="alert alert--info">
            <?= icon('file', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__text">
                    <?= $codeCount === 1 ? 'One type is' : $codeCount . ' types are' ?>
                    registered in <code>config/content-types.php</code> and
                    <?= $codeCount === 1 ? 'is' : 'are' ?> edited there, not here.
                    Their entries, fields and taxonomies are managed normally.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <?php // The kind of place you are standing in, and the one action that
              // ends the emptiness. ?>
        <div class="empty">
            <?= icon('layer-group', 'empty__glyph', '', 28) ?>
            <p class="empty__title">No content types yet</p>
            <div class="empty__action">
                <a class="btn btn--primary" href="<?= h(Url::admin('content-types/new')) ?>">Add content type</a>
            </div>
        </div>
    <?php else: ?>
        <div class="list ct-list">
            <?php foreach ($rows as $row): ?>
                <?php
                // The entry count is the fact that makes this list scannable:
                // it turns "is anything using this type?" from a click into a
                // glance, which is the question you ask before deleting one.
                $count = Post::countOfType($row['key']);
                ?>
                <div class="list__row">
                    <div class="ct-list__main">
                        <?php // The type's OWN registered glyph, never derived
                              // from its label. ?>
                        <?= icon(Sidebar::iconFor($row['icon']), 'ct-list__glyph icon icon--lg') ?>
                        <div class="ct-list__names">
                            <?php // THE TITLE OPENS THE THING THIS SCREEN
                                  // MANAGES, not the type's ENTRIES. Entries are
                                  // where you want to go nine times out of ten
                                  // from the SIDEBAR — which already has a row
                                  // per type going exactly there — but not from
                                  // a management screen: clicking a content type
                                  // on the Content types page and landing in a
                                  // list of news items is the wrong door.
                                  //
                                  // A code-defined type has no editor, so its
                                  // title keeps the one destination it has. ?>
                            <a class="list__row-link list__row-title" href="<?= h($row['managed']
                                ? Url::admin('content-types/' . $row['id'] . '/edit')
                                : Url::admin($row['admin'])) ?>"><?= h($row['label']) ?></a>
                            <div class="list__row-sub">
                                <?= h($row['key']) ?> · /<?= h($row['admin']) ?>
                            </div>
                        </div>
                    </div>

                    <div class="ct-list__count">
                        <?php // A count, not a badge: the accent fill means "this
                              // element ACTS" and a number does not act. ?>
                        <?= count_of((int) $count, 'entry', 'entries') ?>
                    </div>

                    <?php // An INACTIVE type keeps every row it has; it just
                          // stops being registered, so it leaves the sidebar and
                          // its public routes stop resolving. That is a state
                          // worth showing and it is not an alarm — the neutral
                          // chip carries it. An active type shows nothing,
                          // because "working normally" is not news.
                          //
                          // NO ARCHETYPE CHIPS HERE. A type with two archetypes
                          // would stack two chips and make its row taller than
                          // every other row in the list. The kind belongs on the
                          // FORM, where it is the question being asked; on the
                          // list it buys nothing and costs an even row height. ?>
                    <div class="ct-list__state">
                        <?php if (!$row['active']): ?>
                            <span class="status status--archived">
                                <?= icon('toggle-off', 'status__icon icon', '', 12) ?>Inactive
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="list__row-actions">
                        <?php if ($row['managed']): ?>
                            <a class="btn btn--sm" href="<?= h(Url::admin('content-types/' . $row['id'] . '/edit')) ?>">Edit</a>
                            <?php // A destructive TRIGGER does not need to shout:
                                  // the confirm dialog it opens IS the alert
                                  // surface. Low-emphasis destructive is a plain
                                  // link with no colour at all. ?>
                            <form class="form-inline" method="post"
                                  action="<?= h(Url::admin('content-types/' . $row['id'] . '/delete')) ?>"
                                  data-confirm="Delete the <?= h($row['label']) ?> content type?"
                                  data-confirm-detail="<?= (int) $count ?> <?= $count === 1 ? 'entry stays' : 'entries stay' ?> in the database, but nothing in the admin will link to <?= $count === 1 ? 'it' : 'them' ?> any more."
                                  data-confirm-verb="Delete type">
                                <?= Csrf::field() ?>
                                <button type="submit" class="link link--xs">Delete</button>
                            </form>
                        <?php else: ?>
                            <?php // NOT a disabled Edit button. A control that
                                  // cannot act is a read-only row; the alert
                                  // above says where this type is
                                  // edited, and this row keeps only the action
                                  // it really has. ?>
                            <a class="btn btn--sm" href="<?= h(Url::admin($row['admin'])) ?>">Entries</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<script src="<?= h(\Cms\Helpers\Asset::admin('js/confirm.js')) ?>"></script>
