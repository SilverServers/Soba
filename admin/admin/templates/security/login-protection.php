<?php

declare(strict_types=1);

use Cms\Controllers\Admin\SecurityController;
use Cms\Helpers\Url;
use Cms\Security\AccountLock;
use Cms\Security\Accounts;
use Cms\Security\Csrf;
use Cms\Security\LoginRedirection;
use Cms\Security\LoginUrl;
use Cms\Security\SecurityLog;
use Cms\Security\Throttle;

/**
 * SECURITY → LOGIN PROTECTION — its parts as tabs. A FRAGMENT:
 * `SecurityController` owns `ob_start()` and `layout.php`.
 *
 *   Sign-in address   the switch, the address, and where the old addresses
 *                     send people
 *   Attempt limit     attempts, lockout, message — plus a longer lockout after
 *                     repeated ones (by default after 4 lockouts, 24 hours)
 *   Common usernames  the switch and the list
 *   Password strength length, the three composition boxes, the blocklists, old
 *                     passwords held to the rule — and a danger zone to ask
 *                     everyone for a new one
 *
 * @var string                $tab
 * @var list<string>          $errors
 * @var bool                  $saved
 * @var array<string, string> $address   enabled, slug, redirect
 * @var array<string, string> $limit     enabled, attempts, minutes, escalate_after, escalate_hours,
 *                                       account_enabled, account_attempts, account_minutes, message
 * @var bool                  $moved     is the sign-in page moved right now
 * @var string                $signInUrl where it is right now
 * @var string                $suggestion an address offered while none is stored, or ''
 * @var string                $siteUrl   the site's address, with its slash
 * @var array<string, mixed>  $usernames Usernames::settings()
 * @var array<string, mixed>  $passwords PasswordPolicy::settings() (min as typed, after a refused save)
 * @var array<string, int>    $pending   reason => accounts that still owe a new password
 * @var int|null              $asked     after "Ask everyone": how many accounts were asked
 * @var bool                  $enforced  does this install stop an account that owes one
 * @var array<string, mixed>  $inactive  Accounts::settings() (or as typed, after a refused save)
 * @var list<array<string, mixed>> $suspended  Accounts::suspended() — on the Inactive accounts tab
 * @var bool                  $restored  back from restoring one
 * @var array<string, mixed>  $redirection  LoginRedirection::settings() (or as typed, after a refused save)
 * @var array<string, mixed>|null $editing the rule being edited, or as typed after a refused save
 * @var list<array<string, mixed>> $redirectPages  the published pages a rule may send an account to
 * @var list<array<string, mixed>> $redirectUsers  the accounts a rule may be for
 */

$tabUrl = static fn (string $key): string => Url::admin('security/login-protection') . '?tab=' . $key;
?>
<div class="page">

    <div class="page__header">
        <div class="page__header-main">
            <h1 class="page__title">Login protection</h1>
        </div>
    </div>

    <?php if ($saved): ?>
        <div class="flashes">
            <?php // WHERE THE SIGN-IN PAGE IS NOW — the one thing an owner must
                  // not leave this screen without, stated once, as a link they
                  // can click and keep. ?>
            <?php if ($tab === 'address' && $moved): ?>
                <div class="flash flash--success">
                    Saved. The sign-in page is now at
                    <a href="<?= h($signInUrl) ?>"><?= h($signInUrl) ?></a> — bookmark it.
                </div>
            <?php else: ?>
                <div class="flash flash--success">Settings saved.</div>
            <?php endif; ?>
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

    <div class="tabs">
        <?php foreach (SecurityController::LOGIN_TABS as $key => $label): ?>
            <a class="tabs__tab<?= $tab === $key ? ' is-active' : '' ?>"
               <?= $tab === $key ? 'aria-current="page"' : '' ?>
               href="<?= h($tabUrl($key)) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="form-main">

        <?php if ($tab === 'address'): ?>
            <form method="post" action="<?= h(Url::admin('security/login-protection')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="_tab" value="address">

                <div class="form-card">
                    <div class="form-card__section">
                        <?= panel_title('Sign-in address', 'sign-in-address') ?>

                        <div class="field">
                            <label class="check-list__row" for="f-move">
                                <input type="checkbox" id="f-move" name="enabled" value="1"
                                       <?= $address['enabled'] === '1' ? 'checked' : '' ?>>
                                <span>Move the sign-in page</span>
                            </label>
                        </div>

                        <div class="field">
                            <label class="label" for="f-slug">Address</label>
                            <?php // The site's address in front of the box, as
                                  // it will be typed. With nothing
                                  // stored, a fresh address is offered as the
                                  // placeholder: a suggestion, not a setting.
                                  // Randomize puts another one IN the box, from
                                  // LoginUrl's own parts (sign-in-address.js);
                                  // the owner keeps it, edits it or types their
                                  // own, and Save takes what the box holds. ?>
                            <div class="prefixed">
                                <span class="prefixed__prefix"><?= h($siteUrl) ?></span>
                                <input class="input input--mono" type="text" id="f-slug" name="slug"
                                       value="<?= h($address['slug']) ?>" autocomplete="off"
                                       <?= $suggestion !== '' ? 'placeholder="' . h($suggestion) . '"' : '' ?>
                                       spellcheck="false" autocapitalize="none">
                                <button type="button" class="btn" data-randomize="f-slug"
                                        data-words="<?= h(implode(' ', LoginUrl::WORDS)) ?>"
                                        data-alphabet="<?= h(LoginUrl::ALPHABET) ?>"
                                        data-length="<?= LoginUrl::TAIL ?>"><?= icon('shuffle', 'icon icon--sm') ?> Randomize</button>
                            </div>
                            <?php if ($suggestion !== ''): ?>
                                <input type="hidden" name="slug_suggestion" value="<?= h($suggestion) ?>">
                                <p class="form-help">
                                    Nothing is saved yet. <code data-suggestion><?= h($suggestion) ?></code> is a suggestion,
                                    new on each visit: tick the box and save to use it, or type your own.
                                </p>
                            <?php endif; ?>
                            <p class="form-help">
                                <?php if ($moved): ?>
                                    The sign-in page is at <a href="<?= h($signInUrl) ?>"><?= h($signInUrl) ?></a>.
                                    <code><?= h(Url::admin('login')) ?></code> shows the not-found page.
                                <?php else: ?>
                                    The sign-in page is at <a href="<?= h($signInUrl) ?>"><?= h($signInUrl) ?></a>.
                                    Moved, that shows the not-found page and only the address above signs anyone in.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <div class="form-card__section">
                        <?= panel_title('Old addresses', 'old-addresses') ?>

                        <div class="field">
                            <label class="label" for="f-redirect">Send signed-out visitors to</label>
                            <div class="prefixed">
                                <span class="prefixed__prefix"><?= h($siteUrl) ?></span>
                                <input class="input input--mono" type="text" id="f-redirect" name="redirect"
                                       value="<?= h(ltrim($address['redirect'], '/')) ?>" autocomplete="off"
                                       spellcheck="false" autocapitalize="none">
                            </div>
                            <?php // THE DEFAULT, SAID ONCE. A blank box is the
                                  // not-found page — which is the point: a
                                  // redirect is itself an answer ("something is
                                  // here"), and the not-found page is what every
                                  // address that does not exist gets. ?>
                            <p class="form-help">
                                For <code>/admin</code> and everything under it. Leave blank
                                to show the not-found page; type <code>/</code> for the home page.
                            </p>
                        </div>
                    </div>

                    <div class="form-card__section form-actions">
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn btn--primary">Save changes</button>
                    </div>
                </div>
            </form>
            <script src="<?= h(\Cms\Helpers\Asset::admin('js/sign-in-address.js')) ?>"></script>

        <?php elseif ($tab === 'usernames'): ?>
            <form method="post" action="<?= h(Url::admin('security/login-protection')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="_tab" value="usernames">

                <div class="form-card">
                    <div class="form-card__section">
                        <?= panel_title('Common usernames', 'common-usernames') ?>

                        <div class="field">
                            <label class="check-list__row" for="f-usernames-on">
                                <input type="checkbox" id="f-usernames-on" name="enabled" value="1"
                                       <?= $usernames['enabled'] ? 'checked' : '' ?>>
                                <span>Refuse these usernames</span>
                            </label>
                        </div>

                        <div class="field">
                            <label class="label" for="f-usernames">Usernames</label>
                            <textarea class="textarea input--mono" id="f-usernames" name="list" rows="8"
                                      spellcheck="false" autocapitalize="none"><?= h(implode("\n", $usernames['list'])) ?></textarea>
                            <?php // Exactly where Usernames::blocked() is asked:
                                  // UserController::handleCreate(), the
                                  // first-sign-in screen of an account that owes
                                  // a new password, and — through trapped() —
                                  // the sign-in form. Nothing else. ?>
                            <p class="form-help">
                                One per line. Refused when an account is added, and replaced
                                the next time an account that has one must choose a new password.
                            </p>
                        </div>

                        <div class="field">
                            <label class="check-list__row" for="f-usernames-trap">
                                <input type="checkbox" id="f-usernames-trap" name="trap" value="1"
                                       <?= $usernames['trap'] ? 'checked' : '' ?>>
                                <span>Lock out an address that signs in as one of them</span>
                            </label>
                            <?php // Usernames::trapped(): the list switched on, a
                                  // name no account has, and Throttle::lockNow(),
                                  // which is the attempt limit's own lockout. ?>
                            <p class="form-help">
                                No account can have these names, so only a script tries one. A name an
                                account still has — the starting one, before its first sign-in — is never
                                trapped, and neither is an address someone has signed in from before: there
                                it is the owner typing an old name. It uses the attempt limit's
                                lockout<?= \Cms\Security\Throttle::enabled() ? '' : ', which is off, so this does nothing until the limit is on' ?>.<?= \Cms\Auth\Auth::enforcesPasswordChanges() ? '' : ' This is a development install, which never traps a name: its test tools sign in by script.' ?>
                            </p>
                        </div>
                    </div>

                    <div class="form-card__section form-actions">
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn btn--primary">Save changes</button>
                    </div>
                </div>
            </form>

        <?php elseif ($tab === 'passwords'): ?>
            <?php if ($asked !== null): ?>
                <div class="flashes">
                    <div class="flash flash--success">
                        <?= $asked === 1 ? '1 account' : (int) $asked . ' accounts' ?> signed out and asked for a new password.
                    </div>
                </div>
            <?php endif; ?>

            <?php // THE FORM IS THE CARD (like the sign-in page's), so it and
                  // the danger-zone card below are adjacent `.form-card`s and
                  // core.css's `.form-card + .form-card` spaces them. Wrapped in
                  // a bare <form>, the two would touch. ?>
            <form class="form-card" method="post" action="<?= h(Url::admin('security/login-protection')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="_tab" value="passwords">

                    <div class="form-card__section">
                        <?= panel_title('Password strength', 'password-strength') ?>

                        <div class="field">
                            <label class="label" for="f-min">Minimum length</label>
                            <input class="input input--count" type="number" id="f-min" name="min"
                                   min="<?= \Cms\Security\PasswordPolicy::MIN_RANGE[0] ?>"
                                   max="<?= \Cms\Security\PasswordPolicy::MIN_RANGE[1] ?>"
                                   value="<?= h((string) $passwords['min']) ?>" inputmode="numeric">
                        </div>

                        <div class="field">
                            <span class="label">Also require</span>
                            <div class="check-list">
                                <label class="check-list__row"><input type="checkbox" name="mixed_case" value="1" <?= $passwords['mixed_case'] ? 'checked' : '' ?>><span>Capital and small letters</span></label>
                                <label class="check-list__row"><input type="checkbox" name="digit" value="1" <?= $passwords['digit'] ? 'checked' : '' ?>><span>A number</span></label>
                                <label class="check-list__row"><input type="checkbox" name="symbol" value="1" <?= $passwords['symbol'] ? 'checked' : '' ?>><span>A symbol</span></label>
                            </div>
                        </div>

                        <div class="field">
                            <span class="label">Refuse</span>
                            <div class="check-list">
                                <label class="check-list__row"><input type="checkbox" name="common" value="1" <?= $passwords['common'] ? 'checked' : '' ?>><span>The passwords everybody tries first</span></label>
                                <label class="check-list__row"><input type="checkbox" name="personal" value="1" <?= $passwords['personal'] ? 'checked' : '' ?>><span>Passwords made of the account's own name or the site's</span></label>
                            </div>
                        </div>

                        <div class="field">
                            <label class="check-list__row" for="f-existing">
                                <input type="checkbox" id="f-existing" name="existing" value="1"
                                       <?= $passwords['existing'] ? 'checked' : '' ?>>
                                <span>Ask for a new password at sign-in when an old one breaks this rule</span>
                            </label>
                            <?php // The development tree does not ask (Auth::mustChange);
                                  // saying so there, rather than promising it. ?>
                            <?php if (!$enforced): ?>
                                <p class="form-help">This is a development install: it records who owes a new password but does not ask.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-card__section form-actions">
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn btn--primary">Save changes</button>
                    </div>
            </form>

            <?php // THE ONE CONTROL ON THE SCREEN THAT DOES NOT SAVE WITH THE
                  // REST, so it is a card of its own — core.css §4's danger
                  // zone, the only place a --danger button is legal. The confirm
                  // dialog is the alert surface; the trigger does not shout. ?>
            <form class="form-card form-card--danger-zone" method="post"
                  action="<?= h(Url::admin('security/passwords/reset-all')) ?>"
                  data-confirm="Ask everyone for a new password?"
                  data-confirm-detail="Every other account is signed out now, and must choose a new password at its next sign-in. Yours is not affected."
                  data-confirm-verb="Ask everyone">
                <?= Csrf::field() ?>
                <div class="form-card__section">
                    <?= panel_title('Everyone chooses a new password', 'reset-all') ?>
                    <?php $owed = array_sum(array_map('intval', $pending)); ?>
                    <p class="form-help">
                        <?php if ($owed > 0): ?>
                            <?= $owed === 1 ? '1 account' : (int) $owed . ' accounts' ?> must already choose a new
                            one<?= isset($pending['seed']) ? ', including one still on the password every new site starts with' : '' ?>.
                        <?php else: ?>
                            Every account has chosen its own password.
                        <?php endif; ?>
                    </p>
                </div>
                <div class="form-card__section form-actions">
                    <div class="form-actions__spacer"></div>
                    <button type="submit" class="btn btn--danger">Ask everyone</button>
                </div>
            </form>
            <script src="<?= h(\Cms\Helpers\Asset::admin('js/confirm.js')) ?>"></script>

        <?php elseif ($tab === 'redirection'): ?>
            <?php
            // LOGIN REDIRECTION: the switch and the fallback, then the rules,
            // then the rule being added or edited.
            // `Security\LoginRedirection` has which rule wins, and why a
            // destination never leaves the site.
            $pageTitles = [];
            foreach ($redirectPages as $p) {
                $pageTitles[(int) $p['id']] = (string) $p['title'];
            }
            $userNames = [];
            foreach ($redirectUsers as $u) {
                $userNames[(int) $u['id']] = \Cms\Models\User::displayName($u);
            }
            $whoSays = static fn (array $r): string => match ((string) ($r['who'] ?? '')) {
                'role'  => implode(', ', array_map(static fn (string $k): string => LoginRedirection::ROLES[$k] ?? $k, (array) ($r['roles'] ?? []))),
                'user'  => implode(', ', array_map(static fn ($id): string => $userNames[(int) $id] ?? 'An account removed', (array) ($r['users'] ?? []))),
                default => 'All users',
            };
            $whereSays = static fn (array $t, string $none): string => match ((string) ($t['type'] ?? '')) {
                'page'  => $pageTitles[(int) ($t['value'] ?? 0)] ?? 'A page no longer published',
                'url'   => (string) ($t['value'] ?? ''),
                default => $none,
            };
            $kinds = ['all' => ['user-group', 'All users'], 'role' => ['shield-halved', 'A role'], 'user' => ['user', 'Accounts']];
            $rule  = $editing ?? ['id' => 0, 'who' => 'all', 'roles' => [], 'users' => [],
                                  'login' => ['type' => 'page', 'value' => ''], 'logout' => ['type' => 'none', 'value' => ''], 'order' => '0'];
            $tabUrl = Url::admin('security/login-protection') . '?tab=redirection';
            ?>
            <?php // THE FORMS ARE THE CARDS, as on Inactive accounts, so the three
                  // are siblings and `.form-card + .form-card` spaces them. ?>
            <form class="form-card" method="post" action="<?= h(Url::admin('security/login-protection')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="_tab" value="redirection">
                <input type="hidden" name="_part" value="settings">
                    <div class="form-card__section">
                        <?= panel_title('Login redirection', 'login-redirection') ?>
                        <div class="field">
                            <label class="check-list__row" for="f-redirect-on">
                                <input type="checkbox" id="f-redirect-on" name="enabled" value="1" <?= $redirection['on'] ? 'checked' : '' ?>>
                                <span>Send accounts where the rules say, after they sign in and after they sign out</span>
                            </label>
                            <p class="form-help">Otherwise every account lands on the dashboard after signing in, and on the
                                sign-in page after signing out. A password an account owes, and two-step sign-in the site asks of
                                it, still come first.</p>
                        </div>
                        <div class="field">
                            <span class="label">When a rule's page is gone</span>
                            <div class="check-list">
                                <label class="check-list__row">
                                    <input type="radio" name="fallback" value="dashboard" <?= $redirection['fallback'] !== 'url' ? 'checked' : '' ?>>
                                    <span>The dashboard — or, after signing out, the sign-in page</span>
                                </label>
                                <label class="check-list__row">
                                    <input type="radio" name="fallback" value="url" <?= $redirection['fallback'] === 'url' ? 'checked' : '' ?>>
                                    <span>This address on the site</span>
                                </label>
                            </div>
                            <p class="form-help">For a rule whose page has been unpublished or deleted — not a lower rule.</p>
                        </div>
                        <?php // Its own field, as Blocking's list and its addresses are. Flush under
                              // the radios, the box would take their last row's bottom pixel. ?>
                        <div class="field">
                            <label class="label" for="f-redirect-fallback">The fallback address</label>
                            <input class="input" type="text" id="f-redirect-fallback" name="fallback_url" value="<?= h((string) $redirection['fallback_url']) ?>"
                                   placeholder="/" spellcheck="false" autocapitalize="none">
                            <p class="form-help">On this site, beginning with /. Kept only while the choice above is this address.</p>
                        </div>
                    </div>
                    <div class="form-card__section form-actions">
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn btn--primary">Save changes</button>
                    </div>
            </form>

            <div class="form-card">
                <div class="form-card__section">
                    <?= panel_title('Rules', 'redirect-rules') ?>
                    <?php if ($redirection['rules'] === []): ?>
                        <p class="form-help">None yet. A rule says who it is for, where they go after signing in, and where after
                            signing out.</p>
                    <?php else: ?>
                        <div class="list list--bleed entry-list">
                            <?php foreach (LoginRedirection::ordered($redirection['rules']) as $r): ?>
                                <?php
                                $rid  = (int) ($r['id'] ?? 0);
                                $kind = $kinds[(string) ($r['who'] ?? 'all')] ?? $kinds['all'];
                                $sub  = 'In: ' . $whereSays((array) ($r['login'] ?? []), 'the dashboard')
                                      . ' · Out: ' . $whereSays((array) ($r['logout'] ?? []), 'the sign-in page');
                                ?>
                                <div class="list__row">
                                    <div class="entry-list__main">
                                        <a class="list__row-link list__row-title" href="<?= h($tabUrl . '&rule=' . $rid) ?>"><?= h($whoSays($r)) ?></a>
                                        <div class="list__row-sub" title="<?= h($sub) ?>"><?= h($sub) ?></div>
                                    </div>
                                    <div><span class="status status--phrase"><?= icon($kind[0], 'status__icon') ?><?= h($kind[1]) ?></span></div>
                                    <div class="list__row-meta"><span><?= (int) ($r['order'] ?? 0) > 0 ? 'Order ' . (int) $r['order'] : 'No order' ?></span></div>
                                    <div class="list__row-actions">
                                        <a class="btn btn--sm" href="<?= h($tabUrl . '&rule=' . $rid) ?>">Edit</a>
                                        <button type="submit" class="btn btn--sm" form="redirect-delete-<?= $rid ?>">Delete</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <form class="form-card" method="post" action="<?= h(Url::admin('security/login-protection')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="_tab" value="redirection">
                <input type="hidden" name="_part" value="rule">
                <input type="hidden" name="rule" value="<?= (int) $rule['id'] ?>">
                    <div class="form-card__section">
                        <?= panel_title((int) $rule['id'] > 0 ? 'Edit the rule' : 'Add a rule', 'login-redirection') ?>
                        <div class="field">
                            <label class="label" for="f-redirect-who">Who it is for</label>
                            <select class="select" id="f-redirect-who" name="who" data-reveal="redirect-who">
                                <?php foreach (LoginRedirection::WHO as $key => $label): ?>
                                    <option value="<?= h($key) ?>" <?= ($rule['who'] ?? '') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-help">A rule for particular accounts comes before one for a role, which comes before
                                one for all users.</p>
                        </div>
                        <div class="form-grid form-grid--2">
                            <div class="field" data-reveal-for="redirect-who" data-reveal-when="role">
                                <span class="label">Roles</span>
                                <div class="check-list">
                                    <?php foreach (LoginRedirection::ROLES as $key => $label): ?>
                                        <label class="check-list__row">
                                            <input type="checkbox" name="roles[]" value="<?= h($key) ?>" <?= in_array($key, (array) ($rule['roles'] ?? []), true) ? 'checked' : '' ?>>
                                            <span><?= h($label) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="form-help">For a role: an account with any of these.</p>
                            </div>
                            <div class="field" data-reveal-for="redirect-who" data-reveal-when="user">
                                <span class="label">Accounts</span>
                                <div class="check-list">
                                    <?php foreach ($redirectUsers as $u): ?>
                                        <label class="check-list__row">
                                            <input type="checkbox" name="users[]" value="<?= (int) $u['id'] ?>" <?= in_array((int) $u['id'], array_map('intval', (array) ($rule['users'] ?? [])), true) ? 'checked' : '' ?>>
                                            <span><?= h(\Cms\Models\User::displayName($u)) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="form-help">For particular accounts: any of these.</p>
                            </div>
                        </div>
                        <div class="form-grid form-grid--2">
                            <?php foreach (['login' => 'After signing in', 'logout' => 'After signing out'] as $event => $label): ?>
                                <?php $t = (array) ($rule[$event] ?? []); ?>
                                <div class="field">
                                    <label class="label" for="f-redirect-<?= $event ?>"><?= $label ?></label>
                                    <select class="select" id="f-redirect-<?= $event ?>" name="<?= $event ?>_type" data-reveal="redirect-<?= $event ?>">
                                        <?php if ($event === 'logout'): ?>
                                            <option value="none" <?= ($t['type'] ?? 'none') === 'none' ? 'selected' : '' ?>>The sign-in page</option>
                                        <?php endif; ?>
                                        <?php foreach (LoginRedirection::TARGETS as $key => $name): ?>
                                            <option value="<?= $key ?>" <?= ($t['type'] ?? '') === $key ? 'selected' : '' ?>><?= h($name) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select class="select" name="<?= $event ?>_page" aria-label="<?= $label ?>: the page"
                                            data-reveal-for="redirect-<?= $event ?>" data-reveal-when="page">
                                        <?php if ($redirectPages === []): ?>
                                            <option value="0">No published pages</option>
                                        <?php endif; ?>
                                        <?php foreach ($redirectPages as $p): ?>
                                            <option value="<?= (int) $p['id'] ?>" <?= ($t['type'] ?? '') === 'page' && (int) ($t['value'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= h((string) $p['title']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input class="input" type="text" name="<?= $event ?>_url" value="<?= ($t['type'] ?? '') === 'url' ? h((string) ($t['value'] ?? '')) : '' ?>"
                                           placeholder="/members/{{username}}" aria-label="<?= $label ?>: the address" spellcheck="false" autocapitalize="none"
                                           data-reveal-for="redirect-<?= $event ?>" data-reveal-when="url">
                                    <p class="form-help" data-reveal-for="redirect-<?= $event ?>" data-reveal-when="url">An address on this site,
                                        beginning with /. <code>{{username}}</code> and <code>{{user_id}}</code> are filled in.</p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="field">
                            <label class="label" for="f-redirect-order">Order</label>
                            <input class="input input--count" type="number" id="f-redirect-order" name="order" min="0" max="999"
                                   value="<?= h((string) ($rule['order'] ?? '0')) ?>" inputmode="numeric">
                            <p class="form-help">Lower numbers first: 1, 2, 3… 0 is no order — and once any rule has one,
                                rules without one are not used.</p>
                        </div>
                    </div>
                    <div class="form-card__section form-actions">
                        <?php if ((int) $rule['id'] > 0): ?>
                            <a class="link link--xs" href="<?= h($tabUrl) ?>">Cancel</a>
                        <?php endif; ?>
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn btn--primary"><?= (int) $rule['id'] > 0 ? 'Save the rule' : 'Add the rule' ?></button>
                    </div>
            </form>
            <?php foreach ($redirection['rules'] as $r): ?>
                <?php $rid = (int) ($r['id'] ?? 0); ?>
                <form id="redirect-delete-<?= $rid ?>" method="post" hidden action="<?= h(Url::admin('security/login-protection')) ?>"
                      data-confirm="Delete the rule for <?= h($whoSays($r)) ?>?"
                      data-confirm-detail="Those accounts go where the other rules say, or to the dashboard."
                      data-confirm-verb="Delete"><?= Csrf::field() ?><input type="hidden" name="_tab" value="redirection"><input type="hidden" name="_part" value="delete"><input type="hidden" name="rule" value="<?= $rid ?>"></form>
            <?php endforeach; ?>
            <script src="<?= h(\Cms\Helpers\Asset::admin('js/confirm.js')) ?>"></script>

        <?php elseif ($tab === 'inactive'): ?>
            <?php if ($restored): ?>
                <div class="flashes">
                    <div class="flash flash--success">Restored. It can sign in again.</div>
                </div>
            <?php endif; ?>

            <?php // THE FORM IS THE CARD, as on Password strength, so the list
                  // of suspended accounts is the next card — a restore is not
                  // part of saving the rule. `Security\Accounts` has the rule. ?>
            <form class="form-card" method="post" action="<?= h(Url::admin('security/login-protection')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="_tab" value="inactive">

                <div class="form-card__section">
                    <?= panel_title('Inactive accounts', 'inactive') ?>

                    <div class="field">
                        <label class="check-list__row" for="f-inactive-on">
                            <input type="checkbox" id="f-inactive-on" name="enabled" value="1"
                                   <?= $inactive['enabled'] ? 'checked' : '' ?>>
                            <span>Suspend an account nobody has used</span>
                        </label>
                    </div>

                    <div class="field">
                        <label class="label" for="f-inactive-days">After this many days</label>
                        <input class="input input--count" type="number" id="f-inactive-days" name="days"
                               min="<?= Accounts::DAYS_LIMITS[0] ?>" max="<?= Accounts::DAYS_LIMITS[1] ?>"
                               value="<?= h((string) $inactive['days']) ?>" inputmode="numeric">
                        <p class="form-help">Counted from its last sign-in, or the last day it was in use — and never
                            from before this rule was switched on.</p>
                    </div>

                    <div class="field">
                        <span class="label">Whose accounts</span>
                        <div class="check-list">
                            <?php foreach (Accounts::ROLES as $role => $label): ?>
                                <label class="check-list__row">
                                    <input type="checkbox" name="roles[]" value="<?= h($role) ?>"
                                           <?= in_array($role, (array) $inactive['roles'], true) ? 'checked' : '' ?>>
                                    <span class="check-list__name">
                                        <?= icon($role === 'editor' ? 'pen-to-square' : 'pen', 'check-list__glyph icon') ?><?= h($label) ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?php // Accounts::ROLES has no `admin`, on purpose. ?>
                        <p class="form-help">Never administrators: on most sites that is the owner, who would be shut out of
                            their own site by a long holiday.</p>
                    </div>
                </div>

                <div class="form-card__section form-actions">
                    <div class="form-actions__spacer"></div>
                    <button type="submit" class="btn btn--primary">Save changes</button>
                </div>
            </form>

            <?php // EVERY SUSPENDED ACCOUNT, by the rule or by an administrator,
                  // swept just before this was drawn. `--bleed` against the
                  // heading, so no band of card shows round the list in its
                  // own tone. ?>
            <div class="form-card">
                <div class="form-card__section">
                    <?= panel_title('Suspended accounts', 'suspended') ?>
                    <?php if ($suspended === []): ?>
                        <p class="form-help">None. A suspended account is listed here, and on Users.</p>
                    <?php else: ?>
                        <div class="list list--bleed entry-list">
                            <?php foreach ($suspended as $row): ?>
                                <div class="list__row">
                                    <div class="entry-list__main">
                                        <a class="list__row-link list__row-title"
                                           href="<?= h(Url::admin('users/' . (int) $row['id'] . '/edit')) ?>"><?= h(\Cms\Models\User::displayName($row)) ?></a>
                                        <div class="list__row-sub"><?= h(Accounts::why($row)) ?></div>
                                    </div>
                                    <div><span class="status status--phrase"><?= icon('pause', 'status__icon') ?>Suspended</span></div>
                                    <div class="list__row-meta">
                                        <span><?= h(\Cms\Helpers\Dates::short((string) $row['suspended_at'])) ?></span>
                                    </div>
                                    <div class="list__row-actions">
                                        <button type="submit" class="btn btn--sm" form="restore-<?= (int) $row['id'] ?>">Restore</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php foreach ($suspended as $row): ?>
                <form id="restore-<?= (int) $row['id'] ?>" method="post" hidden
                      action="<?= h(Url::admin('users/' . (int) $row['id'] . '/restore')) ?>"><?= Csrf::field() ?><input type="hidden" name="back" value="inactive"></form>
            <?php endforeach; ?>

        <?php else: ?>
            <form method="post" action="<?= h(Url::admin('security/login-protection')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="_tab" value="attempts">

                <div class="form-card">
                    <div class="form-card__section">
                        <?= panel_title('Attempt limit', 'attempt-limit') ?>

                        <div class="field">
                            <label class="check-list__row" for="f-limit">
                                <input type="checkbox" id="f-limit" name="enabled" value="1"
                                       <?= $limit['enabled'] === '1' ? 'checked' : '' ?>>
                                <span>Limit sign-in attempts</span>
                            </label>
                        </div>

                        <div class="form-grid form-grid--2">
                            <div class="field">
                                <label class="label" for="f-attempts">Attempts</label>
                                <input class="input" type="number" id="f-attempts" name="attempts"
                                       min="<?= Throttle::LIMITS[Throttle::ATTEMPTS_KEY][0] ?>"
                                       max="<?= Throttle::LIMITS[Throttle::ATTEMPTS_KEY][1] ?>"
                                       value="<?= h($limit['attempts']) ?>" inputmode="numeric">
                            </div>
                            <div class="field">
                                <label class="label" for="f-minutes">Lockout (minutes)</label>
                                <input class="input" type="number" id="f-minutes" name="minutes"
                                       min="<?= Throttle::LIMITS[Throttle::MINUTES_KEY][0] ?>"
                                       max="<?= Throttle::LIMITS[Throttle::MINUTES_KEY][1] ?>"
                                       value="<?= h($limit['minutes']) ?>" inputmode="numeric">
                            </div>
                        </div>
                        <?php // Exactly `Throttle::failures()`: per address, the
                              // last WINDOW_HOURS, restarted by a sign-in or a
                              // lockout from that address. ?>
                        <p class="form-help">
                            Failures from one address in the last
                            <?= (int) Throttle::WINDOW_HOURS ?> hours count, starting again
                            after a successful sign-in or a lockout.
                        </p>
                    </div>

                    <div class="form-card__section">
                        <?= panel_title('Longer lockout', 'longer-lockout') ?>

                        <div class="form-grid form-grid--2">
                            <div class="field">
                                <label class="label" for="f-escalate-after">After this many lockouts in a day</label>
                                <input class="input" type="number" id="f-escalate-after" name="escalate_after"
                                       min="<?= Throttle::LIMITS[Throttle::ESCALATE_AFTER_KEY][0] ?>"
                                       max="<?= Throttle::LIMITS[Throttle::ESCALATE_AFTER_KEY][1] ?>"
                                       value="<?= h($limit['escalate_after']) ?>" inputmode="numeric">
                            </div>
                            <div class="field">
                                <label class="label" for="f-escalate-hours">Lock out for (hours)</label>
                                <input class="input" type="number" id="f-escalate-hours" name="escalate_hours"
                                       min="<?= Throttle::LIMITS[Throttle::ESCALATE_HOURS_KEY][0] ?>"
                                       max="<?= Throttle::LIMITS[Throttle::ESCALATE_HOURS_KEY][1] ?>"
                                       value="<?= h($limit['escalate_hours']) ?>" inputmode="numeric">
                            </div>
                        </div>
                        <p class="form-help">0 lockouts turns the longer lockout off.</p>
                    </div>

                    <?php // ONE USERNAME, EVERY ADDRESS (`AccountLock`): what the
                          // limit above cannot see — guesses spread across many
                          // addresses. The help is `AccountLock::failures()` and
                          // `lockFor()` in words; change one, change the other. ?>
                    <div class="form-card__section">
                        <?= panel_title('Per username', 'name-limit') ?>

                        <div class="field">
                            <label class="check-list__row" for="f-account-limit">
                                <input type="checkbox" id="f-account-limit" name="account_enabled" value="1"
                                       <?= $limit['account_enabled'] === '1' ? 'checked' : '' ?>>
                                <span>Limit attempts on one username, from every address</span>
                            </label>
                        </div>

                        <div class="form-grid form-grid--2">
                            <div class="field">
                                <label class="label" for="f-account-attempts">Attempts</label>
                                <input class="input" type="number" id="f-account-attempts" name="account_attempts"
                                       min="<?= AccountLock::LIMITS[AccountLock::ATTEMPTS_KEY][0] ?>"
                                       max="<?= AccountLock::LIMITS[AccountLock::ATTEMPTS_KEY][1] ?>"
                                       value="<?= h($limit['account_attempts']) ?>" inputmode="numeric">
                            </div>
                            <div class="field">
                                <label class="label" for="f-account-minutes">Lock the username for (minutes)</label>
                                <input class="input" type="number" id="f-account-minutes" name="account_minutes"
                                       min="<?= AccountLock::LIMITS[AccountLock::MINUTES_KEY][0] ?>"
                                       max="<?= AccountLock::LIMITS[AccountLock::MINUTES_KEY][1] ?>"
                                       value="<?= h($limit['account_minutes']) ?>" inputmode="numeric">
                            </div>
                        </div>
                        <p class="form-help">
                            Wrong passwords for one username from any address in the last
                            <?= (int) Throttle::WINDOW_HOURS ?> hours count, starting again after it
                            signs in, is locked or is unlocked. While it is locked it can still sign in from an
                            address it has signed in from in the last <?= (int) SecurityLog::KEEP_DAYS ?> days,
                            and its owner is emailed a link that unlocks it.
                        </p>
                    </div>

                    <div class="form-card__section">
                        <?= panel_title('Lockout message', 'lockout-message') ?>

                        <div class="field">
                            <label class="label" for="f-message">What a locked-out visitor reads</label>
                            <input class="input" type="text" id="f-message" name="message" maxlength="200"
                                   value="<?= h($limit['message']) ?>"
                                   placeholder="<?= h(Throttle::DEFAULT_MESSAGE) ?>">
                            <p class="form-help">
                                <code>{time}</code> becomes the time left, such as
                                &ldquo;20 minutes&rdquo;. Leave blank for the wording shown.
                            </p>
                        </div>
                    </div>

                    <div class="form-card__section form-actions">
                        <div class="form-actions__spacer"></div>
                        <button type="submit" class="btn btn--primary">Save changes</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>

    </div>
</div>
