<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * TOOLS → REDIRECTS.
 *
 * ── THE LAYOUT ──────────────────────────────────────────────────────────────
 *   the rules come FIRST, the form is under them   you arrive to see what is
 *                                                  already forwarding; adding
 *                                                  is the second thing you do
 *   the two addresses share ONE cell               source on top, target under
 *                                                  it, so a rule reads as one
 *                                                  fact instead of two columns
 *                                                  you have to join by eye
 *   labelled columns                               Code · Hits · Last access.
 *                                                  `.list` has no header row
 *                                                  anywhere else in this admin
 *                                                  and did not need one; here
 *                                                  three of the five cells are
 *                                                  bare numbers and dates, and
 *                                                  an unlabelled number is a
 *                                                  fault
 *   Add redirect in the page header                jumps to the form. On a long
 *                                                  list the form is off-screen
 *
 * Left out: bulk-action checkboxes, groups, search and filter bars, a repeated
 * footer header, and Log / Import / Export tabs. The Code column keeps
 * the word with the code after it rather than a bare `301`, because a status
 * code is not what the owner of the site came here knowing.
 *
 * ── ONE SCREEN, NOT A LIST AND AN EDITOR ────────────────────────────────────
 * A redirect is three fields. The list/edit pair every other section uses would
 * put two of them behind a page load, so the same form does both: `?edit={id}`
 * fills it from the rule and posts to that rule's own address. No second
 * template, and the Edit control is a link.
 *
 * NO DISABLED CONTROLS AND NO ROWS WITHOUT VERBS. Every rule here is editable
 * and deletable, because every rule here was typed in here.
 *
 * ── NOT FOUND ───────────────────────────────────────────────────────────────
 * Under the rules, the addresses visitors asked for that answered "not found"
 * (`SEO\NotFound`), the most asked-for first: how often, when last, and what
 * linked there. Add redirect fills the form below with the address, for the
 * owner to say where it goes; once a rule answers it, it leaves this list.
 * Absent when there are none.
 *
 * @var list<array<string, mixed>>   $redirects
 * @var array{rows: list<array<string, mixed>>, total: int} $notFound
 * @var array<string, mixed>|null    $form       rule under edit, or a rejected
 *                                               submission handed back
 * @var list<string>                 $errors
 * @var bool                         $saved
 * @var array<string, mixed>         $user
 */

$editing = $form !== null && (int) ($form['id'] ?? 0) > 0;
$fFrom   = (string) ($form['from_path'] ?? '');
$fTo     = (string) ($form['to_url'] ?? '');
$fType   = (int) ($form['type'] ?? 301);

$formAction = $editing
    ? Url::admin('tools/redirects/' . (int) $form['id'])
    : Url::admin('tools/redirects');

$pageTitle = 'Redirects';

ob_start();
?>
<div class="page">

    <a class="page__back" href="<?= h(Url::admin('tools')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Tools
    </a>

    <div class="page__header">
        <div class="page__header-main">
            <div class="page__title-row">
                <h1 class="page__title">Redirects</h1>
                <?php // The count beside the NAME, not in the actions slot —
                      // that slot is where every screen puts its primary
                      // button, so a bordered box alone in it gets clicked. ?>
                <?php if ($redirects !== []): ?>
                    <?= count_of(count($redirects), 'redirect') ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="page__header-actions">
            <?php // A LINK, not a JavaScript toggle. The form is always on the
                  // page; hiding it behind this button would make a control
                  // that does nothing without script. This one moves you to
                  // it, which is the same result with none of the
                  // machinery, and it still works on the empty screen. ?>
            <?php if ($redirects !== []): ?>
                <a class="btn btn--primary" href="#redirect-form">Add redirect</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($saved && $errors === []): ?>
        <div class="flashes">
            <div class="flash flash--success">Redirect saved.</div>
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

    <?php if ($redirects === []): ?>
        <?php // Show, don't tell: the kind of place you are standing in and
              // what fills it.
              //
              // NO BUTTON IN THE EMPTY ACTION. The form is the next element
              // down and already open, so an "Add redirect" button would be the
              // third thing on one screen offering the same act — the page
              // header's is hidden for the same reason. It would scroll you
              // 100px to a form you could already see. ?>
        <div class="empty rd-empty">
            <?= icon('share', 'empty__glyph', '', 28) ?>
            <p class="empty__title">Nothing is being forwarded</p>
            <p class="empty__text">
                When a page moves or goes away, put its old address in the form
                below so links to it, and search results pointing at it, still
                land somewhere.
            </p>
        </div>
    <?php else: ?>
        <div class="list rd-list">
            <?php // THE HEADER ROW. `.list__row` for the grid, the padding and
                  // the rule under it — the same bones the rows use, so the
                  // columns cannot drift apart from their labels. It has no
                  // `a.list__row-link`, so the hover cue does not apply to it. ?>
            <div class="list__row rd-list__head">
                <div class="rd-list__main">URL</div>
                <div class="rd-list__state">Code</div>
                <div class="rd-list__count">Hits</div>
                <div class="rd-list__seen">Last access</div>
                <div class="list__row-actions" aria-hidden="true"></div>
            </div>

            <?php foreach ($redirects as $r): ?>
                <?php
                $rid     = (int) $r['id'];
                $rFrom   = (string) $r['from_path'];
                $rTo     = (string) $r['to_url'];
                $rHits   = (int) $r['hit_count'];
                $rSeen   = $r['last_accessed_at'] ?? null;
                $isThis  = $editing && $rid === (int) $form['id'];
                $editUrl = Url::admin('tools/redirects') . '?edit=' . $rid . '#redirect-form';
                ?>
                <div class="list__row<?= $isThis ? ' is-current' : '' ?>">
                    <div class="rd-list__main">
                        <?php // SOURCE OVER TARGET, one cell. The pair IS the
                              // rule; splitting them into two columns makes you
                              // read across a 100px gap to
                              // find out where an address goes. ?>
                        <a class="list__row-link list__row-title rd-list__from"
                           href="<?= h($editUrl) ?>"><?= h($rFrom) ?></a>
                        <div class="list__row-sub rd-list__to">
                            <?= icon('arrow-right-long', 'rd-list__arrow icon icon--sm') ?>
                            <span class="rd-list__target"><?= h($rTo) ?></span>
                        </div>
                    </div>

                    <div class="rd-list__state">
                        <?php // The word the form used, with the code after it. ?>
                        <span class="status">
                            <?= (int) $r['type'] === 302 ? 'Temporary' : 'Permanent' ?>
                            (<?= (int) $r['type'] ?>)
                        </span>
                    </div>

                    <?php // THE NUMBER CARRIES ITS OWN UNIT BELOW 720px, where
                          // the header row is gone and "42" would sit beside a
                          // date with nothing saying what it counts — the
                          // unlabelled-figure fault the header exists to fix,
                          // reappearing at phone width. The
                          // word is hidden at full width, where the column is
                          // labelled and repeating it on every row is noise.
                          //
                          // Grouped: 1788 and 1,788 are the same fact and only
                          // one of them can be read at a glance. ?>
                    <div class="rd-list__count">
                        <?= h(number_format($rHits)) ?><span class="rd-list__unit">
                            <?= $rHits === 1 ? 'hit' : 'hits' ?></span>
                    </div>

                    <div class="rd-list__seen">
                        <?php // NEVER IS A FACT, not a blank. A rule that has
                              // never once fired is usually a rule typed wrong —
                              // the router matches `from_path` exactly — and an
                              // em dash in this cell hides the one symptom that
                              // says so. See migration 039. ?>
                        <?php // AND IT CARRIES ITS OWN LABEL BELOW 720px, the
                              // same duty the hit count's unit performs one cell
                              // over: the header row is gone at that width, and
                              // a bare date under an address reads as when the
                              // rule was MADE. Hidden at full width, where the
                              // column is labelled and repeating it per row is
                              // noise. ?>
                        <span class="rd-list__unit rd-list__seen-label">Last access</span>
                        <?php if ($rSeen === null || $rSeen === ''): ?>
                            <span class="placeholder">Never</span>
                        <?php else: ?>
                            <?= h(Dates::short((string) $rSeen)) ?>
                        <?php endif; ?>
                    </div>

                    <div class="list__row-actions">
                        <a class="btn btn--sm" href="<?= h($editUrl) ?>">Edit</a>
                        <?php // A destructive trigger does not shout — the
                              // confirm dialog it opens IS the alert surface.
                              // Same shape as the taxonomies and terms lists. ?>
                        <form class="form-inline" method="post"
                              action="<?= h(Url::admin('tools/redirects/' . $rid . '/delete')) ?>"
                              data-confirm="Delete this redirect?"
                              data-confirm-detail="<?= h($rFrom) ?> will stop forwarding and start returning a 404."
                              data-confirm-verb="Delete redirect">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link link--xs">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($notFound['rows'] !== []): ?>
        <div class="form-card rd-missing" id="not-found">
            <div class="form-card__section">
                <div class="panel-head">
                    <?= panel_title('Not found', 'not-found') ?>
                    <form class="form-inline" method="post"
                          action="<?= h(Url::admin('tools/redirects/not-found/clear')) ?>"
                          data-confirm="Clear the list of addresses not found?"
                          data-confirm-detail="It fills again as visitors ask for addresses that are not there."
                          data-confirm-verb="Clear list">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn--sm">Clear list</button>
                    </form>
                </div>
                <?php // The redirects list's cells and labels, so the two read
                      // alike: the address (and what linked to it) where a
                      // rule's addresses are, then its count and its date. A
                      // list of its own, so nothing reading the rules reads
                      // these as rules. ?>
                <div class="list list--bleed rd-missing__list">
                    <div class="list__row rd-list__head">
                        <div class="rd-list__main">URL</div>
                        <div class="rd-list__count">Hits</div>
                        <div class="rd-list__seen">Last asked for</div>
                        <div class="list__row-actions" aria-hidden="true"></div>
                    </div>
                    <?php foreach ($notFound['rows'] as $miss): ?>
                        <?php
                        $mPath = (string) $miss['path'];
                        $mHits = (int) $miss['hits'];
                        $mRef  = trim((string) ($miss['referrer'] ?? ''));
                        $mAdd  = Url::admin('tools/redirects') . '?from=' . rawurlencode($mPath) . '#redirect-form';
                        ?>
                        <div class="list__row" data-missing="<?= h($mPath) ?>">
                            <div class="rd-list__main">
                                <a class="list__row-link list__row-title rd-list__from" href="<?= h($mAdd) ?>"><?= h($mPath) ?></a>
                                <?php if ($mRef !== ''): ?>
                                    <div class="list__row-sub rd-list__to">
                                        <?= icon('link', 'rd-list__arrow icon icon--sm') ?>
                                        <span class="rd-list__target"><?= h($mRef) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="rd-list__count">
                                <?= h(number_format($mHits)) ?><span class="rd-list__unit">
                                    <?= $mHits === 1 ? 'hit' : 'hits' ?></span>
                            </div>
                            <div class="rd-list__seen">
                                <span class="rd-list__unit rd-list__seen-label">Last asked for</span>
                                <?= h(Dates::short((string) $miss['last_seen'])) ?>
                            </div>
                            <div class="list__row-actions">
                                <a class="btn btn--sm" href="<?= h($mAdd) ?>">Add redirect</a>
                                <form class="form-inline" method="post"
                                      action="<?= h(Url::admin('tools/redirects/not-found/' . (int) $miss['id'] . '/dismiss')) ?>">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="link link--xs">Dismiss</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($notFound['total'] > count($notFound['rows'])): ?>
                    <p class="form-help rd-missing__more">
                        <?= h(number_format($notFound['total'] - count($notFound['rows']))) ?> more, asked for less often.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="form-main">
        <div class="form-card" id="redirect-form">
            <div class="form-card__section">
                <?= panel_title($editing ? 'Edit redirect' : 'Add a redirect', $editing ? 'edit-redirect' : 'add-redirect') ?>

                <form method="post" action="<?= h($formAction) ?>">
                    <?= Csrf::field() ?>

                    <div class="form-grid form-grid--2">
                        <div class="field">
                            <label class="label" for="f-redirect-from">Old address</label>
                            <input class="input input--mono" type="text" id="f-redirect-from"
                                   name="redirect_from" placeholder="/old-address"
                                   value="<?= h($fFrom) ?>" spellcheck="false" autocomplete="off">
                            <?php // WHAT THE ROUTER ACTUALLY DOES, in
                                  // www/index.php: the redirect table is consulted
                                  // BEFORE any route is dispatched, and the path it
                                  // looks up has its query string cut, a leading
                                  // slash forced and a trailing one trimmed. Both
                                  // halves matter to whoever is typing — the match
                                  // is exact (or, ending in /*, everything under
                                  // it), and a rule beats a page that still exists
                                  // at the same address. ?>
                            <p class="form-help">
                                Everything after the domain, starting with a slash.
                                Checked before anything is served, so this wins even
                                if a page still lives at that address. End it with
                                <code>/*</code> to redirect everything under an old
                                section, like <code>/old-news/*</code>.
                            </p>
                        </div>

                        <div class="field">
                            <label class="label" for="f-redirect-to">Send to</label>
                            <input class="input input--mono" type="text" id="f-redirect-to"
                                   name="redirect_to" placeholder="/new-address"
                                   value="<?= h($fTo) ?>" spellcheck="false" autocomplete="off">
                            <p class="form-help">
                                Another address on this site, or a full one starting
                                <code>https://</code> to send visitors off it. For a
                                <code>/*</code> rule, a <code>*</code> here is replaced
                                by the rest of the old address: <code>/articles/*</code>.
                            </p>
                        </div>
                    </div>

                    <div class="field field--narrow">
                        <label class="label" for="f-redirect-type">Kind</label>
                        <select class="select" id="f-redirect-type" name="redirect_type">
                            <option value="301"<?= $fType === 301 ? ' selected' : '' ?>>Permanent (301)</option>
                            <option value="302"<?= $fType === 302 ? ' selected' : '' ?>>Temporary (302)</option>
                        </select>
                        <p class="form-help">
                            Permanent asks search engines to file the new address in
                            place of the old one. Temporary asks them to keep the old.
                        </p>
                    </div>

                    <div class="form-actions form-actions--bare">
                        <?php if ($editing): ?>
                            <?php // A way OUT of the edit that is not the browser's
                                  // back button — the form is the same element in
                                  // both states, so without this there is nothing on
                                  // the screen that returns it to Add. ?>
                            <a class="btn" href="<?= h(Url::admin('tools/redirects')) ?>">Cancel</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn--primary">
                            <?= $editing ? 'Save redirect' : 'Add redirect' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>
<script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
