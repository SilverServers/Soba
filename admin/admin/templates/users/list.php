<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * USERS — who can sign in, and what they may do.
 *
 * `content-type/list.php`'s row vocabulary with the status chip replaced by the
 * role. A user has no draft/published axis; the only thing you scan this list
 * for is "who is an admin", so that is what the row carries.
 *
 * ── NO FILTER BAR ────────────────────────────────────────────────────────────
 * `User::all()` is unpaginated and an install has a handful of accounts. A
 * search box over five rows is a control that costs more attention than the
 * scan it replaces. If this list ever grows a page-size, it gets the same real
 * GET form the media library uses, and for the same stated reason.
 *
 * ── DELETE IS A FORM, NOT A LINK ─────────────────────────────────────────────
 * The route is POST, so a link could not reach it — and should not: a GET that
 * deletes is a row any crawler or prefetch can fire. `confirm.js` intercepts
 * `[data-confirm]` and puts the sanctioned dialog in front of it (the confirm
 * dialog IS the alert surface, so the trigger does not need to shout).
 *
 * ── YOU CANNOT DELETE YOURSELF, AND THE ROW SAYS SO BY OMISSION ─────────────
 * `handleDelete` redirects without acting when `$targetId` is the current user.
 * Rendering the button anyway would be a control that silently does nothing —
 * so the row simply does not draw one, the same way an unpublished entry draws
 * no "View on site".
 *
 * @var list<array<string, mixed>> $users  id, username, email, role, created_at
 * @var array<string, mixed>       $user   the signed-in admin
 */

$pageTitle = 'Users';

/** The three roles the controller will actually accept — see handleCreate(). */
$roleLabels = [
    'admin'  => 'Administrator',
    'editor' => 'Editor',
    'author' => 'Author',
];

/**
 * A GLYPH PER ROLE, because the word alone is not shown, it is said.
 *
 * Without one, mute the text and the entire page is blank — show, don't tell,
 * failing completely.
 *
 * A role is a JOB, not a state, so it is not a `.status` chip — those are the
 * published/draft vocabulary and colouring a role with them would say an Author
 * is a lesser state of an Administrator. A glyph beside the word is the honest
 * form: it distinguishes without ranking, and it still reads with the text
 * muted.
 */
$roleGlyphs = [
    'admin'  => 'shield-halved',
    'editor' => 'pen-to-square',
    'author' => 'pen',
];

ob_start();
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Users</h1>
        </div>

        <div class="page__header-actions">
            <a class="btn btn--primary" href="<?= h(Url::admin('users/new')) ?>">Add user</a>
        </div>
    </div>

    <?php // `entry-list` CARRIES THE ROW GRID — without it `.list__row` has no
          // template columns and every cell stacks down the left edge.
          // `user-list` drops the status track; see admin.css §4. ?>
    <div class="list entry-list user-list">
        <?php foreach ($users as $row): ?>
            <?php
            $rowId  = (int) $row['id'];
            $isSelf = $rowId === (int) $user['id'];
            $role   = (string) $row['role'];
            ?>
            <div class="list__row">
                <?php // THE FACE FIRST. `.avatar` is core.css §16 and the top bar
                      // already draws one for the signed-in user; a list OF PEOPLE
                      // that shows no people is a list of words. The CMS has no
                      // uploaded avatars, so the initial is what we do know — the
                      // same choice `nav-top.php` states.
                      //
                      // `--avatar-color` is the component's identity hook, and
                      // core.css §16 calls per-user colour the one sanctioned
                      // escape from the single-accent rule. Derived from the
                      // username so it is stable across sessions and installs
                      // without storing anything: the same name always lands on
                      // the same hue. ?>
                <span class="avatar" aria-hidden="true"
                      style="--avatar-color: hsl(<?= (int) (crc32((string) $row['username']) % 360) ?> 45% 55%)">
                    <?= h(mb_substr(\Cms\Models\User::displayName($row), 0, 1)) ?>
                </span>

                <div class="entry-list__main">
                    <a class="list__row-link list__row-title"
                       href="<?= h(Url::admin('users/' . $rowId . '/edit')) ?>"><?= h(\Cms\Models\User::displayName($row)) ?></a>
                    <?php // The name the byline shows is the title; the sign-in
                          // name goes under it when the two differ, because an
                          // admin looking for "sam" must still find them. ?>
                    <div class="list__row-sub"><?= trim((string) ($row['display_name'] ?? '')) !== ''
                        ? h((string) $row['username']) . ' &middot; ' : '' ?><?= h((string) $row['email']) ?></div>
                    <?php // WHICH OF THE SITE'S PEOPLE IT IS:
                          // the galleries and forms lists' line, for the
                          // same relationship — linked to the person's page. ?>
                    <?php if (\Cms\Helpers\People::declared() && ($personKey = (string) ($row['person_key'] ?? '')) !== ''): ?>
                        <?php $person = \Cms\Helpers\People::find($personKey); ?>
                        <div class="list__row-sub entry-list__parent">
                            <?php if ($person === null): ?>
                                <?= icon('triangle-exclamation', 'icon icon--sm') ?><?= h(\Cms\Helpers\People::label()) ?>: “<?= h($personKey) ?>”, no longer on the list
                            <?php else: ?>
                                <?= icon('up-right-from-square', 'icon icon--sm') ?><?= h(\Cms\Helpers\People::label()) ?>:
                                <?php if (!empty($person['url'])): ?>
                                    <a href="<?= h((string) $person['url']) ?>" target="_blank" rel="noopener"><?= h((string) $person['name']) ?></a>
                                <?php else: ?>
                                    <?= h((string) $person['name']) ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php // THE ROLE IS THE ROW'S ONE FACT, in the slot the entry
                      // list gives the status chip. Not a `.status` chip: those
                      // are the published/draft vocabulary and colouring a role
                      // with them would say an Author is a lesser state of an
                      // Administrator rather than a different job. ?>
                <div class="list__row-meta">
                    <?= icon($roleGlyphs[$role] ?? 'user', 'icon icon--sm') ?>
                    <span><?= h($roleLabels[$role] ?? $role) ?></span>
                    <?php if ($isSelf): ?>
                        <span class="list__row-sub">You</span>
                    <?php endif; ?>
                    <?php // SUSPENDED is a state, so it IS a chip (migration 067). ?>
                    <?php if (($row['suspended_at'] ?? null) !== null): ?>
                        <span class="status status--phrase"><?= icon('pause', 'status__icon') ?>Suspended</span>
                    <?php endif; ?>
                    <?php // So is TEMPORARY: signed in only by its link, for a while. ?>
                    <?php if (!empty($row['temporary'])): ?>
                        <span class="status status--phrase"><?= icon('stopwatch', 'status__icon') ?>Temporary</span>
                    <?php endif; ?>
                </div>

                <?php // EDIT IS THE PRIMARY VERB and this rail had only a
                      // delete. The title links to the same place, but a link
                      // with no affordance is not an offer — and a row whose
                      // only visible control is destructive invites the wrong
                      // click. Editing YOURSELF is allowed, so this is outside
                      // the `!$isSelf` guard that hides the bin. ?>
                <div class="list__row-actions">
                    <a class="btn btn--sm" href="<?= h(Url::admin('users/' . $rowId . '/edit')) ?>">Edit</a>
                    <?php if (!$isSelf): ?>
                        <?php // THE ATTRIBUTES GO ON THE FORM, not the button —
                              // confirm.js intercepts the form's submit event.
                              // On the button they are inert, and the delete
                              // fires with no dialog at all. ?>
                        <form method="post"
                              action="<?= h(Url::admin('users/' . $rowId . '/delete')) ?>"
                              data-confirm="Delete <?= h((string) $row['username']) ?>?"
                              data-confirm-detail="Their pages and entries are reassigned to you, not deleted. The account cannot be restored."
                              data-confirm-verb="Delete user">
                            <?= Csrf::field() ?>
                            <button type="submit" class="icon-btn"
                                    data-tip="Delete user" aria-label="Delete user">
                                <?= icon('trash-can', 'icon') ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

</div>

<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
