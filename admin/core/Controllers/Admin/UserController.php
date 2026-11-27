<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\Helpers\People;
use Cms\Helpers\Url;
use Cms\Security\Accounts;
use Cms\Security\PasswordPolicy;
use Cms\Security\SecurityLog;
use Cms\Security\Sessions;
use Cms\Security\TemporaryAccess;
use Cms\Security\TwoStep;
use Cms\Security\Usernames;
use Cms\Models\User;

class UserController
{
    /**
     * List all users — admin only.
     * Non-admins are redirected to their own profile.
     */
    public function index(array $vars = []): void
    {
        Auth::requireAuth();
        $user = Auth::user();

        if ($user['role'] !== 'admin') {
            header('Location: ' . ADMIN_URL . '/users/' . $user['id'] . '/edit');
            exit;
        }

        Accounts::sweep();                  // so the list says who the inactivity rule has suspended
        $users   = User::all();
        require ROOT_PATH . '/admin/templates/users/list.php';
    }

    /**
     * Show the create user form — admin only.
     */
    public function showCreate(array $vars = []): void
    {
        Auth::requireRole('admin');

        $errors  = [];
        require ROOT_PATH . '/admin/templates/users/create.php';
    }

    /**
     * Handle create user POST — admin only.
     */
    public function handleCreate(array $vars = []): void
    {
        Auth::requireRole('admin');

        $errors   = [];
        $username = trim((string) ($_POST['username'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['password_confirm'] ?? '');
        $role     = trim((string) ($_POST['role'] ?? 'author'));

        // Validate
        if ($username === '') {
            $errors[] = 'Username is required.';
        } elseif (Usernames::blocked($username)) {
            // Common usernames are refused: the name is half of every guess,
            // and these are the names every guess starts with.
            $errors[] = '“' . $username . '” is one of the names every script tries first. Choose another.';
        } elseif (User::usernameExists($username)) {
            $errors[] = 'That username is already taken.';
        }

        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        if ($displayName !== '' && mb_strtolower($displayName) === mb_strtolower($username)) {
            // The display name is PRINTED on the public site (bylines); the
            // username is half of the sign-in. They may not be the same word.
            $errors[] = 'The display name is shown on the public site, so it cannot be the username.';
        }

        if ($email === '') {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } elseif (User::emailExists($email)) {
            $errors[] = 'That email is already in use.';
        }

        if ($password === '') {
            $errors[] = 'Password is required.';
        } else {
            // THE PASSWORD RULE (Security → Login protection → Password
            // strength), which replaced a bare "at least 8 characters".
            $problems = PasswordPolicy::problems($password, [
                'username' => $username, 'email' => $email, 'display_name' => $displayName,
            ]);
            if ($problems !== []) {
                array_push($errors, ...$problems);
            } elseif ($password !== $confirm) {
                $errors[] = 'Passwords do not match.';
            }
        }

        if (!isset(Auth::ROLE_LABELS[$role])) {
            $role = 'author';
        }

        // Which of the site's people it is (change 26) — this screen is an
        // administrator's already. Checked before anything is created.
        $personKey = null;
        if (People::declared() && array_key_exists('person_key', $_POST)) {
            $posted = is_string($_POST['person_key']) ? trim($_POST['person_key']) : '';
            if ($posted !== '') {
                $problem = self::personProblem(0, $posted);
                if ($problem !== null) {
                    $errors[] = $problem;
                } else {
                    $personKey = $posted;
                }
            }
        }

        if (!empty($errors)) {
            require ROOT_PATH . '/admin/templates/users/create.php';
            return;
        }

        // The administrator typed this password, so the person it is for is
        // asked for their own at their first sign-in (migration 061).
        try {
            User::create([
                'username'             => $username,
                'display_name'         => $displayName,
                'email'                => $email,
                'password'             => $password,
                'role'                 => $role,
                'must_change_password' => 'admin',
                'person_key'           => $personKey,
            ]);
        } catch (\PDOException $e) {
            // The unique key, when another save took the person between the
            // check above and this insert: refused like any other problem.
            if (!self::personTaken($e)) {
                throw $e;
            }
            $errors[] = 'That person was just linked to another account. Pick again.';
            require ROOT_PATH . '/admin/templates/users/create.php';
            return;
        }

        header('Location: ' . ADMIN_URL . '/users');
        exit;
    }

    /**
     * Why an account cannot be linked to this person, or null: the site must
     * list the key (exactly — a typed string is never stored), and the person
     * must not already be another account (one person, one account).
     */
    private static function personProblem(int $userId, string $key): ?string
    {
        $person = People::find($key);
        if ($person === null) {
            return 'No one on the site\'s list has the key "' . $key . '"; the list may have changed. Pick again.';
        }
        $holder = User::byPerson($key);
        if ($holder !== null && (int) $holder['id'] !== $userId) {
            return $person['name'] . ' is already ' . User::displayName($holder) . '\'s account. Unlink it there first.';
        }
        return null;
    }

    /** Is this the person's unique key refusing a second account — and nothing else? */
    private static function personTaken(\PDOException $e): bool
    {
        return (string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_users_person_key');
    }

    /**
     * Show the profile / theme edit form for a user.
     * Admins can edit any user; others can only edit themselves.
     */
    public function showEdit(array $vars = []): void
    {
        Auth::requireAuth();
        $user     = Auth::user();
        $targetId = (int) $vars['id'];

        if ($user['role'] !== 'admin' && $user['id'] !== $targetId) {
            header('Location: ' . ADMIN_URL . '/users/' . $user['id'] . '/edit');
            exit;
        }

        Accounts::sweep();
        $profile = User::find($targetId);
        if ($profile === null) {
            http_response_code(404);
            require ROOT_PATH . '/templates/404.php';
            return;
        }

        $isAdmin  = $user['role'] === 'admin';
        $isSelf   = $user['id'] === $targetId;
        $errors   = [];
        $sessions = Sessions::forUser($targetId, Auth::idleSeconds());
        $ended    = isset($_GET['ended']) && ctype_digit((string) $_GET['ended']) ? (int) $_GET['ended'] : null;
        require ROOT_PATH . '/admin/templates/users/edit.php';
    }

    /**
     * Handle profile update POST.
     */
    public function handleUpdate(array $vars = []): void
    {
        Auth::requireAuth();
        $user     = Auth::user();
        $targetId = (int) $vars['id'];

        if ($user['role'] !== 'admin' && $user['id'] !== $targetId) {
            header('Location: ' . ADMIN_URL . '/users/' . $user['id'] . '/edit');
            exit;
        }

        $profile = User::find($targetId);
        if ($profile === null) {
            http_response_code(404);
            require ROOT_PATH . '/templates/404.php';
            return;
        }

        $isAdmin = $user['role'] === 'admin';
        $isSelf  = $user['id'] === $targetId;
        $errors  = [];

        // THE ADMIN THEME (`Ui\Theme`): the account's own, set by the user or
        // by an administrator. The default is kept as NULL (migration 070), so
        // the account follows whatever the default is.
        if (isset($_POST['theme_preset'])) {
            $themePreset = (string) $_POST['theme_preset'];
            if (!\Cms\Ui\Theme::offers($themePreset)) {
                $errors[] = 'Choose an admin theme from the list.';
            } else {
                $stored = $themePreset === \Cms\Ui\Theme::defaultKey() ? null : $themePreset;
                User::update($targetId, ['theme_preset' => $stored]);
                if ($isSelf) {
                    Auth::refreshSessionField('theme_preset', $stored);
                }
            }
        }

        // ITS CORNERS, beside the theme and set by the same people: Square,
        // the default, kept as NULL, or Rounded (`Ui\Theme::CORNERS`).
        if (isset($_POST['radius_pref'])) {
            $radius = (string) $_POST['radius_pref'];
            if (!isset(\Cms\Ui\Theme::CORNERS[$radius])) {
                $errors[] = 'Choose Square or Rounded corners.';
            } else {
                $stored = $radius === \Cms\Ui\Theme::DEFAULT_RADIUS ? null : $radius;
                User::update($targetId, ['radius_pref' => $stored]);
                if ($isSelf) {
                    Auth::refreshSessionField('radius_pref', $stored);
                }
            }
        }

        // Email (editable by self or admin)
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email !== '' && $email !== $profile['email']) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            } elseif (User::emailExists($email, $targetId)) {
                $errors[] = 'That email is already in use.';
            } else {
                User::update($targetId, ['email' => $email]);
                if ($isSelf) {
                    Auth::refreshSessionField('email', $email);
                }
            }
        }

        // Display name (self or admin, like the email) — migration 055. The
        // key's presence says the box was there; empty clears it back to the
        // username.
        if (array_key_exists('display_name', $_POST) && !is_array($_POST['display_name'])) {
            $displayName = trim((string) $_POST['display_name']);
            if ($displayName !== '' && mb_strtolower($displayName) === mb_strtolower((string) $profile['username'])) {
                // Printed on the public site; the username is half the sign-in.
                $errors[] = 'The display name is shown on the public site, so it cannot be the username.';
            } else {
                User::update($targetId, ['display_name' => $displayName]);
            }
        }

        // Role (admin editing another user only — cannot change own role)
        if ($isAdmin && !$isSelf) {
            $role = trim((string) ($_POST['role'] ?? ''));
            // A TEMPORARY account is never an administrator.
            if ($role === 'admin' && TemporaryAccess::is($targetId)) {
                $errors[] = 'A temporary account is never an administrator.';
                $role = '';
            }
            if ($role !== '' && $role !== $profile['role'] && isset(Auth::ROLE_LABELS[$role])) {
                User::update($targetId, ['role' => $role]);
            }
        }

        // WHICH OF THE SITE'S PEOPLE THIS ACCOUNT IS —
        // AN ADMINISTRATOR'S CALL ONLY, own account included: a
        // byline's page and photo are the site's to give, and an author who
        // could set this could sign as somebody else. The key's presence says
        // the field was there; a save without it leaves the link alone.
        if ($isAdmin && People::declared() && array_key_exists('person_key', $_POST)) {
            $posted  = is_string($_POST['person_key']) ? trim($_POST['person_key']) : '';
            $current = (string) ($profile['person_key'] ?? '');
            if ($posted !== $current) {
                $problem = $posted === '' ? null : self::personProblem($targetId, $posted);
                if ($problem !== null) {
                    $errors[] = $problem;
                } else {
                    try {
                        User::linkPerson($targetId, $posted);
                    } catch (\PDOException $e) {
                        // The unique key, when two saves race for one person.
                        if (!self::personTaken($e)) {
                            throw $e;
                        }
                        $errors[] = 'That person was just linked to another account. Pick again.';
                    }
                }
            }
        }

        // Password change
        $newPassword = (string) ($_POST['new_password'] ?? '');
        // A TEMPORARY account has none: it signs in only by its link, so its
        // time and its uses mean what they say.
        if ($newPassword !== '' && TemporaryAccess::is($targetId)) {
            $errors[] = 'A temporary account has no password: it signs in only by its link.';
            $newPassword = '';
        }
        if ($newPassword !== '') {
            $problems = PasswordPolicy::problems($newPassword, [
                'username'     => (string) $profile['username'],
                'email'        => (string) $profile['email'],
                'display_name' => (string) ($profile['display_name'] ?? ''),
            ]);
            if ($problems !== []) {
                array_push($errors, ...$problems);
            } elseif ($newPassword !== (string) ($_POST['new_password_confirm'] ?? '')) {
                $errors[] = 'New passwords do not match.';
            } else {
                // Self: require current password. Admin editing another: no current password needed.
                // A NEW PASSWORD ENDS EVERY OTHER SESSION the account has, on
                // every machine — the reason anyone changes a password is that
                // somebody else might know the old one. The browser making the
                // change stays signed in (`Auth::endSessions()`).
                if ($isSelf) {
                    $currentPassword = (string) ($_POST['current_password'] ?? '');
                    if ($currentPassword === '') {
                        $errors[] = 'Current password is required to set a new password.';
                    } elseif (!User::verifyPassword($targetId, $currentPassword)) {
                        $errors[] = 'Current password is incorrect.';
                    } else {
                        User::updatePassword($targetId, $newPassword, null);
                        Auth::endSessions($targetId);
                    }
                } else {
                    // An administrator chose it, so its owner is asked for
                    // their own at their next sign-in (migration 061).
                    User::updatePassword($targetId, $newPassword, 'admin');
                    Auth::endSessions($targetId);
                }
            }
        }

        // Re-fetch after changes
        $profile  = User::find($targetId);
        $saved    = empty($errors);
        $sessions = Sessions::forUser($targetId, Auth::idleSeconds());
        $ended    = null;
        require ROOT_PATH . '/admin/templates/users/edit.php';
    }

    /**
     * POST — sign one of this account's browsers out (`Security\Sessions`).
     * Your own current one is not offered: that is "Log out".
     */
    public function endSession(array $vars = []): void
    {
        Auth::requireAuth();
        $targetId = $this->mayManageSessions((int) $vars['id']);

        $n = Sessions::revoke($targetId, (int) $vars['sid']) ? 1 : 0;
        if ($n === 1) {
            SecurityLog::record(SecurityLog::SESSION_ENDED, null, (int) Auth::user()['id'], 'one browser of account #' . $targetId);
        }

        header('Location: ' . ADMIN_URL . '/users/' . $targetId . '/edit?ended=' . $n, true, 303);
        exit;
    }

    /**
     * POST — sign this account out everywhere: every other browser when it is
     * yours, every browser when it is someone else's. `Auth::endSessions()`,
     * the same call a password change makes.
     */
    public function endAllSessions(array $vars = []): void
    {
        Auth::requireAuth();
        $targetId = $this->mayManageSessions((int) $vars['id']);

        $before = count(Sessions::forUser($targetId, Auth::idleSeconds()));
        Auth::endSessions($targetId);
        $after  = count(Sessions::forUser($targetId, Auth::idleSeconds()));
        SecurityLog::record(SecurityLog::SESSION_ENDED, null, (int) Auth::user()['id'], 'every other browser of account #' . $targetId);

        header('Location: ' . ADMIN_URL . '/users/' . $targetId . '/edit?ended=' . max(0, $before - $after), true, 303);
        exit;
    }

    /** Yours, or anyone's if you are an administrator — as editing is. Otherwise back to your own. */
    private function mayManageSessions(int $targetId): int
    {
        $user = Auth::user();
        if ($user['role'] !== 'admin' && (int) $user['id'] !== $targetId) {
            header('Location: ' . ADMIN_URL . '/users/' . $user['id'] . '/edit');
            exit;
        }

        return $targetId;
    }

    /**
     * POST — SUSPEND this account (`Accounts`, migration 067): it cannot sign
     * in by any route until an administrator restores it, and every browser
     * it is signed in on is signed out now. An administrator's act on someone
     * else's account — never their own, which would leave nobody to restore
     * it. The password again first, as for the Security screens: a browser
     * left signed in must not be able to lock the owner out.
     */
    public function suspend(array $vars = []): void
    {
        Auth::requireRole('admin');
        $targetId = (int) $vars['id'];
        TwoStep::requireConfirmed('/admin/users/' . $targetId . '/edit');
        $me = (int) Auth::user()['id'];

        if ($targetId !== $me && User::find($targetId) !== null) {
            Accounts::suspend($targetId, 'admin', $me);
        }

        header('Location: ' . ADMIN_URL . '/users/' . $targetId . '/edit?done=suspended', true, 303);
        exit;
    }

    /**
     * POST — RESTORE a suspended account: it can sign in again, and the
     * inactivity rule's clock starts over. From Users → Edit, or from the
     * list on Security → Login protection → Inactive accounts (`back`).
     */
    public function restore(array $vars = []): void
    {
        Auth::requireRole('admin');
        $targetId = (int) $vars['id'];
        $fromList = ($_POST['back'] ?? '') === 'inactive';
        TwoStep::requireConfirmed($fromList ? '/admin/security/login-protection?tab=inactive' : '/admin/users/' . $targetId . '/edit');

        Accounts::restore($targetId, (int) Auth::user()['id']);

        header('Location: ' . ($fromList
            ? Url::admin('security/login-protection') . '?tab=inactive&restored=1'
            : ADMIN_URL . '/users/' . $targetId . '/edit?done=restored'), true, 303);
        exit;
    }

    /**
     * Delete a user — admin only. Cannot delete self.
     */
    public function handleDelete(array $vars = []): void
    {
        Auth::requireRole('admin');
        $user     = Auth::user();
        $targetId = (int) $vars['id'];

        // Cannot delete yourself
        if ($user['id'] === $targetId) {
            header('Location: ' . ADMIN_URL . '/users');
            exit;
        }

        // Reassign content to the current admin and delete
        User::delete($targetId, (int) $user['id']);

        header('Location: ' . ADMIN_URL . '/users');
        exit;
    }
}
