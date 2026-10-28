<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Helpers\Url;
use Cms\Models\User;
use Cms\Security\AccountLock;
use Cms\Security\Accounts;
use Cms\Security\Alerts;
use Cms\Security\Challenge;
use Cms\Security\ClientIp;
use Cms\Security\Csrf;
use Cms\Security\Factors;
use Cms\Security\LoginRedirection;
use Cms\Security\LoginUrl;
use Cms\Security\Passwordless;
use Cms\Security\PasswordPolicy;
use Cms\Security\PasswordReset;
use Cms\Security\SecurityLog;
use Cms\Security\TemporaryAccess;
use Cms\Security\Throttle;
use Cms\Security\TrustedBrowsers;
use Cms\Security\TwoStep;
use Cms\Security\Usernames;

/**
 * THE DOOR. Every sign-in goes through `handleLogin()`, in this order, and there
 * is no second way in — `Auth::attempt()` checks a password and does nothing
 * else, and this is its only caller:
 *
 *   1. LOCKED OUT?   The address is refused before its password is read, so
 *                    guessing during a lockout is worth nothing. (Counted on
 *                    the lockout, never logged per attempt — see migration 060.)
 *   2. OUR FORM?     The CSRF token. `www/index.php` exempts the sign-in page
 *                    from the global check so that a page left open overnight
 *                    gets an explanation here rather than a bare 403.
 *   3. A SCRIPT?     The honeypot, the question when the address must answer
 *                    one (`Challenge`), and a trap username (`Usernames`) —
 *                    each a failed attempt before any password is read.
 *                    (An address the owner has shut out never got this far:
 *                    `AddressRules`, in `www/index.php`.)
 *   4. A LOCKED      Too many failures on this NAME, from anywhere, and this
 *      NAME?         address has never signed in as it (`AccountLock`).
 *                    Refused before its password is read, and counted on the
 *                    lock, as step 1 is.
 *   5. THE PASSWORD  `Auth::attempt()`, which takes as long for a name that does
 *                    not exist as for one that does.
 *   6. THE SECOND    For an account with two-step sign-in, on a browser it has
 *      STEP          not remembered: `?action=two-step`, at this same address
 *                    (`TwoStep`). Steps 1 and 2 guard it too.
 *   7. THE RECORD    success or failure, with the name typed, the address and
 *                    the browser — then the attempt limits, the address's and
 *                    the name's, on a failure. A sign-in is logged when the
 *                    session starts, not before.
 */
class AuthController
{
    /**
     * GET — the sign-in page, at whichever address it lives now. It also
     * answers the two lost-password actions (`PasswordReset` has why
     * they live here and not under /admin), and the link a locked name's owner
     * is mailed (`AccountLock`).
     */
    public function showLogin(array $vars = []): void
    {
        if (Auth::check()) {
            // AN ADMINISTRATOR LOOKING at the sign-in page's look (Appearance →
            // Branding, Sign-in tab): shown as a visitor sees it, not sent
            // past it. Only the page itself; signing in from it does nothing.
            if (($_GET['preview'] ?? '') === '1' && !isset($_GET['action']) && (Auth::user()['role'] ?? '') === 'admin') {
                $this->render();
                return;
            }
            header('Location: ' . ADMIN_URL);
            exit;
        }

        match ((string) ($_GET['action'] ?? '')) {
            'lostpassword' => $this->renderReset('ask'),
            'resetpass'    => $this->renderReset(PasswordReset::find((string) ($_GET['key'] ?? '')) !== null ? 'choose' : 'invalid'),
            'two-step'     => $this->renderTwoStep(),
            'unlock'       => $this->renderUnlock(AccountLock::forLink((string) ($_GET['key'] ?? ''))),
            // Signing in without a password — only where offered.
            'code'         => Passwordless::offered('code') ? $this->renderPasswordless('code', 'ask') : $this->render(),
            'link'         => Passwordless::offered('link') ? $this->renderPasswordless('link', 'ask') : $this->render(),
            'signin'       => $this->renderPasswordless('link', Passwordless::findLink((string) ($_GET['key'] ?? '')) !== null ? 'button' : 'invalid'),
            // A temporary access link: its one button, or why not.
            'access'       => $this->renderAccess(TemporaryAccess::find((string) ($_GET['key'] ?? ''))),
            default        => $this->render(null, null, null, 200, match (true) {
                ($_GET['reset'] ?? '') === 'done'  => 'Your password is changed. Sign in with it.',
                ($_GET['unlocked'] ?? '') === '1'  => 'Unlocked. Sign in.',
                default                            => null,
            }),
        };
    }

    /** POST — one attempt. */
    public function handleLogin(array $vars = []): void
    {
        if (Auth::check()) {
            header('Location: ' . ADMIN_URL);
            exit;
        }

        // The lost-password actions come FIRST — before the lockout, which is
        // about guessing passwords. An owner who has locked themselves out is
        // exactly who needs a link; the link has its own throttle.
        $action = (string) ($_GET['action'] ?? '');
        if ($action === 'lostpassword' || $action === 'resetpass') {
            $this->handleReset($action);
            return;
        }
        // So does the link a locked name's owner was mailed: spending it is
        // not a guess, and the owner may well be at an address that has been
        // failing.
        if ($action === 'unlock') {
            $this->handleUnlock();
            return;
        }
        // ASKING for a code or a sign-in link, and SPENDING a link: each has its
        // own throttle (`Passwordless`), and none of them is a guess. TYPING a
        // code is one, and waits for the lockout below.
        if (($action === 'code' && !isset($_POST['code'])) || $action === 'link' || $action === 'signin') {
            $this->handlePasswordless($action);
            return;
        }
        // So is pressing a temporary access link's button: the key is not a
        // guess, and whoever was given it may be anywhere.
        if ($action === 'access') {
            $this->handleAccess();
            return;
        }

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $key      = ClientIp::key();

        $lockout = Throttle::lockoutFor($key);
        if ($lockout !== null) {
            Throttle::refuse($lockout);
            $this->render(Throttle::message($lockout), $username, null, 429);
            return;
        }

        if (!Csrf::validate()) {
            match ($action) {
                'two-step' => $this->renderTwoStep('This page had expired. Please try again.'),
                'code'     => $this->renderPasswordless('code', 'enter', ['This page had expired. Please try again.']),
                default    => $this->render('This page had expired. Please sign in again.', $username),
            };
            return;
        }

        if ($action === 'two-step') {
            $this->handleTwoStep($key);
            return;
        }
        if ($action === 'code') {
            $this->handleCode($key);
            return;
        }

        // ── WHAT A SCRIPT TRIPS OVER BEFORE ANY PASSWORD IS READ ────────────
        // A filled HONEYPOT is a script. It is answered exactly like a wrong
        // password — counted, and with the same words — so it never learns
        // which field gave it away.
        if ((string) ($_POST['website'] ?? '') !== '') {
            $this->refused($key, $username, 'hidden field filled', 'Incorrect username or password.');
            return;
        }

        if ($username === '' || $password === '') {
            $this->render('Please enter your username and password.', $username);
            return;
        }

        // The QUESTION, when this address must answer one (`Challenge`): a
        // wrong answer is a failed attempt, so a script that cannot answer
        // runs into the attempt limit without a password ever being tried.
        if (Challenge::required($key) && !Challenge::passes()) {
            $this->refused($key, $username, 'wrong CAPTCHA answer', 'That answer was not right. Please try again.');
            return;
        }

        // A TRAP USERNAME — a blocked name no account has — locks the address
        // out at once (`Usernames::trapped()`). Not from an address someone
        // has signed in from before: there it is the owner typing the name
        // their account had before it was renamed, not a word list.
        if (Usernames::trapped($username) && !SecurityLog::signedInFrom($key)) {
            SecurityLog::record(SecurityLog::SIGN_IN_FAILED, $username, null, 'trap username');
            $lockout = Throttle::lockNow($key, $username, 'trap username “' . $username . '”');
            $lockout !== null
                ? $this->render(Throttle::message($lockout), $username, null, 429)
                : $this->render('Incorrect username or password.', $username);
            return;
        }

        // A LOCKED NAME (`AccountLock`): guessed at too often, from anywhere,
        // and never signed in as from here. Counted on the lock and never
        // checked, like a locked-out address — so spreading the guesses
        // across a thousand addresses buys a script nothing.
        $nameLock = AccountLock::lockFor($username, $key);
        if ($nameLock !== null) {
            Throttle::refuse($nameLock);
            $this->render(AccountLock::message($nameLock), $username, null, 429);
            return;
        }

        $result = Auth::attempt($username, $password);

        if ($result['user'] !== null) {
            // SUSPENDED (migration 067, `Accounts`): said only to someone who
            // knew the password, and not counted against the address or the
            // name — it was the right one. Before the second step, so a
            // suspended account is never mailed a code.
            if (!Accounts::mayEnter((int) $result['id'])) {
                SecurityLog::record(SecurityLog::SIGN_IN_FAILED, $username, $result['id'], 'account suspended');
                $this->render(Accounts::MESSAGE, $username, null, 403);
                return;
            }
            // A TEMPORARY account signs in only by its link, so its
            // time and its uses mean what they say — even with a password an
            // administrator has set on it.
            if (TemporaryAccess::is((int) $result['id'])) {
                SecurityLog::record(SecurityLog::SIGN_IN_FAILED, $username, $result['id'], 'temporary account: password refused');
                $this->render(TemporaryAccess::MESSAGE, $username, null, 403);
                return;
            }

            // A PASSWORD THAT BREAKS TODAY'S RULE, found the one moment it is
            // in hand. Flagged on the account, not the session, so signing in
            // somewhere else does not get round it (`PasswordPolicy` header).
            $user = $result['user'];
            if (($user['must_change_password'] ?? null) === null
                && PasswordPolicy::settings()['existing']
                && PasswordPolicy::problems($password, $user) !== []) {
                User::flagPasswordChange((int) $result['id'], 'weak');
                $user['must_change_password'] = 'weak';
            }

            if (TwoStep::needed($user)) {
                TwoStep::begin($user);
                header('Location: ' . LoginUrl::url() . '?action=two-step', true, 303);
                exit;
            }

            $this->signIn($user, $username, null);
        }

        // The LOG says which it was; the PAGE never does (see login.php).
        SecurityLog::record(
            SecurityLog::SIGN_IN_FAILED,
            $username,
            $result['id'],
            $result['id'] === null ? 'unknown username' : 'wrong password'
        );

        $lockout = Throttle::fail($key, $username);
        // The NAME's count as well, from every address — its owner is mailed
        // the way back in the moment it locks (`AccountLock`).
        AccountLock::fail($username, $result['id'], $key);
        if ($lockout !== null) {
            $this->render(Throttle::message($lockout), $username, null, 429);
            return;
        }
        $nameLock = AccountLock::lockFor($username, $key);
        if ($nameLock !== null) {
            $this->render(AccountLock::message($nameLock), $username, null, 429);
            return;
        }

        $this->render(
            'Incorrect username or password.',
            $username,
            Throttle::enabled() ? Throttle::attemptsLeft($key) : null
        );
    }

    /**
     * /admin/login — the sign-in page's ORIGINAL address. While the page has
     * not moved, this is the page. Once it has, a signed-out visitor gets the
     * site's own not-found page here: not a redirect, which would announce
     * that something is being hidden. See `LoginUrl`.
     */
    public function legacyLogin(array $vars = []): void
    {
        if (!LoginUrl::enabled()) {
            ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
                ? $this->handleLogin($vars)
                : $this->showLogin($vars);
            return;
        }

        if (Auth::check()) {
            header('Location: ' . ADMIN_URL);
            exit;
        }

        LoginUrl::notFound();
    }

    /**
     * CHOOSE A NEW PASSWORD — the only screen an account that owes one can
     * reach (`Auth::requireAuth()`): after a new site's first sign-in with the
     * password migrate.php printed, and for an older install's `admin / corecms`.
     *
     * It says WHY it is here — the reason is on the account (migration 061) —
     * because "choose a new password" out of nowhere reads as a fault, and the
     * owner's first question is "what did I do?". And when the account's name
     * is one of the blocked ones (`Usernames`) — every install's seeded
     * `admin` — it asks for a new name in the same breath: the password half
     * of a guess is no use if the name half is `admin`.
     *
     * The new password must pass the rule and must not be the one it replaces.
     * Saving it ends every other session the account has.
     */
    public function choosePassword(array $vars = []): void
    {
        Auth::requireAuth();

        $reason = Auth::mustChange();
        if ($reason === null) {
            header('Location: ' . ADMIN_URL);
            exit;
        }

        $user     = (array) Auth::user();
        $renaming = Usernames::blocked((string) $user['username']);
        $errors   = [];
        $newName  = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $newName  = trim((string) ($_POST['new_username'] ?? ''));
            $password = (string) ($_POST['new_password'] ?? '');
            $confirm  = (string) ($_POST['new_password_confirm'] ?? '');
            $name     = $renaming ? $newName : (string) $user['username'];

            if ($renaming) {
                if ($newName === '') {
                    $errors[] = 'Choose a new username.';
                } elseif (mb_strlen($newName) > 100) {
                    $errors[] = 'A username can be at most 100 characters.';
                } elseif (Usernames::blocked($newName)) {
                    $errors[] = '“' . $newName . '” is one of the names every script tries first.';
                } elseif (User::usernameExists($newName, (int) $user['id'])) {
                    $errors[] = 'That username is already taken.';
                }
            }

            foreach (PasswordPolicy::problems($password, ['username' => $name, 'email' => (string) $user['email']]) as $problem) {
                $errors[] = $problem;
            }
            if ($errors === [] && User::verifyPassword((int) $user['id'], $password)) {
                $errors[] = 'That is the password this account has now. Choose a different one.';
            }
            if ($errors === [] && $password !== $confirm) {
                $errors[] = 'The two passwords do not match.';
            }

            if ($errors === []) {
                User::updatePassword((int) $user['id'], $password, null);
                if ($renaming) {
                    User::rename((int) $user['id'], $newName);
                }
                Auth::endSessions((int) $user['id']);
                SecurityLog::record(SecurityLog::PASSWORD_CHANGED, $name, (int) $user['id'], 'chosen at sign-in (' . $reason . ')');

                // On to what signIn() would have sent it to next: two-step
                // sign-in, when the site asks it of this account. A fresh
                // install's owner meets both on its first sign-in.
                header('Location: ' . (TwoStep::owed() !== null ? Url::admin('two-step') : ADMIN_URL));
                exit;
            }
        }

        http_response_code($errors === [] ? 200 : 422);
        header('Cache-Control: no-store');
        $summary = PasswordPolicy::summary();
        require ROOT_PATH . '/admin/templates/choose-password.php';
    }

    /**
     * Sign out. The link carries the session's CSRF token: a sign-out that any
     * page on the internet can trigger with an <img> is a nuisance, and it costs
     * nothing to refuse. Without the token, a signed-in visitor is simply taken
     * back to the dashboard.
     */
    public function logout(array $vars = []): void
    {
        if (Auth::check() && !Csrf::matches((string) ($_GET['_token'] ?? ''))) {
            header('Location: ' . ADMIN_URL);
            exit;
        }

        // Where a rule sends this account after signing out — asked
        // while it is still signed in.
        Auth::logout(Auth::check() ? LoginRedirection::afterSignOut((array) Auth::user()) : null);
    }

    /**
     * The sign-in is complete: log it, start the session, and go where the
     * account must go first — a new password it owes, then two-step sign-in
     * the site asks of it, then the dashboard.
     *
     * @param array<string, mixed> $user    the account row, as a session carries it
     * @param string|null          $method  the second step it passed, if any
     * @param string|null          $via     how it got in without a password, if it did
     */
    private function signIn(array $user, string $typed, ?string $method, ?string $via = null): never
    {
        $id = (int) $user['id'];
        // THE LAST WORD BEFORE A SESSION STARTS: a second step can take
        // minutes, and the account may be suspended meanwhile.
        if (!Accounts::mayEnter($id)) {
            SecurityLog::record(SecurityLog::SIGN_IN_FAILED, $typed, $id, 'account suspended');
            $this->render(Accounts::MESSAGE, $typed, null, 403);
            exit;
        }
        // A BROWSER AND A PLACE THIS ACCOUNT HAS NOT SIGNED IN FROM: its owner
        // is told, after the page has gone (`Alerts`). Asked before this
        // sign-in is logged, so it is not its own precedent.
        if (Alerts::isNewDevice($id, ClientIp::key(), SecurityLog::agent())) {
            Alerts::mailNewDevice($user, ClientIp::get(), SecurityLog::agent());
        }
        $how = implode(', ', array_filter([$via !== null ? 'by ' . $via : null, $method !== null ? 'two-step: ' . $method : null]));
        SecurityLog::record(SecurityLog::SIGN_IN, $typed, $id, $how !== '' ? $how : null);
        Auth::startSession($user);
        TwoStep::markConfirmed();

        if (TwoStep::enforces() && TwoStep::requiredFor($user) && TwoStep::methods($id) === []) {
            TwoStep::markAsked($id);
        }

        header('Location: ' . match (true) {
            Auth::mustChange() !== null => Url::admin('choose-password'),
            TwoStep::owed() !== null    => Url::admin('two-step'),
            // Then where a rule sends this account, else the dashboard.
            default                     => LoginRedirection::afterSignIn($user) ?? ADMIN_URL,
        });
        exit;
    }

    /**
     * POST `?action=two-step` — one code, or a request for another method or
     * another emailed code. The address's lockout and the form token have been
     * checked already (`handleLogin()`).
     */
    private function handleTwoStep(string $key): void
    {
        $pending = TwoStep::pending();
        if ($pending === null) {
            $this->render('That sign-in took too long. Please sign in again.');
            return;
        }

        if (isset($_POST['use'])) {
            TwoStep::choose((string) $_POST['use']);
            header('Location: ' . LoginUrl::url() . '?action=two-step', true, 303);
            exit;
        }
        if (isset($_POST['resend'])) {
            $sent = TwoStep::sendEmailCode();
            $sent === 'wait'
                ? $this->renderTwoStep('A code went out less than a minute ago. Check your email, or wait a moment and ask again.')
                : $this->renderTwoStep(null, $sent === 'sent' ? 'A new code is on its way.' : null);
            return;
        }

        $id   = (int) $pending['id'];
        $wait = TwoStep::waitSeconds($id);
        if ($wait > 0) {
            $this->renderTwoStep('Too soon after a wrong code. Wait ' . $wait . ($wait === 1 ? ' second' : ' seconds') . ', then try again.', null, 429);
            return;
        }

        $method = (string) ($_POST['method'] ?? '');
        $method = in_array($method, [Factors::APP, Factors::EMAIL, Factors::BACKUP], true) ? $method : (string) $pending['method'];

        if (TwoStep::check($method, (string) ($_POST['code'] ?? ''))) {
            $remember = ($_POST['remember'] ?? '') === '1' && TwoStep::settings()['remember'];
            $user     = TwoStep::finish();
            if ($user === null) {
                $this->render('That sign-in took too long. Please sign in again.');
                return;
            }
            if ($remember) {
                TrustedBrowsers::remember($id, TwoStep::settings()['remember_days']);
            }
            $this->signIn($user, (string) $pending['username'], $method, isset($pending['via']) ? (string) $pending['via'] : null);
        }

        SecurityLog::record(SecurityLog::SIGN_IN_FAILED, (string) $pending['username'], $id, 'wrong code (' . $method . ')');

        if (TwoStep::wrongCodes($id) >= TwoStep::RESET_AFTER) {
            TwoStep::replacePassword($pending);
            $this->render('Too many wrong codes. This account\'s password has been replaced, and its owner has been sent a way back in.', null, null, 429);
            return;
        }

        $lockout = Throttle::fail($key, (string) $pending['username']);
        if ($lockout !== null) {
            TwoStep::abandon();
            $this->render(Throttle::message($lockout), null, null, 429);
            return;
        }

        $this->renderTwoStep('That code is not right.', null, 422);
    }

    // ── Signing in without a password (`Passwordless`) ──────────────────────

    /** POST — ask for a code or a link (one answer for everyone), or spend a link. */
    private function handlePasswordless(string $action): void
    {
        $method = $action === 'code' ? 'code' : 'link';
        if (!Csrf::validate()) {
            $this->renderPasswordless($method, $action === 'signin' ? 'button' : 'ask', ['This page had expired. Please try again.']);
            return;
        }

        if ($action === 'signin') {
            $id = Passwordless::spendLink((string) ($_GET['key'] ?? ''));
            if ($id === null) {
                $this->renderPasswordless('link', 'invalid');
                return;
            }
            $this->passwordlessSignIn($id, 'email link');
        }

        // The sign-in form's question, by the same rule: a script cannot use
        // this form to mail an account's owner either.
        if (Challenge::required(ClientIp::key()) && !Challenge::passes()) {
            $this->renderPasswordless($method, 'ask', ['That answer was not right. Please try again.'], 422);
            return;
        }
        Passwordless::request($method, (string) ($_POST['login'] ?? ''));
        $this->renderPasswordless($method, $method === 'code' ? 'enter' : 'sent');
    }

    /**
     * POST `?action=code` with a code — a guess, so after the lockout. A wrong
     * code counts against the address like a wrong password; five stop it.
     */
    private function handleCode(string $key): void
    {
        $wrong = null;
        $id    = Passwordless::checkCode((string) ($_POST['code'] ?? ''), $wrong);
        if ($id !== null) {
            $this->passwordlessSignIn($id, 'email code');
        }

        $name = $wrong !== null ? (string) (User::find($wrong)['username'] ?? '') : '';
        SecurityLog::record(SecurityLog::SIGN_IN_FAILED, $name !== '' ? $name : null, $wrong, Passwordless::WRONG_CODE_DETAIL);
        $lockout = Throttle::fail($key, $name);
        if ($lockout !== null) {
            $this->render(Throttle::message($lockout), null, null, 429);
            return;
        }
        $this->renderPasswordless('code', Passwordless::codePending() ? 'enter' : 'ask', [Passwordless::codePending()
            ? 'That is not the code we sent, or it is too old.'
            : 'Too many wrong codes. Ask for a new one.'], 422);
    }

    /**
     * The inbox is proven. On to whatever ELSE the account has as a second
     * step — its authenticator app — never another code by email. Skipping
     * the second step here would make the inbox the account.
     */
    private function passwordlessSignIn(int $id, string $via): never
    {
        $user = Auth::accountRow($id);
        if ($user === null) {
            $this->render('That sign-in no longer works. Please sign in again.');
            exit;
        }
        if (!Accounts::mayEnter($id)) {
            SecurityLog::record(SecurityLog::SIGN_IN_FAILED, (string) $user['username'], $id, 'account suspended');
            $this->render(Accounts::MESSAGE, (string) $user['username'], null, 403);
            exit;
        }
        $proven = [Factors::EMAIL];
        if (TwoStep::needed($user, $proven)) {
            TwoStep::begin($user, $proven, $via);
            header('Location: ' . LoginUrl::url() . '?action=two-step', true, 303);
            exit;
        }
        $this->signIn($user, (string) $user['username'], null, $via);
    }

    // ── Temporary access (`TemporaryAccess`) ───────────────────────────────

    /** POST — the button on a temporary access link's page: one use, then in. */
    private function handleAccess(): void
    {
        $token = (string) ($_GET['key'] ?? '');
        if (!Csrf::validate()) {
            $this->renderAccess(TemporaryAccess::find($token), 'This page had expired. Please try again.');
            return;
        }
        $id = TemporaryAccess::spend($token);
        if ($id === null) {
            $this->renderAccess(null);
            return;
        }

        $user = Auth::accountRow($id);
        if ($user === null || !Accounts::mayEnter($id)) {
            SecurityLog::record(SecurityLog::SIGN_IN_FAILED, (string) ($user['username'] ?? ''), $id, 'account suspended');
            $this->render(Accounts::MESSAGE, (string) ($user['username'] ?? ''), null, 403);
            return;
        }
        // The link proves nothing but itself: a method the account set up for
        // itself is still asked for. The policy never asks a temporary account.
        if (TwoStep::needed($user)) {
            TwoStep::begin($user, [], 'temporary access');
            header('Location: ' . LoginUrl::url() . '?action=two-step', true, 303);
            exit;
        }
        $this->signIn($user, (string) $user['username'], null, 'temporary access');
    }

    /** The page a temporary access link opens: its one button, or why the link no longer works. */
    private function renderAccess(?array $access, ?string $error = null): void
    {
        http_response_code($error !== null ? 422 : 200);
        header('Cache-Control: no-store');

        $signIn = LoginUrl::url();
        require ROOT_PATH . '/admin/templates/access.php';
    }

    /** The passwordless pages: ask, enter (a code), sent (a link), button (a link opened), invalid. */
    private function renderPasswordless(string $method, string $state, array $errors = [], int $status = 200): void
    {
        http_response_code($status);
        header('Cache-Control: no-store');

        $signIn    = LoginUrl::url();
        $settings  = Passwordless::settings();
        $minutes   = $method === 'code' ? $settings['code_minutes'] : $settings['link_minutes'];
        $challenge = $state === 'ask' ? Challenge::questionFor(ClientIp::key()) : null;
        $key       = (string) ($_GET['key'] ?? '');
        require ROOT_PATH . '/admin/templates/passwordless.php';
    }

    /**
     * The second step's page — or, with no sign-in waiting, the sign-in page.
     */
    private function renderTwoStep(?string $error = null, ?string $notice = null, int $status = 200): void
    {
        $pending = TwoStep::pending();
        if ($pending === null) {
            $this->render($error !== null ? 'That sign-in took too long. Please sign in again.' : null);
            return;
        }

        http_response_code($status);
        header('Cache-Control: no-store');

        $id       = (int) $pending['id'];
        $method   = (string) $pending['method'];
        $methods  = TwoStep::pendingMethods($pending);
        $account  = User::find($id);
        $email    = self::maskEmail((string) ($account['email'] ?? ''));
        $settings = TwoStep::settings();
        $backups  = Factors::backupCodesLeft($id);
        $action   = LoginUrl::url() . '?action=two-step';
        $signIn   = LoginUrl::url();
        require ROOT_PATH . '/admin/templates/two-step.php';
    }

    /** "j•••@example.com": enough for the owner to know which inbox, not enough to read one off the page. */
    private static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '';
        }

        return mb_substr($email, 0, 1) . '•••' . substr($email, $at);
    }

    /**
     * POST — ask for a link, or spend one. The token travels in the query
     * string (`?key=`), the way the mailed link carried it, and is checked
     * again here: a form left open past the link's hour must not still work.
     */
    private function handleReset(string $action): void
    {
        if (!Csrf::validate()) {
            $this->renderReset($action === 'lostpassword' ? 'ask' : 'choose', ['This page had expired. Please try again.']);
            return;
        }

        if ($action === 'lostpassword') {
            // The same question as the sign-in form, by the same rule: a
            // script cannot use this form to mail the account's owner either.
            if (Challenge::required(ClientIp::key()) && !Challenge::passes()) {
                $this->renderReset('ask', ['That answer was not right. Please try again.'], 422);
                return;
            }
            PasswordReset::request((string) ($_POST['login'] ?? ''));
            $this->renderReset('sent');
            return;
        }

        $user = PasswordReset::find((string) ($_GET['key'] ?? ''));
        if ($user === null) {
            $this->renderReset('invalid');
            return;
        }

        $password = (string) ($_POST['new_password'] ?? '');
        $errors   = PasswordPolicy::problems($password, $user);
        if ($errors === [] && $password !== (string) ($_POST['new_password_confirm'] ?? '')) {
            $errors[] = 'The two passwords do not match.';
        }
        if ($errors !== []) {
            $this->renderReset('choose', $errors, 422);
            return;
        }

        PasswordReset::complete($user, $password);
        header('Location: ' . LoginUrl::url() . '?reset=done', true, 303);
        exit;
    }

    /**
     * POST `?action=unlock&key=…` — the button on the page the mailed link
     * opens (`unlock.php` has why it is a button). The link is checked again
     * here; a good one lifts the lock and sends its owner to sign in.
     */
    private function handleUnlock(): void
    {
        $token = (string) ($_GET['key'] ?? '');
        if (!Csrf::validate()) {
            $this->renderUnlock(AccountLock::forLink($token), 'This page had expired. Please try again.');
            return;
        }
        if (!AccountLock::unlock($token)) {
            $this->renderUnlock(null);
            return;
        }

        header('Location: ' . LoginUrl::url() . '?unlocked=1', true, 303);
        exit;
    }

    /** The page the mailed link opens: its one button, or why the link no longer works. */
    private function renderUnlock(?array $lock, ?string $error = null): void
    {
        http_response_code($error !== null ? 422 : 200);
        header('Cache-Control: no-store');

        $signIn = LoginUrl::url();
        require ROOT_PATH . '/admin/templates/unlock.php';
    }

    /**
     * The lost-password page, in one of its four states:
     *   ask      the one field — a username or an email address
     *   sent     the same answer whatever was typed
     *   choose   the new password, from a good link
     *   invalid  the link is wrong, used, or out of date
     *
     * @param list<string> $errors
     */
    private function renderReset(string $state, array $errors = [], int $status = 200): void
    {
        http_response_code($status);
        header('Cache-Control: no-store');

        $signIn    = LoginUrl::url();
        $summary   = PasswordPolicy::summary();
        $minutes   = PasswordReset::LIFETIME_MINUTES;
        $challenge = $state === 'ask' ? Challenge::questionFor(ClientIp::key()) : null;
        require ROOT_PATH . '/admin/templates/lost-password.php';
    }

    /**
     * The sign-in page, with whatever it needs to say.
     *
     * @param int|null    $attemptsLeft  shown after a failure while the limit is on
     * @param string|null $notice        just back from a new password, or an unlock link
     */
    private function render(
        ?string $error = null,
        ?string $username = null,
        ?int $attemptsLeft = null,
        int $status = 200,
        ?string $notice = null,
    ): void {
        http_response_code($status);
        // A sign-in page is never cached: it holds a token tied to this session,
        // and a stale copy is a form that can only fail.
        header('Cache-Control: no-store');

        $action    = LoginUrl::url();
        $challenge = Challenge::questionFor(ClientIp::key());
        require ROOT_PATH . '/admin/templates/login.php';
    }

    /**
     * A failed attempt that never reached a password: logged with what it was,
     * counted against the address, answered — or the lockout it has earned.
     */
    private function refused(string $key, string $username, string $detail, string $say): void
    {
        SecurityLog::record(SecurityLog::SIGN_IN_FAILED, $username, null, $detail);

        $lockout = Throttle::fail($key, $username);
        if ($lockout !== null) {
            $this->render(Throttle::message($lockout), $username, null, 429);
            return;
        }

        $this->render($say, $username, Throttle::enabled() ? Throttle::attemptsLeft($key) : null);
    }
}
