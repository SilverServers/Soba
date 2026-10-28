<?php

declare(strict_types=1);

use Cms\Controllers\Admin\SecurityController;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Security\ClientIp;
use Cms\Security\SecurityLog;
use Cms\Security\Throttle;

/**
 * SECURITY → OVERVIEW — how much has been thrown at the door, and whether the
 * locks are on. A FRAGMENT: `SecurityController` owns the layout.
 *
 * ── THREE THINGS, EACH ONCE ─────────────────────────────────────────────────
 *   the COUNTS for a period       the dashboard's own stat cards, each one a
 *                                 way into the log tab it counts
 *   what is SWITCHED ON           one row per protection that exists, with its
 *                                 settings in a word and a way to change them
 *   the LATEST few events         the log's own row, newest first
 *
 * A protection that has not been built is not drawn here as "off" — a row
 * advertising a switch nobody can throw is an upsell panel. Each row comes with
 * the screen that controls it.
 *
 * @var string                     $period     today | 7 | 30
 * @var array<string, int>         $counts     failed, lockouts, sign-ins, active
 * @var list<array<string, mixed>> $recent
 * @var array<string, mixed>       $limit      Throttle::settings()
 * @var array<string, mixed>       $account    AccountLock::settings() — the per-username limit
 * @var bool                       $moved
 * @var string                     $signInUrl
 * @var array<string, mixed>       $passwordless Passwordless::settings()
 * @var bool                       $emailSends   false on a development install, which offers no way by email
 * @var int                        $temporaryRunning  temporary accounts whose access is still running
 */

$periodUrl = static fn (string $p): string => Url::admin('security') . ($p === '7' ? '' : '?period=' . $p);
$logUrl    = static fn (string $tab): string => Url::admin('security/activity') . '?tab=' . $tab;

// THREE COUNTS: successful, failed, locked out — addresses and usernames
// alike, as the Lockouts tab the card opens lists both.
// "Locked out NOW" is not a fourth card — the Lockouts tab is where the ones in
// force are listed, each with the Lift that acts on it (one fact, one place).
$cards = [
    ['sign-ins', 'arrow-right-to-bracket', 'Sign-ins',        $counts['sign-ins']],
    ['failed',   'x',                      'Failed sign-ins', $counts['failed']],
    ['lockouts', 'lock',                   'Lockouts',        $counts['lockouts']],
];

$chip = static function (string $glyph, string $label, string $mod = ''): string {
    return '<span class="status status--phrase' . ($mod !== '' ? ' ' . $mod : '') . '">'
        . icon($glyph, 'status__icon') . h($label) . '</span>';
};
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Security</h1>
        </div>
    </div>

    <?php // THE PERIOD SELECTOR, as the tabs every screen here uses. ?>
    <div class="tabs">
        <?php foreach (SecurityController::PERIODS as $key => $label): ?>
            <a class="tabs__tab<?= $period === (string) $key ? ' is-active' : '' ?>"
               <?= $period === (string) $key ? 'aria-current="page"' : '' ?>
               href="<?= h($periodUrl((string) $key)) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="card-grid">
        <?php foreach ($cards as [$tab, $glyph, $label, $n]): ?>
            <a class="card stat" href="<?= h($logUrl($tab)) ?>">
                <div class="card__head">
                    <?= icon($glyph, 'card__glyph icon icon--lg') ?>
                    <strong><?= h($label) ?></strong>
                </div>
                <p class="stat__n"><?= (int) $n ?></p>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="panel__section">
        <div class="panel__heading">
            <h3>Protection</h3>
        </div>

        <div class="list entry-list">
            <div class="list__row">
                <div class="entry-list__main">
                    <a class="list__row-link list__row-title"
                       href="<?= h(Url::admin('security/login-protection') . '?tab=address') ?>">Sign-in address</a>
                    <div class="list__row-sub"><?= h(parse_url($signInUrl, PHP_URL_PATH) ?: '/admin/login') ?></div>
                </div>
                <div>
                    <?= $moved
                        ? $chip('check', 'Moved', 'status--active')
                        : $chip('toggle-off', 'Not moved') ?>
                </div>
                <div class="list__row-meta"></div>
                <div class="list__row-actions">
                    <a class="btn btn--sm" href="<?= h(Url::admin('security/login-protection') . '?tab=address') ?>">Change</a>
                </div>
            </div>

            <div class="list__row">
                <div class="entry-list__main">
                    <a class="list__row-link list__row-title"
                       href="<?= h(Url::admin('security/login-protection') . '?tab=attempts') ?>">Attempt limit</a>
                    <?php
                    // Per address, then per username — each only when it is
                    // on, so "off" is never described as a number.
                    $limits = [];
                    if ($limit['enabled']) {
                        $limits[] = (int) $limit['attempts'] . ' attempts, then ' . Throttle::duration((int) $limit['minutes'])
                            . ((int) $limit['escalate_after'] > 0
                                ? '; ' . Throttle::duration((int) $limit['escalate_hours'] * 60) . ' after ' . (int) $limit['escalate_after'] . ' lockouts in a day'
                                : '');
                    }
                    if ($account['enabled']) {
                        $limits[] = (int) $account['attempts'] . ' on one username, then ' . Throttle::duration((int) $account['minutes']);
                    }
                    ?>
                    <div class="list__row-sub"><?= $limits === [] ? 'Any number of guesses' : h(implode(' · ', $limits)) ?></div>
                </div>
                <div>
                    <?= $limits !== []
                        ? $chip('check', 'On', 'status--active')
                        : $chip('toggle-off', 'Off') ?>
                </div>
                <div class="list__row-meta"></div>
                <div class="list__row-actions">
                    <a class="btn btn--sm" href="<?= h(Url::admin('security/login-protection') . '?tab=attempts') ?>">Change</a>
                </div>
            </div>

            <div class="list__row">
                <div class="entry-list__main">
                    <a class="list__row-link list__row-title"
                       href="<?= h(Url::admin('security/login-protection') . '?tab=usernames') ?>">Common usernames</a>
                    <div class="list__row-sub"><?= $usernames['enabled']
                        ? count($usernames['list']) . ' names refused'
                        : 'Any name may be used' ?></div>
                </div>
                <div>
                    <?= $usernames['enabled']
                        ? $chip('check', 'On', 'status--active')
                        : $chip('toggle-off', 'Off') ?>
                </div>
                <div class="list__row-meta"></div>
                <div class="list__row-actions">
                    <a class="btn btn--sm" href="<?= h(Url::admin('security/login-protection') . '?tab=usernames') ?>">Change</a>
                </div>
            </div>

            <?php // THE RULE, and — the fact an owner acts on — how many accounts
                  // still owe a new password. The seeded one is named: it is the
                  // password every copy of this software starts with. ?>
            <?php $owed = array_sum(array_map('intval', $pending)); ?>
            <div class="list__row">
                <div class="entry-list__main">
                    <a class="list__row-link list__row-title"
                       href="<?= h(Url::admin('security/login-protection') . '?tab=passwords') ?>">Password strength</a>
                    <div class="list__row-sub"><?= h($rule) ?></div>
                </div>
                <div>
                    <?php if (isset($pending['seed'])): ?>
                        <?= $chip('triangle-exclamation', 'Starting password in use') ?>
                    <?php elseif ($owed > 0): ?>
                        <?= $chip('clock', $owed === 1 ? '1 to change' : $owed . ' to change') ?>
                    <?php else: ?>
                        <?= $chip('check', 'On', 'status--active') ?>
                    <?php endif; ?>
                </div>
                <div class="list__row-meta"></div>
                <div class="list__row-actions">
                    <a class="btn btn--sm" href="<?= h(Url::admin('security/login-protection') . '?tab=passwords') ?>">Change</a>
                </div>
            </div>

            <?php // Inactive accounts: the rule, and — the number an owner may
                  // want to look at — how many accounts are suspended
                  // now, by the rule or by an administrator. ?>
            <?php $covered = $inactive['enabled'] && $inactive['roles'] !== []; ?>
            <div class="list__row">
                <div class="entry-list__main">
                    <a class="list__row-link list__row-title"
                       href="<?= h(Url::admin('security/login-protection') . '?tab=inactive') ?>">Inactive accounts</a>
                    <div class="list__row-sub"><?= $covered
                        ? h(implode(' and ', array_intersect_key(\Cms\Security\Accounts::ROLES, array_flip($inactive['roles'])))
                            . ' suspended after ' . (int) $inactive['days'] . ' days unused')
                        : 'Never suspended for going unused' ?></div>
                </div>
                <div>
                    <?php if ($suspendedCount > 0): ?>
                        <?= $chip('pause', $suspendedCount === 1 ? '1 suspended' : $suspendedCount . ' suspended') ?>
                    <?php elseif ($covered): ?>
                        <?= $chip('check', 'On', 'status--active') ?>
                    <?php else: ?>
                        <?= $chip('toggle-off', 'Off') ?>
                    <?php endif; ?>
                </div>
                <div class="list__row-meta"></div>
                <div class="list__row-actions">
                    <a class="btn btn--sm" href="<?= h(Url::admin('security/login-protection') . '?tab=inactive') ?>">Change</a>
                </div>
            </div>

            <?php // Two-step sign-in: who must, and how many do. The
                  // chip counts the accounts the policy covers that have not
                  // set it up — the number an owner can do something about. ?>
            <?php $unset = $twoStep['adoption']['required'] - $twoStep['adoption']['requiredOn']; ?>
            <div class="list__row">
                <div class="entry-list__main">
                    <a class="list__row-link list__row-title" href="<?= h(Url::admin('security/two-step')) ?>">Two-step sign-in</a>
                    <div class="list__row-sub"><?= h($twoStep['policy']) ?> · <?= (int) $twoStep['adoption']['on'] ?> of <?= (int) $twoStep['adoption']['total'] ?> accounts use it</div>
                </div>
                <div>
                    <?php if (!$twoStep['ready']): ?>
                        <?= $chip('triangle-exclamation', 'Needs an app_secret') ?>
                    <?php elseif ($unset > 0): ?>
                        <?= $chip('clock', $unset === 1 ? '1 to set up' : $unset . ' to set up') ?>
                    <?php elseif ($twoStep['adoption']['required'] > 0): ?>
                        <?= $chip('check', 'On', 'status--active') ?>
                    <?php else: ?>
                        <?= $chip('toggle-off', 'Optional') ?>
                    <?php endif; ?>
                </div>
                <div class="list__row-meta"></div>
                <div class="list__row-actions">
                    <a class="btn btn--sm" href="<?= h(Url::admin('security/two-step')) ?>">Change</a>
                </div>
            </div>

            <?php // Signing in without a password: which ways. Off is a choice
                  // here, not a gap — it is a convenience. On only where
                  // the sign-in page offers it: a development install sends no
                  // mail, so a way switched on there is still not offered. ?>
            <?php
            $ways = array_values(array_filter([
                $passwordless['code'] ? 'a code' : null,
                $passwordless['link'] ? 'a link' : null,
            ]));
            $waysOffered = $ways !== [] && $emailSends;
            ?>
            <div class="list__row">
                <div class="entry-list__main">
                    <a class="list__row-link list__row-title" href="<?= h(Url::admin('security/passwordless')) ?>">Passwordless</a>
                    <div class="list__row-sub"><?php if ($ways === []): ?>Everyone signs in with a password<?php elseif ($waysOffered): ?><?= h(ucfirst(implode(' or ', $ways)) . ' by email') ?><?php else: ?>Not offered: this is a development install, and it sends no email<?php endif; ?></div>
                </div>
                <div>
                    <?= $waysOffered ? $chip('check', 'On', 'status--active') : $chip('toggle-off', 'Off') ?>
                </div>
                <div class="list__row-meta"></div>
                <div class="list__row-actions">
                    <a class="btn btn--sm" href="<?= h(Url::admin('security/passwordless')) ?>">Change</a>
                </div>
            </div>

            <?php // Temporary access: how many are let in for a while.
                  // None is not a gap — it is given only when someone needs it. ?>
            <div class="list__row">
                <div class="entry-list__main">
                    <a class="list__row-link list__row-title" href="<?= h(Url::admin('security/temporary-access')) ?>">Temporary access</a>
                    <div class="list__row-sub"><?= $temporaryRunning === 0
                        ? 'Nobody is let in for a while'
                        : ($temporaryRunning === 1 ? 'One account, signed in only by its link' : $temporaryRunning . ' accounts, each signed in only by its link') ?></div>
                </div>
                <div>
                    <?= $temporaryRunning > 0 ? $chip('stopwatch', $temporaryRunning . ' running', 'status--active') : $chip('toggle-off', 'None') ?>
                </div>
                <div class="list__row-meta"></div>
                <div class="list__row-actions">
                    <a class="btn btn--sm" href="<?= h(Url::admin('security/temporary-access')) ?>">Open</a>
                </div>
            </div>

            <?php // Blocking: the question, and the address list. ?>
            <div class="list__row">
                <div class="entry-list__main">
                    <a class="list__row-link list__row-title" href="<?= h(Url::admin('security/blocking')) ?>">CAPTCHA</a>
                    <div class="list__row-sub"><?= h(\Cms\Security\Challenge::MODES[$blocking['captcha']]) ?></div>
                </div>
                <div>
                    <?= $blocking['captcha'] === 'off'
                        ? $chip('toggle-off', 'Off')
                        : $chip('check', 'On', 'status--active') ?>
                </div>
                <div class="list__row-meta"></div>
                <div class="list__row-actions">
                    <a class="btn btn--sm" href="<?= h(Url::admin('security/blocking')) ?>">Change</a>
                </div>
            </div>
            <?php $listed = count($blocking['addresses']['list']); ?>
            <div class="list__row">
                <div class="entry-list__main">
                    <a class="list__row-link list__row-title" href="<?= h(Url::admin('security/blocking') . '?tab=addresses') ?>">Addresses</a>
                    <div class="list__row-sub"><?= h(match ($blocking['addresses']['mode']) {
                        'block' => ($listed === 1 ? '1 address or range' : $listed . ' addresses and ranges') . ' turned away',
                        'allow' => 'Only ' . ($listed === 1 ? '1 address or range' : $listed . ' addresses and ranges') . ' let in',
                        default => 'No address is turned away',
                    }) ?></div>
                </div>
                <div>
                    <?= $blocking['addresses']['mode'] === 'off'
                        ? $chip('toggle-off', 'Off')
                        : $chip('check', 'On', 'status--active') ?>
                </div>
                <div class="list__row-meta"></div>
                <div class="list__row-actions">
                    <a class="btn btn--sm" href="<?= h(Url::admin('security/blocking') . '?tab=addresses') ?>">Change</a>
                </div>
            </div>

            <?php // The CMS's files against what shipped, compared
                  // as this page opened. A development tree has nothing to
                  // compare with, and says so. ?>
            <?php $differ = $files === null ? 0 : count($files['modified']) + count($files['missing']) + count($files['unknown']); ?>
            <div class="list__row">
                <div class="entry-list__main">
                    <a class="list__row-link list__row-title" href="<?= h(Url::admin('security/files')) ?>">Files</a>
                    <div class="list__row-sub"><?= $files === null
                        ? 'Not an install: no release manifest to compare with'
                        : h(\Cms\Security\FileCheck::summary($files)) ?></div>
                </div>
                <div>
                    <?php if ($files === null): ?>
                        <?= $chip('toggle-off', 'Not an install') ?>
                    <?php elseif ($differ > 0): ?>
                        <?= $chip('file', $differ === 1 ? '1 differs' : $differ . ' differ') ?>
                    <?php else: ?>
                        <?= $chip('check', 'As shipped', 'status--active') ?>
                    <?php endif; ?>
                </div>
                <div class="list__row-meta"></div>
                <div class="list__row-actions">
                    <a class="btn btn--sm" href="<?= h(Url::admin('security/files')) ?>">Open</a>
                </div>
            </div>
        </div>
    </div>

    <div class="panel__section">
        <div class="panel__heading">
            <h3>Recent activity</h3>
        </div>

        <?php if ($recent === []): ?>
            <div class="empty">
                <?= icon('signal', 'empty__glyph', '', 28) ?>
                <p class="empty__title">Nothing yet</p>
            </div>
        <?php else: ?>
            <div class="list entry-list">
                <?php foreach ($recent as $row): ?>
                    <?php
                    $name = trim((string) ($row['username'] ?? ''));
                    [$glyph, $label, $mod] = SecurityLog::describe($row);
                    ?>
                    <div class="list__row">
                        <div class="entry-list__main">
                            <?php if ((string) $row['event'] === SecurityLog::FILES_CHANGED): ?>
                                <?php // The site's own finding: what changed, not who asked. ?>
                                <a class="list__row-link list__row-title" href="<?= h(Url::admin('security/files')) ?>">Core files</a>
                                <div class="list__row-sub"><?= h((string) ($row['detail'] ?? '')) ?></div>
                            <?php else: ?>
                                <span class="list__row-title"><?= $name !== '' ? h($name) : h(ClientIp::display((string) $row['ip'])) ?></span>
                                <div class="list__row-sub">
                                    <?= h(SecurityLog::browser($row['user_agent'] ?? null)) ?>
                                    · <?= h(ClientIp::display((string) $row['ip'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div><?= $chip($glyph, $label, $mod) ?></div>
                        <div class="list__row-meta">
                            <span><?= h(Dates::stamp((string) $row['created_at'])) ?></span>
                        </div>
                        <div class="list__row-actions"></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p>
                <a class="link link--xs" href="<?= h($logUrl('failed')) ?>">All activity</a>
            </p>
        <?php endif; ?>
    </div>

</div>
