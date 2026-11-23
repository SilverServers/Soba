<?php

declare(strict_types=1);

use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\SEO\Head;
use Cms\Ui\Sidebar;
use Cms\Ui\Status;

/**
 * THE DASHBOARD — where every admin session starts.
 *
 * ── WHAT IT IS FOR ──────────────────────────────────────────────────────────
 * Three questions, in the order a site owner actually asks them:
 *
 *   1. Is anything waiting for me?   unread form submissions
 *   2. What is on this site?         one card per Content Type, from the Registry
 *   3. What was I last doing?        the eight most recently edited entries
 *
 * ── AND WHAT IT IS DELIBERATELY NOT ─────────────────────────────────────────
 * There is no "Welcome back, admin!", no "Here's what's happening with your
 * site", and no tips panel. Greeting the user is the purest form of telling: it
 * occupies the most valuable space on the screen to say something they already
 * know. The h1 is the SITE'S NAME, which answers a question they may genuinely
 * have — *which* site am I editing — and which "Dashboard" does not.
 *
 * A NEW SITE'S NEXT STEPS are not a greeting (`Ui\Welcome`): on a site just
 * set up, each is a link to where that step is done, as the cards below are,
 * until an administrator hides them for good.
 *
 * The counts are not bars. A bar is for a number you compare, and "5 News
 * against 3 Events" is not a comparison anyone makes; the number is the fact.
 * Recent activity reuses the entry list's exact row vocabulary, so the two
 * screens read as one system rather than two takes on a list.
 *
 * @var list<array{label:string,label_single:string,admin_slug:string,menu_icon:string,count:int}> $stats
 * @var int                        $pageCount
 * @var int                        $unreadSubmissions
 * @var list<array<string, mixed>> $recentPosts
 * @var array<string, mixed>       $user
 */

$siteName  = \Cms\SEO\Head::globalTokens()['site_name'];
$pageTitle = 'Dashboard';

ob_start();
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title"><?= h($siteName) ?></h1>
        </div>
    </div>

    <?php if ($unreadSubmissions > 0): ?>
        <?php // THIS IS THE DASHBOARD'S ONE NOTIFICATION AND IT WEARS AN ALERT
              // COLOUR. Unread post is not a fault, but `--warning` and
              // `--danger` are the only two alert colours there are, so a
              // notice in neither (`--info`, the alert SHAPE with no alert hue)
              // renders as a grey box on a grey page and reads as nothing at
              // all. A message somebody sent you and nobody has opened is the
              // single thing on this screen that needs acting on today.
              //
              // core.css §10's closed list allows this: item 1 is `.alert`, the
              // block-level notice, which is exactly what this is. Amber, not
              // red — nothing is broken, something is waiting. ?>
        <div class="alert">
            <?= icon('envelope', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <?php // TITLE THEN BODY, the same structure as every other alert
                      // in the admin. One line of `alert__text` — muted
                      // `--ink-2`, no title — does not read as a warning even in
                      // the warning skin. The count is the fact; the sentence is
                      // what it means. ?>
                <p class="alert__title">
                    <?= (int) $unreadSubmissions ?>
                    unread form <?= $unreadSubmissions === 1 ? 'submission' : 'submissions' ?>
                </p>
                <p class="alert__text">
                    Somebody filled in a form on your site and nobody has opened it yet.
                </p>
                <div class="alert__actions">
                    <a class="btn btn--sm" href="<?= h(Url::admin('forms/submissions')) ?>">Open the inbox</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php // ── A NEW SITE'S NEXT STEPS (`Ui\Welcome`) ─────────────────────────
          // Each a whole-card link, as the cards below are; an administrator
          // hides them for good. ?>
    <?php if (\Cms\Ui\Welcome::shown($user)): ?>
        <div class="panel__section" data-welcome>
            <div class="panel__heading">
                <h3>Next steps</h3>
                <form method="post" action="<?= h(Url::admin('welcome/hide')) ?>">
                    <?= \Cms\Security\Csrf::field() ?>
                    <button class="btn btn--sm" type="submit">Hide these</button>
                </form>
            </div>
            <div class="card-grid card-grid--stats">
                <?php foreach (\Cms\Ui\Welcome::steps() as $step): ?>
                    <a class="card stat" href="<?= h($step['href']) ?>">
                        <div class="card__head">
                            <?= icon($step['icon'], 'card__glyph icon icon--lg') ?>
                            <strong><?= h($step['label']) ?></strong>
                        </div>
                        <p class="card__text"><?= h($step['text']) ?></p>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php // ── WHAT IS ON THIS SITE ────────────────────────────────────────
          // One card per registered Content Type, plus Pages. The whole card is
          // an <a>: it has no other interactive children, and a card with so
          // few controls navigates as a unit — and it is a real anchor rather
          // than a click handler, which is what gives it keyboard, middle-click
          // and open-in-new-tab for free. `data-row-href` would need a delegated
          // handler, and no such handler ships in this package. ?>
    <div class="card-grid card-grid--stats">
        <?php // THIS MONTH'S SALES, first, on a site that sells: the figure a
              // shop opens its admin for, linking to the report it is read from. ?>
        <?php if (\Cms\Helpers\Modules::enabled('commerce') && \Cms\ContentType\Registry::withArchetype('product') !== []): ?>
            <?php [$monthFrom, $monthTo] = \Cms\Commerce\Reports::named('this-month');
                  $month = \Cms\Commerce\Reports::summary($monthFrom, $monthTo); ?>
            <a class="card stat" href="<?= h(Url::admin('reports')) ?>" data-dashboard-sales>
                <div class="card__head">
                    <?= icon(Sidebar::iconFor('reports'), 'card__glyph icon icon--lg') ?>
                    <strong>Sales this month</strong>
                </div>
                <p class="stat__n"><?= h(\Cms\Commerce\Money::format($month['net'])) ?></p>
                <p class="stat__sub"><?= (int) $month['orders'] ?> <?= (int) $month['orders'] === 1 ? 'order' : 'orders' ?></p>
            </a>
        <?php endif; ?>
        <?php foreach ($stats as $stat): ?>
            <a class="card stat" href="<?= h(Url::admin((string) $stat['admin_slug'])) ?>">
                <div class="card__head">
                    <?php // The type's OWN registered glyph. Identical shells
                          // stacked across a dashboard are told apart only by
                          // their heading text unless you give them a mark —
                          // that mark is what makes this scannable rather than
                          // readable. ?>
                    <?= icon(Sidebar::iconFor((string) $stat['menu_icon']), 'card__glyph icon icon--lg') ?>
                    <strong><?= h((string) $stat['label']) ?></strong>
                </div>
                <p class="stat__n"><?= (int) $stat['count'] ?></p>
            </a>
        <?php endforeach; ?>

        <?php // Pages are hierarchical and standalone — NOT a Content Type — so
              // they are not in the Registry and are named here explicitly.
              // No card when the pages module is switched off (Helpers\Modules). ?>
        <?php if (\Cms\Helpers\Modules::enabled('pages')): ?>
        <a class="card stat" href="<?= h(Url::admin('pages')) ?>">
            <div class="card__head">
                <?= icon(Sidebar::iconFor('pages'), 'card__glyph icon icon--lg') ?>
                <strong>Pages</strong>
            </div>
            <p class="stat__n"><?= (int) $pageCount ?></p>
        </a>
        <?php endif; ?>
    </div>

    <?php // ── WHAT WAS I LAST DOING ───────────────────────────────────────── ?>
    <div class="panel__section">
        <div class="panel__heading">
            <h3>Recently edited</h3>
        </div>

        <?php if ($recentPosts === []): ?>
            <?php // The kind of place you are standing in, as a glyph. Not a
                  // magnifying glass — nothing has been filtered; there simply
                  // is not any content yet, and those are different facts. ?>
            <div class="empty">
                <?= icon('pen-to-square', 'empty__glyph', '', 28) ?>
                <p class="empty__title">Nothing edited yet</p>
            </div>
        <?php else: ?>
            <div class="list entry-list entry-list--read">
                <?php foreach ($recentPosts as $post): ?>
                    <?php
                    $type = \Cms\ContentType\Registry::get((string) $post['post_type']);

                    // A type can be UNREGISTERED and still have rows: deactivate
                    // a Content Type and its entries stay in the table. Those
                    // rows have no admin route to link to, so the row renders
                    // without one rather than pointing at a 404 — and it says
                    // which type it was, because that is the fact you need to
                    // work out what happened.
                    $editUrl = $type !== null
                        ? Url::admin($type['admin_slug'] . '/' . (int) $post['id'] . '/edit')
                        : null;
                    $typeLabel = $type !== null
                        ? (string) $type['label_single']
                        : (string) $post['post_type'];
                    ?>
                    <div class="list__row">
                        <div class="entry-list__main">
                            <?php if ($editUrl !== null): ?>
                                <a class="list__row-link list__row-title" href="<?= h($editUrl) ?>"><?= h((string) $post['title']) ?></a>
                            <?php else: ?>
                                <span class="list__row-title"><?= h((string) $post['title']) ?></span>
                            <?php endif; ?>
                            <div class="list__row-sub"><?= h($typeLabel) ?></div>
                        </div>

                        <?= Status::render(Status::forEntry($post)) ?>

                        <div class="list__row-meta">
                            <span class="entry-list__author"><?= h((string) $post['author_name']) ?></span>
                            <time datetime="<?= h(date('c', strtotime((string) $post['updated_at']))) ?>"><?= h(Dates::short((string) $post['updated_at'])) ?></time>
                        </div>

                        <?php // NO ACTION RAIL: this list's job is to get you back
                              // into an entry, and the whole row already does that
                              // (`.entry-list--read`). ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
