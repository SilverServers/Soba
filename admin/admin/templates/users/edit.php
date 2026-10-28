<?php

declare(strict_types=1);

use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * EDIT USER — and it is TWO screens wearing one template, because
 * `UserController::handleUpdate()` applies different rules depending on who is
 * looking:
 *
 *   EDITING YOURSELF     a new password requires the current one.
 *                        Your own role is NOT editable — the controller's role
 *                        branch is `if ($isAdmin && !$isSelf)`, so an admin
 *                        cannot demote themselves and lock the last admin out.
 *   ADMIN EDITING ANOTHER  the role select appears; the current-password field
 *                        does not, because the controller does not ask for it.
 *
 * EVERY ONE OF THOSE BRANCHES IS THE CONTROLLER'S, NOT THIS TEMPLATE'S. A field
 * rendered where the controller ignores it is a control that does nothing — a
 * read-only row in a different costume. So the rule here is: if
 * `handleUpdate()` will not read the input under these conditions, do not print
 * it.
 *
 * ── THE USERNAME IS SHOWN AND NOT EDITABLE ──────────────────────────────────
 * `handleUpdate()` reads `email`, `role`, and the password trio. It never reads
 * `username`. Rendering a username input would post a value that is silently
 * discarded — the worst kind of control, because it looks like it worked.
 * It is printed as a FACT instead, the same device `content-types/edit.php`
 * uses for the immutable type key, and for the same reason.
 *
 * @var array<string,mixed> $profile  the user being edited (re-fetched after save)
 * @var array<string,mixed> $user     the signed-in user
 * @var bool                $isAdmin  viewer is an admin
 * @var bool                $isSelf   viewer is editing their own account
 * @var list<string>        $errors
 * @var bool                $saved    handleUpdate() only — absent on first GET
 * @var list<array<string,mixed>> $sessions  Security\Sessions::forUser() — where it is signed in
 * @var int|null            $ended    after a sign-out from the list: how many browsers
 */

$errors = $errors ?? [];
$saved  = $saved  ?? false;

$targetId  = (int) $profile['id'];
$pageTitle = $isSelf ? 'Your account' : (string) $profile['username'];

$roleLabels = [
    'admin'  => 'Administrator',
    'editor' => 'Editor',
    'author' => 'Author',
];

ob_start();
?>
<div class="page">

    <?php // AN ADMIN CAME FROM THE LIST AND CAN GO BACK TO IT. Anyone else was
          // redirected here by the controller and the list would bounce them
          // straight back, so the link is not drawn — a way out that returns you
          // to where you are is worse than none. ?>
    <?php if ($isAdmin): ?>
        <a class="page__back" href="<?= h(Url::admin('users')) ?>">
            <?= icon('angle-left', 'icon icon--sm') ?>Users
        </a>
    <?php endif; ?>

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title"><?= h($pageTitle) ?></h1>
        </div>
    </div>

    <?php if ($saved && $errors === []): ?>
        <div class="flashes">
            <div class="flash flash--success">Account updated.</div>
        </div>
    <?php endif; ?>

    <?php if (($ended ?? null) !== null): ?>
        <div class="flashes">
            <div class="flash flash--success"><?= $ended === 1 ? '1 browser' : (int) $ended . ' browsers' ?> signed out.</div>
        </div>
    <?php endif; ?>

    <?php // Back from a two-step change (`TwoStepController`), which says what it did.
    $done = [
        'app-on'       => 'The authenticator app is on. Your other browsers have been signed out.',
        'email-on'     => 'Codes by email are on. Your other browsers have been signed out.',
        'app-off'      => 'The authenticator app is removed. Your other browsers have been signed out.',
        'email-off'    => 'Codes by email are removed. Your other browsers have been signed out.',
        'forgot'       => 'Forgotten: the second step will be asked for there again.',
        'two-step-off' => 'Two-step sign-in is off for this account, and it has been signed out everywhere.',
        'suspended'    => 'Suspended. It is signed out everywhere, and cannot sign in until it is restored.',
        'restored'     => 'Restored. It can sign in again.',
    ][(string) ($_GET['done'] ?? '')] ?? null; ?>
    <?php if ($done !== null): ?>
        <div class="flashes"><div class="flash flash--success"><?= h($done) ?></div></div>
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

    <form method="post" action="<?= h(Url::admin('users/' . $targetId . '/edit')) ?>">
        <?= Csrf::field() ?>

        <?php // `.form-main` IS WHAT PUTS A FORM ON THE SURFACE LADDER. Without
              // it this card would be `--surface` and every `.input` inside it
              // `--surface` too.
              //
              // The tone rules are scoped `.form-main .input` / `.form-aside
              // .input` (admin.css §7a) precisely so a control declares no tone
              // of its own; a form outside both columns therefore has no ladder
              // at all. A single-column form is a DOCUMENT column with no aside,
              // so it takes `.form-main` rather than a third case. ?>
        <div class="form-main">
        <?php // ── SUSPENDED (migration 067, `Security\Accounts`) ────────────
              // First, because it is the thing about this account that
              // matters most. A state, so a panel with its facts — not an
              // alert: alert colour is a surface, never a state. ?>
        <?php $suspended = $isAdmin && !$isSelf && ($profile['suspended_at'] ?? null) !== null; ?>
        <?php // A TEMPORARY account has its own card below, which says when it
              // was suspended and offers Renew, not Restore. ?>
        <?php $temporary = \Cms\Security\TemporaryAccess::of((int) $profile['id']); ?>
        <?php if ($suspended && $temporary === null): ?>
            <?php
            $byName = ($profile['suspended_by'] ?? null) !== null
                ? (string) (\Cms\Models\User::find((int) $profile['suspended_by'])['username'] ?? '')
                : '';
            ?>
            <div class="form-card">
                <div class="form-card__section">
                    <?= panel_title('Suspended', 'suspended') ?>
                    <div class="kv-list">
                        <div class="kv-list__row">
                            <span class="kv-list__key">Why</span>
                            <span class="kv-list__val"><?= h(\Cms\Security\Accounts::why(['suspended_by_name' => $byName] + $profile)) ?></span>
                        </div>
                        <div class="kv-list__row">
                            <span class="kv-list__key">Since</span>
                            <span class="kv-list__val"><?= h(\Cms\Helpers\Dates::stamp((string) $profile['suspended_at'])) ?></span>
                        </div>
                    </div>
                    <p class="form-help">It cannot sign in — by password, a reset link or a code — until it is restored.
                        Restoring it starts the inactivity rule's clock again.</p>
                </div>
                <div class="form-card__section form-actions">
                    <div class="form-actions__spacer"></div>
                    <button type="submit" class="btn btn--primary" form="account-restore">Restore</button>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($temporary !== null && ($isAdmin || $isSelf)): ?>
            <?php
            $accessState = \Cms\Security\TemporaryAccess::state($temporary, time());
            $accessChip  = [
                'active'  => ['check', 'Active', 'status--active'],
                'used'    => ['stop', 'Used up', ''],
                'expired' => ['clock', 'Ended', 'status--complete'],
                'revoked' => ['user-slash', 'Revoked', ''],
            ][$accessState];
            $accessUrl   = Url::admin('security/temporary-access');
            $manage      = $isAdmin && !$isSelf;
            ?>
            <div class="form-card">
                <div class="form-card__section">
                    <?= panel_title('Temporary access', 'temporary-access') ?>
                    <div class="kv-list">
                        <div class="kv-list__row">
                            <span class="kv-list__key">State</span>
                            <span class="kv-list__val"><span class="status status--phrase <?= $accessChip[2] ?>"><?= icon($accessChip[0], 'status__icon') ?><?= h($accessChip[1]) ?></span></span>
                        </div>
                        <div class="kv-list__row">
                            <span class="kv-list__key">Uses</span>
                            <span class="kv-list__val"><?= (int) $temporary['uses'] ?> of <?= (int) $temporary['max_uses'] ?></span>
                        </div>
                        <div class="kv-list__row">
                            <span class="kv-list__key"><?= $accessState === 'active' || $accessState === 'used' ? 'Until' : 'Ended' ?></span>
                            <span class="kv-list__val"><?= h(\Cms\Helpers\Dates::stamp((string) ($temporary['ended_at'] ?? null ?: $temporary['expires_at']))) ?></span>
                        </div>
                        <?php if ($manage): ?>
                            <div class="kv-list__row">
                                <span class="kv-list__key">Given by</span>
                                <span class="kv-list__val"><?= h((string) ($temporary['created_by_name'] ?? '') !== '' ? (string) $temporary['created_by_name'] : 'An administrator') ?>, <?= h(\Cms\Helpers\Dates::short((string) $temporary['created_at'])) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <p class="form-help">It signs in only by its link — never with a password. When its time runs out, or it is
                        revoked, it is suspended and every browser signed in with it is signed out; its work is kept.</p>
                </div>
                <?php if ($manage): ?>
                    <div class="form-card__section form-actions">
                        <a class="link link--xs" href="<?= h($accessUrl) ?>">All temporary access</a>
                        <div class="form-actions__spacer"></div>
                        <?php if ($accessState === 'active'): ?>
                            <button type="submit" class="btn" form="access-link">New link</button>
                        <?php else: ?>
                            <a class="btn<?= $accessState === 'used' ? '' : ' btn--primary' ?>" href="<?= h($accessUrl . '?renew=' . (int) $profile['id']) ?>">Renew</a>
                        <?php endif; ?>
                        <?php if ($accessState === 'active' || $accessState === 'used'): ?>
                            <button type="submit" class="btn" form="access-revoke">Revoke</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="form-card">

            <div class="form-card__section">
                <?= panel_title('Account', 'account') ?>

                <?php // A FACT, NOT A DISABLED INPUT. `handleUpdate()` never
                      // reads `username`, and a greyed box invites a click that
                      // does nothing (taxonomies/edit.php's docblock makes the
                      // same point for the same reason). ?>
                <div class="field">
                    <span class="label">Username</span>
                    <?php // `.kv-list` IS THE EXISTING RECIPE for a value shown
                          // rather than edited — content-types/edit.php uses it
                          // for the immutable type key. A new `.form-static`
                          // was written here first and deleted: two classes for
                          // one job is how a vocabulary drifts. ?>
                    <div class="kv-list">
                        <div class="kv-list__row">
                            <span class="kv-list__val"><?= h((string) $profile['username']) ?></span>
                        </div>
                    </div>
                </div>

                <div class="field">
                    <label class="label" for="f-display-name">Name shown on posts</label>
                    <input class="input" type="text" id="f-display-name" name="display_name" maxlength="100"
                           value="<?= h((string) ($_POST['display_name'] ?? $profile['display_name'] ?? '')) ?>" autocomplete="name">
                    <p class="form-help">The byline on what they write. Empty, the byline credits the site — never the username.</p>
                </div>

                <?php if ($isAdmin && \Cms\Helpers\People::declared()): ?>
                    <?php
                    // Which of the site's people this account is (change 26) —
                    // an administrator's call; see the partial. What it holds NOW,
                    // after a refused save too: the error says what was refused.
                    $personNow     = (string) ($profile['person_key'] ?? '');
                    $personAccount = (int) $profile['id'];
                    require ROOT_PATH . '/admin/templates/partials/person-field.php';
                    ?>
                <?php endif; ?>

                <div class="field">
                    <label class="label" for="f-email">Email</label>
                    <input class="input" type="email" id="f-email" name="email"
                           value="<?= h((string) $profile['email']) ?>" autocomplete="email">
                </div>

                <?php if ($isAdmin && !$isSelf): ?>
                    <div class="field">
                        <label class="label" for="f-role">Role</label>
                        <select class="select" id="f-role" name="role">
                            <?php foreach ($temporary !== null ? array_intersect_key($roleLabels, \Cms\Security\TemporaryAccess::ROLES) : $roleLabels as $key => $label): ?>
                                <option value="<?= h($key) ?>"<?= $profile['role'] === $key ? ' selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <?php // YOUR OWN ROLE IS A FACT TOO. The controller's branch
                          // is `$isAdmin && !$isSelf`, so a select here would
                          // post a role that is thrown away — and the rule it
                          // enforces is worth stating plainly, because "why
                          // can't I change this" is the obvious question. ?>
                    <div class="field">
                        <span class="label">Role</span>
                        <div class="kv-list">
                            <div class="kv-list__row">
                                <span class="kv-list__val"><?= h($roleLabels[(string) $profile['role']] ?? (string) $profile['role']) ?></span>
                            </div>
                        </div>
                        <?php if ($isSelf): ?>
                            <p class="form-help">You cannot change your own role. Another administrator can.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php // WHEN IT WAS LAST USED — the inactivity rule's clock
                      // (migration 067, `Security\Accounts`): a sign-in, or a
                      // day it was in use. A date, because it moves once a day. ?>
                <?php if ($isAdmin && !$isSelf): ?>
                    <div class="field">
                        <span class="label">Last seen</span>
                        <div class="kv-list">
                            <div class="kv-list__row">
                                <?php if (($profile['last_seen_at'] ?? null) !== null): ?>
                                    <span class="kv-list__val"><?= h(\Cms\Helpers\Dates::short((string) $profile['last_seen_at'])) ?></span>
                                <?php else: ?>
                                    <span class="kv-list__val kv-list__val--muted">Never signed in</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($temporary !== null): ?>
            <div class="form-card__section">
                <?= panel_title('Password', 'password') ?>
                <p class="form-help">None. A temporary account signs in only by its link.</p>
            </div>
            <?php else: ?>
            <div class="form-card__section">
                <?= panel_title('Password', 'password') ?>

                <?php if ($isSelf): ?>
                    <div class="field">
                        <label class="label" for="f-current-password">Current password</label>
                        <input class="input" type="password" id="f-current-password"
                               name="current_password" autocomplete="current-password">
                        <p class="form-help">Required only if you are setting a new password.</p>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label class="label" for="f-new-password">New password</label>
                    <input class="input" type="password" id="f-new-password"
                           name="new_password" minlength="<?= (int) \Cms\Security\PasswordPolicy::settings()['min'] ?>" autocomplete="new-password">
                    <p class="form-help"><?= h(\Cms\Security\PasswordPolicy::summary()) ?> Leave blank to keep the current one.<?= !$isSelf && \Cms\Auth\Auth::enforcesPasswordChanges() ? ' They choose their own at their next sign-in.' : '' ?></p>
                </div>

                <div class="field">
                    <label class="label" for="f-new-password-confirm">Confirm new password</label>
                    <input class="input" type="password" id="f-new-password-confirm"
                           name="new_password_confirm" minlength="<?= (int) \Cms\Security\PasswordPolicy::settings()['min'] ?>" autocomplete="new-password">
                </div>
            </div>
            <?php endif; ?>

            <div class="form-card__section form-actions">
                <?php if ($isAdmin): ?>
                    <a class="link link--xs" href="<?= h(Url::admin('users')) ?>">Cancel</a>
                <?php endif; ?>
                <div class="form-actions__spacer"></div>
                <button type="submit" class="btn btn--primary">Save changes</button>
            </div>

        </div>

        <?php // ── TWO-STEP SIGN-IN (migration 063) ───────────────────────────
              // What the account HAS, and the buttons to change it. Setting a
              // method up is its own page (/admin/two-step), the one the site's
              // policy sends an account to; this is what is kept. For someone
              // else's account an administrator sees what they have and can
              // turn it off — a lost phone — but never set it up for them. ?>
        <?php
        $twoStepOn = \Cms\Security\TwoStep::settings();
        $factors   = \Cms\Security\Factors::of($targetId);
        $codesLeft = \Cms\Security\Factors::backupCodesLeft($targetId);
        $browsers  = \Cms\Security\TrustedBrowsers::of($targetId);
        $kinds     = ['app' => 'Authenticator app', 'email' => 'A code by email'];
        ?>
        <div class="form-card" id="two-step">
            <div class="form-card__section">
                <?= panel_title('Two-step sign-in', 'two-step') ?>
                <?php if (!\Cms\Security\TwoStep::ready()): ?>
                    <p class="form-help">Not available on this site yet: config.php has no app_secret of its own.</p>
                <?php else: ?>
                    <?php // Said BEFORE the list, so the list ends the section and
                          // can reach the card's edges (`--bleed`): inset, it would
                          // sit in the card's own --surface with a band of card
                          // round it, at any height. ?>
                    <?php if (\Cms\Security\TwoStep::requiredFor($profile)): ?>
                        <p class="form-help">This site asks <?= h(strtolower(\Cms\Security\TwoStep::ROLE_NAMES[(string) $profile['role']] ?? 'this role')) ?> to use it.</p>
                    <?php endif; ?>
                    <div class="list list--bleed entry-list">
                        <?php foreach ($kinds as $kind => $name): ?>
                            <?php if (!$twoStepOn[$kind] && !isset($factors[$kind])) { continue; } ?>
                            <div class="list__row">
                                <div class="entry-list__main">
                                    <span class="list__row-title"><?= h($name) ?></span>
                                    <div class="list__row-sub">
                                        <?php if (isset($factors[$kind])): ?>
                                            Set up <?= h(\Cms\Helpers\Dates::stamp((string) $factors[$kind]['created_at'])) ?><?php
                                            if ($factors[$kind]['last_used_at'] !== null): ?> · last used <?= h(\Cms\Helpers\Dates::stamp((string) $factors[$kind]['last_used_at'])) ?><?php endif;
                                            if (!$twoStepOn[$kind]): ?> · switched off for this site<?php endif; ?>
                                        <?php else: ?>
                                            Not set up
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div>
                                    <?php if (isset($factors[$kind]) && $twoStepOn[$kind]): ?>
                                        <span class="status status--phrase status--active"><?= icon('check', 'status__icon') ?>On</span>
                                    <?php endif; ?>
                                </div>
                                <div class="list__row-meta"></div>
                                <div class="list__row-actions">
                                    <?php if ($isSelf && isset($factors[$kind])): ?>
                                        <button type="submit" class="btn btn--sm" form="two-step-remove-<?= h($kind) ?>">Remove</button>
                                    <?php elseif ($isSelf && $twoStepOn[$kind]): ?>
                                        <a class="btn btn--sm" href="<?= h(Url::admin('two-step/' . $kind)) ?>">Set up</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($factors !== []): ?>
                            <div class="list__row">
                                <div class="entry-list__main">
                                    <span class="list__row-title">Backup codes</span>
                                    <div class="list__row-sub"><?= (int) $codesLeft ?> of <?= \Cms\Security\Factors::BACKUP_COUNT ?> left</div>
                                </div>
                                <div></div>
                                <div class="list__row-meta"></div>
                                <div class="list__row-actions">
                                    <?php if ($isSelf): ?>
                                        <button type="submit" class="btn btn--sm" form="two-step-codes">New codes</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($browsers !== []): ?>
                <div class="form-card__section">
                    <span class="label">Browsers that skip the second step</span>
                    <div class="list list--bleed entry-list">
                        <?php foreach ($browsers as $row): ?>
                            <div class="list__row">
                                <div class="entry-list__main">
                                    <span class="list__row-title"><?= h(\Cms\Security\SecurityLog::browser($row['user_agent'] ?? null)) ?></span>
                                    <div class="list__row-sub">
                                        <?= h(\Cms\Security\ClientIp::display((string) $row['ip'])) ?>
                                        · until <?= h(\Cms\Helpers\Dates::stamp((string) $row['expires_at'])) ?>
                                    </div>
                                </div>
                                <div>
                                    <?php if ((bool) $row['current']): ?>
                                        <span class="status status--phrase status--active"><?= icon('check', 'status__icon') ?>This browser</span>
                                    <?php endif; ?>
                                </div>
                                <div class="list__row-meta"></div>
                                <div class="list__row-actions">
                                    <button type="submit" class="btn btn--sm" form="two-step-forget-<?= (int) $row['id'] ?>">Forget</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if (count($browsers) > 1): ?>
                    <div class="form-card__section form-actions">
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn" form="two-step-forget-all">Forget them all</button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($isAdmin && !$isSelf && $factors !== []): ?>
            <?php // A DANGER ZONE: it lowers an account's protection, for a
                  // lost phone. The confirm dialog is the alert surface. ?>
            <div class="form-card form-card--danger-zone">
                <div class="form-card__section">
                    <?= panel_title('Turn off two-step sign-in', 'two-step-off') ?>
                    <p class="form-help">For a lost phone and no backup codes. Every method, backup code and remembered
                        browser of this account goes, and it is signed out everywhere. It can set two-step sign-in up
                        again once it has signed in.</p>
                </div>
                <div class="form-card__section form-actions">
                    <div class="form-actions__spacer"></div>
                    <button type="submit" class="btn btn--danger" form="two-step-off">Turn it off</button>
                </div>
            </div>
        <?php endif; ?>

        <?php // ── WHERE THIS ACCOUNT IS SIGNED IN (migration 062) ────────────
              // A card of its own in the same column, so `.form-card +
              // .form-card` spaces it. Its buttons submit the small forms
              // after this one through the `form` attribute — forms cannot
              // nest, and this is how pages/edit.php's "Move to trash" works.
              //
              // The browser asking is marked and has no button: signing IT out
              // is "Log out", in the top bar. ?>
        <?php $others = array_values(array_filter($sessions ?? [], static fn (array $r): bool => !(bool) $r['current'])); ?>
        <?php if (($sessions ?? []) !== []): ?>
            <div class="form-card">
                <div class="form-card__section">
                    <?= panel_title('Where this account is signed in', 'sessions') ?>
                    <div class="list list--bleed entry-list">
                        <?php foreach ($sessions as $row): ?>
                            <div class="list__row">
                                <div class="entry-list__main">
                                    <span class="list__row-title"><?= h(\Cms\Security\SecurityLog::browser($row['user_agent'] ?? null)) ?></span>
                                    <div class="list__row-sub">
                                        <?= h(\Cms\Security\ClientIp::display((string) $row['ip'])) ?>
                                        · signed in <?= h(\Cms\Helpers\Dates::stamp((string) $row['created_at'])) ?>
                                    </div>
                                </div>
                                <div>
                                    <?php if ((bool) $row['current']): ?>
                                        <span class="status status--phrase status--active"><?= icon('check', 'status__icon') ?>This browser</span>
                                    <?php endif; ?>
                                </div>
                                <div class="list__row-meta">
                                    <span>Active <?= h(\Cms\Helpers\Dates::stamp((string) $row['last_seen_at'])) ?></span>
                                </div>
                                <div class="list__row-actions">
                                    <?php if (!(bool) $row['current']): ?>
                                        <button type="submit" class="btn btn--sm" form="end-session-<?= (int) $row['id'] ?>">Sign out</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if ($others !== []): ?>
                    <div class="form-card__section form-actions">
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn" form="end-sessions-all"><?= $isSelf ? 'Sign out everywhere else' : 'Sign out everywhere' ?></button>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php // ── SUSPEND (migration 067) — a danger zone: it shuts an
              // account out. Never on your own account, which would leave
              // nobody to restore it. The confirm dialog is the alert surface. ?>
        <?php if ($isAdmin && !$isSelf && !$suspended && $temporary === null): ?>
            <div class="form-card form-card--danger-zone">
                <div class="form-card__section">
                    <?= panel_title('Suspend this account', 'suspended') ?>
                    <p class="form-help">It cannot sign in — by password, a reset link or a code — until an administrator
                        restores it, and every browser it is signed in on is signed out now. What it wrote, its role and
                        its password stay as they are.</p>
                </div>
                <div class="form-card__section form-actions">
                    <div class="form-actions__spacer"></div>
                    <button type="submit" class="btn btn--danger" form="account-suspend">Suspend</button>
                </div>
            </div>
        <?php endif; ?>
        </div>
    </form>

    <?php foreach ($others as $row): ?>
        <form id="end-session-<?= (int) $row['id'] ?>" method="post" hidden
              action="<?= h(Url::admin('users/' . $targetId . '/sessions/' . (int) $row['id'] . '/end')) ?>"><?= Csrf::field() ?></form>
    <?php endforeach; ?>
    <?php if ($others !== []): ?>
        <form id="end-sessions-all" method="post" hidden
              action="<?= h(Url::admin('users/' . $targetId . '/sessions/end-all')) ?>"><?= Csrf::field() ?></form>
    <?php endif; ?>

    <?php if ($isSelf): ?>
        <?php foreach ($kinds as $kind => $name): ?>
            <form id="two-step-remove-<?= h($kind) ?>" method="post" hidden action="<?= h(Url::admin('two-step/remove')) ?>"
                  data-confirm="Remove <?= $kind === 'app' ? 'the authenticator app' : 'codes by email' ?>?"
                  data-confirm-detail="Your other browsers are signed out. <?= \Cms\Security\TwoStep::requiredFor($profile) ? 'This site asks your account to use two-step sign-in, so with nothing left you will be asked to set it up again.' : '' ?>"
                  data-confirm-verb="Remove"><?= Csrf::field() ?><input type="hidden" name="kind" value="<?= h($kind) ?>"></form>
        <?php endforeach; ?>
        <form id="two-step-codes" method="post" hidden action="<?= h(Url::admin('two-step/codes')) ?>"
              data-confirm="Make new backup codes?" data-confirm-detail="The ones you have now stop working."
              data-confirm-verb="Make new codes"><?= Csrf::field() ?></form>
    <?php endif; ?>
    <?php foreach ($browsers as $row): ?>
        <form id="two-step-forget-<?= (int) $row['id'] ?>" method="post" hidden
              action="<?= h(Url::admin('users/' . $targetId . '/two-step/browsers/' . (int) $row['id'] . '/forget')) ?>"><?= Csrf::field() ?></form>
    <?php endforeach; ?>
    <?php if (count($browsers) > 1): ?>
        <form id="two-step-forget-all" method="post" hidden
              action="<?= h(Url::admin('users/' . $targetId . '/two-step/browsers/forget-all')) ?>"><?= Csrf::field() ?></form>
    <?php endif; ?>
    <?php if ($isAdmin && !$isSelf && $factors !== []): ?>
        <form id="two-step-off" method="post" hidden action="<?= h(Url::admin('users/' . $targetId . '/two-step/off')) ?>"
              data-confirm="Turn off two-step sign-in for <?= h((string) $profile['username']) ?>?"
              data-confirm-detail="Their methods, backup codes and remembered browsers go, and they are signed out everywhere."
              data-confirm-verb="Turn it off"><?= Csrf::field() ?></form>
    <?php endif; ?>
    <?php if ($temporary !== null && $isAdmin && !$isSelf): ?>
        <?php $accessName = \Cms\Models\User::displayName($profile); ?>
        <form id="access-link" method="post" hidden action="<?= h(Url::admin('security/temporary-access')) ?>"
              data-confirm="A new link for <?= h($accessName) ?>?"
              data-confirm-detail="The link they have stops working. The new one is shown once."
              data-confirm-verb="Make a new link"><?= Csrf::field() ?><input type="hidden" name="_do" value="link"><input type="hidden" name="user_id" value="<?= $targetId ?>"></form>
        <form id="access-revoke" method="post" hidden action="<?= h(Url::admin('security/temporary-access')) ?>"
              data-confirm="Revoke <?= h($accessName) ?>’s access?"
              data-confirm-detail="They are signed out everywhere now, and the account is suspended. Its work is kept. Renew gives them a new link."
              data-confirm-verb="Revoke"><?= Csrf::field() ?><input type="hidden" name="_do" value="revoke"><input type="hidden" name="user_id" value="<?= $targetId ?>"></form>
    <?php endif; ?>
    <?php if ($isAdmin && !$isSelf && !$suspended): ?>
        <form id="account-suspend" method="post" hidden action="<?= h(Url::admin('users/' . $targetId . '/suspend')) ?>"
              data-confirm="Suspend <?= h((string) $profile['username']) ?>?"
              data-confirm-detail="They are signed out everywhere now, and cannot sign in until an administrator restores the account."
              data-confirm-verb="Suspend"><?= Csrf::field() ?></form>
    <?php elseif ($suspended): ?>
        <form id="account-restore" method="post" hidden action="<?= h(Url::admin('users/' . $targetId . '/restore')) ?>"><?= Csrf::field() ?></form>
    <?php endif; ?>
    <script src="<?= h(\Cms\Helpers\Asset::admin('js/confirm.js')) ?>"></script>

</div>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
