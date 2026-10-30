<?php

declare(strict_types=1);

use Cms\Helpers\Url;
use Cms\Security\Csrf;

/**
 * ADD USER — admin only, enforced in `UserController::showCreate()`.
 *
 * A SHORT SETTINGS FORM, so it keeps the footer Save that the editors gave up.
 * That is not an inconsistency: `partials/form-topbar.php` states the rule it
 * follows — the action moves to the top when the form has a full-height body
 * field that would push it below the fold. Five inputs do not.
 *
 * ── THE FIELDS MIRROR handleCreate() EXACTLY ────────────────────────────────
 * `username`, `email`, `password`, `password_confirm`, `role` — every name here
 * is read there, and every rule stated here is enforced there:
 *   · username must be unique          User::usernameExists()
 *   · …and not a blocked one           Security\Usernames::blocked()
 *   · display name is not the username (it is printed on the public site)
 *   · email must be a valid address    FILTER_VALIDATE_EMAIL, and unique
 *   · password passes the rule         Security\PasswordPolicy::problems(),
 *                                      stated under the box by its summary()
 *   · the two passwords must match     $password !== $confirm
 *   · role is one of three             in_array(), silently falling back to author
 * Nothing is claimed here that is not checked there: a template that describes
 * behaviour it has not read makes claims that go false.
 *
 * ── $_POST WINS ON A FAILED SUBMIT ──────────────────────────────────────────
 * `handleCreate()` re-requires this template with `$errors` populated, so the
 * form must render what was typed or a validation error discards four correct
 * fields to complain about the fifth. Passwords are the exception and are
 * deliberately NOT repopulated: re-emitting a submitted password into HTML puts
 * it in the page source and in the browser's cache of it.
 *
 * @var list<string>         $errors
 * @var array<string,mixed>  $user   the signed-in admin
 */

$pageTitle = 'Add user';

$old = static fn (string $k): string => (string) ($_POST[$k] ?? '');

$roleLabels = \Cms\Auth\Auth::ROLE_LABELS;

/**
 * WHAT EACH ROLE ACTUALLY DOES, read off the call sites rather than assumed.
 * "An Author can create and publish content" would be wrong in two ways that
 * matter to whoever is picking a role here:
 *
 *   `Auth::hasRole()` is a LADDER — author 1, editor 2, admin 3 — so every
 *   `requireRole('editor')` locks authors out entirely.
 *   `PageController` calls `requireRole('editor')` on every action, so an
 *   author cannot touch Pages at all.
 *   `ContentTypeController:75` sets `$authorFilter = hasRole('editor') ? null
 *   : $user['id']`, so an author sees only their OWN entries in the list.
 *
 * If a call site moves, this hint is wrong — it describes behaviour, and
 * behaviour is the thing that moves.
 */
$roleHints = [
    'admin'        => 'Everything, including users, settings and content types.',
    // `OrderController` and `CouponController` are `requireRole('shop_manager')`;
    // Settings → Shop stays `requireRole('admin')` in `SettingsController`.
    'shop_manager' => 'An editor who also runs the shop: orders and discount codes. Not the shop\'s settings.',
    'editor'       => 'Pages, all entries, files and revisions.',
    'author'       => 'Their own entries only. No pages, files or settings.',
];

$selectedRole = $old('role') !== '' ? $old('role') : 'author';

ob_start();
?>
<div class="page">

    <a class="page__back" href="<?= h(Url::admin('users')) ?>">
        <?= icon('angle-left', 'icon icon--sm') ?>Users
    </a>

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

    <form method="post" action="<?= h(Url::admin('users/new')) ?>">
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
        <div class="form-card">

            <div class="form-card__section">
                <div class="field">
                    <label class="label" for="f-username">
                        Username<span class="label__req">required</span>
                    </label>
                    <input class="input" type="text" id="f-username" name="username" required
                           value="<?= h($old('username')) ?>" autocomplete="username"
                           spellcheck="false">
                    <p class="form-help">What they sign in with. It must be unique.</p>
                </div>

                <div class="field">
                    <label class="label" for="f-display-name">Name shown on posts</label>
                    <input class="input" type="text" id="f-display-name" name="display_name"
                           maxlength="100" value="<?= h($old('display_name')) ?>" autocomplete="name">
                    <p class="form-help">The byline on what they write. Empty, the byline credits the site — never the username.</p>
                </div>

                <?php if (\Cms\Helpers\People::declared()): ?>
                    <?php
                    // Which of the site's people the new account is (change 26);
                    // this screen is an administrator's. See the partial.
                    $personNow     = trim($old('person_key'));
                    $personAccount = 0;
                    require ROOT_PATH . '/admin/templates/partials/person-field.php';
                    ?>
                <?php endif; ?>

                <div class="field">
                    <label class="label" for="f-email">
                        Email<span class="label__req">required</span>
                    </label>
                    <input class="input" type="email" id="f-email" name="email" required
                           value="<?= h($old('email')) ?>" autocomplete="email">
                </div>

                <div class="field">
                    <label class="label" for="f-role">Role</label>
                    <select class="select" id="f-role" name="role">
                        <?php foreach ($roleLabels as $key => $label): ?>
                            <option value="<?= h($key) ?>"<?= $selectedRole === $key ? ' selected' : '' ?>>
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php // ONE LINE PER ROLE, all three visible, because the
                          // choice is being made now and a hint that describes
                          // only the current selection makes you change the
                          // control to read about the alternative. ?>
                    <p class="form-help">
                        <?php foreach ($roleHints as $key => $hint): ?>
                            <strong><?= h($roleLabels[$key]) ?></strong> — <?= h($hint) ?><br>
                        <?php endforeach; ?>
                    </p>
                </div>
            </div>

            <div class="form-card__section">
                <div class="field">
                    <label class="label" for="f-password">
                        Password<span class="label__req">required</span>
                    </label>
                    <input class="input" type="password" id="f-password" name="password" required
                           minlength="<?= (int) \Cms\Security\PasswordPolicy::settings()['min'] ?>" autocomplete="new-password">
                    <?php // The rule as the policy states it — never a copy of
                          // it here, which is what said "8" after the rule moved. ?>
                    <p class="form-help"><?= h(\Cms\Security\PasswordPolicy::summary()) ?><?= \Cms\Auth\Auth::enforcesPasswordChanges() ? ' They choose their own at their first sign-in.' : '' ?></p>
                </div>

                <div class="field">
                    <label class="label" for="f-password-confirm">
                        Confirm password<span class="label__req">required</span>
                    </label>
                    <input class="input" type="password" id="f-password-confirm"
                           name="password_confirm" required minlength="<?= (int) \Cms\Security\PasswordPolicy::settings()['min'] ?>"
                           autocomplete="new-password">
                </div>
            </div>

            <div class="form-card__section form-actions">
                <a class="link link--xs" href="<?= h(Url::admin('users')) ?>">Cancel</a>
                <div class="form-actions__spacer"></div>
                <button type="submit" class="btn btn--primary">Add user</button>
            </div>

        </div>
        </div>
    </form>

</div>
<?php
$pageContent = ob_get_clean();
require ROOT_PATH . '/admin/templates/layout.php';
