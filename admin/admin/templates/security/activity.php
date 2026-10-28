<?php

declare(strict_types=1);

use Cms\Controllers\Admin\SecurityController;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Security\ClientIp;
use Cms\Security\Csrf;
use Cms\Security\Audit;
use Cms\Security\Enumeration;
use Cms\Security\SecurityLog;
use Cms\Security\Throttle;

/**
 * SECURITY → ACTIVITY LOG — the answer to "who has been trying to get in?". A
 * FRAGMENT: `SecurityController` owns the layout.
 *
 *   Lockouts          every address that was shut out, and every NAME locked
 *                     from the addresses it has never signed in from
 *                     (`AccountLock`): in force, lifted, over. One in force
 *                     can be LIFTED — the one verb on this screen.
 *   Failed sign-ins   the name that was typed, why it failed, from where
 *   Sign-ins          who got in, from where: a failed attempt means little
 *                     without the successful one it was aimed at.
 *   Enumeration       who came looking for usernames, and where they looked
 *                     (`Security\Enumeration`) — one line per address an hour
 *   Changes           who changed what, on which screen (`Security\Audit`) —
 *                     never a value, so nothing secret to show
 *   Notifications     who is told, and how (`Security\Alerts`): settings, in
 *                     `notifications.php`
 *
 * ── THE NAME THAT WAS TYPED, NOT THE ACCOUNT ────────────────────────────────
 * A failure against "administrator" or "test" is still shown under that name —
 * which is what tells an owner a script is walking a word list rather than
 * someone mistyping. Escaped like everything else: it is text a stranger
 * chose.
 *
 * ── NO ALERT COLOUR ─────────────────────────────────────────────────────────
 * A failed sign-in is the protection working, not a fault on this site. The
 * state reads from the chip's glyph (§8 of core.css), never from red.
 *
 * @var string                     $tab
 * @var list<array<string, mixed>> $rows
 * @var int                        $total
 * @var int                        $page
 * @var int                        $totalPages
 * @var string                     $lifted     '' | 'address' | 'name' — just lifted
 */

$tabUrl  = static fn (string $key, int $n = 1): string
    => Url::admin('security/activity') . '?tab=' . $key . ($n > 1 ? '&page=' . $n : '');

/** A chip: glyph + phrase, the state carried by the glyph. */
$chip = static function (string $glyph, string $label, string $mod = ''): string {
    return '<span class="status status--phrase' . ($mod !== '' ? ' ' . $mod : '') . '">'
        . icon($glyph, 'status__icon') . h($label) . '</span>';
};

$empty = [
    'lockouts'    => ['lock', 'No lockouts'],
    'failed'      => ['x', 'No failed sign-ins'],
    'sign-ins'    => ['arrow-right-to-bracket', 'No sign-ins yet'],
    'enumeration' => ['microscope', 'Nobody has come looking for usernames'],
    'changes'     => ['pen-to-square', 'No changes yet'],
];
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Activity log</h1>
        </div>
    </div>

    <?php if ($lifted !== ''): ?>
        <div class="flashes">
            <div class="flash flash--success"><?= $lifted === 'name'
                ? 'Lockout lifted. That name can sign in again, from anywhere.'
                : 'Lockout lifted. That address can sign in again.' ?></div>
        </div>
    <?php endif; ?>

    <div class="tabs">
        <?php foreach (SecurityController::LOG_TABS as $key => $label): ?>
            <a class="tabs__tab<?= $tab === $key ? ' is-active' : '' ?>"
               <?= $tab === $key ? 'aria-current="page"' : '' ?>
               href="<?= h($tabUrl($key)) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($tab === 'notifications'): ?>
        <?php // Settings, not rows — the last tab. ?>
        <?php require __DIR__ . '/notifications.php'; ?>

    <?php elseif ($rows === []): ?>
        <div class="empty">
            <?= icon($empty[$tab][0], 'empty__glyph', '', 28) ?>
            <p class="empty__title"><?= h($empty[$tab][1]) ?></p>
        </div>

    <?php elseif ($tab === 'lockouts'): ?>
        <div class="list entry-list">
            <?php foreach ($rows as $row): ?>
                <?php
                $state   = (string) $row['state'];
                $refused = (int) $row['refused'];
                // A NAME locked (`AccountLock`) is titled by the name: the
                // address on its row is only where the last attempt came from.
                $name    = $row['username'] !== null ? (string) $row['username'] : null;
                ?>
                <div class="list__row">
                    <div class="entry-list__main">
                        <span class="list__row-title"><?= h($name ?? ClientIp::display((string) $row['ip'])) ?></span>
                        <div class="list__row-sub">
                            <?= (int) $row['attempts'] ?> failed attempts<?php if ($name !== null): ?>
                                on this name, the last from <?= h(ClientIp::display((string) $row['ip'])) ?><?php endif; ?><?php if ($refused > 0): ?>
                                · tried <?= $refused === 1 ? 'once' : $refused . ' times' ?> more while locked out<?php endif; ?><?php if ($state === 'lifted' && !empty($row['lifted_by_name'])): ?>
                                · lifted by <?= h((string) $row['lifted_by_name']) ?><?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <?php if ($state === 'active'): ?>
                            <?= $chip('lock', $name !== null ? 'Name locked' : 'Locked out', 'status--active') ?>
                        <?php elseif ($state === 'lifted'): ?>
                            <?= $chip('unlock', 'Lifted', 'status--archived') ?>
                        <?php else: ?>
                            <?= $chip('clock', 'Ended', 'status--complete') ?>
                        <?php endif; ?>
                    </div>

                    <div class="list__row-meta">
                        <?php // IN FORCE: how long is left, which is what the
                              // owner is deciding against. OVER: when it
                              // happened. ?>
                        <?php if ($state === 'active'): ?>
                            <span><?= h(Throttle::duration(max(1, (int) ceil((int) $row['seconds_left'] / 60)))) ?> left</span>
                        <?php else: ?>
                            <span><?= h(Dates::stamp((string) $row['locked_at'])) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="list__row-actions">
                        <?php if ($state === 'active'): ?>
                            <?php // No confirm: lifting a lockout is undone by
                                  // the next five wrong passwords, and a dialog
                                  // in front of it would guard nothing. ?>
                            <form method="post"
                                  action="<?= h(Url::admin('security/lockouts/' . (int) $row['id'] . '/lift')) ?>">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn btn--sm">Lift</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php elseif ($tab === 'changes'): ?>
        <div class="list entry-list">
            <?php foreach ($rows as $row): ?>
                <?php [$screen, $action] = Audit::parse((string) ($row['detail'] ?? '')); ?>
                <div class="list__row">
                    <div class="entry-list__main">
                        <span class="list__row-title"><?= h($screen !== '' ? $screen : '—') ?></span>
                        <?php // ONE LINE OF TEXT, clipped by `.list__row-sub`'s own
                              // ellipsis and whole on hover. An inline span inside it
                              // would run past a phone's edge at 390px: clipped from
                              // view, but still a box beyond the screen. ?>
                        <?php $line = (trim((string) ($row['username'] ?? '')) !== '' ? (string) $row['username'] : '—')
                            . ' · ' . ClientIp::display((string) $row['ip']) . ' · ' . $action; ?>
                        <div class="list__row-sub" title="<?= h($line) ?>"><?= h($line) ?></div>
                    </div>

                    <div>
                        <?php [$glyph, $label, $mod] = SecurityLog::describe($row); ?>
                        <?= $chip($glyph, $label, $mod) ?>
                    </div>

                    <div class="list__row-meta">
                        <span><?= h(Dates::stamp((string) $row['created_at'])) ?></span>
                    </div>

                    <div class="list__row-actions"></div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php elseif ($tab === 'enumeration'): ?>
        <div class="list entry-list">
            <?php foreach ($rows as $row): ?>
                <?php
                // What was asked for is a stranger's text, of any length, with
                // no spaces to wrap at: shortened here, whole on hover.
                [, $asked] = Enumeration::parse((string) ($row['detail'] ?? ''));
                $guessed   = trim((string) ($row['username'] ?? ''));
                ?>
                <div class="list__row">
                    <div class="entry-list__main">
                        <span class="list__row-title"><?= h(ClientIp::display((string) $row['ip'])) ?></span>
                        <?php // One line of text, as on Changes: what was asked shortened
                              // so "guessed" still shows, the whole line on hover. ?>
                        <?php
                        $guess = $guessed !== '' ? ' · guessed “' . $guessed . '”' : '';
                        $who   = SecurityLog::browser($row['user_agent'] ?? null);
                        ?>
                        <div class="list__row-sub" title="<?= h($who . ' · ' . $asked . $guess) ?>"><?= h($who . ' · ' . mb_strimwidth($asked, 0, 48, '…') . $guess) ?></div>
                    </div>

                    <div>
                        <?php [$glyph, $label, $mod] = SecurityLog::describe($row); ?>
                        <?= $chip($glyph, $label, $mod) ?>
                    </div>

                    <div class="list__row-meta">
                        <span><?= h(Dates::stamp((string) $row['created_at'])) ?></span>
                    </div>

                    <div class="list__row-actions"></div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php else: ?>
        <div class="list entry-list">
            <?php foreach ($rows as $row): ?>
                <?php
                $name = trim((string) ($row['username'] ?? ''));
                ?>
                <div class="list__row">
                    <div class="entry-list__main">
                        <span class="list__row-title"><?= $name !== '' ? h($name) : '—' ?></span>
                        <div class="list__row-sub">
                            <?= h(SecurityLog::browser($row['user_agent'] ?? null)) ?>
                            · <?= h(ClientIp::display((string) $row['ip'])) ?>
                        </div>
                    </div>

                    <div>
                        <?php // One map with the Overview (`SecurityLog::describe()`):
                              // an unknown name is the mark of a script guessing,
                              // a wrong CODE means the password was right. ?>
                        <?php [$glyph, $label, $mod] = SecurityLog::describe($row); ?>
                        <?= $chip($glyph, $label, $mod) ?>
                    </div>

                    <div class="list__row-meta">
                        <span><?= h(Dates::stamp((string) $row['created_at'])) ?></span>
                    </div>

                    <?php // RESERVED AND EMPTY, as on the dashboard's recent
                          // list: a sign-in that already happened has nothing to
                          // do to it, and without the rail the time column would
                          // jump 160px moving between this tab and Lockouts. ?>
                    <div class="list__row-actions"></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php // Only when there is more than one page — the entry list's rule. ?>
    <?php if ($totalPages > 1): ?>
        <nav class="pager" aria-label="Activity log pages">
            <?php if ($page > 1): ?>
                <a class="btn btn--sm" href="<?= h($tabUrl($tab)) ?>">First</a>
                <a class="btn btn--sm" href="<?= h($tabUrl($tab, $page - 1)) ?>" rel="prev">Previous</a>
            <?php endif; ?>

            <form class="pager__jump" method="get" action="<?= h(Url::admin('security/activity')) ?>">
                <input type="hidden" name="tab" value="<?= h($tab) ?>">
                <label class="pager__status">
                    Page
                    <input class="input input--sm input--count" type="number" name="page" min="1"
                           max="<?= (int) $totalPages ?>" value="<?= (int) $page ?>"
                           aria-label="Page number">
                    of <?= (int) $totalPages ?>
                </label>
            </form>

            <?php if ($page < $totalPages): ?>
                <a class="btn btn--sm" href="<?= h($tabUrl($tab, $page + 1)) ?>" rel="next">Next</a>
                <a class="btn btn--sm" href="<?= h($tabUrl($tab, $totalPages)) ?>">Last</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

</div>
