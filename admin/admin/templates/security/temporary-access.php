<?php

declare(strict_types=1);

use Cms\Helpers\Asset;
use Cms\Helpers\Dates;
use Cms\Helpers\Url;
use Cms\Models\User;
use Cms\Security\Csrf;
use Cms\Security\TemporaryAccess;

/**
 * SECURITY → TEMPORARY ACCESS — accounts signed in only by their link, for a
 * while (`Security\TemporaryAccess` has the rules). A FRAGMENT:
 * `SecurityController` owns `ob_start()` and `layout.php`.
 *
 *   the link just made   shown ONCE: only its hash is kept
 *   the form             give someone access — or, at `?renew=ID`, renew one
 *   the list             every temporary account: what is left of it, and
 *                        New link · Revoke · Renew
 *
 * @var list<string>                    $errors
 * @var string|null                     $notice
 * @var array<string, mixed>|null       $made      user_id, link, how (given | renewed | replaced), access, account
 * @var array<string, mixed>|null       $renewing  access, account
 * @var array<string, string>           $form      email, name, role, count, unit, uses
 * @var list<array<string, mixed>>      $accesses  TemporaryAccess::all()
 * @var int                             $now
 */

$screen = Url::admin('security/temporary-access');
// Where an access stands, as a chip: the glyph and the word.
$states = [
    'active'  => ['check', 'Active', 'status--active'],
    'used'    => ['stop', 'Used up', ''],
    'expired' => ['clock', 'Ended', 'status--complete'],
    'revoked' => ['user-slash', 'Revoked', ''],
];
$chip = static fn (string $state): string => '<span class="status status--phrase ' . $states[$state][2] . '">'
    . icon($states[$state][0], 'status__icon') . h($states[$state][1]) . '</span>';
$left = static fn (array $a): int => max(0, (int) $a['max_uses'] - (int) $a['uses']);
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Temporary access</h1>
        </div>
    </div>

    <?php if ($notice !== null): ?>
        <div class="flashes"><div class="flash flash--success"><?= h($notice) ?></div></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert--danger">
            <?= icon('triangle-exclamation', 'alert__icon icon', '', 18) ?>
            <div class="alert__body">
                <p class="alert__title"><?= count($errors) === 1 ? 'One thing needs fixing' : count($errors) . ' things need fixing' ?></p>
                <ul class="alert__text">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="form-main">

        <?php if ($made !== null && $made['access'] !== null): ?>
            <?php // THE LINK, ONCE. Only its hash is kept, so this page is the
                  // only place it is ever shown; a lost one is replaced. ?>
            <div class="form-card">
                <div class="form-card__section">
                    <?= panel_title(match ($made['how']) {
                        'renewed'  => 'Access renewed',
                        'replaced' => 'A new link',
                        default    => 'Access given',
                    }, 'temporary-access') ?>
                    <div class="field">
                        <label class="label" for="f-access-link">The link for <?= h(User::displayName((array) $made['account'])) ?></label>
                        <div class="input-row">
                            <input class="input input--mono" type="text" id="f-access-link" value="<?= h((string) $made['link']) ?>"
                                   readonly spellcheck="false">
                            <button type="button" class="btn btn--sm" data-copy="#f-access-link">Copy</button>
                        </div>
                        <p class="form-help">Copy it now: it is not shown again. It signs in
                            <?= $left($made['access']) === 1 ? 'once' : h((string) $left($made['access'])) . ' times' ?>, until
                            <?= h(Dates::stamp((string) $made['access']['expires_at'])) ?>. Send it the way you would send a
                            password.<?= $made['how'] === 'replaced' ? ' The old link no longer works.' : '' ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <form class="form-card" method="post" action="<?= h($screen) ?>">
            <?= Csrf::field() ?>
            <div class="form-card__section">
                <?php if ($renewing !== null): ?>
                    <?php $account = (array) $renewing['account']; ?>
                    <input type="hidden" name="_do" value="renew">
                    <input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                    <?= panel_title('Renew ' . User::displayName($account) . '’s access', 'temporary-access') ?>
                    <?php // A `.field`, so the form's rhythm spaces it from the terms below. ?>
                    <div class="field">
                        <p class="form-help"><?= h((string) $account['email']) ?> · <?= h(TemporaryAccess::ROLES[(string) $account['role']] ?? ucfirst((string) $account['role'])) ?>.
                            A new link, its uses counted from nothing and its time from now. The account is restored if it was suspended.</p>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="_do" value="give">
                    <?= panel_title('Give someone temporary access', 'temporary-access') ?>
                    <div class="form-grid form-grid--2">
                        <div class="field">
                            <label class="label" for="f-access-email">Their email address</label>
                            <input class="input" type="email" id="f-access-email" name="email" value="<?= h($form['email']) ?>"
                                   autocomplete="off" required>
                        </div>
                        <div class="field">
                            <label class="label" for="f-access-name">Their name</label>
                            <input class="input" type="text" id="f-access-name" name="name" value="<?= h($form['name']) ?>"
                                   maxlength="100" autocomplete="off">
                        </div>
                    </div>
                    <div class="field">
                        <label class="label" for="f-access-role">Role</label>
                        <select class="select" id="f-access-role" name="role">
                            <?php foreach (TemporaryAccess::ROLES as $role => $label): ?>
                                <option value="<?= h($role) ?>" <?= $form['role'] === $role ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-help">Never an administrator. The account is its own, so what they do is theirs in every log.</p>
                    </div>
                <?php endif; ?>

                <div class="form-grid form-grid--2">
                    <div class="field">
                        <label class="label" for="f-access-count">It lasts</label>
                        <div class="input-row">
                            <input class="input input--count" type="number" id="f-access-count" name="count" min="1" max="365"
                                   value="<?= h($form['count']) ?>" inputmode="numeric" required>
                            <select class="select" name="unit" aria-label="Hours or days">
                                <?php foreach (['hours' => 'Hours', 'days' => 'Days'] as $unit => $label): ?>
                                    <option value="<?= $unit ?>" <?= $form['unit'] === $unit ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <p class="form-help">From an hour to 365 days. Then the account is suspended, and every browser signed in with it is signed out.</p>
                    </div>
                    <div class="field">
                        <label class="label" for="f-access-uses">Uses</label>
                        <input class="input input--count" type="number" id="f-access-uses" name="uses"
                               min="<?= TemporaryAccess::USES[0] ?>" max="<?= TemporaryAccess::USES[1] ?>"
                               value="<?= h($form['uses']) ?>" inputmode="numeric" required>
                        <p class="form-help">How many times the link signs in.</p>
                    </div>
                </div>
            </div>

            <div class="form-card__section form-actions">
                <?php if ($renewing !== null): ?>
                    <a class="link link--xs" href="<?= h($screen) ?>">Cancel</a>
                <?php endif; ?>
                <div class="form-actions__spacer"></div>
                <button type="submit" class="btn btn--primary"><?= $renewing !== null ? 'Renew access' : 'Make the link' ?></button>
            </div>
        </form>

        <div class="form-card">
            <div class="form-card__section">
                <?= panel_title('Temporary accounts', 'temporary-accounts') ?>
                <?php if ($accesses === []): ?>
                    <p class="form-help">None. Each account made here is listed, with what is left of it, and on Users.</p>
                <?php else: ?>
                    <div class="list list--bleed entry-list">
                        <?php foreach ($accesses as $a): ?>
                            <?php $state = TemporaryAccess::state($a, $now); $id = (int) $a['user_id']; ?>
                            <div class="list__row">
                                <div class="entry-list__main">
                                    <a class="list__row-link list__row-title"
                                       href="<?= h(Url::admin('users/' . $id . '/edit')) ?>"><?= h(User::displayName($a)) ?></a>
                                    <?php // One line, as every list's second line is: who, as what, and the uses. ?>
                                    <?php $sub = (string) $a['email'] . ' · ' . (TemporaryAccess::ROLES[(string) $a['role']] ?? ucfirst((string) $a['role']))
                                        . ' · ' . (int) $a['uses'] . ' of ' . (int) $a['max_uses'] . ((int) $a['max_uses'] === 1 ? ' use' : ' uses'); ?>
                                    <div class="list__row-sub" title="<?= h($sub) ?>"><?= h($sub) ?></div>
                                </div>
                                <div><?= $chip($state) ?></div>
                                <?php // THE META TRACK IS 168px: one date. The time is in its title. ?>
                                <div class="list__row-meta">
                                    <?php $when = $state === 'active' || $state === 'used' ? (string) $a['expires_at'] : ((string) $a['ended_at'] ?: (string) $a['expires_at']); ?>
                                    <span title="<?= h(Dates::stamp($when)) ?>"><?= $state === 'active' || $state === 'used' ? 'until' : 'ended' ?> <?= h(Dates::short($when)) ?></span>
                                </div>
                                <div class="list__row-actions">
                                    <?php if ($state === 'active'): ?>
                                        <button type="submit" class="btn btn--sm" form="access-link-<?= $id ?>">New link</button>
                                    <?php else: ?>
                                        <a class="btn btn--sm" href="<?= h($screen . '?renew=' . $id) ?>">Renew</a>
                                    <?php endif; ?>
                                    <?php if ($state === 'active' || $state === 'used'): ?>
                                        <button type="submit" class="btn btn--sm" form="access-revoke-<?= $id ?>">Revoke</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php foreach ($accesses as $a): ?>
        <?php $id = (int) $a['user_id']; $name = User::displayName($a); ?>
        <form id="access-link-<?= $id ?>" method="post" hidden action="<?= h($screen) ?>"
              data-confirm="A new link for <?= h($name) ?>?"
              data-confirm-detail="The link they have stops working. The new one is shown once."
              data-confirm-verb="Make a new link"><?= Csrf::field() ?><input type="hidden" name="_do" value="link"><input type="hidden" name="user_id" value="<?= $id ?>"></form>
        <form id="access-revoke-<?= $id ?>" method="post" hidden action="<?= h($screen) ?>"
              data-confirm="Revoke <?= h($name) ?>’s access?"
              data-confirm-detail="They are signed out everywhere now, and the account is suspended. Its work is kept. Renew gives them a new link."
              data-confirm-verb="Revoke"><?= Csrf::field() ?><input type="hidden" name="_do" value="revoke"><input type="hidden" name="user_id" value="<?= $id ?>"></form>
    <?php endforeach; ?>
    <script src="<?= h(Asset::admin('js/confirm.js')) ?>"></script>

</div>
