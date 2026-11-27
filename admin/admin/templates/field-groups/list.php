<?php

declare(strict_types=1);

use Cms\ContentType\Registry;
use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Security\Csrf;
use Cms\Ui\Sidebar;

/**
 * FIELD GROUPS — the list.
 *
 * A field group is a named set of fields attached to one or more Content Types
 * by a location rule. It is the ONE mechanism for content with no home: when
 * the assistant or the owner cannot reach some content, the question is which
 * existing mechanism should hold it, and this is that mechanism. Do not add
 * another store because a field group felt like a poor fit.
 *
 * An archetype is the other way a Content Type's fields arrive — the type form
 * ticks `Post`/`Event`/`Listing`/`Product` and the controller generates a
 * group. That is a starting shape, not a schema editor.
 *
 * ── THE TWO SOURCES ─────────────────────────────────────────────────────────
 * Fields also come from `config/content-types.php`, embedded in a
 * `Registry::register()` call. Those are NOT field groups — they have no row in
 * `field_groups`, no id, and nothing here can edit or delete them.
 *
 * So they are NOT rendered as rows: a read-only entry in a management screen is
 * a failure and not a state, and "Defined in code" as a greyed row is exactly
 * that. But the screen must not lie in the other direction either — the trap
 * `content-types/list.php` also avoids: an install whose fields are in code
 * would be told it has none.
 *
 * The resolution is the one that screen uses, rather than a second answer: an
 * `.alert--info` states the fact and names the file, so the admin is told where
 * those fields are edited — something they can act on — instead of being
 * handed a control that does nothing.
 *
 * ── WHAT CARRIES EACH FACT ──────────────────────────────────────────────────
 *   what is in this group        the field count, on the row
 *   where does it apply          the Content Types named on the row, with their
 *                                own registered glyphs — never the raw key
 *   applies to nothing           said on the row, because a group attached to no
 *                                type renders nowhere and looks like a bug
 *   is it switched on            the status chip. An inactive group keeps its
 *                                fields and its stored values; it just stops
 *                                being merged into the entry form
 *   where did it come from       the archetype marker, for a group the type form
 *                                generated rather than a person
 *
 * NO ob_start() AND NO require layout.php HERE. FieldGroupController buffers this
 * file and requires the layout itself — the second of the two conventions
 * layout.php documents. A template that also wrapped itself would render the
 * whole shell TWICE, nested, at HTTP 200.
 *
 * @var list<array<string, mixed>> $groups      rows from `field_groups`
 * @var list<array<string, mixed>> $codeGroups  Content Types carrying code fields
 */

$pageTitle = 'Field groups';

// The Content Type keys a group points at are stored as JSON. Resolve each one
// through the REGISTRY rather than printing the raw key: the key is an
// identifier, and "event" is not what the owner named their Events. A key with
// no registered type is shown as itself — that is a real state (a group left
// behind by a deleted type) and hiding it would make the group look attached to
// nothing when it is attached to something gone.
$resolve = static function (string $key): array {
    // A SITE'S SETTINGS PAGE (`Site\SettingsPages`), named as its sidebar row names it.
    $settingsKey = \Cms\Site\SettingsPages::fromLocation($key);
    if ($settingsKey !== null) {
        $settingsPage = \Cms\Site\SettingsPages::get($settingsKey);
        return $settingsPage === null
            ? ['label' => $settingsKey, 'icon' => null, 'admin' => null, 'gone' => true, 'what' => 'settings page']
            : ['label' => (string) $settingsPage['label'], 'icon' => $settingsPage['icon'] !== '' ? (string) $settingsPage['icon'] : 'settings',
               'admin' => null, 'gone' => false, 'what' => 'settings page'];
    }
    // A TAXONOMY'S TERMS (`ContentType\TermFields`), named as the taxonomy is.
    $taxonomyKey = \Cms\ContentType\TermFields::fromLocation($key);
    if ($taxonomyKey !== null) {
        $taxonomy = \Cms\ContentType\TaxonomyRegistry::get($taxonomyKey);
        return $taxonomy === null
            ? ['label' => $taxonomyKey, 'icon' => null, 'admin' => null, 'gone' => true, 'what' => 'taxonomy']
            : ['label' => (string) $taxonomy['label'], 'icon' => 'taxonomies', 'admin' => null, 'gone' => false, 'what' => 'taxonomy'];
    }
    $ct = Registry::get($key);
    return $ct === null
        ? ['label' => $key, 'icon' => null, 'admin' => null, 'gone' => true]
        : ['label' => (string) $ct['label'], 'icon' => (string) $ct['menu_icon'],
           'admin' => (string) $ct['admin_slug'], 'gone' => false];
};
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Field groups</h1>
        </div>
        <div class="page__header-actions">
            <?php if ($groups !== []): ?>
                <a class="btn btn--primary" href="<?= h(Url::admin('field-groups/new')) ?>">Add field group</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($codeGroups !== []): ?>
        <?php // THE FACT AND THE ACT — the same answer content-types/list.php
              // gives, for the same reason. `--info` is the alert SHAPE with no
              // alert COLOUR: fields registered in code are a documented way to
              // build an install, not a fault, and amber here would make a
              // working one look broken. ?>
        <div class="alert alert--info">
            <?= icon('file', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__text">
                    <?= count($codeGroups) === 1 ? 'One content type carries' : count($codeGroups) . ' content types carry' ?>
                    fields registered in <code>config/content-types.php</code>.
                    Those are edited there, not here — they have no row in this
                    list because there is nothing here that could change them.
                </p>
                <ul class="alert__text">
                    <?php foreach ($codeGroups as $codeGroup): ?>
                        <li>
                            <?= h((string) $codeGroup['title']) ?> ·
                            <?= (int) $codeGroup['count'] ?>
                            <?= (int) $codeGroup['count'] === 1 ? 'field' : 'fields' ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($groups === []): ?>
        <?php // The kind of place you are standing in, and the one action that
              // ends the emptiness — show, don't tell. ?>
        <div class="empty">
            <?= icon('list-check', 'empty__glyph', '', 28) ?>
            <p class="empty__title">No field groups yet</p>
            <div class="empty__action">
                <a class="btn btn--primary" href="<?= h(Url::admin('field-groups/new')) ?>">Add field group</a>
            </div>
        </div>
    <?php else: ?>
        <div class="list fg-list">
            <?php foreach ($groups as $group): ?>
                <?php
                $id     = (int) $group['id'];
                $count  = (int) ($group['field_count'] ?? 0);
                $active = (bool) $group['is_active'];
                $types  = array_map($resolve, (array) $group['content_types']);
                ?>
                <div class="list__row">
                    <div class="fg-list__main">
                        <a class="list__row-link list__row-title" href="<?= h(Url::admin('field-groups/' . $id . '/edit')) ?>">
                            <?= h((string) $group['title']) ?>
                        </a>
                        <div class="list__row-sub fg-list__targets">
                            <?php if ($types === []): ?>
                                <?php // A group attached to nothing renders on no
                                      // screen at all. That is invisible unless
                                      // the row says it, and it looks exactly like
                                      // a broken save. ?>
                                <span class="placeholder">Not attached to a content type</span>
                            <?php else: ?>
                                <?php foreach ($types as $type): ?>
                                    <span class="fg-list__target">
                                        <?php if ($type['gone']): ?>
                                            <?php // SAY THAT IT IS GONE. Without this the
                                                  // row would print a raw key — such as
                                                  // `club_event` — in the same place every
                                                  // other row prints a label, and the only
                                                  // clue would be a missing glyph.
                                                  // `.alert-flag` is the sanctioned inline
                                                  // alert SURFACE: its own box, never red
                                                  // text on the row.
                                                  //
                                                  // Deleting a type takes its generated
                                                  // groups with it, so this should be rare —
                                                  // but a code-registered type removed from
                                                  // `config/content-types.php` still leaves
                                                  // one, and a rare state that says nothing
                                                  // is the worst kind. ?>
                                            <span class="alert-flag">
                                                <?= icon('triangle-exclamation', 'icon', '', 11) ?><?= h($type['label']) ?>
                                                — no such <?= h($type['what'] ?? 'content type') ?>
                                            </span>
                                        <?php else: ?>
                                            <?php if ($type['icon'] !== null): ?>
                                                <?= icon(Sidebar::iconFor($type['icon']), 'icon icon--sm') ?>
                                            <?php endif; ?>
                                            <?= h($type['label']) ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="fg-list__count">
                        <?php // A count, not a badge: the accent fill means "this
                              // element ACTS" and a number does not act. ?>
                        <?= count_of((int) $count, 'field') ?>
                    </div>

                    <div class="fg-list__state">
                        <?php // An INACTIVE group keeps its fields and every value
                              // already stored against them; it just stops being
                              // merged into the entry form. Worth showing, not an
                              // alarm. An active group shows nothing — "working
                              // normally" is not news.
                              //
                              // The archetype marker is a different fact: this
                              // group was GENERATED by ticking a box on the
                              // content type form, so an owner who does not
                              // remember writing it has an explanation. ?>
                        <?php if (!$active): ?>
                            <span class="status status--archived">
                                <?= icon('toggle-off', 'status__icon icon', '', 12) ?>Inactive
                            </span>
                        <?php elseif (!empty($group['is_archetype'])): ?>
                            <?php // Base neutral, no modifier: core.css §8's ramp is muted
                                  // → neutral → accent and nothing else, and
                                  // "generated rather than hand-written" is a
                                  // provenance fact, not a state on that ramp.
                                  // `clone` because that is what it is — a copy
                                  // of the archetype's shape, made when the box
                                  // was ticked on the content type form. ?>
                            <span class="status">
                                <?= icon('clone', 'status__icon icon', '', 12) ?>Archetype
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="list__row-actions">
                        <a class="btn btn--sm" href="<?= h(Url::admin('field-groups/' . $id . '/edit')) ?>">Edit</a>
                        <?php // A destructive TRIGGER does not need to shout: the
                              // confirm dialog it opens IS the alert surface.
                              // Deleting a group CASCADEs its field definitions —
                              // the stored VALUES in post_meta stay, so this is
                              // not the data loss it looks like, and the dialog
                              // says so rather than a paragraph on the row. ?>
                        <form class="form-inline" method="post"
                              action="<?= h(Url::admin('field-groups/' . $id . '/delete')) ?>"
                              data-confirm="Delete the <?= h((string) $group['title']) ?> field group?"
                              data-confirm-detail="<?= $count ?> <?= $count === 1 ? 'field stops' : 'fields stop' ?> appearing on <?= $types === [] ? 'any entry form' : h(implode(', ', array_column($types, 'label'))) ?>. Values already saved against <?= $count === 1 ? 'it' : 'them' ?> stay in the database."
                              data-confirm-verb="Delete group">
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
