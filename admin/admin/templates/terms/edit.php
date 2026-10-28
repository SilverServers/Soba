<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Models\Term;
use Cms\Security\Csrf;

/**
 * THE TERM FORM — one screen, create and edit, every taxonomy.
 *
 * Like the entry form, nothing in here is per-taxonomy except what the Registry
 * hands it: the labels and whether it nests. One template serves Categories,
 * Club types and anything registered later, from the same code.
 *
 * Same layout as every other form here — `.form-main` / `.form-aside`, one
 * `.form-card` per column, sections divided by hairlines, footer as the last
 * section. core.css §4.
 *
 * ── THE PARENT PICKER ONLY EXISTS ON A NESTED TAXONOMY ──────────────────────
 * Not disabled, not empty: absent. `TermController::validateAndSanitize()` does
 * not read `parent_id` at all unless the taxonomy is hierarchical, so a picker
 * on a flat taxonomy would be a control that posts nowhere.
 *
 * ── AND A TERM CANNOT BE ITS OWN PARENT, OR ITS CHILD'S ─────────────────────
 * The options exclude this term and everything under it. Without that, choosing
 * your own descendant as a parent detaches the whole branch from the tree: the
 * cycle has no root, `treeForTaxonomy()` never reaches it, and the terms vanish
 * from every screen while still sitting in the table. The model promotes
 * orphans, so this is the one shape it cannot rescue.
 *
 * @var array<string, mixed>       $taxonomy   the REGISTRY entry
 * @var array<string, mixed>|null  $term       null on create
 * @var array<string, mixed>       $formData   $_POST on a failed submit
 * @var array<string, string>      $errors
 * @var list<array<string, mixed>> $allTerms   flat; [] on a flat taxonomy
 */

$saved    = $saved    ?? false;
$errors   = $errors   ?? [];
$formData = $formData ?? [];

$label       = (string) $taxonomy['label'];
$labelSingle = (string) $taxonomy['label_single'];
$adminSlug   = (string) $taxonomy['admin_slug'];
$nested      = !empty($taxonomy['hierarchical']);

$isNew = ($term ?? null) === null;
$id    = $isNew ? 0 : (int) $term['id'];
$base  = 'taxonomies/' . $adminSlug . '/terms';

// On a failed submit the form comes back wearing what the AUTHOR typed, not what
// is in the database — otherwise a validation error silently discards the edit
// it is complaining about.
$val = static function (string $key, string $fallback = '') use ($formData, $term): string {
    if (isset($formData[$key]) && !is_array($formData[$key])) {
        return (string) $formData[$key];
    }
    return (string) ($term[$key] ?? $fallback);
};

$parentId = (int) ($val('parent_id', '0') ?: 0);

// EXCLUDE THIS TERM AND ITS DESCENDANTS from the parent options — see the
// docblock. `getDescendantIds()` is the model's own walk, so the rule lives in
// one place rather than being re-derived from `$allTerms` here.
$excluded = [];
if (!$isNew) {
    $excluded = Term::getDescendantIds($id, (string) $taxonomy['key']);
    $excluded[] = $id;
}

$usage = $isNew ? [] : Term::usageCounts((string) $taxonomy['key']);
$used  = (int) ($usage[$id] ?? 0);

$action = $isNew
    ? Url::admin($base . '/new')
    : Url::admin($base . '/' . $id . '/edit');

// NO ob_start() AND NO require layout.php HERE — TermController buffers this
// file and requires the layout itself.
?>
<div class="page">

    <?php // Back to the TERM LIST, not to Taxonomies. The way back is where you
          // came from; the taxonomy itself is one more hop and its own link is
          // on that screen. core.css §1 for why this sits above the header. ?>
    <a class="page__back" href="<?= h(Url::admin($base)) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?><?= h($label) ?>
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">
                <?= h($isNew ? 'Add ' . strtolower($labelSingle) : (string) $term['name']) ?>
            </h1>
        </div>
    </div>

    <?php if ($saved): ?>
        <div class="flashes">
            <div class="flash flash--success"><?= h($labelSingle) ?> saved.</div>
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <?php // One alert listing every problem — the first of the CLOSED list of
              // places --danger may be painted. ?>
        <div class="alert alert--danger">
            <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__title">
                    <?= count($errors) === 1 ? 'One thing needs fixing' : count($errors) . ' things need fixing' ?>
                </p>
                <ul class="alert__text">
                    <?php foreach ($errors as $message): ?>
                        <li><?= h($message) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= h($action) ?>">
        <?= Csrf::field() ?>

        <div class="panel__grid">

            <div class="form-main">
                <div class="form-card">

                    <div class="form-card__section">
                        <div class="field">
                            <label class="label" for="f-name">Name<span class="label__req">required</span></label>
                            <input class="input" type="text" id="f-name" name="name" required
                                   value="<?= h($val('name')) ?>" autocomplete="off">
                        </div>

                        <div class="field">
                            <label class="label" for="f-slug">URL slug</label>
                            <?php // The slug the server WILL generate is not
                                  // knowable here without duplicating
                                  // Term::generateSlug(), so the box does not
                                  // pretend to preview it. Leaving it empty and
                                  // letting the server fill it in is the honest
                                  // version of "we'll work it out". ?>
                            <input class="input input--mono" type="text" id="f-slug" name="slug"
                                   value="<?= h($val('slug')) ?>" autocomplete="off"
                                   placeholder="<?= h($isNew ? 'left blank, made from the name' : '') ?>">
                        </div>

                        <div class="field">
                            <label class="label" for="f-description">Description</label>
                            <textarea class="textarea" id="f-description" name="description"
                                      rows="4"><?= h($val('description')) ?></textarea>
                            <?php // WHAT READS IT, on a taxonomy with a public address
                                  // (migration 048): `templates/term.php` prints it
                                  // under the heading, and `Public\TermController`
                                  // hands it to `Head` as the page's description.
                                  // Without an address there is no page, and the help
                                  // line says so. ?>
                            <p class="form-help">
                                <?php if (trim((string) ($taxonomy['public_slug'] ?? '')) !== ''): ?>
                                    Shown under the name on this <?= h(strtolower((string) $labelSingle)) ?>'s page,
                                    and given to search engines as that page's description.
                                <?php else: ?>
                                    Shown on this <?= h(strtolower((string) $labelSingle)) ?>'s public page — and
                                    <?= h((string) $taxonomy['label']) ?> has no public address yet, so there is
                                    none. Give it one on the taxonomy's own screen.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <div class="form-card__section form-actions">
                        <a class="link link--xs" href="<?= h(Url::admin($base)) ?>">Cancel</a>
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn btn--primary">
                            <?= $isNew ? 'Create ' . h(strtolower($labelSingle)) : 'Save changes' ?>
                        </button>
                    </div>

                </div>
            </div>

            <div class="form-aside">
                <div class="form-card">

                    <?php if ($nested): ?>
                        <?php // ONLY ON A NESTED TAXONOMY. On a flat one the
                              // controller never reads parent_id, so this would be
                              // a control that posts nowhere. ?>
                        <div class="form-card__section">
                            <?= panel_title('Placement', 'placement') ?>
                            <div class="field">
                                <label class="label" for="f-parent">Sits under</label>
                                <?php // "Nothing — top level" and not a blank
                                      // option: an empty row does not distinguish
                                      // "no parent" from "there is nothing to
                                      // choose". Top level is a real choice, so it
                                      // gets a real label. ?>
                                <select class="select" id="f-parent" name="parent_id">
                                    <option value="">Nothing — top level</option>
                                    <?php foreach ($allTerms as $option): ?>
                                        <?php if (in_array((int) $option['id'], $excluded, true)) { continue; } ?>
                                        <option value="<?= (int) $option['id'] ?>"<?= $parentId === (int) $option['id'] ? ' selected' : '' ?>>
                                            <?= h((string) $option['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!$isNew && $excluded !== [$id]): ?>
                                    <?php // Say WHY the list is short, at the moment
                                          // it looks wrong. Without this, a term with
                                          // children shows a picker missing exactly
                                          // the entries the author was looking for. ?>
                                    <p class="form-help">
                                        This <?= h(strtolower($labelSingle)) ?> and everything
                                        nested under it are left out — a term cannot sit under itself.
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="form-card__section">
                        <?= panel_title('Order', 'order') ?>
                        <div class="field">
                            <label class="label" for="f-sort-order">Position</label>
                            <input class="input" type="number" id="f-sort-order" name="sort_order"
                                   value="<?= h($val('sort_order', '0')) ?>" min="0" step="1">
                            <?php // What the number DOES, in the terms the admin
                                  // will see it act — not "integer, ascending". ?>
                            <p class="form-help">
                                Lower numbers come first. Equal numbers fall back to alphabetical.
                            </p>
                        </div>
                    </div>

                    <?php if (!$isNew): ?>
                        <div class="form-card__section">
                            <?= panel_title('Used by', 'used-by') ?>
                            <?php // SHOW THE FACT, and make it a way in. A count on
                                  // its own answers "can I delete this?"; the link
                                  // answers "what would I be unfiling?". ?>
                            <?php if ($used === 0): ?>
                                <p class="form-help">
                                    Nothing is filed under this <?= h(strtolower($labelSingle)) ?> yet.
                                </p>
                            <?php else: ?>
                                <p class="form-help">
                                    <?= $used ?> <?= $used === 1 ? 'entry is' : 'entries are' ?>
                                    filed under this <?= h(strtolower($labelSingle)) ?>.
                                </p>
                            <?php endif; ?>
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
                        <button type="submit" class="link link--xs"
                                form="delete-term">Delete <?= h(strtolower($labelSingle)) ?></button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </form>

    <?php // OUTSIDE the form above, targeted by `form=`. HTML HAS NO NESTED
          // FORMS — the parser discards the inner tag and reparents its button to
          // the outer form, so a delete written inside the save form quietly
          // SAVES instead. ?>
    <?php if (!$isNew): ?>
        <form id="delete-term" method="post"
              action="<?= h(Url::admin($base . '/' . $id . '/delete')) ?>"
              data-confirm="Delete <?= h((string) $term['name']) ?>?"
              data-confirm-detail="<?= $used === 0
                    ? 'Nothing is filed under it.'
                    : $used . ($used === 1 ? ' entry stops' : ' entries stop') . ' being filed under it. The '
                      . ($used === 1 ? 'entry itself is' : 'entries themselves are') . ' not deleted.' ?><?php
                    // WHERE ITS NESTED TERMS GO, as the terms list's dialog says —
                    // and it is true: Term::delete() moves them up one level.
                    // `$excluded` is this term and its descendants.
                    ?><?= count($excluded) > 1 ? ' Terms nested under it move up a level.' : '' ?>"
              data-confirm-verb="Delete <?= h(strtolower($labelSingle)) ?>" hidden>
            <?= Csrf::field() ?>
        </form>
    <?php endif; ?>

</div>

<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
